<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DomainEvent;
use App\Models\Opportunity;
use App\Models\OpportunityRevision;
use App\Models\User;
use App\Support\Labels;
use App\Support\PrivilegedAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Catalogue éditorial des opportunités : rédaction, vérification explicite, publication, suspension, archivage, éditions.
 * Aucune requête n'est jamais faite vers l'adresse de la source : la vérification est un acte manuel, daté et tracé, de l'éditeur.
 */
class Opportunities
{
    public const DATE_RULE = 'L’organisateur n’indique pas d’heure. Campus ne présume aucune heure de clôture : la fiche reste affichée comme ouverte jusqu’à la fin de cette journée dans le dernier fuseau horaire du monde (UTC−12). Ce n’est pas une heure limite garantie : vérifiez sur la source et n’attendez pas le dernier jour.';

    public const MIN_CONDITIONS = 20;

    public function __construct(private ContextVisibility $visibility, private NotificationStore $notifications) {}

    /**
     * Fiches visibles hors de l'équipe éditoriale : publiées, et elles seules.
     */
    public function published(): Builder
    {
        return Opportunity::query()->where('status', 'published');
    }

    /**
     * Fiches consultables par leur adresse : publiées, ou qui l'ont été puis ont été suspendues ou archivées. Ces dernières sont
     * servies dans une forme réduite et clairement identifiée. Une fiche jamais publiée (brouillon, à vérifier) reste introuvable.
     */
    public function identified(): Builder
    {
        return Opportunity::query()->where(fn (Builder $query) => $query->where('status', 'published')
            ->orWhere(fn (Builder $former) => $former->whereIn('status', ['suspended', 'archived'])->whereNotNull('published_at')));
    }

    /**
     * Fiches des listes ouvertes : publiées et non closes selon la borne technique du catalogue.
     */
    public function open(?CarbonImmutable $now = null): Builder
    {
        $now ??= now()->toImmutable();

        return $this->published()->where(fn (Builder $query) => $query->whereNull('closes_at')->orWhere('closes_at', '>', $now));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $editor, array $data): Opportunity
    {
        $this->authorize($editor);

        return DB::transaction(function () use ($editor, $data): Opportunity {
            $content = $this->content($data);
            $this->assertNoDuplicate($content, null, (bool) ($data['confirm_same_source'] ?? false));
            $opportunity = Opportunity::create([...$content, 'created_by' => $editor->id]);
            $this->revise($opportunity, 'created', $editor);

            return $opportunity;
        }, 3);
    }

    /**
     * Modifie une fiche. Sur une fiche publiée ou suspendue, toute modification exige un nouvel acte de vérification.
     * Un changement de date limite conserve l'ancienne date, invalide les rappels programmés et émet un événement.
     *
     * @param  array<string, mixed>  $data
     * @return array{opportunity: Opportunity, replayed: bool}
     */
    public function update(User $editor, string $id, array $data): array
    {
        $this->authorize($editor);

        return DB::transaction(function () use ($editor, $id, $data): array {
            $opportunity = Opportunity::query()->lockForUpdate()->findOrFail($id);
            $content = $this->content($data);
            $before = $this->snapshot($opportunity);
            $changed = array_keys(array_filter($content, fn ($value, string $field) => array_key_exists($field, $before) && $value != $before[$field], ARRAY_FILTER_USE_BOTH));
            if ($changed === [] && (int) $data['expected_version'] <= $opportunity->lock_version) {
                return ['opportunity' => $opportunity, 'replayed' => true];
            }
            if ((int) $data['expected_version'] !== $opportunity->lock_version) {
                throw new ConflictHttpException('Cette fiche a été modifiée ailleurs. Rechargez-la avant de l’enregistrer.');
            }
            if ($opportunity->status === 'archived') {
                throw ValidationException::withMessages(['reference' => 'Une fiche archivée ne se modifie plus. Créez une nouvelle édition.']);
            }
            $this->assertNoDuplicate($content, $opportunity->id, (bool) ($data['confirm_same_source'] ?? false));
            $live = in_array($opportunity->status, ['published', 'suspended'], true);
            $deadlineChanged = array_intersect($changed, ['deadline_kind', 'deadline_at', 'deadline_timezone', 'deadline_date']) !== [];
            $previous = $this->deadline($opportunity, false);
            $oldCloses = $opportunity->closes_at;
            $opportunity->fill([...$content, 'lock_version' => $opportunity->lock_version + 1]);
            if ($live) {
                $this->assertPublishable($opportunity);
                $this->attest($opportunity, $editor, (bool) ($data['attest'] ?? false));
                if ($deadlineChanged) {
                    // L'ancienne date reste affichée à côté de la nouvelle.
                    $opportunity->previous_deadline = [...$previous, 'changedAt' => now()->utc()->toIso8601String()];
                }
            }
            $opportunity->save();
            $this->revise($opportunity, $live && $deadlineChanged ? 'deadline_changed' : 'updated', $editor, $live && $deadlineChanged ? 'Ancienne date : '.$previous['text'] : null);
            if ($live && $deadlineChanged) {
                $this->notifications->cancelSubject('opportunity', $opportunity->id, 'deadline_changed');
                $this->event($opportunity, 'opportunity.deadline_changed', ['extended' => $opportunity->closes_at === null || ($oldCloses !== null && $opportunity->closes_at->greaterThan($oldCloses))]);
            }

            return ['opportunity' => $opportunity, 'replayed' => false];
        }, 3);
    }

    /**
     * Change l'état d'une fiche. Chaque action est tracée avec son auteur ; publier ou reprendre exige l'acte explicite de vérification.
     */
    public function transition(User $editor, string $id, string $action, int $expectedVersion, bool $attest = false, ?string $note = null): Opportunity
    {
        $this->authorize($editor);

        return DB::transaction(function () use ($editor, $id, $action, $expectedVersion, $attest, $note): Opportunity {
            $opportunity = Opportunity::query()->lockForUpdate()->findOrFail($id);
            [$from, $to, $revision] = match ($action) {
                'submit' => [['draft'], 'review', 'submitted'],
                'return' => [['review'], 'draft', 'returned'],
                'verify' => [['review'], 'published', 'verified'],
                'reverify' => [['published'], 'published', 'reverified'],
                'suspend' => [['published'], 'suspended', 'suspended'],
                'resume' => [['suspended'], 'published', 'resumed'],
                'archive' => [['draft', 'review', 'published', 'suspended'], 'archived', 'archived'],
            };
            // Rejeu : la fiche est déjà dans l'état demandé.
            if ($action !== 'reverify' && $opportunity->status === $to && $expectedVersion < $opportunity->lock_version) {
                return $opportunity;
            }
            if ($expectedVersion !== $opportunity->lock_version) {
                throw new ConflictHttpException('Cette fiche a été modifiée ailleurs. Rechargez-la avant de continuer.');
            }
            if (! in_array($opportunity->status, $from, true)) {
                throw ValidationException::withMessages(['reference' => 'Cette action n’est pas possible depuis l’état « '.Opportunity::STATUSES[$opportunity->status].' ».']);
            }
            $note = $note === null || trim($note) === '' ? null : $this->clean($note, 500, false);
            if ($action === 'suspend' && $note === null) {
                throw ValidationException::withMessages(['note' => 'Indiquez le motif de la suspension (interne à l’équipe éditoriale).']);
            }
            if (in_array($action, ['verify', 'reverify', 'resume'], true)) {
                $this->assertPublishable($opportunity);
                $this->attest($opportunity, $editor, $attest);
                $opportunity->published_at ??= now();
            }
            $opportunity->fill(['status' => $to, 'lock_version' => $opportunity->lock_version + 1,
                'suspended_at' => $to === 'suspended' ? now() : null, 'archived_at' => $to === 'archived' ? now() : null])->save();
            $this->revise($opportunity, $revision, $editor, $note);
            AuditLog::record($editor, 'opportunity.'.$revision, $opportunity, null, null, ['version' => $opportunity->lock_version, 'campaign' => $opportunity->campaign]);
            if (in_array($action, ['suspend', 'archive'], true)) {
                // La fiche quitte les listes ouvertes : ses rappels programmés n'ont plus lieu d'être ; les détenteurs de favoris seront informés par l'événement.
                $this->notifications->cancelSubject('opportunity', $opportunity->id, $to);
                $this->freezeReferences($opportunity);
                $this->event($opportunity, 'opportunity.'.$to);
            } elseif ($action === 'resume') {
                $this->event($opportunity, 'opportunity.resumed');
            }

            return $opportunity;
        }, 3);
    }

    /**
     * Nouvelle édition : une fiche distincte, en brouillon, pour une autre campagne. L'ancienne fiche et tout son historique restent intacts.
     * Les dates et la vérification ne sont pas recopiées : elles doivent être établies de nouveau.
     */
    public function newEdition(User $editor, string $id, string $campaign): Opportunity
    {
        $this->authorize($editor);

        return DB::transaction(function () use ($editor, $id, $campaign): Opportunity {
            $source = Opportunity::query()->lockForUpdate()->findOrFail($id);
            $campaign = $this->clean($campaign, 60, false);
            if ($campaign === '' || Str::lower($campaign) === Str::lower($source->campaign)) {
                throw ValidationException::withMessages(['campaign' => 'Indiquez une campagne différente de « '.$source->campaign.' ».']);
            }
            $content = [...collect($source->getAttributes())->only(Opportunity::CONTENT)->all(), 'campaign' => $campaign,
                ...collect(Opportunity::LISTS)->mapWithKeys(fn (string $list) => [$list => $source->{$list} ?? []])->all(), 'normalized_fields' => $source->normalized_fields,
                'deadline_kind' => 'unknown', 'deadline_at' => null, 'deadline_timezone' => null, 'deadline_date' => null, 'last_day_starts_at' => null, 'closes_at' => null,
                'normalized_title' => $source->normalized_title, 'normalized_organizer' => $source->normalized_organizer, 'source_hash' => $source->source_hash];
            $this->assertNoDuplicate($content, null, true);
            $edition = Opportunity::create([...$content, 'created_by' => $editor->id, 'previous_edition_id' => $source->id]);
            $this->revise($edition, 'created', $editor, 'Nouvelle édition de la campagne '.$source->campaign);

            return $edition;
        }, 3);
    }

    /**
     * Archive les fiches publiées dont la clôture connue est passée. Les fiches sans date connue ne sont jamais archivées d'office.
     */
    public function archiveClosed(int $limit, ?CarbonImmutable $now = null): int
    {
        $now ??= now()->toImmutable();
        $archived = 0;
        $this->published()->whereNotNull('closes_at')->where('closes_at', '<=', $now)->orderBy('closes_at')->limit($limit)->pluck('id')->each(function (string $id) use ($now, &$archived): void {
            DB::transaction(function () use ($id, $now, &$archived): void {
                $opportunity = Opportunity::query()->lockForUpdate()->find($id);
                if ($opportunity === null || $opportunity->status !== 'published' || $opportunity->closes_at === null || $opportunity->closes_at->greaterThan($now)) {
                    return;
                }
                $opportunity->fill(['status' => 'archived', 'archived_at' => $now, 'lock_version' => $opportunity->lock_version + 1])->save();
                $this->revise($opportunity, 'auto_archived', null);
                $this->notifications->cancelSubject('opportunity', $opportunity->id, 'archived');
                $this->freezeReferences($opportunity);
                $this->event($opportunity, 'opportunity.archived');
                $archived++;
            });
        });

        return $archived;
    }

    /**
     * Date limite telle qu'elle doit être affichée : précision d'origine, fuseau d'origine, et état technique du catalogue.
     *
     * @return array<string, mixed>
     */
    public function deadline(Opportunity $opportunity, bool $withPrevious = true): array
    {
        $now = now();
        $kind = $opportunity->deadline_kind;
        $local = $kind === 'instant' ? $opportunity->deadline_at->setTimezone($opportunity->deadline_timezone) : null;
        $state = match (true) {
            $kind === 'rolling' => 'rolling',
            $kind === 'unknown' => 'unknown',
            $opportunity->closes_at->lessThanOrEqualTo($now) => 'closed',
            $kind === 'date' && $opportunity->last_day_starts_at->lessThanOrEqualTo($now) => 'last_day',
            default => 'open',
        };

        return [
            'kind' => $kind, 'kindLabel' => Opportunity::DEADLINES[$kind], 'state' => $state,
            // Instant : l'heure est celle du fuseau publié par l'organisateur, jamais convertie d'office en heure d'Abidjan.
            'instantUtc' => $kind === 'instant' ? $opportunity->deadline_at->utc()->toIso8601String() : null,
            'timezone' => $opportunity->deadline_timezone, 'localInput' => $local?->format('Y-m-d\TH:i'),
            'date' => $kind === 'date' ? $opportunity->deadline_date->toDateString() : null,
            'text' => match ($kind) {
                'instant' => $local->format('d/m/Y').' à '.$local->format('H:i').' ('.$opportunity->deadline_timezone.')',
                'date' => $opportunity->deadline_date->format('d/m/Y').' (heure non communiquée)',
                'rolling' => 'Clôture continue : pas de date limite annoncée',
                default => 'Date limite non communiquée',
            },
            'rule' => $kind === 'date' ? self::DATE_RULE : null,
            'closesAt' => $opportunity->closes_at?->utc()->toIso8601String(),
            'previous' => $withPrevious ? $opportunity->previous_deadline : null,
        ];
    }

    /**
     * Résumé daté minimal d'une fiche, conservé dans les démarches privées pour rester compréhensible après l'archivage :
     * identité de la fiche, date limite telle qu'affichée, site de la source. Jamais le texte des conditions ni du résumé.
     *
     * @return array{title: string, organizer: string, campaign: string, category: string, deadline: string, sourceHost: string|null, verifiedAt: string|null, capturedAt: string}
     */
    public function reference(Opportunity $opportunity): array
    {
        return ['title' => $opportunity->title, 'organizer' => $opportunity->organizer, 'campaign' => $opportunity->campaign, 'category' => Opportunity::CATEGORIES[$opportunity->category],
            'deadline' => $this->deadline($opportunity, false)['text'], 'sourceHost' => parse_url($opportunity->source_url, PHP_URL_HOST) ?: null,
            'verifiedAt' => $opportunity->verified_at?->utc()->toIso8601String(), 'capturedAt' => now()->utc()->toIso8601String()];
    }

    /**
     * Fige le résumé de référence dans toutes les démarches d'une fiche qui quitte le catalogue (suspension, archivage).
     */
    private function freezeReferences(Opportunity $opportunity): void
    {
        DB::table('opportunity_applications')->where('opportunity_id', $opportunity->id)->update(['reference' => json_encode($this->reference($opportunity), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Représentation publique d'une fiche : uniquement le contenu éditorial publié, sans aucune donnée interne.
     *
     * @return array<string, mixed>
     */
    public function present(Opportunity $opportunity): array
    {
        return [
            'id' => $opportunity->id, 'category' => $opportunity->category, 'categoryLabel' => Opportunity::CATEGORIES[$opportunity->category], 'title' => $opportunity->title,
            'organizer' => $opportunity->organizer, 'campaign' => $opportunity->campaign, 'sourceUrl' => $opportunity->source_url, 'sourceHost' => parse_url($opportunity->source_url, PHP_URL_HOST),
            'applicationUrl' => $opportunity->application_url, 'applicationHost' => $opportunity->application_url ? parse_url($opportunity->application_url, PHP_URL_HOST) : null,
            'summary' => $opportunity->summary, 'conditions' => $opportunity->conditions, 'countries' => $opportunity->countries, 'modality' => Opportunity::MODALITIES[$opportunity->modality],
            'levels' => array_map(fn (string $level) => Opportunity::LEVELS[$level], $opportunity->levels), 'fields' => $opportunity->fields, 'languages' => $opportunity->languages,
            'funding' => Opportunity::FUNDING[$opportunity->funding], 'fees' => Opportunity::FEES[$opportunity->fees], 'feesDetail' => $opportunity->fees_detail,
            'deadline' => $this->deadline($opportunity), 'verifiedAt' => $opportunity->verified_at?->utc()->toIso8601String(),
            'publishedAt' => $opportunity->published_at?->utc()->toIso8601String(),
            'countryLabels' => array_map(fn (string $code) => Labels::country($code), $opportunity->countries ?? []),
            'languageLabels' => array_map(fn (string $code) => Labels::language($code), $opportunity->languages ?? []),
            'eligibleNationalities' => array_map(fn (string $code) => Labels::country($code), $opportunity->eligible_nationalities ?? []),
            'eligibleResidences' => array_map(fn (string $code) => Labels::country($code), $opportunity->eligible_residences ?? []),
            // « published » : fiche en ligne ; « suspended » ou « archived » : fiche qui a été publiée et ne l'est plus, identifiée comme telle.
            'availability' => $opportunity->status,
        ];
    }

    /**
     * Contenu éditorial nettoyé et normalisé à partir d'une saisie déjà validée en forme.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function content(array $data): array
    {
        $title = $this->clean($data['title'], 200, false);
        $organizer = $this->clean($data['organizer'], 200, false);
        $campaign = $this->clean($data['campaign'], 60, false);
        foreach (['title' => $title, 'organizer' => $organizer, 'campaign' => $campaign] as $field => $value) {
            if ($value === '') {
                throw ValidationException::withMessages([$field => 'Ce champ est obligatoire.']);
            }
        }
        $source = $this->url('source_url', $data['source_url']);
        $list = fn (string $key, int $max, ?callable $map = null): array => array_values(array_unique(array_filter(array_map(
            fn ($item) => $map ? $map((string) $item) : (string) $item, array_slice((array) ($data[$key] ?? []), 0, $max)), fn (string $item) => $item !== '')));
        $kind = $data['deadline_kind'];
        $deadline = ['deadline_kind' => $kind, 'deadline_at' => null, 'deadline_timezone' => null, 'deadline_date' => null, 'last_day_starts_at' => null, 'closes_at' => null];
        if ($kind === 'instant') {
            // L'heure saisie est interprétée dans le fuseau indiqué par l'organisateur, puis conservée en UTC avec ce fuseau.
            $at = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['deadline_local'], $data['deadline_timezone'])->utc();
            $deadline = [...$deadline, 'deadline_at' => $at->format('Y-m-d H:i:s'), 'deadline_timezone' => $data['deadline_timezone'], 'closes_at' => $at->format('Y-m-d H:i:s')];
        } elseif ($kind === 'date') {
            // Date seule : aucune heure n'est inventée. Le « dernier jour » commence dans le premier fuseau du monde (UTC+14) et se termine dans le dernier (UTC−12).
            $day = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $data['deadline_date'].' 00:00:00', 'UTC');
            $deadline = [...$deadline, 'deadline_date' => $day->toDateString(), 'last_day_starts_at' => $day->subHours(14)->format('Y-m-d H:i:s'), 'closes_at' => $day->addDay()->addHours(12)->format('Y-m-d H:i:s')];
        }
        $text = fn (string $key, int $max): ?string => ($value = $this->clean((string) ($data[$key] ?? ''), $max, true)) === '' ? null : $value;

        return ['category' => $data['category'], 'title' => $title, 'organizer' => $organizer, 'campaign' => $campaign, 'source_url' => $source,
            'application_url' => isset($data['application_url']) && trim((string) $data['application_url']) !== '' ? $this->url('application_url', $data['application_url']) : null,
            'summary' => $text('summary', 2000), 'conditions' => $text('conditions', 5000),
            'countries' => $list('countries', 30, fn (string $code) => strtoupper(trim($code))), 'modality' => $data['modality'], 'levels' => $list('levels', 6),
            'fields' => $fields = $list('fields', 10, fn (string $field) => $this->clean($field, 60, false)), 'languages' => $list('languages', 10, fn (string $code) => strtolower(trim($code))),
            'eligible_nationalities' => $list('eligible_nationalities', 60, fn (string $code) => strtoupper(trim($code))), 'eligible_residences' => $list('eligible_residences', 60, fn (string $code) => strtoupper(trim($code))),
            'normalized_fields' => mb_substr(implode(' | ', array_filter(array_map(fn (string $field) => $this->normalized($field), $fields))), 0, 700),
            'funding' => $data['funding'], 'fees' => $data['fees'], 'fees_detail' => $text('fees_detail', 300), ...$deadline,
            'normalized_title' => $this->normalized($title), 'normalized_organizer' => $this->normalized($organizer), 'source_hash' => $this->sourceHash($source)];
    }

    /**
     * Texte brut : balises retirées, caractères de contrôle supprimés. L'affichage échappe toujours le texte ; ce nettoyage est une seconde barrière.
     */
    private function clean(string $value, int $max, bool $multiline): string
    {
        $value = strip_tags(str_replace(["\r\n", "\r"], "\n", $value));
        $value = (string) preg_replace($multiline ? '/[^\P{C}\n]+/u' : '/\p{C}+/u', $multiline ? '' : ' ', $value);
        $value = $multiline ? (string) preg_replace("/[ \t]+/u", ' ', (string) preg_replace("/\n{3,}/", "\n\n", $value)) : (string) preg_replace('/\s+/u', ' ', $value);

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * Adresse web : HTTP ou HTTPS seulement, avec un hôte, sans identifiants. Elle est conservée telle quelle et n'est jamais appelée par le serveur.
     */
    private function url(string $field, mixed $value): string
    {
        $value = trim((string) $value);
        $parts = parse_url($value);
        if ($value === '' || mb_strlen($value) > 2000 || preg_match('/[\s\p{C}<>"]/u', $value) === 1 || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages([$field => 'Indiquez une adresse web complète commençant par http:// ou https://.']);
        }

        return $value;
    }

    private function normalized(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value)))), 0, 200);
    }

    private function sourceHash(string $url): string
    {
        $parts = parse_url($url);

        return hash('sha256', (string) preg_replace('/^www\./', '', strtolower($parts['host'])).rtrim($parts['path'] ?? '', '/').'?'.($parts['query'] ?? ''));
    }

    /**
     * Doublons : même titre normalisé, même organisateur et même campagne (toujours refusé) ; même source et même campagne (refusé sauf confirmation explicite).
     *
     * @param  array<string, mixed>  $content
     */
    private function assertNoDuplicate(array $content, ?string $exceptId, bool $confirmSameSource): void
    {
        $others = Opportunity::query()->where('campaign', $content['campaign'])->when($exceptId, fn (Builder $query) => $query->where('id', '!=', $exceptId));
        $same = (clone $others)->where('normalized_title', $content['normalized_title'])->where('normalized_organizer', $content['normalized_organizer'])->first();
        if ($same) {
            throw ValidationException::withMessages(['title' => 'Une fiche existe déjà pour ce titre, cet organisateur et cette campagne (état : '.Opportunity::STATUSES[$same->status].'). Pour une autre année, créez une nouvelle édition.']);
        }
        if (! $confirmSameSource && (clone $others)->where('source_hash', $content['source_hash'])->where('status', '!=', 'archived')->exists()) {
            throw ValidationException::withMessages(['source_url' => 'Une autre fiche de cette campagne utilise déjà cette source. Vérifiez qu’il ne s’agit pas d’un doublon, puis confirmez.']);
        }
    }

    /**
     * Conditions minimales de publication : source, organisateur, conditions publiées.
     */
    private function assertPublishable(Opportunity $opportunity): void
    {
        $errors = [];
        if (trim((string) $opportunity->source_url) === '') {
            $errors['source_url'] = 'La source officielle est obligatoire pour publier.';
        }
        if (trim((string) $opportunity->organizer) === '') {
            $errors['organizer'] = 'L’organisateur est obligatoire pour publier.';
        }
        if (mb_strlen(trim((string) $opportunity->conditions)) < self::MIN_CONDITIONS) {
            $errors['conditions'] = 'Les conditions publiées par l’organisateur sont obligatoires pour publier ('.self::MIN_CONDITIONS.' caractères au moins).';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Acte explicite de vérification : l'éditeur atteste avoir consulté lui-même la source. Daté et attribué.
     */
    private function attest(Opportunity $opportunity, User $editor, bool $attest): void
    {
        if (! $attest) {
            throw ValidationException::withMessages(['attest' => 'Confirmez que vous avez vérifié vous-même la source officielle : cet acte est daté et enregistré à votre nom.']);
        }
        $opportunity->fill(['verified_at' => now(), 'verified_by' => $editor->id]);
    }

    private function authorize(User $editor): void
    {
        abort_unless(PrivilegedAccess::available() && $this->visibility->canEditCatalogue($editor), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Opportunity $opportunity): array
    {
        $opportunity = $opportunity->fresh() ?? $opportunity;

        return [...collect($opportunity->getAttributes())->only(Opportunity::CONTENT)->except(Opportunity::LISTS)->all(),
            ...collect(Opportunity::LISTS)->mapWithKeys(fn (string $list) => [$list => $opportunity->{$list} ?? []])->all(),
            'status' => $opportunity->status, 'verified_at' => $opportunity->getAttributes()['verified_at'] ?? null, 'verified_by' => $opportunity->verified_by,
            'closes_at' => $opportunity->getAttributes()['closes_at'] ?? null];
    }

    private function revise(Opportunity $opportunity, string $action, ?User $actor, ?string $note = null): void
    {
        OpportunityRevision::create(['opportunity_id' => $opportunity->id, 'version' => $opportunity->lock_version, 'action' => $action, 'actor_id' => $actor?->id,
            'note' => $note, 'snapshot' => $this->snapshot($opportunity), 'created_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function event(Opportunity $opportunity, string $type, array $extra = []): void
    {
        DomainEvent::create(['type' => $type, 'dedup_key' => str_replace('.', '-', $type).':'.$opportunity->id.':v'.$opportunity->lock_version, 'subject_uuid' => $opportunity->id,
            'payload' => ['opportunity_id' => $opportunity->id, 'version' => $opportunity->lock_version, ...$extra], 'occurred_at' => now()]);
    }
}
