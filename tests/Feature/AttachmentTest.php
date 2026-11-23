<?php

namespace Tests\Feature;

use App\Jobs\ScanAttachment;
use App\Models\Attachment;
use App\Models\AttachmentReport;
use App\Models\AuditLog;
use App\Models\CourseCheckIn;
use App\Models\CourseSeries;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Subject;
use App\Models\Task;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\Attachments;
use App\Support\Scanners\ClamdScanner;
use App\Support\Scanners\Scanner;
use App\Support\Scanners\UnavailableScanner;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use DatabaseTransactions;

    /** Marqueur inoffensif que le scanner de test traite comme une détection : aucun logiciel malveillant n'est utilisé. */
    private const FAKE_SIGNATURE = 'SIGNATURE-FICTIVE-DE-TEST';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(8, 0));
        $this->seed(CampusDemoSeeder::class);
        User::query()->update(['email_verified_at' => now()]);
        config(['campus.identity.privileged_without_mfa' => true]);
        Storage::fake('local');
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@example.test')->firstOrFail();
    }

    private function member(string $name, string $letter = 'A'): Membership
    {
        return $this->user($name)->memberships()->whereHas('institution', fn ($query) => $query->where('code', 'CAMPUS-FICTIF-'.$letter))->firstOrFail();
    }

    private function as(string $name, ?string $letter = null): static
    {
        return $this->freshDevice()->actingAs($this->user($name))
            ->withSession($letter ? [ActiveContext::SESSION_KEY => $this->member($name, $letter)->id] : []);
    }

    /** Scanner de test : « sain », sauf si le fichier contient le marqueur fictif. */
    private function useWorkingScanner(): void
    {
        $this->app->bind(Scanner::class, fn () => new class implements Scanner
        {
            public function scan(string $absolutePath): array
            {
                return str_contains((string) file_get_contents($absolutePath), 'SIGNATURE-FICTIVE-DE-TEST')
                    ? ['result' => 'infected', 'detail' => 'Test.Fictif'] : ['result' => 'clean', 'detail' => null];
            }

            public function available(): bool
            {
                return true;
            }
        });
    }

    private function pdf(string $extra = '', int $padding = 0): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n".$extra."\n%".str_repeat('x', $padding)."\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(4, 4));

        return (string) ob_get_clean();
    }

    private function jpeg(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(4, 4));

        return (string) ob_get_clean();
    }

    private function file(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function task(string $owner = 'independant', string $title = 'Tâche fictive avec pièce jointe'): Task
    {
        $key = (string) Str::uuid();
        $this->as($owner)->post('/taches', ['client_key' => $key, 'title' => $title])->assertSessionHasNoErrors();

        return Task::where('client_key', $key)->firstOrFail();
    }

    private function homework(): Task
    {
        $key = (string) Str::uuid();
        $this->as('enseignant-a', 'A')->post('/taches/devoirs', ['client_key' => $key, 'group_id' => Group::where('code', 'A-G1')->firstOrFail()->id,
            'subject_id' => Subject::where('institution_id', $this->member('enseignant-a')->institution_id)->firstOrFail()->id, 'title' => 'Devoir fictif avec document', 'priority' => 'normal'])->assertSessionHasNoErrors();

        return Task::where('client_key', $key)->firstOrFail();
    }

    private function deposit(string $name, Task $task, UploadedFile $file, array $extra = [], ?string $letter = null, bool $json = false): TestResponse
    {
        $payload = ['client_key' => (string) Str::uuid(), 'file' => $file, ...$extra];
        $url = '/taches/'.$task->id.'/pieces-jointes';

        return $json ? $this->as($name, $letter)->post($url, $payload, ['Accept' => 'application/json']) : $this->as($name, $letter)->post($url, $payload);
    }

    /** Dépôt accepté, renvoie la pièce jointe créée. */
    private function attach(string $name, Task $task, ?UploadedFile $file = null, array $extra = [], ?string $letter = null): Attachment
    {
        $key = (string) Str::uuid();
        $this->deposit($name, $task, $file ?? $this->file('document-fictif.pdf', $this->pdf()), ['client_key' => $key, ...$extra], $letter)->assertSessionHasNoErrors();

        return Attachment::where('client_key', $key)->firstOrFail();
    }

    private function download(string $name, Attachment $attachment, ?string $letter = null): TestResponse
    {
        return $this->as($name, $letter)->get('/pieces-jointes/'.$attachment->id);
    }

    public function test_r49_disguised_active_oversized_or_unauthorised_files_are_refused_before_storage(): void
    {
        $task = $this->task();
        config(['campus.attachments.max_file_kilobytes' => 64]);
        $refused = [
            'programme déguisé en PDF' => ['facture.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\0", 200)],
            'image PNG renommée en PDF' => ['image.pdf', $this->png()],
            'PDF renommé en PNG' => ['document.png', $this->pdf()],
            'page HTML renommée en JPEG' => ['photo.jpg', '<html><script>alert(1)</script></html>'],
            'script renommé en PNG' => ['photo.png', "<?php echo 'fictif'; ?>"],
            'SVG' => ['dessin.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'document à macros' => ['rapport.docm', "PK\x03\x04".str_repeat('x', 100)],
            'exécutable' => ['outil.exe', 'MZ'.str_repeat("\0", 100)],
            'double extension' => ['document.pdf.exe', $this->pdf()],
            'sans extension' => ['document', $this->pdf()],
            'PDF avec script' => ['actif.pdf', $this->pdf("3 0 obj\n<< /S /JavaScript /JS (app.alert\\('fictif'\\)) >>\nendobj")],
            'PDF avec script masqué en hexadécimal' => ['masque.pdf', $this->pdf("3 0 obj\n<< /S /#4AavaScript >>\nendobj")],
            'PDF avec action automatique' => ['auto.pdf', $this->pdf("3 0 obj\n<< /OpenAction 4 0 R >>\nendobj")],
            'PDF avec lancement de programme' => ['lance.pdf', $this->pdf("3 0 obj\n<< /S /Launch /F (calc) >>\nendobj")],
            'PDF avec fichier embarqué' => ['embarque.pdf', $this->pdf("3 0 obj\n<< /Type /EmbeddedFile >>\nendobj")],
            'PNG tronqué' => ['abime.png', substr($this->png(), 0, 20)],
            'fichier vide' => ['vide.pdf', ''],
            'fichier trop gros' => ['gros.pdf', $this->pdf('', 70 * 1024)],
        ];
        foreach ($refused as $case => [$name, $content]) {
            $this->deposit('independant', $task, $this->file($name, $content))->assertSessionHasErrors('file', $case);
        }
        $this->as('independant')->post('/taches/'.$task->id.'/pieces-jointes', ['client_key' => (string) Str::uuid()])->assertSessionHasErrors('file');
        $this->as('independant')->post('/taches/'.$task->id.'/pieces-jointes', ['client_key' => (string) Str::uuid(), 'file' => 'https://exemple.test/fichier.pdf'])->assertSessionHasErrors('file');
        // Rien n'a été enregistré, ni en base ni dans le stockage.
        $this->assertSame([0, []], [Attachment::count(), Storage::disk('local')->allFiles()]);

        // Les trois formats autorisés passent ; le nom de stockage est aléatoire, sans extension, sans rapport avec le nom affiché.
        Queue::fake();
        foreach (['Cours fictif n°1.pdf' => $this->pdf(), 'photo fictive.PNG' => $this->png(), 'scan.jpeg' => $this->jpeg()] as $name => $content) {
            $this->deposit('independant', $task, $this->file($name, $content))->assertSessionHasNoErrors();
        }
        $stored = Attachment::orderBy('created_at')->orderBy('display_name')->get();
        $this->assertEqualsCanonicalizing(['Cours fictif n°1.pdf', 'photo fictive.PNG', 'scan.jpeg'], $stored->pluck('display_name')->all());
        $this->assertEqualsCanonicalizing(['application/pdf', 'image/png', 'image/jpeg'], $stored->pluck('mime')->all());
        foreach ($stored as $attachment) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $attachment->stored_name);
            $this->assertStringNotContainsString(pathinfo($attachment->display_name, PATHINFO_FILENAME), $attachment->stored_name);
            $this->assertTrue(Storage::disk('local')->exists('attachments/'.$attachment->stored_name));
            $this->assertSame(['personal', 'quarantine', $this->user('independant')->id, hash('sha256', Storage::disk('local')->get($attachment->path()))],
                [$attachment->visibility, $attachment->scan_status, $attachment->owner_id, $attachment->sha256]);
            // Le nom de stockage n'est jamais transmis au navigateur.
            $this->assertArrayNotHasKey('stored_name', $attachment->toArray());
        }
        Queue::assertPushed(ScanAttachment::class, 3);
        // Aucun fichier privé sous le dossier public, et aucun lien public vers le stockage.
        $this->assertStringStartsNotWith(public_path(), Storage::disk('local')->path('attachments'));
        $this->get('/storage/attachments/'.$stored[0]->stored_name)->assertNotFound();
        $this->get('/attachments/'.$stored[0]->stored_name)->assertNotFound();
        foreach ([['mime' => 'image/svg+xml'], ['extension' => 'exe'], ['scan_status' => 'valide'], ['visibility' => 'public'], ['size_bytes' => 0]] as $invalid) {
            try {
                DB::table('attachments')->where('id', $stored[0]->id)->update($invalid);
                $this->fail('MySQL a accepté '.json_encode($invalid));
            } catch (QueryException $error) {
                $this->assertSame(3819, (int) $error->errorInfo[1]);
            }
        }
    }

    public function test_r49_a_file_stays_blocked_in_quarantine_and_an_unavailable_scanner_never_validates_it(): void
    {
        $task = $this->task();
        Queue::fake();
        $attachment = $this->attach('independant', $task);
        // Quarantaine : même son propriétaire ne peut pas télécharger, et l'écran l'explique.
        $this->assertSame('quarantine', $attachment->scan_status);
        $this->download('independant', $attachment)->assertStatus(423);
        $this->as('independant')->getJson('/pieces-jointes/'.$attachment->id)->assertStatus(423)->assertJson(['code' => 'FILE_NOT_AVAILABLE'])->assertJsonStructure(['code', 'message', 'errors', 'request_id']);
        $this->as('independant')->get('/taches?statut=all')->assertInertia(fn (Assert $page) => $page->where('undated.0.attachments.0.downloadable', false)
            ->where('undated.0.attachments.0.statusLabel', Attachment::SCAN_LABELS['quarantine'])->where('attachmentLimits.scannerConfigured', false));

        // Aucun antivirus configuré (réglage par défaut) : l'analyse échoue, le fichier reste bloqué.
        $this->assertInstanceOf(UnavailableScanner::class, app(Scanner::class));
        (new ScanAttachment($attachment->id))->handle(app(Scanner::class));
        $this->assertSame(['error', 'scanner_not_configured', 1, null], [$attachment->fresh()->scan_status, $attachment->fresh()->scan_detail, $attachment->fresh()->scan_attempts, $attachment->fresh()->scanned_at]);
        $this->download('independant', $attachment)->assertStatus(423);
        // Démon antivirus configuré mais injoignable : même résultat, jamais « sain ».
        config(['campus.attachments.scanner' => 'clamd', 'campus.attachments.clamd_host' => '127.0.0.1', 'campus.attachments.clamd_port' => 9]);
        $this->assertInstanceOf(ClamdScanner::class, app(Scanner::class));
        (new ScanAttachment($attachment->id))->handle(app(Scanner::class));
        $this->assertSame(['error', 'scanner_unreachable', 2], [$attachment->fresh()->scan_status, $attachment->fresh()->scan_detail, $attachment->fresh()->scan_attempts]);
        $this->download('independant', $attachment)->assertStatus(423);
        $this->artisan('campus:attachments:scanner-check')->expectsOutputToContain('ne répond pas')->assertFailed();
        config(['campus.attachments.scanner' => 'none']);
        $this->artisan('campus:attachments:scanner-check')->expectsOutputToContain('Aucun antivirus configuré')->assertFailed();
        // Scanner qui lève une exception : erreur, pas de validation.
        $this->app->bind(Scanner::class, fn () => new class implements Scanner
        {
            public function scan(string $absolutePath): array
            {
                throw new \RuntimeException('Panne fictive');
            }

            public function available(): bool
            {
                return false;
            }
        });
        (new ScanAttachment($attachment->id))->handle(app(Scanner::class));
        $this->assertSame(['error', 'scanner_exception'], [$attachment->fresh()->scan_status, $attachment->fresh()->scan_detail]);

        // Lecture stricte de la réponse du démon : seul « OK » explicite vaut sain.
        $this->assertSame(['result' => 'clean', 'detail' => null], ClamdScanner::interpret("stream: OK\0"));
        $this->assertSame(['result' => 'infected', 'detail' => 'Eicar-Signature'], ClamdScanner::interpret('stream: Eicar-Signature FOUND'));
        foreach (['', 'stream: OK FOUND extra', 'INSTREAM size limit exceeded. ERROR', 'OK', 'stream: ok', 'PONG'] as $reply) {
            $this->assertNotSame('clean', ClamdScanner::interpret($reply)['result'], $reply);
        }

        // La maintenance relance l'analyse ; une analyse interrompue repasse en quarantaine ; un scanner opérationnel libère le fichier.
        $this->travel(2)->minutes();
        $this->artisan('campus:attachments:maintain')->expectsOutputToContain('analyses relancées=1')->assertSuccessful();
        Queue::assertPushed(ScanAttachment::class, fn (ScanAttachment $job) => $job->attachmentId === $attachment->id);
        $attachment->forceFill(['scan_status' => 'scanning', 'scan_started_at' => now()->subMinutes(11)])->save();
        $this->download('independant', $attachment)->assertStatus(423);
        $this->artisan('campus:attachments:maintain')->expectsOutputToContain('analyses interrompues=1')->assertSuccessful();
        $this->assertSame('quarantine', $attachment->fresh()->scan_status);
        $this->useWorkingScanner();
        (new ScanAttachment($attachment->id))->handle(app(Scanner::class));
        (new ScanAttachment($attachment->id))->handle(app(Scanner::class));
        $this->assertSame(['clean', 4], [$attachment->fresh()->scan_status, $attachment->fresh()->scan_attempts]);

        // Téléchargement : contenu intact, enregistrement forcé, aucune interprétation possible sur le domaine.
        $response = $this->download('independant', $attachment)->assertOk();
        $this->assertSame($this->pdf(), $response->streamedContent());
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));

        // Fichier détecté : refusé, retiré du stockage tout de suite, jamais téléchargeable, et il ne compte plus dans le quota.
        $infected = $this->attach('independant', $task, $this->file('suspect.pdf', $this->pdf('% '.self::FAKE_SIGNATURE)));
        $this->download('independant', $infected)->assertStatus(423);
        (new ScanAttachment($infected->id))->handle(app(Scanner::class));
        $this->assertSame(['rejected', 'Test.Fictif'], [$infected->fresh()->scan_status, $infected->fresh()->scan_detail]);
        $this->assertFalse(Storage::disk('local')->exists($infected->path()));
        $this->download('independant', $infected)->assertStatus(423);
        $this->assertSame($attachment->size_bytes, app(Attachments::class)->usage($this->user('independant')));
    }

    public function test_n14_01_limits_per_object_and_per_account_hold_even_for_files_not_yet_scanned(): void
    {
        Queue::fake();
        $task = $this->task();
        $other = $this->task('independant', 'Seconde tâche fictive');
        foreach ([1, 2, 3] as $index) {
            $this->attach('independant', $task, $this->file("fichier-{$index}.pdf", $this->pdf()));
        }
        // Quatrième fichier sur le même élément : refusé. Un autre compte et un autre élément ne sont pas concernés.
        $this->deposit('independant', $task, $this->file('fichier-4.pdf', $this->pdf()))->assertSessionHasErrors('file');
        $this->attach('independant', $other);
        // Double clic (même clé) : un seul fichier, une seule analyse demandée.
        $key = (string) Str::uuid();
        $first = $this->deposit('independant', $other, $this->file('double.pdf', $this->pdf()), ['client_key' => $key], null, true)->assertOk()->assertJson(['replayed' => false, 'status' => 'quarantine']);
        $this->deposit('independant', $other, $this->file('double.pdf', $this->pdf()), ['client_key' => $key], null, true)->assertOk()->assertJson(['id' => $first->json('id'), 'replayed' => true]);
        $this->assertSame([5, 5], [Attachment::count(), count(Storage::disk('local')->files('attachments'))]);
        Queue::assertPushed(ScanAttachment::class, 5);
        // Supprimer libère une place et l'espace.
        $before = app(Attachments::class)->usage($this->user('independant'));
        $removed = Attachment::where('task_id', $task->id)->firstOrFail();
        $this->as('independant')->delete('/pieces-jointes/'.$removed->id)->assertSessionHasNoErrors();
        $this->as('independant')->delete('/pieces-jointes/'.$removed->id)->assertSessionHasNoErrors();
        $this->assertSame([$before - $removed->size_bytes, false], [app(Attachments::class)->usage($this->user('independant')), Storage::disk('local')->exists($removed->path())]);
        $this->attach('independant', $task, $this->file('fichier-4.pdf', $this->pdf()));

        // Quota du compte (1 Mo ici) : deux dépôts de 400 Ko passent, le troisième ferait dépasser et est refusé,
        // alors même que les deux premiers sont encore en quarantaine. Les dépôts d'un même compte passent l'un après l'autre sous verrou.
        config(['campus.attachments.max_user_megabytes' => 1]);
        $tasks = [$this->task('editeur', 'Quota fictif 1'), $this->task('editeur', 'Quota fictif 2'), $this->task('editeur', 'Quota fictif 3')];
        $big = fn (string $name) => $this->file($name, $this->pdf('', 400 * 1024));
        $this->attach('editeur', $tasks[0], $big('a.pdf'));
        $this->attach('editeur', $tasks[1], $big('b.pdf'));
        $this->deposit('editeur', $tasks[2], $big('c.pdf'))->assertSessionHasErrors('file');
        $this->assertSame([2, ['quarantine']], [Attachment::where('owner_id', $this->user('editeur')->id)->count(), Attachment::where('owner_id', $this->user('editeur')->id)->pluck('scan_status')->unique()->all()]);
        $this->assertGreaterThan(800 * 1024, app(Attachments::class)->usage($this->user('editeur')));
        $this->assertLessThanOrEqual(1024 * 1024, app(Attachments::class)->usage($this->user('editeur')));
        // Un petit fichier tient encore ; le quota d'un compte n'affecte pas les autres.
        $this->attach('editeur', $tasks[2], $this->file('petit.pdf', $this->pdf()));
        $this->attach('plateforme', $this->task('plateforme', 'Autre compte fictif'), $big('d.pdf'));
        $this->as('editeur')->get('/taches?statut=all')->assertInertia(fn (Assert $page) => $page->where('attachmentLimits.maxBytes', 1048576)->where('attachmentLimits.maxPerObject', 3)
            ->where('attachmentLimits.usedBytes', app(Attachments::class)->usage($this->user('editeur'))));
    }

    public function test_r50_a_link_passed_to_someone_without_rights_never_gives_access(): void
    {
        $this->useWorkingScanner();
        $task = $this->task('apprenant-double', 'Tâche fictive privée');
        $private = $this->attach('apprenant-double', $task, $this->file('notes privées fictives.pdf', $this->pdf()));
        $this->assertSame('clean', $private->fresh()->scan_status);
        $this->download('apprenant-double', $private)->assertOk();

        // Le lien d'un fichier personnel, transmis à n'importe qui d'autre : introuvable, en lecture comme en écriture.
        foreach ([['independant', null], ['polyvalent', 'A'], ['enseignant-a', 'A'], ['admin-a', 'A'], ['admin-b', 'B'], ['plateforme', null], ['editeur', null]] as [$name, $letter]) {
            $actor = fn () => in_array($name, ['plateforme', 'editeur'], true)
                ? $this->freshDevice()->actingAs($this->user($name))->withSession([ActiveContext::SESSION_KEY => $this->user($name)->memberships()->firstOrFail()->id])
                : $this->as($name, $letter);
            $actor()->get('/pieces-jointes/'.$private->id)->assertNotFound();
            $actor()->delete('/pieces-jointes/'.$private->id)->assertNotFound();
            $actor()->post('/pieces-jointes/'.$private->id.'/signalement', ['reason' => 'Tentative fictive'])->assertNotFound();
            $actor()->post('/taches/'.$task->id.'/pieces-jointes', ['client_key' => (string) Str::uuid(), 'file' => $this->file('intrus.pdf', $this->pdf())])->assertNotFound();
            $actor()->get('/taches?statut=all')->assertOk()->assertDontSee('notes privées fictives');
        }
        $this->freshDevice()->get('/pieces-jointes/'.$private->id)->assertRedirect('/connexion');
        $this->freshDevice()->get('/pieces-jointes/'.Str::uuid())->assertRedirect('/connexion');
        $this->as('apprenant-double')->get('/pieces-jointes/'.Str::uuid())->assertNotFound();
        $this->assertSame([1, null], [Attachment::count(), $private->fresh()->deleted_at]);

        // Retour de cours : même règle.
        $rules = ['request_key' => (string) Str::uuid(), 'title' => 'Cours fictif', 'kind' => 'once', 'valid_from' => '2026-11-16', 'valid_until' => '2026-11-16', 'start_time' => '06:00', 'end_time' => '07:00',
            'timezone' => 'Africa/Abidjan', 'weekdays' => [1], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [], 'group_ids' => []];
        $this->as('independant')->post('/planning/series', $rules)->assertSessionHasNoErrors();
        $occurrence = CourseSeries::where('request_key', $rules['request_key'])->firstOrFail()->occurrences()->firstOrFail();
        $url = '/planning/occurrences/'.$occurrence->id.'/retour';
        $this->as('independant')->post($url.'/pieces-jointes', ['client_key' => (string) Str::uuid(), 'file' => $this->file('tableau.png', $this->png())])->assertNotFound();
        $this->as('independant')->put($url, ['action' => 'nothing', 'expected_version' => 0])->assertSessionHasNoErrors();
        $this->as('independant')->post($url.'/pieces-jointes', ['client_key' => (string) Str::uuid(), 'file' => $this->file('tableau.png', $this->png())])->assertSessionHasNoErrors();
        $photo = Attachment::where('course_check_in_id', CourseCheckIn::sole()->id)->sole();
        $this->as('independant')->get($url)->assertInertia(fn (Assert $page) => $page->has('attachments', 1)->where('attachments.0.downloadable', true)->where('attachments.0.shared', false));
        $this->download('independant', $photo)->assertOk();
        foreach (['editeur', 'admin-a', 'plateforme'] as $name) {
            $this->download($name, $photo)->assertNotFound();
            $this->as($name)->post($url.'/pieces-jointes', ['client_key' => (string) Str::uuid(), 'file' => $this->file('intrus.png', $this->png())])->assertNotFound();
        }

        // Tâche en corbeille : ses fichiers ne sont plus servis ; restaurée : de nouveau. Purgée : fichiers et lignes disparaissent.
        $this->as('apprenant-double')->delete('/taches/'.$task->id, ['expected_version' => 1])->assertSessionHasNoErrors();
        $this->download('apprenant-double', $private)->assertNotFound();
        $this->as('apprenant-double')->post('/taches/'.$task->id.'/restauration')->assertSessionHasNoErrors();
        $this->download('apprenant-double', $private)->assertOk();
        $this->as('apprenant-double')->delete('/taches/'.$task->id, ['expected_version' => 3])->assertSessionHasNoErrors();
        $this->travel(8)->days();
        $this->artisan('campus:purge-tasks')->expectsOutputToContain('1 tâche(s) purgée(s).')->assertSuccessful();
        $this->assertSame([0, false, 1], [Attachment::where('task_id', $task->id)->count(), Storage::disk('local')->exists($private->path()), Attachment::count()]);
    }

    public function test_n14_02_group_files_follow_the_homework_rights_and_personal_files_never_become_shared(): void
    {
        $this->useWorkingScanner();
        $homework = $this->homework();
        $shared = $this->attach('enseignant-a', $homework, $this->file('sujet fictif.pdf', $this->pdf()), ['visibility' => 'group'], 'A');
        $mine = $this->attach('apprenant-double', $homework, $this->file('brouillon personnel fictif.png', $this->png()));
        $this->assertSame(['group', 'personal'], [$shared->visibility, $mine->visibility]);

        // Fichier du devoir : les apprenants inscrits et l'enseignant affecté ; personne d'autre.
        foreach ([['apprenant-double', null, 200], ['polyvalent', null, 200], ['enseignant-a', 'A', 200], ['enseignant-a', null, 200], ['independant', null, 404], ['admin-a', 'A', 404], ['admin-b', 'B', 404], ['polyvalent', 'B', 200]] as [$name, $letter, $status]) {
            $this->download($name, $shared, $letter)->assertStatus($status);
        }
        // Fichier personnel déposé sur le devoir : son auteur seul. Ni l'autre apprenant, ni l'enseignant, ni l'administration.
        foreach ([['apprenant-double', 200], ['polyvalent', 404], ['enseignant-a', 404], ['admin-a', 404]] as [$name, $status]) {
            $this->download($name, $mine)->assertStatus($status);
        }
        $this->as('polyvalent')->get('/taches?statut=all')->assertOk()->assertDontSee('brouillon personnel fictif')->assertInertia(fn (Assert $page) => $page
            ->where('undated.0.attachments', fn ($files) => collect($files)->pluck('name')->all() === ['sujet fictif.pdf'] && collect($files)[0]['canReport'] === true && collect($files)[0]['canDelete'] === false));
        $this->as('apprenant-double')->get('/taches?statut=all')->assertInertia(fn (Assert $page) => $page->where('undated.0.attachments', fn ($files) => collect($files)->pluck('shared', 'name')->all() === ['sujet fictif.pdf' => true, 'brouillon personnel fictif.png' => false]));
        $this->as('enseignant-a', 'A')->get('/taches/devoirs')->assertOk()->assertDontSee('brouillon personnel fictif')->assertInertia(fn (Assert $page) => $page->has('homework.0.attachments', 1)->where('homework.0.attachments.0.canDelete', true));

        // Un apprenant ne peut pas rendre un fichier collectif, ni sur un devoir ni sur sa propre tâche ; il ne supprime pas le fichier du devoir.
        $this->deposit('apprenant-double', $homework, $this->file('x.pdf', $this->pdf()), ['visibility' => 'group'])->assertNotFound();
        $this->deposit('apprenant-double', $this->task('apprenant-double'), $this->file('x.pdf', $this->pdf()), ['visibility' => 'group'])->assertNotFound();
        $this->deposit('apprenant-double', $homework, $this->file('x.pdf', $this->pdf()), ['visibility' => 'public'])->assertSessionHasErrors('visibility');
        $this->as('apprenant-double')->delete('/pieces-jointes/'.$shared->id)->assertNotFound();
        $this->as('polyvalent', 'B')->delete('/pieces-jointes/'.$shared->id)->assertNotFound();
        // Même si la visibilité d'un fichier personnel était forcée à « groupe » sur une tâche personnelle, il ne serait pas servi : MySQL le refuse et la lecture exige un devoir de groupe.
        $personalTask = $this->task('apprenant-double', 'Tâche fictive personnelle');
        $kept = $this->attach('apprenant-double', $personalTask);
        DB::table('attachments')->where('id', $kept->id)->update(['visibility' => 'group']);
        $this->download('polyvalent', $kept->fresh())->assertNotFound();
        $this->download('apprenant-double', $kept->fresh())->assertNotFound();
        DB::table('attachments')->where('id', $kept->id)->update(['visibility' => 'personal']);
        // Sans vérification renforcée, l'enseignant ne dépose pas de fichier collectif.
        config(['campus.identity.privileged_without_mfa' => false]);
        $this->deposit('enseignant-a', $homework, $this->file('y.pdf', $this->pdf()), ['visibility' => 'group'], 'A')->assertForbidden();
        config(['campus.identity.privileged_without_mfa' => true]);

        // Inscription clôturée, puis appartenance retirée : plus aucun accès, ni au fichier du devoir ni au fichier personnel qui y est rattaché.
        Enrollment::where('membership_id', $this->member('polyvalent')->id)->firstOrFail()->close();
        $this->download('polyvalent', $shared)->assertNotFound();
        $this->member('apprenant-double')->revoke();
        $this->download('apprenant-double', $shared)->assertNotFound();
        $this->download('apprenant-double', $mine)->assertNotFound();
        // Son propriétaire peut toujours supprimer son fichier, pour libérer son espace.
        $this->as('apprenant-double')->delete('/pieces-jointes/'.$mine->id)->assertSessionHasNoErrors();
        $this->assertNotNull($mine->fresh()->deleted_at);
        // Devoir retiré : le fichier n'est plus servi, même à l'enseignant ; affectation clôturée : l'enseignant ne gère plus rien.
        $this->as('enseignant-a', 'A')->delete('/taches/devoirs/'.$homework->id, ['expected_version' => 1])->assertSessionHasNoErrors();
        $this->download('enseignant-a', $shared, 'A')->assertNotFound();
        $this->as('enseignant-a', 'A')->post('/taches/devoirs/'.$homework->id.'/retablissement')->assertSessionHasNoErrors();
        $this->download('enseignant-a', $shared, 'A')->assertOk();
        TeachingAssignment::where('membership_id', $this->member('enseignant-a')->id)->firstOrFail()->close();
        $this->download('enseignant-a', $shared, 'A')->assertNotFound();
        $this->as('enseignant-a', 'A')->delete('/pieces-jointes/'.$shared->id)->assertNotFound();
        $this->assertNull($shared->fresh()->deleted_at);
    }

    public function test_n14_03_a_shared_file_can_be_reported_and_withdrawn_by_the_institution(): void
    {
        $this->useWorkingScanner();
        $homework = $this->homework();
        $shared = $this->attach('enseignant-a', $homework, $this->file('document litigieux fictif.pdf', $this->pdf()), ['visibility' => 'group'], 'A');
        $mine = $this->attach('apprenant-double', $homework);
        $url = '/pieces-jointes/'.$shared->id.'/signalement';

        $this->as('apprenant-double')->post($url, ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->as('independant')->post($url, ['reason' => 'Signalement fictif sans droit'])->assertNotFound();
        $this->as('enseignant-a', 'A')->post($url, ['reason' => 'Signalement fictif de son propre fichier'])->assertForbidden();
        $this->as('apprenant-double')->post('/pieces-jointes/'.$mine->id.'/signalement', ['reason' => 'Fichier personnel fictif'])->assertNotFound();
        // Avant tout signalement, l'administration ne lit pas le fichier.
        $this->download('admin-a', $shared, 'A')->assertNotFound();
        $this->as('apprenant-double')->post($url, ['reason' => 'Contenu fictif inapproprié'])->assertSessionHasNoErrors();
        $this->as('apprenant-double')->post($url, ['reason' => 'Contenu fictif inapproprié, second envoi'])->assertSessionHasNoErrors();
        $this->as('polyvalent')->post($url, ['reason' => 'Autre signalement fictif'])->assertSessionHasNoErrors();
        $this->assertSame([2, 'Contenu fictif inapproprié'], [AttachmentReport::count(), AttachmentReport::where('reporter_id', $this->user('apprenant-double')->id)->sole()->reason]);
        // Le signalement ne retire rien par lui-même : le fichier reste disponible jusqu'à la décision.
        $this->download('polyvalent', $shared)->assertOk();

        // Seule l'administration de cet établissement traite les signalements, et lit le fichier signalé, lui seul.
        foreach ([['enseignant-a', 'A'], ['apprenant-double', 'A'], ['admin-b', 'B'], ['independant', null]] as [$name, $letter]) {
            $this->as($name, $letter)->get('/pieces-jointes/signalements')->assertStatus($name === 'admin-b' ? 200 : 403);
        }
        $this->as('admin-b', 'B')->get('/pieces-jointes/signalements')->assertInertia(fn (Assert $page) => $page->has('reports', 0));
        $this->as('admin-a', 'A')->get('/pieces-jointes/signalements')->assertOk()->assertInertia(fn (Assert $page) => $page->component('AttachmentReports')->has('reports', 2)
            ->where('reports.0.status', 'open')->where('reports.0.fileName', 'document litigieux fictif.pdf')->where('reports.0.downloadable', true));
        $this->download('admin-a', $shared, 'A')->assertOk();
        $this->download('admin-a', $mine, 'A')->assertNotFound();
        $this->download('admin-b', $shared, 'B')->assertNotFound();
        $report = AttachmentReport::orderBy('id')->firstOrFail();
        $this->as('admin-b', 'B')->post('/pieces-jointes/signalements/'.$report->id.'/decision', ['decision' => 'upheld'])->assertNotFound();
        $this->as('enseignant-a', 'A')->post('/pieces-jointes/signalements/'.$report->id.'/decision', ['decision' => 'upheld'])->assertForbidden();
        $this->as('admin-a', 'A')->post('/pieces-jointes/signalements/'.$report->id.'/decision', ['decision' => 'supprimer'])->assertSessionHasErrors('decision');
        config(['campus.identity.privileged_without_mfa' => false]);
        $this->as('admin-a', 'A')->post('/pieces-jointes/signalements/'.$report->id.'/decision', ['decision' => 'upheld'])->assertForbidden();
        config(['campus.identity.privileged_without_mfa' => true]);
        $this->assertNull($shared->fresh()->withdrawn_at);

        // Retrait : le fichier n'est plus servi aux apprenants ni à l'enseignant ; tous les signalements ouverts sont clos ; décision journalisée.
        $this->as('admin-a', 'A')->post('/pieces-jointes/signalements/'.$report->id.'/decision', ['decision' => 'upheld', 'note' => 'Retrait fictif motivé'])->assertSessionHasNoErrors();
        $this->as('admin-a', 'A')->post('/pieces-jointes/signalements/'.$report->id.'/decision', ['decision' => 'dismissed'])->assertSessionHasNoErrors();
        $this->assertNotNull($shared->fresh()->withdrawn_at);
        $this->assertSame(['upheld', 'upheld'], AttachmentReport::orderBy('id')->pluck('status')->all());
        foreach (['apprenant-double', 'polyvalent'] as $name) {
            $this->download($name, $shared)->assertNotFound();
        }
        $this->download('enseignant-a', $shared, 'A')->assertNotFound();
        $this->as('polyvalent')->get('/taches?statut=all')->assertInertia(fn (Assert $page) => $page->where('undated.0.attachments', []));
        $this->as('enseignant-a', 'A')->get('/taches/devoirs')->assertInertia(fn (Assert $page) => $page->where('homework.0.attachments.0.withdrawn', true)->where('homework.0.attachments.0.downloadable', false));
        $audit = AuditLog::where('action', 'attachment.report.upheld')->sole();
        $this->assertSame([$this->user('admin-a')->id, 'Retrait fictif motivé'], [$audit->actor_id, $audit->reason]);
        $this->assertEquals(['attachment_id' => $shared->id], $audit->metadata);
        try {
            DB::table('attachment_reports')->insert(['attachment_id' => $shared->id, 'reporter_id' => $this->user('polyvalent')->id, 'institution_id' => $homework->institution_id, 'reason' => 'x', 'status' => 'open', 'open_slot' => null]);
            $this->fail('MySQL a accepté un signalement ouvert incohérent.');
        } catch (QueryException $error) {
            $this->assertSame(3819, (int) $error->errorInfo[1]);
        }

        // Classement sans suite : le fichier reste disponible, seul ce signalement est clos.
        $second = $this->attach('enseignant-a', $homework, $this->file('second document fictif.pdf', $this->pdf()), ['visibility' => 'group'], 'A');
        $this->as('polyvalent')->post('/pieces-jointes/'.$second->id.'/signalement', ['reason' => 'Signalement fictif infondé'])->assertSessionHasNoErrors();
        $this->as('admin-a', 'A')->post('/pieces-jointes/signalements/'.AttachmentReport::where('attachment_id', $second->id)->sole()->id.'/decision', ['decision' => 'dismissed'])->assertSessionHasNoErrors();
        $this->assertSame(['dismissed', null], [AttachmentReport::where('attachment_id', $second->id)->sole()->status, $second->fresh()->withdrawn_at]);
        $this->download('polyvalent', $second)->assertOk();
    }

    public function test_n14_04_deletion_and_maintenance_remove_files_orphans_and_old_rows_only(): void
    {
        $this->useWorkingScanner();
        $task = $this->task();
        $kept = $this->attach('independant', $task, $this->file('conservé.pdf', $this->pdf()));
        $deleted = $this->attach('independant', $task, $this->file('supprimé.pdf', $this->pdf()));
        $disk = Storage::disk('local');

        $this->as('editeur')->delete('/pieces-jointes/'.$deleted->id)->assertNotFound();
        $this->as('independant')->deleteJson('/pieces-jointes/'.$deleted->id)->assertOk()->assertJson(['deleted' => true]);
        $this->assertSame([false, true], [$disk->exists($deleted->path()), $disk->exists($kept->path())]);
        $this->download('independant', $deleted)->assertNotFound();
        $this->assertNotNull($deleted->fresh()->file_removed_at);
        $this->as('independant')->get('/taches?statut=all')->assertInertia(fn (Assert $page) => $page->has('undated.0.attachments', 1)->where('undated.0.attachments.0.name', 'conservé.pdf'));

        // Orphelins : un fichier sans ligne, ancien, est supprimé ; un fichier sans ligne récent (dépôt en cours) et un fichier rattaché sont conservés.
        $disk->put('attachments/'.str_repeat('a', 64), 'orphelin fictif ancien');
        $disk->put('attachments/'.str_repeat('b', 64), 'orphelin fictif récent');
        touch($disk->path('attachments/'.str_repeat('a', 64)), now()->subDays(2)->getTimestamp());
        touch($disk->path($kept->path()), now()->subDays(2)->getTimestamp());
        // Fichier d'une ligne supprimée resté sur disque (arrêt entre les deux écritures).
        $leftover = $this->attach('independant', $task, $this->file('reste.pdf', $this->pdf()));
        $leftover->forceFill(['deleted_at' => now()])->save();
        $this->artisan('campus:attachments:maintain')->expectsOutputToContain('fichiers retirés=1, lignes purgées=0, orphelins supprimés=1')->assertSuccessful();
        $this->assertSame([false, true, true, false], [$disk->exists('attachments/'.str_repeat('a', 64)), $disk->exists('attachments/'.str_repeat('b', 64)), $disk->exists($kept->path()), $disk->exists($leftover->path())]);

        // Les lignes supprimées depuis plus de trente jours sont purgées ; les autres, et les fichiers actifs, restent.
        $this->travel(31)->days();
        $this->artisan('campus:attachments:maintain')->expectsOutputToContain('lignes purgées=2')->assertSuccessful();
        $this->assertSame([$kept->id], Attachment::pluck('id')->all());
        $this->assertTrue($disk->exists($kept->path()));
        $this->download('independant', $kept)->assertOk();
        $this->assertSame($kept->size_bytes, app(Attachments::class)->usage($this->user('independant')));
    }
}
