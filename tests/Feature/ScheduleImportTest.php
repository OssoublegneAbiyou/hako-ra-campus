<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeScheduleImport;
use App\Models\AuditLog;
use App\Models\CourseOccurrence;
use App\Models\CourseSeries;
use App\Models\CourseSeriesRevision;
use App\Models\Group;
use App\Models\ImportBatch;
use App\Models\ImportIssue;
use App\Models\Membership;
use App\Models\PublishedOccurrence;
use App\Models\SchedulePublication;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\ScheduleImporter;
use App\Support\Xlsx\XlsxReader;
use App\Support\Xlsx\XlsxWriter;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class ScheduleImportTest extends TestCase
{
    use DatabaseTransactions;

    private const HEADER = ['groupe', 'matiere', 'jour_ou_date', 'debut', 'fin', 'enseignant', 'salle', 'valide_du', 'valide_au', 'recurrence', 'identifiant_externe'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(8, 0));
        Storage::fake('local');
        $this->seed(CampusDemoSeeder::class);
        User::query()->update(['email_verified_at' => now()]);
        config(['campus.identity.privileged_without_mfa' => true]);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@example.test')->firstOrFail();
    }

    private function member(string $name, string $letter = 'A'): Membership
    {
        return $this->user($name)->memberships()->whereHas('institution', fn ($query) => $query->where('code', 'CAMPUS-FICTIF-'.$letter))->firstOrFail();
    }

    private function as(string $name, ?string $letter = 'A'): static
    {
        return $this->freshDevice()->actingAs($this->user($name))
            ->withSession($letter ? [ActiveContext::SESSION_KEY => $this->member($name, $letter)->id] : []);
    }

    /** @param list<list<mixed>> $rows */
    private function workbook(array $rows, array $extraSheets = [], string $name = 'planning.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'campus-test-');
        file_put_contents($path, XlsxWriter::build([ScheduleImporter::SHEET => [self::HEADER, ...$rows], ...$extraSheets]));

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function upload(UploadedFile $file, array $overrides = [], string $admin = 'admin-a', string $letter = 'A'): TestResponse
    {
        return $this->as($admin, $letter)->post('/planning/import', ['file' => $file, 'import_key' => (string) Str::uuid(), ...$overrides]);
    }

    /** Dépose un fichier ; avec la file synchrone des tests, l'analyse est déjà terminée au retour. */
    private function import(array $rows): ImportBatch
    {
        return $this->batchOf($this->upload($this->workbook($rows))->assertSessionHasNoErrors()->assertRedirect());
    }

    /** Lot désigné par la redirection qui suit un dépôt. */
    private function batchOf(TestResponse $response): ImportBatch
    {
        return ImportBatch::findOrFail(Str::after((string) $response->headers->get('Location'), 'batch='));
    }

    private function apply(ImportBatch $batch, array $data = []): TestResponse
    {
        return $this->as('admin-a')->post('/planning/import/'.$batch->id.'/application', $data);
    }

    /** @return list<array{line: int|null, column: string|null, severity: string}> */
    private function issues(ImportBatch $batch): array
    {
        return $batch->issues()->orderBy('line')->orderBy('id')->get()->map(fn (ImportIssue $issue): array => ['line' => $issue->line, 'column' => $issue->column_name, 'severity' => $issue->severity])->all();
    }

    public function test_the_documented_template_is_a_macro_free_workbook_that_the_importer_can_read(): void
    {
        $response = $this->as('admin-a')->get('/planning/import/modele')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="modele-import-planning.xlsx"')->assertHeader('X-Content-Type-Options', 'nosniff');
        $path = tempnam(sys_get_temp_dir(), 'campus-modele-');
        file_put_contents($path, $response->getContent());
        $this->assertSame("PK\x03\x04", substr($response->getContent(), 0, 4));
        $parsed = app(XlsxReader::class)->read($path, 10);
        $this->assertSame(['sheet' => 'Planning', 'header' => array_combine(range('A', 'K'), self::HEADER), 'rows' => []], $parsed);
        $zip = new ZipArchive;
        $zip->open($path);
        $names = array_map(fn (int $index) => $zip->getNameIndex($index), range(0, $zip->numFiles - 1));
        $this->assertSame([], array_values(array_filter($names, fn (string $name) => preg_match('/vba|\.bin$|macro/i', $name) === 1)));
        $this->assertStringNotContainsString('<f>', $zip->getFromName('xl/worksheets/sheet1.xml').$zip->getFromName('xl/worksheets/sheet2.xml'));
        $this->assertStringContainsString('Africa/Abidjan', $zip->getFromName('xl/worksheets/sheet2.xml'));
        $this->assertStringContainsString('AAAA-MM-JJ', $zip->getFromName('xl/worksheets/sheet2.xml'));
    }

    public function test_r12_an_end_before_the_start_is_located_and_nothing_is_applied_or_published(): void
    {
        $batch = $this->import([
            ['A-G1', 'METHODO', 'lundi', '09:00', '10:00'],
            ['A-G1', 'METHODO', 'mardi', '11:00', '10:00'],
        ]);
        $this->assertSame(['invalid', 2], [$batch->status, $batch->total_rows]);
        $this->assertSame([['line' => 3, 'column' => 'fin', 'severity' => 'error']], $this->issues($batch));
        $this->assertSame('Planning', $batch->issues()->sole()->sheet);
        $this->assertSame([1, 1], [$batch->counts['errors'], $batch->counts['create']]);
        $this->as('admin-a')->get('/planning/import?batch='.$batch->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ScheduleImport')->where('selected.status', 'invalid')->where('selected.issues.0.line', 3)->where('selected.issues.0.column', 'fin'));

        $this->apply($batch)->assertSessionHasErrors('batch');
        $this->assertSame(0, CourseSeries::count());
        $this->assertSame(0, CourseOccurrence::count());
        $this->assertSame(0, SchedulePublication::count());
    }

    public function test_r13_an_unknown_reference_blocks_the_whole_mixed_batch_and_nothing_is_created_silently(): void
    {
        $references = [Group::count(), Subject::count(), User::count(), Membership::count(), TeachingAssignment::count()];
        $batch = $this->import([
            ['A-G1', 'METHODO', 'lundi', '09:00', '10:00'],
            ['GROUPE-INCONNU', 'METHODO', 'mardi', '09:00', '10:00'],
            ['A-G1', 'MATIERE-INCONNUE', 'mercredi', '09:00', '10:00'],
            ['A-G1', 'METHODO', 'jeudi', '09:00', '10:00', 'inconnu-fictif@example.test'],
            ['B-G1', 'METHODO', 'vendredi', '09:00', '10:00'],
            ['A-G2', 'METHODO', 'samedi', '09:00', '10:00'],
            ['A-G1', 'METHODO', 'pas-un-jour', '9h', ''],
            ['A-G1', 'METHODO', 'lundi', '14:00', '15:00', 'admin-b@example.test'],
        ]);
        $this->assertSame('invalid', $batch->status);
        $this->assertSame([
            ['line' => 3, 'column' => 'groupe', 'severity' => 'error'], ['line' => 4, 'column' => 'matiere', 'severity' => 'error'],
            ['line' => 5, 'column' => 'enseignant', 'severity' => 'error'], ['line' => 6, 'column' => 'groupe', 'severity' => 'error'],
            ['line' => 7, 'column' => 'enseignant', 'severity' => 'error'], ['line' => 8, 'column' => 'fin', 'severity' => 'error'],
            ['line' => 9, 'column' => 'enseignant', 'severity' => 'error'],
        ], $this->issues($batch));
        $this->assertSame('GROUPE-INCONNU', $batch->issues()->where('line', 3)->sole()->cell_value);
        $this->apply($batch)->assertSessionHasErrors('batch');
        $this->assertSame(0, CourseSeries::count());
        $this->assertSame($references, [Group::count(), Subject::count(), User::count(), Membership::count(), TeachingAssignment::count()]);
    }

    public function test_a_valid_batch_is_simulated_without_touching_the_schedule_then_applied_atomically_to_the_draft_only(): void
    {
        $batch = $this->import([
            ['a-g1', 'methodo', 'Lundi', '09:00', '10:00', '', 's12'],
            ['A-G1', 'METHODO', 'mardi', ['number' => 0.4375], ['number' => 0.5], 'enseignant-a@example.test'],
            ['A-G1', 'METHODO', '2026-12-16', '14:00', '15:30', '', '', '', '', 'ponctuel', 'EXT-1'],
            ['A-G1', 'METHODO', ['number' => 46378], '08:00', '09:00'],
        ]);
        $this->assertSame('valid', $batch->status);
        $this->assertSame([4, 0, 0], [$batch->counts['create'], $batch->counts['errors'], $batch->counts['conflicts']]);
        $this->assertSame([0, 0], [CourseSeries::count(), CourseOccurrence::count()]);
        $this->assertTrue(Storage::disk('local')->exists($batch->stored_path));
        $this->assertStringStartsWith('imports/', $batch->stored_path);
        $this->assertFileDoesNotExist(public_path('storage/'.$batch->stored_path));

        $this->apply($batch)->assertSessionHasNoErrors()->assertRedirect();
        $batch->refresh();
        $this->assertEquals(['applied', ['created' => 4, 'updated' => 0, 'unchanged' => 0]], [$batch->status, $batch->counts['applied']]);
        $this->assertSame(4, CourseSeries::count());
        $rules = fn (string $start) => CourseSeriesRevision::get()->pluck('rules')->firstWhere('start_time', $start);
        $this->assertSame(['weekly', [1], 'S12', '10:00', '2026-11-16', '2027-08-31'], [$rules('09:00')['kind'], $rules('09:00')['weekdays'], $rules('09:00')['room'], $rules('09:00')['end_time'], $rules('09:00')['valid_from'], $rules('09:00')['valid_until']]);
        $this->assertSame(['10:30', '12:00', [2]], [$rules('10:30')['start_time'], $rules('10:30')['end_time'], $rules('10:30')['weekdays']]);
        $this->assertSame(['once', '2026-12-16', '2026-12-16'], [$rules('14:00')['kind'], $rules('14:00')['valid_from'], $rules('14:00')['valid_until']]);
        $this->assertSame(['once', '2026-12-22'], [$rules('08:00')['kind'], $rules('08:00')['valid_from']]);
        $this->assertSame('EXT-1', CourseSeries::whereNotNull('external_key')->sole()->external_key);
        $this->assertSame(8 + 8 + 1 + 1, CourseOccurrence::count());
        $this->assertTrue(CourseOccurrence::get()->every(fn (CourseOccurrence $o) => $o->teacher_membership_id === $this->member('enseignant-a')->id && $o->scope === 'institution'));
        $this->assertSame(0, PublishedOccurrence::count());
        $this->as('apprenant-double')->get('/planning?view=day&date=2026-11-23')->assertInertia(fn (Assert $page) => $page->has('rows', 0));
        $this->assertSame(1, AuditLog::where('action', 'schedule.import.applied')->count());

        $this->apply($batch)->assertSessionHasErrors('batch');
        $this->assertSame(4, CourseSeries::count());
    }

    public function test_r14_the_same_file_or_the_same_rows_submitted_twice_never_duplicate_courses(): void
    {
        $rows = [['A-G1', 'METHODO', 'lundi', '09:00', '10:00'], ['A-G1', 'METHODO', 'mardi', '09:00', '10:00']];
        $file = $this->workbook($rows);
        $copy = tempnam(sys_get_temp_dir(), 'campus-copie-');
        copy($file->getRealPath(), $copy);
        $key = (string) Str::uuid();
        $this->upload($file, ['import_key' => $key])->assertSessionHasNoErrors();
        $batch = ImportBatch::sole();
        $this->upload(new UploadedFile($copy, 'autre-nom.xlsx', null, null, true))->assertRedirect('/planning/import?batch='.$batch->id)->assertSessionHas('status', fn (string $status) => str_contains($status, 'déjà été déposé'));
        $this->upload($this->workbook([['A-G1', 'METHODO', 'jeudi', '09:00', '10:00']]), ['import_key' => $key])->assertRedirect('/planning/import?batch='.$batch->id);
        $this->assertSame(1, ImportBatch::count());
        $this->assertSame(1, count(Storage::disk('local')->allFiles('imports')));

        $this->apply($batch)->assertSessionHasNoErrors();
        $this->apply($batch)->assertSessionHasErrors('batch');
        $this->assertSame([2, 16], [CourseSeries::count(), CourseOccurrence::count()]);

        $again = $this->import([...$rows, ['A-G1', 'METHODO', 'mercredi', '09:00', '10:00']]);
        $this->assertSame(['valid', 2, 1], [$again->status, $again->counts['unchanged'], $again->counts['create']]);
        $this->assertSame(2, $again->issues()->where('severity', 'warning')->count());
        $this->apply($again)->assertSessionHasNoErrors();
        $this->assertSame([3, 24], [CourseSeries::count(), CourseOccurrence::count()]);

        $duplicate = $this->import([['A-G1', 'METHODO', 'vendredi', '09:00', '10:00'], ['A-G1', 'METHODO', 'Vendredi', '09:00', '10:00']]);
        $this->assertSame('invalid', $duplicate->status);
        $this->assertSame([['line' => 3, 'column' => null, 'severity' => 'error']], $this->issues($duplicate));
    }

    public function test_r15_overlaps_are_reported_as_conflicts_and_need_a_motivated_confirmation(): void
    {
        $this->apply($this->import([['A-G1', 'METHODO', 'lundi', '09:00', '10:00', '', 'S12']]))->assertSessionHasNoErrors();
        $batch = $this->import([
            ['A-G1', 'METHODO', 'lundi', '09:30', '10:30', '', 'S20'],
            ['A-G1', 'METHODO', 'jeudi', '09:00', '10:00'],
            ['A-G1', 'METHODO', 'jeudi', '09:30', '11:00'],
        ]);
        $this->assertSame(['valid', 2, 0], [$batch->status, $batch->counts['conflicts'], $batch->counts['errors']]);
        $this->assertSame([['line' => 2, 'column' => null, 'severity' => 'conflict'], ['line' => 4, 'column' => null, 'severity' => 'conflict']], $this->issues($batch));

        $this->apply($batch)->assertSessionHasErrors('conflict_reason');
        $this->apply($batch, ['confirm_conflicts' => true, 'conflict_reason' => 'court'])->assertSessionHasErrors('conflict_reason');
        $this->assertSame(['valid', 1], [$batch->fresh()->status, CourseSeries::count()]);
        // La ligne 2 ressemble au cours du lundi déjà présent : sans identifiant externe, la décision est exigée aussi.
        $this->apply($batch, ['confirm_conflicts' => true, 'conflict_reason' => 'Dédoublement fictif du groupe'])->assertSessionHasErrors('decisions');
        $this->apply($batch, ['confirm_conflicts' => true, 'conflict_reason' => 'Dédoublement fictif du groupe', 'decisions' => [2 => 'create']])->assertSessionHasNoErrors();
        $this->assertSame(['applied', 4], [$batch->fresh()->status, CourseSeries::count()]);
        $this->assertTrue(AuditLog::where('action', 'schedule.conflict.confirmed')->exists());

        $exact = $this->import([['A-G1', 'METHODO', '2026-11-23', '09:00', '10:00', '', 'S12']]);
        $this->assertSame('invalid', $exact->status);
        $this->assertStringContainsString('Doublon exact', $exact->issues()->sole()->message);
    }

    public function test_r16_an_external_key_updates_the_same_series_and_the_published_schedule_can_be_rolled_back(): void
    {
        $this->apply($this->import([['A-G1', 'METHODO', 'lundi', '09:00', '10:00', '', 'S12', '', '', '', 'EDT-42']]))->assertSessionHasNoErrors();
        $series = CourseSeries::sole();
        $publish = function (): void {
            $props = $this->as('admin-a')->get('/planning/publication')->viewData('page')['props'];
            $this->as('admin-a')->post('/planning/publication', ['request_key' => (string) Str::uuid(), 'expected_version' => $props['current']['version'] ?? 0, 'preview_hash' => $props['preview']['hash'], 'reason' => 'Publication fictive'])->assertSessionHasNoErrors();
        };
        $publish();
        $ids = CourseOccurrence::orderBy('id')->pluck('id')->all();
        $start = fn () => PublishedOccurrence::orderBy('starts_at')->skip(1)->firstOrFail()->starts_at->format('Y-m-d H:i');
        $this->assertSame('2026-11-23 09:00', $start());

        $update = $this->import([['A-G1', 'METHODO', 'lundi', '14:00', '15:00', '', 'S30', '', '', '', 'EDT-42']]);
        $this->assertSame(['valid', 1, 0], [$update->status, $update->counts['update'], $update->counts['create']]);
        $this->apply($update)->assertSessionHasNoErrors();
        $this->assertSame(['applied', 1], [$update->fresh()->status, CourseSeries::count()]);
        $this->assertSame([2, $series->id], [$series->fresh()->lock_version, CourseSeries::sole()->id]);
        $this->assertSame($ids, CourseOccurrence::orderBy('id')->pluck('id')->all());
        $this->assertSame('2026-11-23 09:00', $start());
        $publish();
        $this->assertSame('2026-11-23 14:00', $start());

        $this->as('admin-a')->post('/planning/publication/1/retour', ['request_key' => (string) Str::uuid(), 'expected_version' => 2, 'reason' => 'Retour arrière fictif'])->assertSessionHasNoErrors();
        $this->assertSame([3, 'rollback'], [SchedulePublication::max('version'), SchedulePublication::orderByDesc('version')->first()->kind]);
        $this->assertSame('2026-11-23 09:00', $start());
        $this->assertSame($ids, CourseOccurrence::orderBy('id')->pluck('id')->all());

        $unchanged = $this->import([['A-G1', 'METHODO', 'lundi', '14:00', '15:00', '', 'S30', '', '', '', 'EDT-42'], ['A-G1', 'METHODO', 'mardi', '08:00', '09:00', '', '', '', '', '', 'EDT-42']]);
        $this->assertSame([['line' => 3, 'column' => 'identifiant_externe', 'severity' => 'error']], $this->issues($unchanged));
    }

    public function test_without_an_external_key_a_likely_match_must_be_decided_before_application(): void
    {
        $this->apply($this->import([['A-G1', 'METHODO', 'lundi', '09:00', '10:00']]))->assertSessionHasNoErrors();
        $batch = $this->import([['A-G1', 'METHODO', 'lundi', '15:00', '16:00']]);
        $series = CourseSeries::sole();
        $this->assertSame(['valid', 1], [$batch->status, $batch->counts['match']]);
        $this->assertSame([['line' => 2, 'label' => 'A-G1 · METHODO · lundi 15:00–16:00', 'candidates' => [['id' => $series->id, 'label' => '09:00–10:00 · du 2026-11-16 au 2027-08-31']]]], $batch->counts['proposals']);

        $this->apply($batch)->assertSessionHasErrors('decisions');
        $this->apply($batch, ['decisions' => [2 => 'update:'.(string) Str::uuid()]])->assertSessionHasNoErrors();
        $batch->refresh();
        $this->assertSame(['invalid', 1], [$batch->status, CourseSeries::count()]);
        $this->assertStringContainsString('aucune ligne appliquée', $batch->failure);

        $this->as('admin-a')->post('/planning/import/'.$batch->id.'/analyse')->assertSessionHasNoErrors();
        $this->assertSame('valid', $batch->fresh()->status);
        $this->apply($batch, ['decisions' => [2 => 'update:'.$series->id]])->assertSessionHasNoErrors();
        $this->assertSame(['applied', 1, 2], [$batch->fresh()->status, CourseSeries::count(), $series->fresh()->lock_version]);
        $this->assertSame('15:00', CourseSeriesRevision::orderByDesc('version')->first()->rules['start_time']);
    }

    public function test_application_is_all_or_nothing_when_references_change_after_the_simulation(): void
    {
        $batch = $this->import([['A-G1', 'METHODO', 'lundi', '09:00', '10:00'], ['A-G1', 'METHODO', 'mardi', '09:00', '10:00'], ['A-G1', 'METHODO', 'mercredi', '09:00', '10:00']]);
        $this->assertSame('valid', $batch->status);
        TeachingAssignment::query()->where('membership_id', $this->member('enseignant-a')->id)->get()->each->close();

        $this->apply($batch)->assertSessionHasNoErrors();
        $batch->refresh();
        $this->assertSame(['invalid', 0, 0], [$batch->status, CourseSeries::count(), CourseOccurrence::count()]);
        $this->assertStringContainsString('aucune ligne appliquée', $batch->failure);
        $this->assertTrue($batch->issues()->where('message', 'like', 'Application annulée en entier%')->exists());
        $this->as('admin-a')->post('/planning/import/'.$batch->id.'/analyse')->assertSessionHasNoErrors();
        $this->assertSame(['invalid', 3], [$batch->fresh()->status, $batch->fresh()->counts['errors']]);
    }

    public function test_files_that_are_not_safe_bounded_workbooks_are_refused(): void
    {
        $fake = tempnam(sys_get_temp_dir(), 'campus-faux-');
        file_put_contents($fake, 'groupe;matiere'.PHP_EOL.'A-G1;METHODO');
        $this->upload(new UploadedFile($fake, 'planning.xlsx', null, null, true))->assertSessionHasErrors('file');
        $this->upload($this->workbook([['A-G1', 'METHODO', 'lundi', '09:00', '10:00']], [], 'planning.xlsm'))->assertSessionHasErrors('file');
        $this->upload($this->workbook([['A-G1', 'METHODO', 'lundi', '09:00', '10:00']], [], 'planning.csv'))->assertSessionHasErrors('file');

        // Copie du classeur dans une archive neuve, avec un faux projet de macros en plus.
        $source = new ZipArchive;
        $source->open($this->workbook([['A-G1', 'METHODO', 'lundi', '09:00', '10:00']])->getRealPath(), ZipArchive::RDONLY);
        $macroPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'campus-macro-'.bin2hex(random_bytes(8)).'.xlsx';
        $zip = new ZipArchive;
        $zip->open($macroPath, ZipArchive::CREATE | ZipArchive::EXCL);
        for ($index = 0; $index < $source->numFiles; $index++) {
            $zip->addFromString($source->getNameIndex($index), $source->getFromIndex($index));
        }
        $zip->addFromString('xl/vbaProject.bin', 'contenu fictif');
        $zip->close();
        $source->close();
        $this->upload(new UploadedFile($macroPath, 'planning.xlsx', null, null, true))->assertSessionHasErrors(['file' => 'Le classeur contient des macros, des objets intégrés ou des liens externes : ce type de fichier est refusé.']);

        config(['campus.imports.max_uncompressed_bytes' => 500]);
        $this->upload($this->workbook([['A-G1', 'METHODO', 'mardi', '09:00', '10:00']]))->assertSessionHasErrors(['file' => 'Le classeur est trop volumineux une fois décompressé.']);
        config(['campus.imports.max_uncompressed_bytes' => 60 * 1024 * 1024, 'campus.imports.max_kilobytes' => 1]);
        $this->upload($this->workbook(array_fill(0, 400, ['A-G1', 'METHODO', 'lundi', '09:00', '10:00', '', Str::random(40)])))->assertSessionHasErrors('file');
        $this->assertSame(0, ImportBatch::count());
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));

        config(['campus.imports.max_kilobytes' => 5120, 'campus.imports.max_rows' => 2]);
        $tooLong = $this->import([['A-G1', 'METHODO', 'lundi', '09:00', '10:00'], ['A-G1', 'METHODO', 'mardi', '09:00', '10:00'], ['A-G1', 'METHODO', 'jeudi', '09:00', '10:00']]);
        $this->assertSame('invalid', $tooLong->status);
        $this->assertStringContainsString('dépasse 2 lignes', $tooLong->issues()->sole()->message);

        config(['campus.imports.max_rows' => 5000]);
        $sheets = $this->batchOf($this->upload($this->workbook([['A-G1', 'METHODO', 'lundi', '11:00', '12:00']], ['Deux' => [['x']], 'Trois' => [['x']], 'Quatre' => [['x']]]))->assertSessionHasNoErrors());
        $this->assertStringContainsString('trop de feuilles', $sheets->issues()->sole()->message);

        $formula = $this->import([['A-G1', 'METHODO', 'lundi', ['formula' => 'TIME(9,0,0)'], '10:00'], [['formula' => 'HYPERLINK("http://exemple.invalid")'], 'METHODO', 'mardi', '09:00', '10:00']]);
        $this->assertSame('invalid', $formula->status);
        $this->assertSame([['line' => 2, 'column' => 'debut', 'severity' => 'error'], ['line' => 3, 'column' => 'groupe', 'severity' => 'error']], $this->issues($formula));
        $this->assertStringContainsString('formules ne sont pas acceptées', $formula->issues()->first()->message);

        $headers = tempnam(sys_get_temp_dir(), 'campus-entetes-');
        file_put_contents($headers, XlsxWriter::build(['Planning' => [['matiere', 'groupe', 'jour_ou_date', 'debut', 'fin'], ['METHODO', 'A-G1', 'lundi', '09:00', '10:00']]]));
        $wrong = $this->batchOf($this->upload(new UploadedFile($headers, 'planning.xlsx', null, null, true))->assertSessionHasNoErrors());
        $this->assertStringContainsString('En-tête inattendu en colonne A', $wrong->issues()->sole()->message);
        $this->assertSame(0, CourseSeries::count());
    }

    public function test_only_the_administration_of_the_active_year_imports_and_jobs_recheck_rights_when_they_run(): void
    {
        $file = fn () => $this->workbook([['A-G1', 'METHODO', 'lundi', '09:00', '10:00']]);
        foreach ([['apprenant-double', 'A'], ['enseignant-a', 'A'], ['independant', null]] as [$name, $letter]) {
            $this->as($name, $letter)->get('/planning/import')->assertForbidden();
            $this->as($name, $letter)->get('/planning/import/modele')->assertForbidden();
            $this->as($name, $letter)->post('/planning/import', ['file' => $file(), 'import_key' => (string) Str::uuid()])->assertForbidden();
        }
        $batch = $this->import([['A-G1', 'METHODO', 'lundi', '09:00', '10:00']]);
        foreach (['/planning/import?batch='.$batch->id, '/planning/import/'.$batch->id.'/rapport'] as $url) {
            $this->as('admin-b', 'B')->get($url)->assertNotFound();
        }
        $this->as('admin-b', 'B')->post('/planning/import/'.$batch->id.'/application')->assertNotFound();
        $this->as('admin-b', 'B')->post('/planning/import/'.$batch->id.'/analyse')->assertNotFound();
        $this->as('admin-b', 'B')->get('/planning/import')->assertOk()->assertInertia(fn (Assert $page) => $page->has('batches', 0)->where('institution', 'Établissement fictif B'));
        $this->assertSame('valid', $batch->fresh()->status);

        Queue::fake();
        $this->upload($this->workbook([['A-G1', 'METHODO', 'mardi', '09:00', '10:00']]))->assertSessionHasNoErrors();
        $queued = ImportBatch::where('status', 'uploaded')->sole();
        Queue::assertPushed(AnalyzeScheduleImport::class, fn (AnalyzeScheduleImport $job) => $job->batchId === $queued->id);
        $this->member('admin-a')->revoke();
        (new AnalyzeScheduleImport($queued->id))->handle(app(ScheduleImporter::class));
        $this->assertSame('failed', $queued->fresh()->status);
        $this->assertStringContainsString('droits d’administration', $queued->fresh()->failure);
        app(ScheduleImporter::class)->apply($batch->id, $this->user('admin-a')->id, $batch->author_membership_id, [], false, null);
        $this->assertSame(0, CourseSeries::count());

        config(['campus.identity.privileged_without_mfa' => false]);
        $this->as('admin-b', 'B')->get('/planning/import')->assertForbidden();
        $this->as('admin-b', 'B')->post('/planning/import', ['file' => $file(), 'import_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_the_report_is_downloadable_and_neutralises_spreadsheet_formulas(): void
    {
        $batch = $this->import([['=CMD|calc', 'METHODO', 'lundi', '09:00', '10:00'], ['A-G1', '@SUM(1)', 'mardi', '09:00', '10:00']]);
        $csv = $this->as('admin-a')->get('/planning/import/'.$batch->id.'/rapport')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->streamedContent();
        $this->assertStringContainsString('Feuille;Ligne;Colonne;Gravité;Message;"Valeur lue"', $csv);
        $this->assertStringContainsString('Planning;2;groupe;"Erreur bloquante"', $csv);
        $this->assertStringContainsString("'=CMD|calc", $csv);
        $this->assertStringContainsString("'@SUM(1)", $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|;)[=@+\-]/m', $csv);
    }

    public function test_raw_files_are_purged_after_the_retention_period_while_the_report_is_kept(): void
    {
        $old = $this->import([['GROUPE-INCONNU', 'METHODO', 'lundi', '09:00', '10:00']]);
        $pending = $this->import([['A-G1', 'METHODO', 'mardi', '09:00', '10:00']]);
        $path = $pending->stored_path;
        $this->travel(29)->days();
        $this->artisan('campus:purge-import-files')->assertSuccessful();
        $this->assertNotNull($old->fresh()->stored_path);

        $this->travel(2)->days();
        $recent = $this->import([['A-G1', 'METHODO', 'jeudi', '09:00', '10:00']]);
        $this->artisan('campus:purge-import-files')->expectsOutput('2 lot(s) purgé(s).')->assertSuccessful();
        $this->assertSame([null, null, 'invalid', 1], [$old->fresh()->stored_path, $old->fresh()->parsed_rows, $old->fresh()->status, $old->issues()->count()]);
        $this->assertSame(['failed', null], [$pending->fresh()->status, $pending->fresh()->stored_path]);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertTrue(Storage::disk('local')->exists($recent->fresh()->stored_path));
        $this->apply($pending)->assertSessionHasErrors('batch');
        $this->assertSame(0, CourseSeries::count());
    }

    /**
     * Mesure réelle, exécutée seulement sur demande : CAMPUS_MEASURE=1 php artisan test --filter=measured_import
     */
    public function test_measured_import_of_one_thousand_rows(): void
    {
        if (! env('CAMPUS_MEASURE')) {
            $this->markTestSkipped('Mesure sur demande : définir CAMPUS_MEASURE=1.');
        }
        $admin = $this->member('admin-a');
        $subject = Subject::where('institution_id', $admin->institution_id)->firstOrFail();
        $rows = [];
        foreach (range(1, 25) as $number) {
            $group = Group::create(['institution_id' => $admin->institution_id, 'academic_year_id' => $admin->academic_year_id, 'code' => 'MESURE-'.$number, 'name' => 'Groupe de mesure '.$number]);
            $teacher = Membership::create(['user_id' => User::factory()->create()->id, 'scope' => 'institution', 'institution_id' => $admin->institution_id, 'academic_year_id' => $admin->academic_year_id, 'role' => 'teacher', 'starts_at' => '2026-09-01 00:00:00']);
            TeachingAssignment::create(['membership_id' => $teacher->id, 'user_id' => $teacher->user_id, 'institution_id' => $admin->institution_id, 'academic_year_id' => $admin->academic_year_id, 'group_id' => $group->id, 'subject_id' => $subject->id, 'starts_at' => '2026-09-01 00:00:00']);
            foreach (['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi'] as $day) {
                foreach (range(8, 15) as $hour) {
                    $rows[] = [$group->code, $subject->code, $day, sprintf('%02d:00', $hour), sprintf('%02d:00', $hour + 1), '', '', '', '', '', $group->code.'-'.$day.'-'.$hour];
                }
            }
        }
        $this->assertCount(1000, $rows);
        $file = $this->workbook($rows);
        $size = $file->getSize();

        $started = microtime(true);
        $this->upload($file)->assertSessionHasNoErrors();
        $analysis = microtime(true) - $started;
        $batch = ImportBatch::sole();
        $this->assertSame(['valid', 1000, 1000], [$batch->status, $batch->total_rows, $batch->counts['create']]);

        $started = microtime(true);
        $this->apply($batch)->assertSessionHasNoErrors();
        $application = microtime(true) - $started;
        $this->assertSame(['applied', 1000], [$batch->fresh()->status, CourseSeries::count()]);

        fwrite(STDERR, sprintf("\nMESURE import 1000 lignes : fichier %d octets ; dépôt + analyse %.1f s ; application au brouillon %.1f s ; %d séries, %d occurrences ; mémoire maximale %.0f Mo\n",
            $size, $analysis, $application, CourseSeries::count(), CourseOccurrence::count(), memory_get_peak_usage(true) / 1048576));
    }
}
