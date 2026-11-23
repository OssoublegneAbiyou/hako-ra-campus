<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CourseOccurrence;
use App\Models\DomainEvent;
use App\Models\Membership;
use App\Models\PublishedOccurrence;
use App\Models\SchedulePublication;
use App\Models\SchedulePublicationChange;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publication versionnée du planning institutionnel.
 * Le brouillon (course_occurrences) reste la copie de travail ; chaque publication fige une version immuable,
 * met à jour la projection lue par les membres et écrit les événements métier dans la même transaction.
 */
class SchedulePublisher
{
    public const CHANGE_LABELS = ['added' => 'Ajouté', 'updated' => 'Modifié', 'cancelled' => 'Annulé', 'restored' => 'Rétabli', 'removed' => 'Retiré',
        'skipped' => 'Non rétabli (référence devenue invalide)', 'overlaps' => 'Rétabli avec chevauchement à vérifier'];

    public function __construct(private ScheduleAccess $access, private ScheduleManager $manager) {}

    public function current(Membership $acting): ?SchedulePublication
    {
        return $this->publications($acting)->orderByDesc('version')->first();
    }

    public function publications(Membership $acting)
    {
        return SchedulePublication::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id);
    }

    /**
     * État canonique d'une occurrence : mêmes clés, même ordre, instants en UTC. Les groupes doivent être chargés.
     *
     * @return array<string, mixed>
     */
    public function canonical(CourseOccurrence $occurrence): array
    {
        $state = $this->manager->snapshot($occurrence);
        $state['starts_at'] = $occurrence->starts_at->utc()->format('Y-m-d\TH:i:s\Z');
        $state['ends_at'] = $occurrence->ends_at->utc()->format('Y-m-d\TH:i:s\Z');
        $state['is_exception'] = (bool) $state['is_exception'];
        ksort($state);

        return $state;
    }

    /** @param array<string, mixed> $state */
    public function hash(array $state): string
    {
        ksort($state);

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Compare le brouillon à la version publiée, sans rien écrire.
     * Un cours jamais publié et déjà annulé ou exclu n'est pas publié : il n'a jamais été annoncé.
     *
     * @return array{changes: array<string, array{change: string, before: array<string, mixed>|null, after: array<string, mixed>, hash: string}>, stateHash: string}
     */
    public function diff(Membership $acting): array
    {
        $draft = CourseOccurrence::query()->where('scope', 'institution')->where('institution_id', $acting->institution_id)
            ->where('academic_year_id', $acting->academic_year_id);
        $limit = (int) config('campus.schedule.publication_max_occurrences');
        if ((clone $draft)->count() > $limit) {
            throw ValidationException::withMessages(['publication' => 'Le planning dépasse '.$limit.' occurrences : la publication en une fois n’est pas prise en charge.']);
        }
        $state = PublishedOccurrence::query()->where('institution_id', $acting->institution_id)
            ->where('academic_year_id', $acting->academic_year_id)->pluck('snapshot_hash', 'course_occurrence_id')->all();
        $changes = [];
        $draft->with('groups')->chunkById(500, function ($occurrences) use (&$changes, &$state): void {
            foreach ($occurrences as $occurrence) {
                $after = $this->canonical($occurrence);
                $hash = $this->hash($after);
                $known = $state[$occurrence->id] ?? null;
                if (($known === null && $after['status'] !== 'active') || $known === $hash) {
                    continue;
                }
                $changes[$occurrence->id] = ['change' => 'added', 'before' => null, 'after' => $after, 'hash' => $hash];
                $state[$occurrence->id] = $hash;
            }
        });
        foreach (array_chunk(array_keys($changes), 500) as $ids) {
            foreach (PublishedOccurrence::query()->whereIn('course_occurrence_id', $ids)->get() as $published) {
                $changes[$published->course_occurrence_id]['before'] = $published->snapshot;
                $changes[$published->course_occurrence_id]['change'] = $this->kind($published->snapshot, $changes[$published->course_occurrence_id]['after']);
            }
        }

        return ['changes' => $changes, 'stateHash' => $this->stateHash($state)];
    }

    /**
     * Publie exactement ce qui a été prévisualisé. Une demande rejouée renvoie la version déjà créée ;
     * un brouillon ou une version qui a changé depuis la prévisualisation provoque un conflit.
     */
    public function publish(Request $r): SchedulePublication
    {
        $d = $r->validate([
            'request_key' => 'required|uuid', 'expected_version' => 'required|integer|min:0',
            'preview_hash' => 'required|string|size:64', 'reason' => 'required|string|min:3|max:1000',
        ]);

        return DB::transaction(function () use ($r, $d): SchedulePublication {
            $acting = $this->access->lock($r);
            abort_unless($acting !== null, 403);
            $replayed = $this->publications($acting)->where('request_key', $d['request_key'])->first();
            if ($replayed) {
                abort_unless($replayed->kind === 'publish' && hash_equals($replayed->content_hash, $d['preview_hash']), 409);

                return $replayed;
            }
            abort_unless(($this->current($acting)?->version ?? 0) === (int) $d['expected_version'], 409);
            $diff = $this->diff($acting);
            abort_unless(hash_equals($diff['stateHash'], $d['preview_hash']), 409);
            if ($diff['changes'] === []) {
                throw ValidationException::withMessages(['publication' => 'Aucune différence à publier : le planning publié est déjà identique au brouillon.']);
            }

            return $this->record($r, $acting, 'publish', null, $diff['changes'], $d);
        }, 3);
    }

    /**
     * Revient à une version antérieure en créant une nouvelle version. Seuls les cours à venir sont touchés ;
     * le brouillon est réaligné pour qu'une publication suivante ne fasse pas réapparaître ce qui vient d'être retiré.
     */
    public function rollback(Request $r, int $version): SchedulePublication
    {
        $d = $r->validate(['request_key' => 'required|uuid', 'expected_version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:1000']);

        return DB::transaction(function () use ($r, $d, $version): SchedulePublication {
            $acting = $this->access->lock($r);
            abort_unless($acting !== null, 403);
            $replayed = $this->publications($acting)->where('request_key', $d['request_key'])->first();
            if ($replayed) {
                abort_unless($replayed->kind === 'rollback' && $replayed->source_version === $version, 409);

                return $replayed;
            }
            $current = $this->current($acting);
            abort_unless($current !== null && $version >= 1 && $version < $current->version && $this->publications($acting)->where('version', $version)->exists(), 404);
            abort_unless($current->version === (int) $d['expected_version'], 409);

            $target = $this->stateAt($acting, $version);
            $now = now();
            $reason = 'Retour à la version '.$version.' : '.$d['reason'];
            $changes = [];
            $active = [];
            $skipped = 0;
            $published = PublishedOccurrence::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id);
            foreach ($published->cursor() as $row) {
                $before = $row->snapshot;
                $wanted = $target[$row->course_occurrence_id] ?? null;
                if (CarbonImmutable::parse($before['ends_at'])->lte($now) || ($wanted !== null && CarbonImmutable::parse($wanted['ends_at'])->lte($now))) {
                    continue;
                }
                if ($wanted === null) {
                    if ($before['status'] === 'calendar_excluded') {
                        continue;
                    }
                    $wanted = [...$before, 'status' => 'calendar_excluded', 'is_exception' => true];
                }
                if ($this->hash($wanted) === $row->snapshot_hash) {
                    continue;
                }
                if ($wanted['status'] === 'active' && ! $this->manager->referencesStillValid($wanted)) {
                    $skipped++;

                    continue;
                }
                $occurrence = CourseOccurrence::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)
                    ->with('groups')->lockForUpdate()->findOrFail($row->course_occurrence_id);
                $restored = $this->manager->restore($r, $occurrence, $wanted, mb_substr($reason, 0, 1000));
                $after = $this->canonical($restored->load('groups'));
                $changes[$row->course_occurrence_id] = ['change' => $this->kind($before, $after), 'before' => $before, 'after' => $after, 'hash' => $this->hash($after)];
                if ($after['status'] === 'active') {
                    $active[] = $restored;
                }
            }
            if ($changes === []) {
                throw ValidationException::withMessages(['publication' => $skipped > 0
                    ? 'Rien à rétablir : les '.$skipped.' cours concernés référencent un enseignant, un groupe ou une matière qui n’est plus valide.'
                    : 'Aucun cours à venir ne diffère de la version '.$version.' : rien à rétablir.']);
            }
            // Le retour arrière ne se bloque pas : les écarts sont comptés dans le résumé de la version pour être traités ensuite.
            $notes = array_filter([
                'skipped' => $skipped,
                'overlaps' => collect($active)->filter(fn (CourseOccurrence $occurrence) => $this->manager->overlapping($occurrence) > 0)->count(),
            ]);

            return $this->record($r, $acting, 'rollback', $version, $changes, [...$d, 'reason' => $d['reason']], $notes);
        }, 3);
    }

    /**
     * État publié à une version donnée : pour chaque occurrence, sa dernière différence jusqu'à cette version.
     *
     * @return array<string, array<string, mixed>>
     */
    public function stateAt(Membership $acting, int $version): array
    {
        $ranked = DB::table('schedule_publication_changes as c')->join('schedule_publications as p', 'p.id', '=', 'c.schedule_publication_id')
            ->where('p.institution_id', $acting->institution_id)->where('p.academic_year_id', $acting->academic_year_id)->where('p.version', '<=', $version)
            ->selectRaw('c.course_occurrence_id, c.after_state, ROW_NUMBER() OVER (PARTITION BY c.course_occurrence_id ORDER BY p.version DESC) AS position');
        $state = [];
        foreach (DB::query()->fromSub($ranked, 'ranked')->where('position', 1)->cursor() as $row) {
            $state[$row->course_occurrence_id] = json_decode($row->after_state, true, 512, JSON_THROW_ON_ERROR);
        }

        return $state;
    }

    /**
     * @param  array<string, array{change: string, before: array<string, mixed>|null, after: array<string, mixed>, hash: string}>  $changes
     * @param  array<string, mixed>  $d
     * @param  array<string, int>  $notes
     */
    private function record(Request $r, Membership $acting, string $kind, ?int $source, array $changes, array $d, array $notes = []): SchedulePublication
    {
        $now = now();
        $version = ($this->current($acting)?->version ?? 0) + 1;
        $state = PublishedOccurrence::query()->where('institution_id', $acting->institution_id)
            ->where('academic_year_id', $acting->academic_year_id)->pluck('snapshot_hash', 'course_occurrence_id')->all();
        foreach ($changes as $id => $change) {
            $state[$id] = $change['hash'];
        }
        $summary = [...array_count_values(array_column($changes, 'change')), ...$notes];
        $publication = SchedulePublication::create([
            'institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id, 'version' => $version, 'kind' => $kind,
            'source_version' => $source, 'author_id' => $r->user()->id, 'reason' => $d['reason'], 'request_key' => $d['request_key'],
            'content_hash' => $this->stateHash($state), 'summary' => $summary, 'published_at' => $now,
        ]);
        $json = fn (?array $value): ?string => $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $relevant = fn (?array $s): ?array => $s === null ? null : array_intersect_key($s, array_flip(['starts_at', 'ends_at', 'status', 'group_ids', 'teacher_membership_id', 'timezone']));

        foreach (array_chunk($changes, 300, true) as $chunk) {
            $rows = $projection = $pivot = $events = [];
            foreach ($chunk as $id => $change) {
                $after = $change['after'];
                $rows[] = ['schedule_publication_id' => $publication->id, 'course_occurrence_id' => $id, 'change' => $change['change'],
                    'before_state' => $json($change['before']), 'after_state' => $json($after)];
                $projection[] = ['course_occurrence_id' => $id, 'institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id,
                    'publication_version' => $version, 'snapshot_hash' => $change['hash'], 'title' => $after['title'], 'status' => $after['status'],
                    'starts_at' => CarbonImmutable::parse($after['starts_at'])->utc()->format('Y-m-d H:i:s'),
                    'ends_at' => CarbonImmutable::parse($after['ends_at'])->utc()->format('Y-m-d H:i:s'),
                    'teacher_membership_id' => $after['teacher_membership_id'], 'snapshot' => $json($after)];
                foreach ($after['group_ids'] as $groupId) {
                    $pivot[] = ['course_occurrence_id' => $id, 'group_id' => $groupId, 'institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id];
                }
                $future = CarbonImmutable::parse($after['ends_at'])->gt($now) || ($change['before'] !== null && CarbonImmutable::parse($change['before']['ends_at'])->gt($now));
                $events[] = ['type' => 'schedule.occurrence.changed', 'dedup_key' => 'schedule-publication:'.$publication->id.':'.$id,
                    'institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id, 'subject_uuid' => $id,
                    'payload' => $json(['publication_id' => $publication->id, 'version' => $version, 'kind' => $kind, 'change' => $change['change'],
                        'before' => $relevant($change['before']), 'after' => $relevant($after), 'affects_future' => $future]),
                    'occurred_at' => $now];
            }
            SchedulePublicationChange::query()->insert($rows);
            PublishedOccurrence::query()->upsert($projection, ['course_occurrence_id'], ['publication_version', 'snapshot_hash', 'title', 'status', 'starts_at', 'ends_at', 'teacher_membership_id', 'snapshot']);
            DB::table('published_occurrence_group')->whereIn('course_occurrence_id', array_keys($chunk))->delete();
            DB::table('published_occurrence_group')->insert($pivot);
            DomainEvent::query()->insert($events);
        }
        DomainEvent::query()->insert(['type' => 'schedule.published', 'dedup_key' => 'schedule-publication:'.$publication->id,
            'institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id, 'subject_uuid' => null,
            'payload' => $json(['publication_id' => $publication->id, 'version' => $version, 'kind' => $kind, 'source_version' => $source, 'summary' => $summary]),
            'occurred_at' => $now]);
        AuditLog::record($r->user(), 'schedule.publication.'.$kind, $publication, $acting->institution_id, null, ['version' => $version, 'source_version' => $source, 'changes' => count($changes)]);

        return $publication;
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function kind(?array $before, array $after): string
    {
        return match (true) {
            $before === null => 'added',
            $after['status'] === 'cancelled' && $before['status'] !== 'cancelled' => 'cancelled',
            $after['status'] === 'calendar_excluded' && $before['status'] !== 'calendar_excluded' => 'removed',
            $after['status'] === 'active' && $before['status'] !== 'active' => 'restored',
            default => 'updated',
        };
    }

    /** @param array<string, string> $state */
    private function stateHash(array $state): string
    {
        ksort($state);

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
