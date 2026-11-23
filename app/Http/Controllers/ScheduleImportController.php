<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeScheduleImport;
use App\Jobs\ApplyScheduleImport;
use App\Models\ImportBatch;
use App\Models\ImportIssue;
use App\Models\Membership;
use App\Services\ScheduleAccess;
use App\Services\ScheduleImporter;
use App\Support\Xlsx\XlsxWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScheduleImportController extends Controller
{
    public function index(Request $r, ScheduleAccess $access, ScheduleImporter $importer): Response
    {
        $filters = $r->validate(['batch' => 'sometimes|uuid', 'page' => 'sometimes|integer|min:1', 'issues_page' => 'sometimes|integer|min:1']);
        $acting = $this->acting($r, $access);
        $batches = $this->batches($acting)->with('author:id,name')->latest('created_at')->paginate(10)->withQueryString();
        $selected = isset($filters['batch']) ? $this->batches($acting)->findOrFail($filters['batch']) : null;
        $issues = $selected?->issues()->orderByRaw("field(severity, 'error', 'conflict', 'warning')")->orderBy('line')->orderBy('id')
            ->paginate(50, ['*'], 'issues_page')->withQueryString();

        return Inertia::render('ScheduleImport', [
            'institution' => $acting->institution->name,
            'year' => $acting->academicYear->name,
            'timezone' => $acting->institution->timezone,
            'limits' => ['megabytes' => round(config('campus.imports.max_kilobytes') / 1024, 1), 'rows' => (int) config('campus.imports.max_rows'), 'retentionDays' => (int) config('campus.imports.raw_retention_days')],
            'columns' => collect(ScheduleImporter::COLUMNS)->map(fn (array $column, string $letter): array => ['letter' => $letter, 'name' => $column[0], 'help' => $column[1]])->values()->all(),
            'batches' => $batches->getCollection()->map(fn (ImportBatch $batch): array => $this->summary($batch))->all(),
            'pagination' => ['page' => $batches->currentPage(), 'total' => $batches->lastPage(), 'previous' => $batches->previousPageUrl(), 'next' => $batches->nextPageUrl()],
            'selected' => $selected ? [
                ...$this->summary($selected),
                'appliedProgress' => $selected->status === 'applying' ? $importer->appliedProgress($selected) : null,
                'proposals' => $selected->counts['proposals'] ?? [],
                'issues' => $issues->getCollection()->map(fn (ImportIssue $issue): array => [
                    'id' => $issue->id, 'sheet' => $issue->sheet, 'line' => $issue->line, 'column' => $issue->column_name, 'severity' => $issue->severity,
                    'severityLabel' => ImportIssue::SEVERITY_LABELS[$issue->severity], 'message' => $issue->message, 'value' => $issue->cell_value,
                ])->all(),
                'issuesPagination' => ['page' => $issues->currentPage(), 'total' => $issues->lastPage(), 'previous' => $issues->previousPageUrl(), 'next' => $issues->nextPageUrl()],
            ] : null,
        ]);
    }

    /**
     * Modèle XLSX documenté, généré à la demande : texte uniquement, sans macro ni formule.
     */
    public function template(Request $r, ScheduleAccess $access): HttpResponse
    {
        $acting = $this->acting($r, $access);
        $notice = [
            ['Modèle d’import du planning — HAKO-RA Campus'],
            ['Saisissez vos cours dans la feuille « '.ScheduleImporter::SHEET.' », une ligne par cours, sans modifier la ligne d’en-têtes ni l’ordre des colonnes.'],
            ['Fuseau horaire des heures saisies : '.$acting->institution->timezone.' (celui de l’établissement).'],
            ['Formats : dates AAAA-MM-JJ, heures HH:MM sur 24 heures. Les cellules de date et d’heure d’Excel sont aussi acceptées.'],
            ['Limites : '.config('campus.imports.max_rows').' lignes et '.round(config('campus.imports.max_kilobytes') / 1024, 1).' Mo par fichier. Les formules et les macros sont refusées.'],
            ['Les codes de groupe et de matière doivent déjà exister dans les référentiels : rien n’est créé automatiquement.'],
            ['Sans date de validité, un cours hebdomadaire court d’aujourd’hui à la fin de l’année académique.'],
            [''],
            ['Colonne', 'En-tête', 'Contenu'],
            ...collect(ScheduleImporter::COLUMNS)->map(fn (array $column, string $letter): array => [$letter, $column[0], $column[1]])->values()->all(),
            [''],
            ['Exemples fictifs (à ne pas copier tels quels) :'],
            array_column(ScheduleImporter::COLUMNS, 0),
            ['LYC2A', 'MATH', 'lundi', '08:00', '10:00', '', 'S12', '', '', 'hebdomadaire', 'EDT-0001'],
            ['LYC2A', 'FRAN', '2026-12-15', '10:15', '12:15', '', 'S04', '', '', 'ponctuel', 'EDT-0002'],
        ];
        $binary = XlsxWriter::build([ScheduleImporter::SHEET => [array_column(ScheduleImporter::COLUMNS, 0)], 'Notice' => $notice]);

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="modele-import-planning.xlsx"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
        ]);
    }

    public function store(Request $r, ScheduleAccess $access, ScheduleImporter $importer): RedirectResponse
    {
        $result = $importer->upload($r, $this->acting($r, $access));

        return redirect()->route('planning.import', ['batch' => $result['batch']->id])->with('status', $result['duplicate']
            ? 'Ce fichier a déjà été déposé pour cet établissement : voici le lot existant, rien n’a été créé en double.'
            : 'Fichier déposé. L’analyse ne modifie pas le planning ; son rapport s’affiche ci-dessous.');
    }

    /**
     * Relance la simulation d'un lot non appliqué, par exemple après correction des référentiels.
     */
    public function analyze(Request $r, ScheduleAccess $access, string $batch): RedirectResponse
    {
        $acting = $this->acting($r, $access);
        $target = $this->batches($acting)->findOrFail($batch);
        if (! in_array($target->status, ['invalid', 'failed', 'valid'], true)) {
            throw ValidationException::withMessages(['batch' => 'Ce lot ne peut pas être analysé de nouveau dans son état actuel.']);
        }
        AnalyzeScheduleImport::dispatch($target->id);

        return redirect()->route('planning.import', ['batch' => $target->id])->with('status', 'Analyse relancée. Le planning n’est pas modifié.');
    }

    /**
     * Met en file l'application d'un lot valide. Le passage à l'état « en cours » sous verrou empêche une double application.
     */
    public function apply(Request $r, ScheduleAccess $access, string $batch): RedirectResponse
    {
        $acting = $this->acting($r, $access);
        $data = $r->validate([
            'decisions' => 'sometimes|array|max:200', 'decisions.*' => ['string', 'regex:/^(create|update:[0-9a-f-]{36})$/'],
            'confirm_conflicts' => 'sometimes|boolean', 'conflict_reason' => 'nullable|string|max:1000',
        ]);
        $target = DB::transaction(function () use ($acting, $batch, $data): ImportBatch {
            $target = $this->batches($acting)->lockForUpdate()->findOrFail($batch);
            if ($target->status !== 'valid') {
                throw ValidationException::withMessages(['batch' => 'Seul un lot valide et non encore appliqué peut être appliqué au brouillon.']);
            }
            $counts = $target->counts ?? [];
            if (($counts['conflicts'] ?? 0) > 0 && (! ($data['confirm_conflicts'] ?? false) || mb_strlen(trim((string) ($data['conflict_reason'] ?? ''))) < 10)) {
                throw ValidationException::withMessages(['conflict_reason' => 'Ce lot contient des chevauchements : confirmez-les avec un motif d’au moins 10 caractères.']);
            }
            foreach ($counts['proposals'] ?? [] as $proposal) {
                if (! isset($data['decisions'][$proposal['line']])) {
                    throw ValidationException::withMessages(['decisions' => 'Choisissez une décision pour chaque correspondance proposée (ligne '.$proposal['line'].').']);
                }
            }
            $target->forceFill(['status' => 'applying', 'failure' => null])->save();

            return $target;
        });
        ApplyScheduleImport::dispatch($target->id, $r->user()->id, $acting->id, $data['decisions'] ?? [], (bool) ($data['confirm_conflicts'] ?? false), $data['conflict_reason'] ?? null);

        return redirect()->route('planning.import', ['batch' => $target->id])->with('status', 'Application au brouillon lancée. Le planning publié ne change pas tant que vous ne publiez pas.');
    }

    /**
     * Rapport complet du lot au format CSV ; toute cellule qu'un tableur lirait comme une formule est neutralisée.
     */
    public function report(Request $r, ScheduleAccess $access, string $batch): StreamedResponse
    {
        $target = $this->batches($this->acting($r, $access))->findOrFail($batch);
        $safe = fn (?string $value): string => $value !== null && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : (string) $value;

        return response()->streamDownload(function () use ($target, $safe): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Feuille', 'Ligne', 'Colonne', 'Gravité', 'Message', 'Valeur lue'], ';', '"', '');
            $target->issues()->orderBy('line')->orderBy('id')->chunk(500, function ($issues) use ($output, $safe): void {
                foreach ($issues as $issue) {
                    fputcsv($output, array_map($safe, [$issue->sheet, (string) $issue->line, $issue->column_name, ImportIssue::SEVERITY_LABELS[$issue->severity], $issue->message, $issue->cell_value]), ';', '"', '');
                }
            });
            fclose($output);
        }, 'rapport-import-planning.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }

    /** L'import est réservé à l'administration de l'année active, avec le même garde que les autres écritures institutionnelles. */
    private function acting(Request $r, ScheduleAccess $access): Membership
    {
        $acting = $access->acting($r, true);
        abort_unless($acting !== null, 403);

        return $acting;
    }

    private function batches(Membership $acting)
    {
        return ImportBatch::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id);
    }

    /** @return array<string, mixed> */
    private function summary(ImportBatch $batch): array
    {
        $counts = $batch->counts ?? [];
        unset($counts['proposals']);

        return [
            'id' => $batch->id, 'name' => $batch->original_name, 'status' => $batch->status, 'statusLabel' => ImportBatch::STATUS_LABELS[$batch->status],
            'author' => $batch->author?->name, 'createdAt' => $batch->created_at->utc()->toIso8601String(), 'totalRows' => $batch->total_rows,
            'processedRows' => $batch->processed_rows, 'counts' => $counts, 'failure' => $batch->failure, 'rawPurged' => $batch->raw_purged_at !== null,
        ];
    }
}
