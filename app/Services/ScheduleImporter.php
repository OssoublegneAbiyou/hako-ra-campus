<?php

namespace App\Services;

use App\Exceptions\ImportRejected;
use App\Jobs\AnalyzeScheduleImport;
use App\Models\AuditLog;
use App\Models\CourseOccurrence;
use App\Models\CourseSeries;
use App\Models\CourseSeriesRevision;
use App\Models\Group;
use App\Models\ImportBatch;
use App\Models\ImportIssue;
use App\Models\Membership;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Support\PrivilegedAccess;
use App\Support\Xlsx\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Import Excel du planning institutionnel : dépôt privé, simulation sans écriture, rapport, puis application
 * atomique au brouillon. Rien n'est publié ici : la publication reste celle du lot 08.
 */
class ScheduleImporter
{
    public const SHEET = 'Planning';

    public const COLUMNS = [
        'A' => ['groupe', 'Code du groupe (obligatoire)'],
        'B' => ['matiere', 'Code de la matière (obligatoire)'],
        'C' => ['jour_ou_date', 'Jour (lundi à dimanche) ou date AAAA-MM-JJ (obligatoire)'],
        'D' => ['debut', 'Heure de début HH:MM (obligatoire)'],
        'E' => ['fin', 'Heure de fin HH:MM (obligatoire)'],
        'F' => ['enseignant', 'Adresse électronique ou identifiant HK- de l’enseignant (facultatif s’il est seul affecté)'],
        'G' => ['salle', 'Salle (facultatif)'],
        'H' => ['valide_du', 'Début de validité AAAA-MM-JJ (facultatif)'],
        'I' => ['valide_au', 'Fin de validité AAAA-MM-JJ (facultatif)'],
        'J' => ['recurrence', 'hebdomadaire ou ponctuel (facultatif)'],
        'K' => ['identifiant_externe', 'Identifiant stable de votre système, pour les mises à jour (facultatif)'],
    ];

    private const WEEKDAYS = ['lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7];

    public function __construct(
        private ContextVisibility $visibility,
        private ScheduleAccess $access,
        private ScheduleManager $manager,
        private WeeklyRecurrence $calendar,
        private XlsxReader $reader,
    ) {}

    /**
     * Dépose le fichier dans le stockage privé et met son analyse en file. Un fichier identique déjà déposé
     * dans ce périmètre, ou une demande rejouée, renvoie le lot existant sans rien créer.
     *
     * @return array{batch: ImportBatch, duplicate: bool}
     */
    public function upload(Request $r, Membership $acting): array
    {
        $maxKb = (int) config('campus.imports.max_kilobytes');
        $data = $r->validate(['file' => 'required|file|max:'.$maxKb, 'import_key' => 'required|uuid'], [
            'file.max' => 'Le fichier dépasse la taille autorisée de '.round($maxKb / 1024, 1).' Mo.',
            'file.required' => 'Choisissez un fichier .xlsx.', 'file.file' => 'Le dépôt du fichier a échoué.',
        ]);
        /** @var UploadedFile $file */
        $file = $data['file'];
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            throw ValidationException::withMessages(['file' => 'Seuls les classeurs .xlsx sont acceptés (pas .xls, .xlsm ni .csv).']);
        }
        try {
            $this->reader->inspect($file->getRealPath());
        } catch (ImportRejected $rejected) {
            throw ValidationException::withMessages(['file' => $rejected->getMessage()]);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $perimeter = ['institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id];

        return DB::transaction(function () use ($r, $acting, $file, $hash, $perimeter, $data): array {
            $existing = ImportBatch::query()->where($perimeter)->where(fn ($same) => $same->where('file_hash', $hash)->orWhere('import_key', $data['import_key']))
                ->lockForUpdate()->orderByRaw('file_hash = ? desc', [$hash])->first();
            if ($existing) {
                return ['batch' => $existing, 'duplicate' => true];
            }
            $batch = new ImportBatch([...$perimeter, 'author_id' => $r->user()->id, 'author_membership_id' => $acting->id,
                'original_name' => mb_substr(preg_replace('/[^\pL\pN ._-]+/u', '_', $file->getClientOriginalName()) ?? 'planning.xlsx', 0, 255),
                'size_bytes' => $file->getSize(), 'file_hash' => $hash, 'import_key' => $data['import_key'], 'status' => 'uploaded']);
            $batch->id = (string) Str::uuid();
            $batch->stored_path = $file->storeAs('imports/'.$acting->institution_id, $batch->id.'.xlsx', 'local');
            $batch->save();
            AuditLog::record($r->user(), 'schedule.import.uploaded', $batch, $acting->institution_id);
            AnalyzeScheduleImport::dispatch($batch->id)->afterCommit();

            return ['batch' => $batch, 'duplicate' => false];
        });
    }

    /**
     * Simulation : lit le fichier, vérifie chaque ligne contre les référentiels et le brouillon, écrit le rapport.
     * Aucune série ni occurrence n'est créée ou modifiée.
     */
    public function analyze(string $batchId): void
    {
        $batch = ImportBatch::query()->findOrFail($batchId);
        if (! in_array($batch->status, ['uploaded', 'invalid', 'failed', 'valid'], true)) {
            return;
        }
        $acting = $this->authorOf($batch);
        if ($acting === null) {
            $this->fail($batch, 'Analyse abandonnée : vos droits d’administration sur cet établissement ne sont plus actifs.');

            return;
        }
        $batch->forceFill(['status' => 'analyzing', 'failure' => null, 'processed_rows' => 0])->save();
        try {
            if ($batch->parsed_rows === null) {
                if ($batch->stored_path === null || ! Storage::disk('local')->exists($batch->stored_path)) {
                    throw new ImportRejected('Le fichier déposé n’est plus disponible : déposez-le de nouveau.');
                }
                $parsed = $this->reader->read(Storage::disk('local')->path($batch->stored_path), (int) config('campus.imports.max_rows'));
                $this->checkHeader($parsed['header']);
                $batch->forceFill(['parsed_rows' => json_encode(['sheet' => $parsed['sheet'], 'rows' => $parsed['rows']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'total_rows' => count($parsed['rows'])])->save();
            }
            $content = json_decode($batch->parsed_rows, true, 512, JSON_THROW_ON_ERROR);
            if ($content['rows'] === []) {
                throw new ImportRejected('Le fichier ne contient aucune ligne de planning sous les en-têtes.');
            }
            $result = $this->resolve($acting, $content['rows'], fn (int $done) => $done % 250 === 0 ? $batch->forceFill(['processed_rows' => $done])->save() : null);
        } catch (ImportRejected $rejected) {
            $this->report($batch, self::SHEET, [['line' => $rejected->sheetLine, 'column' => $rejected->columnName, 'severity' => 'error', 'message' => $rejected->getMessage(), 'value' => null]]);
            $batch->forceFill(['status' => 'invalid', 'counts' => ['rows' => $batch->total_rows, 'errors' => 1], 'plan_hash' => null, 'analyzed_at' => now()])->save();

            return;
        }
        $this->report($batch, $content['sheet'], $result['issues']);
        $batch->forceFill([
            'status' => $result['counts']['errors'] > 0 ? 'invalid' : 'valid', 'counts' => [...$result['counts'], 'proposals' => $result['proposals']],
            'plan_hash' => $result['planHash'], 'processed_rows' => count($content['rows']), 'analyzed_at' => now(),
        ])->save();
    }

    /**
     * Applique le lot au brouillon dans une seule transaction : toute erreur annule l'ensemble.
     * Les droits, les références et le résultat de la simulation sont revérifiés au moment de l'exécution.
     *
     * @param  array<int|string, string>  $decisions  ligne => "create" ou "update:<identifiant de série>"
     */
    public function apply(string $batchId, int $actorId, int $membershipId, array $decisions, bool $confirmConflicts, ?string $conflictReason): void
    {
        $batch = ImportBatch::query()->findOrFail($batchId);
        $actor = User::query()->findOrFail($actorId);
        $progress = fn (int $done) => Cache::store('file')->put('campus:import:'.$batchId.':applied', $done, 3600);
        $progress(0);
        try {
            DB::transaction(function () use ($batch, $actor, $membershipId, $decisions, $confirmConflicts, $conflictReason, $progress): void {
                $request = $this->request($actor, $membershipId);
                $acting = $this->access->lock($request);
                $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
                if ($acting === null || $locked->status !== 'applying' || $locked->institution_id !== $acting->institution_id || $locked->academic_year_id !== $acting->academic_year_id) {
                    throw new ImportRejected('Ce lot ne peut pas être appliqué depuis ce contexte ou dans son état actuel.');
                }
                $content = json_decode((string) $locked->parsed_rows, true, 512, JSON_THROW_ON_ERROR);
                $result = $this->resolve($acting, $content['rows']);
                if ($result['counts']['errors'] > 0 || ! hash_equals((string) $locked->plan_hash, $result['planHash'])) {
                    throw new ImportRejected('Les référentiels ou le planning ont changé depuis la simulation. Relancez l’analyse avant d’appliquer.');
                }
                if ($result['counts']['conflicts'] > 0 && (! $confirmConflicts || mb_strlen(trim((string) $conflictReason)) < 10)) {
                    throw new ImportRejected('Ce lot contient des chevauchements : confirmez-les avec un motif d’au moins 10 caractères.');
                }
                $extra = $confirmConflicts ? ['confirm_conflicts' => true, 'conflict_reason' => $conflictReason] : [];
                $applied = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
                foreach ($result['items'] as $index => $item) {
                    $action = $item['action'];
                    $target = $item['series_id'];
                    if ($action === 'match') {
                        $decision = (string) ($decisions[$item['line']] ?? '');
                        if ($decision === 'create') {
                            $action = 'create';
                        } elseif (str_starts_with($decision, 'update:') && in_array(substr($decision, 7), array_column($item['candidates'], 'id'), true)) {
                            $action = 'update';
                            $target = substr($decision, 7);
                        } else {
                            throw new ImportRejected('Choisissez, pour chaque correspondance proposée, de mettre à jour la série existante ou d’en créer une nouvelle.', $item['line']);
                        }
                    }
                    try {
                        if ($action === 'create') {
                            $request->replace([...$item['rules'], ...$extra, 'request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'campus-import:'.$locked->id.':'.$item['line'])->toString()]);
                            $series = $this->manager->create($request);
                            if ($item['external_key'] !== null) {
                                $series->forceFill(['external_key' => $item['external_key']])->save();
                            }
                            $applied['created']++;
                        } elseif ($action === 'update') {
                            $series = CourseSeries::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)->findOrFail($target);
                            $effective = max($item['rules']['valid_from'], now($item['rules']['timezone'])->toDateString());
                            $request->replace([...$item['rules'], ...$extra, 'effective_from' => $effective, 'lock_version' => $series->lock_version, 'reason' => 'Import Excel du '.now($item['rules']['timezone'])->format('d/m/Y')]);
                            $this->manager->changeFuture($request, $series->id);
                            if ($item['external_key'] !== null && $series->external_key === null) {
                                CourseSeries::query()->whereKey($series->id)->update(['external_key' => $item['external_key']]);
                            }
                            $applied['updated']++;
                        } else {
                            $applied['unchanged']++;
                        }
                    } catch (ValidationException $invalid) {
                        throw new ImportRejected(collect($invalid->errors())->flatten()->first() ?? 'Ligne refusée.', $item['line']);
                    } catch (ModelNotFoundException|HttpException|AuthorizationException) {
                        throw new ImportRejected('Une référence de cette ligne n’est plus disponible ou cette action n’est plus autorisée.', $item['line']);
                    }
                    if (($index + 1) % 25 === 0) {
                        $progress($index + 1);
                    }
                }
                $locked->forceFill(['status' => 'applied', 'applied_at' => now(), 'counts' => [...($locked->counts ?? []), 'applied' => $applied]])->save();
                AuditLog::record($actor, 'schedule.import.applied', $locked, $acting->institution_id, null, $applied);
            });
        } catch (ImportRejected $rejected) {
            $this->report($batch, self::SHEET, [['line' => $rejected->sheetLine, 'column' => null, 'severity' => 'error', 'message' => 'Application annulée en entier : '.$rejected->getMessage(), 'value' => null]], false);
            ImportBatch::query()->whereKey($batch->id)->where('status', 'applying')->update(['status' => 'invalid', 'failure' => mb_substr('Application annulée, aucune ligne appliquée. '.$rejected->getMessage(), 0, 500)]);
        } catch (HttpException|AuthorizationException) {
            $this->fail($batch, 'Application annulée, aucune ligne appliquée : vos droits d’administration ne sont plus actifs.');
        }
    }

    public function fail(ImportBatch $batch, string $message): void
    {
        ImportBatch::query()->whereKey($batch->id)->update(['status' => 'failed', 'failure' => mb_substr($message, 0, 500)]);
    }

    public function appliedProgress(ImportBatch $batch): int
    {
        return (int) Cache::store('file')->get('campus:import:'.$batch->id.':applied', 0);
    }

    /**
     * Appartenance d'administration de l'auteur, relue en base : null si elle n'est plus effective ou si le second facteur manque.
     */
    private function authorOf(ImportBatch $batch): ?Membership
    {
        try {
            $author = User::query()->findOrFail($batch->author_id);
            $acting = $this->visibility->administeredMembership($author, $batch->author_membership_id);
        } catch (Throwable) {
            return null;
        }

        return PrivilegedAccess::available($author) && $acting->institution_id === $batch->institution_id && $acting->academic_year_id === $batch->academic_year_id ? $acting : null;
    }

    /**
     * Requête de service pour les traitements en file : même contrôle d'accès que l'écran, appartenance relue en base.
     */
    private function request(User $actor, int $membershipId): Request
    {
        $request = Request::create('/planning/import', 'POST');
        $session = new Store('campus-import', new ArraySessionHandler(10));
        $session->put(ActiveContext::SESSION_KEY, $membershipId);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $actor);

        return $request;
    }

    /** @param array<string, string> $header */
    private function checkHeader(array $header): void
    {
        foreach (array_slice(self::COLUMNS, 0, 5, true) as $column => [$name]) {
            if (Str::ascii(Str::lower(trim($header[$column] ?? ''))) !== $name) {
                throw new ImportRejected('En-tête inattendu en colonne '.$column.' : « '.$name.' » est attendu. Utilisez le modèle fourni sans déplacer les colonnes.', 1, $name);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    private function report(ImportBatch $batch, string $sheet, array $issues, bool $replace = true): void
    {
        if ($replace) {
            ImportIssue::query()->where('import_batch_id', $batch->id)->delete();
        }
        $rows = array_map(fn (array $issue): array => [
            'import_batch_id' => $batch->id, 'sheet' => mb_substr($sheet, 0, 64), 'line' => $issue['line'], 'column_name' => $issue['column'],
            'severity' => $issue['severity'], 'message' => mb_substr($issue['message'], 0, 500), 'cell_value' => $issue['value'] === null ? null : mb_substr($issue['value'], 0, 120),
        ], array_slice($issues, 0, (int) config('campus.imports.max_stored_issues')));
        foreach (array_chunk($rows, 500) as $chunk) {
            ImportIssue::query()->insert($chunk);
        }
    }

    /**
     * Vérifie les lignes contre les référentiels du périmètre et le brouillon, sans rien écrire.
     *
     * @param  list<array{line: int, cells: array<string, array{value: string, formula: bool, numeric: bool}>}>  $rows
     * @return array{items: list<array<string, mixed>>, issues: list<array<string, mixed>>, counts: array<string, int>, proposals: list<array<string, mixed>>, planHash: string}
     */
    public function resolve(Membership $acting, array $rows, ?callable $progress = null): array
    {
        $timezone = $acting->institution->timezone;
        $year = $acting->academicYear;
        $today = now($timezone)->toDateString();
        $yearStart = $year->starts_on->toDateString();
        $yearEnd = $year->ends_on->toDateString();
        $perimeter = ['institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id];

        $groups = Group::query()->where($perimeter)->whereNull('archived_at')->get(['id', 'code'])->keyBy(fn (Group $g) => Str::upper($g->code));
        $subjects = Subject::query()->where('institution_id', $acting->institution_id)->whereNull('archived_at')->get(['id', 'code', 'name'])->keyBy(fn (Subject $s) => Str::upper($s->code));
        $teachers = [];
        foreach (Membership::query()->where($perimeter)->where('role', 'teacher')->effective()->with('user:id,email,login_identifier,name')->get() as $teacher) {
            foreach (array_filter([Str::lower((string) $teacher->user->email), Str::lower((string) $teacher->user->login_identifier)]) as $key) {
                $teachers[$key] = $teacher->id;
            }
        }
        $assignments = [];
        foreach (TeachingAssignment::query()->where($perimeter)->where('status', 'active')->get(['membership_id', 'group_id', 'subject_id']) as $assignment) {
            $assignments[$assignment->group_id.':'.$assignment->subject_id][$assignment->membership_id] = true;
        }

        $existing = [];
        $byExternal = [];
        $series = CourseSeries::query()->where('scope', 'institution')->where($perimeter)->get(['id', 'external_key', 'lock_version'])->keyBy('id');
        foreach ($series->keys()->chunk(500) as $ids) {
            $latest = CourseSeriesRevision::query()->whereIn('course_series_id', $ids)
                ->whereRaw('version = (select max(r.version) from course_series_revisions r where r.course_series_id = course_series_revisions.course_series_id)')->get();
            foreach ($latest as $revision) {
                $existing[$revision->course_series_id] = $revision->rules;
                if ($series[$revision->course_series_id]->external_key !== null) {
                    $byExternal[$series[$revision->course_series_id]->external_key] = $revision->course_series_id;
                }
            }
        }
        $byCore = [];
        foreach ($existing as $id => $rules) {
            $byCore[$this->core($rules)][] = $id;
        }

        $issues = $items = $proposals = [];
        $counts = ['rows' => count($rows), 'create' => 0, 'update' => 0, 'unchanged' => 0, 'match' => 0, 'errors' => 0, 'conflicts' => 0, 'warnings' => 0];
        $issue = function (int $line, ?string $column, string $severity, string $message, ?string $value = null) use (&$issues, &$counts): void {
            $issues[] = ['line' => $line, 'column' => $column, 'severity' => $severity, 'message' => $message, 'value' => $value];
            $counts[['error' => 'errors', 'conflict' => 'conflicts', 'warning' => 'warnings'][$severity]]++;
        };
        $seenExternal = $seenSlots = $buckets = [];

        foreach ($rows as $position => $row) {
            $line = (int) $row['line'];
            $cell = fn (string $column): string => (string) ($row['cells'][$column]['value'] ?? '');
            $before = $counts['errors'];
            foreach ($row['cells'] as $column => $content) {
                if ($content['formula'] && isset(self::COLUMNS[$column])) {
                    $issue($line, self::COLUMNS[$column][0], 'error', 'Les formules ne sont pas acceptées : saisissez la valeur directement.');
                }
            }
            foreach (['A', 'B', 'C', 'D', 'E'] as $column) {
                if ($cell($column) === '' && empty($row['cells'][$column]['formula'])) {
                    $issue($line, self::COLUMNS[$column][0], 'error', 'Valeur obligatoire manquante.');
                }
            }
            if ($counts['errors'] > $before) {
                continue;
            }

            $group = $groups[Str::upper($cell('A'))] ?? null;
            if (! $group) {
                $issue($line, 'groupe', 'error', 'Groupe inconnu dans cet établissement pour cette année. Créez-le dans les référentiels ou corrigez le code : rien n’est créé automatiquement.', $cell('A'));
            }
            $subject = $subjects[Str::upper($cell('B'))] ?? null;
            if (! $subject) {
                $issue($line, 'matiere', 'error', 'Matière inconnue dans cet établissement. Créez-la dans les référentiels ou corrigez le code : rien n’est créé automatiquement.', $cell('B'));
            }
            $start = $this->time($row['cells']['D']);
            $end = $this->time($row['cells']['E']);
            if ($start === null) {
                $issue($line, 'debut', 'error', 'Heure de début illisible : utilisez le format HH:MM.', $cell('D'));
            }
            if ($end === null) {
                $issue($line, 'fin', 'error', 'Heure de fin illisible : utilisez le format HH:MM.', $cell('E'));
            }
            if ($start !== null && $end !== null && $end <= $start) {
                $issue($line, 'fin', 'error', 'L’heure de fin ('.$end.') doit suivre l’heure de début ('.$start.').', $cell('E'));
            }

            $weekday = self::WEEKDAYS[Str::ascii(Str::lower($cell('C')))] ?? null;
            $date = $weekday === null ? $this->date($row['cells']['C']) : null;
            if ($weekday === null && $date === null) {
                $issue($line, 'jour_ou_date', 'error', 'Indiquez un jour (lundi à dimanche) ou une date au format AAAA-MM-JJ.', $cell('C'));
            }
            $recurrence = Str::ascii(Str::lower($cell('J')));
            if (! in_array($recurrence, ['', 'hebdomadaire', 'ponctuel'], true)) {
                $issue($line, 'recurrence', 'error', 'Valeur attendue : hebdomadaire ou ponctuel.', $cell('J'));
            } elseif ($recurrence === 'ponctuel' && $weekday !== null) {
                $issue($line, 'recurrence', 'error', 'Un cours ponctuel exige une date précise en colonne jour_ou_date.', $cell('J'));
            }
            $kind = $weekday !== null || $recurrence === 'hebdomadaire' ? 'weekly' : 'once';

            $from = $cell('H') === '' ? null : $this->date($row['cells']['H']);
            $until = $cell('I') === '' ? null : $this->date($row['cells']['I']);
            if ($cell('H') !== '' && $from === null) {
                $issue($line, 'valide_du', 'error', 'Date illisible : utilisez le format AAAA-MM-JJ.', $cell('H'));
            }
            if ($cell('I') !== '' && $until === null) {
                $issue($line, 'valide_au', 'error', 'Date illisible : utilisez le format AAAA-MM-JJ.', $cell('I'));
            }
            $explicit = $from !== null || $until !== null;
            if ($kind === 'once' && $date !== null) {
                $from = $until = $date;
                $explicit = true;
            } elseif ($date !== null) {
                $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;
                $from ??= $date;
                $explicit = true;
            }
            $from ??= max($today, $yearStart);
            $until ??= $yearEnd;
            if ($from < $yearStart || $until > $yearEnd) {
                $issue($line, $kind === 'once' ? 'jour_ou_date' : 'valide_du', 'error', 'Les dates doivent rester dans l’année académique ('.$yearStart.' au '.$yearEnd.').');
            } elseif ($until < $from) {
                $issue($line, 'valide_au', 'error', 'La fin de validité doit suivre le début.');
            }

            $external = $cell('K') === '' ? null : mb_substr($cell('K'), 0, 100);
            if ($external !== null && isset($seenExternal[$external])) {
                $issue($line, 'identifiant_externe', 'error', 'Identifiant externe déjà utilisé à la ligne '.$seenExternal[$external].' de ce fichier.', $external);
            } elseif ($external !== null) {
                $seenExternal[$external] = $line;
            }

            $teacherId = null;
            if ($group && $subject) {
                $assigned = array_keys($assignments[$group->id.':'.$subject->id] ?? []);
                if ($cell('F') !== '') {
                    $teacherId = $teachers[Str::lower($cell('F'))] ?? null;
                    if ($teacherId === null) {
                        $issue($line, 'enseignant', 'error', 'Enseignant inconnu parmi les enseignants actifs de cet établissement : aucun compte n’est créé automatiquement.', $cell('F'));
                    } elseif (! in_array($teacherId, $assigned, true)) {
                        $issue($line, 'enseignant', 'error', 'Cet enseignant n’est pas affecté à ce groupe pour cette matière. Créez l’affectation dans les référentiels.', $cell('F'));
                    }
                } elseif (count($assigned) === 1) {
                    $teacherId = $assigned[0];
                } else {
                    $issue($line, 'enseignant', 'error', $assigned === []
                        ? 'Aucun enseignant n’est affecté à ce groupe pour cette matière. Créez l’affectation dans les référentiels.'
                        : 'Plusieurs enseignants sont affectés à ce groupe pour cette matière : précisez la colonne enseignant.');
                }
            }
            if ($counts['errors'] > $before) {
                continue;
            }

            $rules = [
                'title' => $subject->name, 'kind' => $kind, 'valid_from' => $from, 'valid_until' => $until, 'start_time' => $start, 'end_time' => $end, 'timezone' => $timezone,
                'weekdays' => [$weekday ?? CarbonImmutable::parse($from)->dayOfWeekIso], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [],
                'group_ids' => [$group->id], 'subject_id' => $subject->id, 'personal_subject_id' => null, 'teacher_membership_id' => $teacherId,
                'room' => $cell('G') === '' ? null : mb_strtoupper(mb_substr($cell('G'), 0, 100)), 'meeting_url' => null,
            ];
            $slot = $this->slot($rules);
            if (isset($seenSlots[$slot])) {
                $issue($line, null, 'error', 'Ligne en double : même cours que la ligne '.$seenSlots[$slot].' de ce fichier.');

                continue;
            }
            $seenSlots[$slot] = $line;

            $target = $external !== null ? ($byExternal[$external] ?? null) : null;
            $candidates = [];
            if ($target !== null) {
                if (! $explicit) {
                    $rules['valid_from'] = $existing[$target]['valid_from'];
                    $rules['valid_until'] = $existing[$target]['valid_until'];
                }
                $action = $this->slot($rules) === $this->slot($existing[$target]) && $rules['valid_from'] === $existing[$target]['valid_from'] && $rules['valid_until'] === $existing[$target]['valid_until'] ? 'unchanged' : 'update';
                if ($action === 'update' && $rules['valid_until'] < $today) {
                    $issue($line, 'valide_au', 'error', 'Cette série est entièrement passée : elle ne peut plus être modifiée par import.');

                    continue;
                }
            } else {
                $same = array_values(array_filter($byCore[$this->core($rules)] ?? [], fn (string $id) => $this->slot($existing[$id]) === $slot
                    && (! $explicit || ($existing[$id]['valid_from'] === $rules['valid_from'] && $existing[$id]['valid_until'] === $rules['valid_until']))));
                if ($same !== []) {
                    $action = 'unchanged';
                    $target = $same[0];
                    $issue($line, null, 'warning', 'Ce cours existe déjà à l’identique dans le brouillon : la ligne sera ignorée.');
                } else {
                    $candidates = array_slice(array_values(array_filter($byCore[$this->core($rules)] ?? [], fn (string $id) => $existing[$id]['valid_until'] >= $today)), 0, 3);
                    $action = $candidates === [] ? 'create' : 'match';
                }
            }
            $counts[$action]++;
            $label = $group->code.' · '.$subject->code.' · '.($kind === 'once' ? $from : array_search($rules['weekdays'][0], self::WEEKDAYS, true)).' '.$start.'–'.$end;
            if ($action === 'match') {
                $proposals[] = ['line' => $line, 'label' => $label, 'candidates' => array_map(fn (string $id): array => [
                    'id' => $id, 'label' => $existing[$id]['start_time'].'–'.$existing[$id]['end_time'].($existing[$id]['room'] ? ' · salle '.$existing[$id]['room'] : '').' · du '.$existing[$id]['valid_from'].' au '.$existing[$id]['valid_until'],
                ], $candidates)];
            }
            $items[] = ['line' => $line, 'action' => $action, 'series_id' => $target, 'candidates' => array_map(fn (string $id): array => ['id' => $id], $candidates),
                'rules' => $rules, 'external_key' => $external, 'label' => $label];
            if ($action !== 'unchanged') {
                $buckets[$rules['weekdays'][0]][] = count($items) - 1;
            }
            if ($progress) {
                $progress($position + 1);
            }
        }

        $this->conflictsInFile($items, $buckets, $issue);
        $this->conflictsWithDraft($acting, $items, $today, $issue);

        return ['items' => $items, 'issues' => $issues, 'counts' => $counts, 'proposals' => array_slice($proposals, 0, 200),
            'planHash' => hash('sha256', json_encode(array_map(fn (array $item): array => [$item['line'], $item['action'], $item['series_id'], $item['rules'], $item['external_key']], $items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }

    /**
     * Chevauchements entre lignes du fichier partageant un groupe, l'enseignant ou la salle.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, list<int>>  $buckets
     */
    private function conflictsInFile(array $items, array $buckets, callable $issue): void
    {
        foreach ($buckets as $indexes) {
            foreach ($indexes as $offset => $index) {
                $a = $items[$index]['rules'];
                for ($other = 0; $other < $offset; $other++) {
                    $b = $items[$indexes[$other]]['rules'];
                    if ($a['start_time'] < $b['end_time'] && $a['end_time'] > $b['start_time'] && $a['valid_from'] <= $b['valid_until'] && $a['valid_until'] >= $b['valid_from'] && $this->shares($a, $b)) {
                        $issue($items[$index]['line'], null, 'conflict', 'Chevauchement avec la ligne '.$items[$indexes[$other]]['line'].' de ce fichier (même groupe, enseignant ou salle).');

                        break;
                    }
                }
            }
        }
    }

    /**
     * Chevauchements avec les cours déjà présents dans le brouillon, sur la première fenêtre que l'application générera.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function conflictsWithDraft(Membership $acting, array $items, string $today, callable $issue): void
    {
        $pending = array_values(array_filter($items, fn (array $item) => $item['action'] !== 'unchanged'));
        if ($pending === []) {
            return;
        }
        $from = min(array_map(fn (array $item) => max($item['rules']['valid_from'], $item['action'] === 'create' || $item['action'] === 'match' ? $item['rules']['valid_from'] : $today), $pending));
        $until = CarbonImmutable::parse(max(array_map(fn (array $item) => $item['rules']['valid_from'], $pending)))->addDays(57)->toDateString();
        $timezone = $acting->institution->timezone;
        $draft = [];
        $query = CourseOccurrence::query()->where('scope', 'institution')->where('institution_id', $acting->institution_id)->where('status', 'active')
            ->where('starts_at', '>=', CarbonImmutable::parse($from, $timezone)->utc())->where('starts_at', '<', CarbonImmutable::parse($until, $timezone)->utc())->with('groups:id');
        foreach ($query->limit(30000)->cursor() as $occurrence) {
            $draft[$occurrence->starts_at->setTimezone($timezone)->toDateString()][] = [
                'series' => $occurrence->course_series_id, 'start' => $occurrence->starts_at->getTimestamp(), 'end' => $occurrence->ends_at->getTimestamp(),
                'teacher_membership_id' => $occurrence->teacher_membership_id, 'room' => $occurrence->room, 'subject_id' => $occurrence->subject_id,
                'group_ids' => $occurrence->groups->pluck('id')->all(),
            ];
        }
        foreach ($pending as $item) {
            $rules = $item['rules'];
            $first = max($rules['valid_from'], $item['action'] === 'update' ? $today : $rules['valid_from']);
            $last = min($rules['valid_until'], CarbonImmutable::parse($first)->addDays(55)->toDateString());
            for ($day = CarbonImmutable::parse($first, 'UTC'); $day->toDateString() <= $last; $day = $day->addDay()) {
                $date = $day->toDateString();
                if (! $this->calendar->active($rules, $date) || ! isset($draft[$date])) {
                    continue;
                }
                try {
                    $start = $this->calendar->instant($date, $rules['start_time'], $timezone)->getTimestamp();
                    $end = $this->calendar->instant($date, $rules['end_time'], $timezone)->getTimestamp();
                } catch (ValidationException) {
                    $issue($item['line'], 'debut', 'error', 'Heure locale inexistante ou ambiguë le '.$date.' (changement d’heure) : choisissez un autre horaire.');

                    continue 2;
                }
                foreach ($draft[$date] as $occupied) {
                    if ($occupied['series'] === $item['series_id'] || $start >= $occupied['end'] || $end <= $occupied['start'] || ! $this->shares($rules, $occupied)) {
                        continue;
                    }
                    $exact = $start === $occupied['start'] && $end === $occupied['end'] && $rules['teacher_membership_id'] === $occupied['teacher_membership_id']
                        && $rules['room'] === $occupied['room'] && $rules['subject_id'] === $occupied['subject_id'] && $rules['group_ids'] == $occupied['group_ids'];
                    $issue($item['line'], null, $exact ? 'error' : 'conflict', $exact
                        ? 'Doublon exact d’un cours déjà présent dans le brouillon le '.$date.'.'
                        : 'Chevauchement le '.$date.' avec un cours du brouillon (même groupe, enseignant ou salle).');

                    continue 3;
                }
            }
        }
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function shares(array $a, array $b): bool
    {
        return $a['teacher_membership_id'] === $b['teacher_membership_id'] || ($a['room'] !== null && $a['room'] === $b['room'])
            || array_intersect($a['group_ids'], $b['group_ids']) !== [];
    }

    /** Identité d'une série pour proposer une correspondance : même groupe, matière, jour ou date. */
    private function core(array $rules): string
    {
        return implode('|', [$rules['kind'], $rules['kind'] === 'once' ? $rules['valid_from'] : implode(',', $rules['weekdays']), implode(',', $rules['group_ids']), $rules['subject_id']]);
    }

    /** Créneau complet, hors bornes de validité. */
    private function slot(array $rules): string
    {
        return implode('|', [$this->core($rules), $rules['start_time'], $rules['end_time'], $rules['teacher_membership_id'], (string) $rules['room']]);
    }

    /** @param array{value: string, formula: bool, numeric: bool} $cell */
    private function time(array $cell): ?string
    {
        if ($cell['numeric']) {
            $fraction = (float) $cell['value'];
            if ($fraction < 0 || $fraction >= 1) {
                return null;
            }
            $minutes = (int) round($fraction * 1440);

            return $minutes >= 1440 ? null : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }
        if (preg_match('/^(\d{1,2})\s?[:hH]\s?(\d{2})(?::\d{2})?$/', $cell['value'], $match) !== 1 || (int) $match[1] > 23 || (int) $match[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $match[1], $match[2]);
    }

    /** @param array{value: string, formula: bool, numeric: bool} $cell */
    private function date(array $cell): ?string
    {
        if ($cell['numeric']) {
            $serial = (int) floor((float) $cell['value']);

            return $serial < 20000 || $serial > 80000 ? null : CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->addDays($serial)->toDateString();
        }
        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            $parsed = CarbonImmutable::hasFormat($cell['value'], ltrim($format, '!')) ? CarbonImmutable::createFromFormat($format, $cell['value'], 'UTC') : null;
            if ($parsed && $parsed->format(ltrim($format, '!')) === $cell['value']) {
                return $parsed->toDateString();
            }
        }

        return null;
    }
}
