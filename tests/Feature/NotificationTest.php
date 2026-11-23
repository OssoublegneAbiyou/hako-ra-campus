<?php

namespace Tests\Feature;

use App\Jobs\RunNotificationCycle;
use App\Models\Assessment;
use App\Models\CourseOccurrence;
use App\Models\CourseSeries;
use App\Models\DomainEvent;
use App\Models\Group;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Period;
use App\Models\PushSubscription;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\CampusReminder;
use App\Services\ActiveContext;
use App\Services\NotificationDispatcher;
use App\Services\NotificationPlanner;
use App\Support\Vapid;
use App\Support\WebPush;
use Carbon\CarbonImmutable;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abonnement-fictif-';

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-11-16 08:00');
        $this->seed(CampusDemoSeeder::class);
        User::query()->update(['email_verified_at' => now()]);
        config(['campus.identity.privileged_without_mfa' => true]);
        Notification::fake();
        Http::preventStrayRequests();
        DB::table('notification_cursors')->delete();
    }

    private function at(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
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

    /** Un cycle complet, tel que la file l'exécute chaque minute. */
    private function cycle(): array
    {
        return (new RunNotificationCycle)->handle(app(NotificationPlanner::class), app(NotificationDispatcher::class));
    }

    private function rules(array $overrides = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'title' => 'Révision fictive', 'kind' => 'once', 'valid_from' => '2026-11-16', 'valid_until' => '2026-11-16',
            'start_time' => '09:00', 'end_time' => '10:00', 'timezone' => 'Africa/Abidjan', 'weekdays' => [1], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [], 'group_ids' => []], $overrides);
    }

    /** Événement personnel du lundi 16 novembre, de 9 h à 10 h par défaut. */
    private function personalCourse(array $overrides = [], string $owner = 'independant'): CourseOccurrence
    {
        $data = $this->rules($overrides);
        $this->as($owner)->post('/planning/series', $data)->assertSessionHasNoErrors();

        return CourseSeries::where('request_key', $data['request_key'])->firstOrFail()->occurrences()->orderBy('original_date')->firstOrFail();
    }

    /** Cours hebdomadaire de A-G1, le lundi de 9 h à 10 h, publié. */
    private function publishedCourse(): CourseOccurrence
    {
        $teacher = $this->member('enseignant-a');
        $data = $this->rules(['title' => 'Méthodologie fictive publiée', 'kind' => 'weekly', 'valid_until' => '2026-12-31',
            'group_ids' => [Group::where('code', 'A-G1')->firstOrFail()->id], 'subject_id' => Subject::where('institution_id', $teacher->institution_id)->firstOrFail()->id, 'teacher_membership_id' => $teacher->id]);
        $this->as('admin-a', 'A')->post('/planning/series', $data)->assertSessionHasNoErrors();
        $this->publishSchedule();

        return CourseSeries::where('request_key', $data['request_key'])->firstOrFail()->occurrences()->orderBy('original_date')->firstOrFail();
    }

    private function publishSchedule(): void
    {
        $props = $this->as('admin-a', 'A')->get('/planning/publication')->viewData('page')['props'];
        $this->as('admin-a', 'A')->post('/planning/publication', ['request_key' => (string) Str::uuid(), 'expected_version' => $props['current']['version'] ?? 0, 'preview_hash' => $props['preview']['hash'], 'reason' => 'Publication fictive'])->assertSessionHasNoErrors();
    }

    private function task(string $owner, string $dueAt, string $title = 'Tâche fictive à rappeler'): Task
    {
        $key = (string) Str::uuid();
        $this->as($owner)->post('/taches', ['client_key' => $key, 'title' => $title, 'due_at' => $dueAt])->assertSessionHasNoErrors();
        // Tâche réputée créée la veille : un rappel dont l'heure est antérieure à la création de la tâche n'est, à juste titre, jamais émis.
        Task::where('client_key', $key)->update(['created_at' => now()->subDay()]);

        return Task::where('client_key', $key)->firstOrFail();
    }

    private function homework(string $dueAt = '2026-11-25T18:00'): Task
    {
        $key = (string) Str::uuid();
        $this->as('enseignant-a', 'A')->post('/taches/devoirs', ['client_key' => $key, 'group_id' => Group::where('code', 'A-G1')->firstOrFail()->id,
            'subject_id' => Subject::where('institution_id', $this->member('enseignant-a')->institution_id)->firstOrFail()->id, 'title' => 'Devoir fictif confidentiel', 'due_at' => $dueAt, 'priority' => 'normal'])->assertSessionHasNoErrors();

        return Task::where('client_key', $key)->firstOrFail();
    }

    private function preferences(string $name, array $attributes): NotificationPreference
    {
        return NotificationPreference::updateOrCreate(['user_id' => $this->user($name)->id], [...$attributes, 'lock_version' => 1]);
    }

    private function enableEmail(string $name, string $category): void
    {
        config(['campus.notifications.email_enabled' => true]);
        $this->preferences($name, ['categories' => [$category => ['enabled' => true, 'email' => true, 'push' => false]]]);
    }

    private function enablePush(string $name, string $category): PushSubscription
    {
        config(['campus.notifications.push_private_key' => Vapid::generate(), 'campus.notifications.push_subject' => 'mailto:exploitant@example.test']);
        $this->preferences($name, ['categories' => [$category => ['enabled' => true, 'email' => false, 'push' => true]]]);
        $this->as($name)->post('/notifications/appareils', ['endpoint' => self::ENDPOINT.$name])->assertSessionHasNoErrors();

        return PushSubscription::where('user_id', $this->user($name)->id)->sole();
    }

    private function notifications(string $name, ?string $type = null)
    {
        return UserNotification::where('user_id', $this->user($name)->id)->when($type, fn ($query) => $query->where('type', $type))->orderBy('id')->get();
    }

    private function center(string $name): array
    {
        return $this->as($name)->get('/notifications')->assertOk()->viewData('page')['props']['notifications'];
    }

    public function test_r21_a_reminder_is_computed_by_the_server_five_minutes_after_the_course_end_or_at_the_chosen_delay(): void
    {
        $course = $this->personalCourse();
        $later = $this->personalCourse(['title' => 'Cours fictif réglé à trente minutes'], 'editeur');
        $this->preferences('editeur', ['course_end_delay_minutes' => 30]);
        $institutional = $this->publishedCourse();
        DomainEvent::query()->update(['processed_at' => now()]);

        // Avant la fin du cours : rien. Aucun onglet n'est ouvert, seul le cycle de la file s'exécute.
        $this->at('2026-11-16 09:59');
        $this->cycle();
        $this->assertSame(0, UserNotification::where('type', 'course.checkin')->count());

        $this->at('2026-11-16 10:01');
        $this->cycle();
        $mine = $this->notifications('independant', 'course.checkin')->sole();
        $this->assertSame(['scheduled', '2026-11-16 10:05:00', '2026-11-16 16:05:00', $course->id, 'course_checkin', '/planning/occurrences/'.$course->id.'/retour'],
            [$mine->status, $mine->scheduled_for->format('Y-m-d H:i:s'), $mine->expires_at->format('Y-m-d H:i:s'), $mine->subject_uuid, $mine->category, $mine->url]);
        $this->assertSame('2026-11-16 10:30:00', $this->notifications('editeur', 'course.checkin')->sole()->scheduled_for->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->center('independant'));
        $this->as('independant')->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.unreadNotifications', 0));

        $this->at('2026-11-16 10:05');
        $this->cycle();
        $this->assertSame(['delivered', '2026-11-16 10:05:00'], [$mine->fresh()->status, $mine->fresh()->delivered_at->format('Y-m-d H:i:s')]);
        $this->assertSame('scheduled', $this->notifications('editeur', 'course.checkin')->sole()->status);
        $center = $this->center('independant');
        $this->assertSame(['Un cours vient de se terminer', 'Retour après un cours', false], [$center[0]['title'], $center[0]['categoryLabel'], $center[0]['read']]);
        $this->as('independant')->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.unreadNotifications', 1));
        $this->freshDevice()->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.unreadNotifications', null));

        // Cours publié : les apprenants inscrits au groupe, pas l'enseignant ni l'administration.
        $this->assertSame(1, $this->notifications('apprenant-double', 'course.checkin')->where('subject_uuid', $institutional->id)->count());
        $this->assertSame(1, $this->notifications('polyvalent', 'course.checkin')->count());
        $this->assertSame([0, 0], [$this->notifications('enseignant-a', 'course.checkin')->count(), $this->notifications('admin-a', 'course.checkin')->count()]);

        // Ouvrir la notification la marque comme lue et mène à la page du retour ; un tiers ne peut pas l'ouvrir.
        $this->as('editeur')->post('/notifications/'.$mine->id.'/lecture')->assertNotFound();
        $this->as('independant')->post('/notifications/'.$mine->id.'/lecture')->assertRedirect('/planning/occurrences/'.$course->id.'/retour');
        $this->assertNotNull($mine->fresh()->read_at);
        $this->at('2026-11-16 10:30');
        $this->cycle();
        $this->assertSame('delivered', $this->notifications('editeur', 'course.checkin')->sole()->status);
        $this->assertSame(1, $this->notifications('independant', 'course.checkin')->count());
        $this->assertTrue(collect(Schedule::events())->contains(fn ($event) => $event->description === 'campus-notifications' && $event->expression === '* * * * *'));
    }

    public function test_r18_r22_a_cancelled_rescheduled_or_already_answered_course_produces_no_reminder(): void
    {
        $cancelledBefore = $this->personalCourse(['title' => 'Cours fictif annulé avant']);
        $cancelledAfter = $this->personalCourse(['title' => 'Cours fictif annulé après'], 'editeur');
        $answered = $this->personalCourse(['title' => 'Cours fictif renseigné', 'start_time' => '07:00', 'end_time' => '10:00'], 'plateforme');
        $published = $this->publishedCourse();
        $this->as('independant')->patch('/planning/occurrences/'.$cancelledBefore->id, ['lock_version' => 1, 'action' => 'cancel', 'reason' => 'Annulation fictive'])->assertSessionHasNoErrors();

        $this->at('2026-11-16 10:01');
        $this->cycle();
        // R18 : cours annulé avant sa fin, aucun rappel n'est même créé.
        $this->assertSame(0, $this->notifications('independant', 'course.checkin')->count());
        $this->assertSame(['scheduled', 'scheduled'], [$this->notifications('editeur', 'course.checkin')->sole()->status, $this->notifications('plateforme', 'course.checkin')->sole()->status]);

        // Annulation, retour renseigné et report surviennent après la programmation, avant l'échéance du rappel.
        $this->as('editeur')->patch('/planning/occurrences/'.$cancelledAfter->id, ['lock_version' => 1, 'action' => 'cancel', 'reason' => 'Annulation fictive'])->assertSessionHasNoErrors();
        $this->as('plateforme')->put('/planning/occurrences/'.$answered->id.'/retour', ['action' => 'nothing', 'expected_version' => 0])->assertSessionHasNoErrors();
        $this->as('admin-a', 'A')->patch('/planning/occurrences/'.$published->id, ['lock_version' => $published->fresh()->lock_version, 'action' => 'reschedule', 'date' => '2026-11-17', 'start_time' => '14:00', 'end_time' => '15:00', 'reason' => 'Report fictif'])->assertSessionHasNoErrors();
        $this->publishSchedule();
        $this->at('2026-11-16 10:06');
        $this->cycle();
        $this->assertSame(['cancelled', 'course_cancelled'], [$this->notifications('editeur', 'course.checkin')->sole()->status, $this->notifications('editeur', 'course.checkin')->sole()->status_reason]);
        // R22 : l'événement « retour enregistré » annule le rappel programmé.
        $this->assertSame(['cancelled', 'settled'], [$this->notifications('plateforme', 'course.checkin')->sole()->status, $this->notifications('plateforme', 'course.checkin')->sole()->status_reason]);
        $old = $this->notifications('apprenant-double', 'course.checkin')->sole();
        $this->assertSame(['cancelled', 'rescheduled'], [$old->status, $old->status_reason]);
        $this->assertSame([[], [], []], [$this->center('editeur'), $this->center('plateforme'), collect($this->center('apprenant-double'))->where('category', 'course_checkin')->all()]);

        // Le cours reporté produit son propre rappel à sa nouvelle fin, une seule fois.
        $this->at('2026-11-17 15:06');
        $this->cycle();
        $this->cycle();
        $new = $this->notifications('apprenant-double', 'course.checkin')->where('status', 'delivered');
        $this->assertSame([1, '2026-11-17 15:00:00'], [$new->count(), $new->first()->anchor_at->format('Y-m-d H:i:s')]);
        // R22 après remise : renseigner le retour retire le rappel devenu inutile du centre.
        $this->as('apprenant-double')->put('/planning/occurrences/'.$published->id.'/retour', ['action' => 'save', 'expected_version' => 0, 'summary' => 'Retour fictif'])->assertSessionHasNoErrors();
        $this->cycle();
        $this->assertSame(['cancelled', 'settled'], [$new->first()->fresh()->status, $new->first()->fresh()->status_reason]);
        $this->assertSame([], collect($this->center('apprenant-double'))->where('category', 'course_checkin')->all());
        $this->assertSame('delivered', $this->notifications('polyvalent', 'course.checkin')->last()->status);
    }

    public function test_r25_task_reminders_follow_the_user_timezone_and_stop_when_the_task_is_done_or_moved(): void
    {
        // Échéance vendredi 20 à 18 h (Abidjan = UTC) : rappel la veille (jeudi 8 h) et le jour même (vendredi 8 h).
        $task = $this->task('independant', '2026-11-20T18:00');
        // Même échéance vue de Tokyo : samedi 21 à 3 h. La veille à 8 h, heure de Tokyo = jeudi 19 à 23 h UTC ; le jour même à 8 h serait après l'échéance.
        $tokyo = $this->task('editeur', '2026-11-20T18:00', 'Tâche fictive vue de Tokyo');
        $this->preferences('editeur', ['timezone' => 'Asia/Tokyo']);
        $moved = $this->task('plateforme', '2026-11-20T18:00', 'Tâche fictive déplacée');
        $done = $this->task('polyvalent', '2026-11-20T18:00', 'Tâche fictive terminée');
        $off = $this->task('admin-a', '2026-11-20T18:00', 'Tâche fictive sans rappel la veille');
        $this->preferences('admin-a', ['task_day_before' => false, 'task_reminder_time' => '06:30:00']);
        DomainEvent::query()->update(['processed_at' => now()]);

        $this->at('2026-11-19 07:59');
        $this->cycle();
        $this->assertSame(0, UserNotification::where('category', 'task_due')->count());

        $this->at('2026-11-19 08:00');
        $planned = app(NotificationPlanner::class)->run();
        $this->assertSame(3, $planned['created']);
        $this->assertSame(['task.due.d1', 'scheduled', '2026-11-19 08:00:00', '2026-11-19 20:00:00'], array_values($this->notifications('independant')->sole()->only(['type', 'status']))
            + [2 => $this->notifications('independant')->sole()->scheduled_for->format('Y-m-d H:i:s'), 3 => $this->notifications('independant')->sole()->expires_at->format('Y-m-d H:i:s')]);
        $this->assertSame([0, 0], [$this->notifications('editeur')->count(), $this->notifications('admin-a')->count()]);
        // Entre la programmation et la remise : une tâche est terminée, une autre voit son échéance déplacée.
        $this->as('polyvalent')->patch('/taches/'.$done->id, ['expected_version' => 1, 'title' => $done->title, 'priority' => 'normal', 'status' => 'done', 'due_at' => '2026-11-20T18:00'])->assertSessionHasNoErrors();
        $this->as('plateforme')->patch('/taches/'.$moved->id, ['expected_version' => 1, 'title' => $moved->title, 'priority' => 'normal', 'status' => 'todo', 'due_at' => '2026-11-27T18:00'])->assertSessionHasNoErrors();
        app(NotificationDispatcher::class)->release();
        $this->assertSame('delivered', $this->notifications('independant')->sole()->status);
        $this->assertSame(['cancelled', 'settled'], array_values($this->notifications('polyvalent')->sole()->only(['status', 'status_reason'])));
        $this->assertSame(['cancelled', 'rescheduled'], array_values($this->notifications('plateforme')->sole()->only(['status', 'status_reason'])));
        $this->assertSame('Tâche : Tâche fictive à rappeler', $this->center('independant')[0]['body']);

        // 23 h UTC = 8 h à Tokyo : le rappel « veille » de l'utilisateur de Tokyo, calculé dans son fuseau.
        $this->at('2026-11-19 23:00');
        $this->cycle();
        $this->assertSame(['task.due.d1', 'delivered', '2026-11-19 23:00:00'], [$this->notifications('editeur')->sole()->type, $this->notifications('editeur')->sole()->status, $this->notifications('editeur')->sole()->scheduled_for->format('Y-m-d H:i:s')]);

        // Jour J : 6 h 30 pour celui qui a choisi cette heure, 8 h pour les autres ; rien pour la tâche terminée ni pour Tokyo (8 h locale après l'échéance).
        $this->at('2026-11-20 06:30');
        $this->cycle();
        $this->assertSame(['task.due.d0'], $this->notifications('admin-a')->pluck('type')->all());
        $this->at('2026-11-20 08:00');
        $this->cycle();
        $this->cycle();
        $this->assertSame(['task.due.d1', 'task.due.d0'], $this->notifications('independant')->pluck('type')->all());
        $this->assertSame([1, 1, 1], [$this->notifications('polyvalent')->count(), $this->notifications('editeur')->count(), $this->notifications('plateforme')->count()]);

        // Terminer après la remise : le rappel disparaît du centre (événement task.settled).
        $this->as('independant')->patch('/taches/'.$task->id, ['expected_version' => 1, 'title' => $task->title, 'priority' => 'normal', 'status' => 'done', 'due_at' => '2026-11-20T18:00'])->assertSessionHasNoErrors();
        $this->cycle();
        $this->assertSame(['cancelled', 'cancelled'], $this->notifications('independant')->pluck('status')->all());
        $this->assertSame([], $this->center('independant'));
        $this->assertNotNull($tokyo->fresh());
        $this->assertNotNull($off->fresh());
    }

    public function test_r43_a_replayed_or_interrupted_worker_never_creates_or_sends_a_second_notification(): void
    {
        $this->enableEmail('independant', 'task_due');
        $task = $this->task('independant', '2026-11-17T18:00');
        $this->at('2026-11-16 08:01');
        // Le cycle rejoué plusieurs fois (reprise de la file, double déclenchement) : un rappel, un envoi, un courriel.
        $first = $this->cycle();
        $this->cycle();
        $this->cycle();
        $this->assertSame(1, $first['planned']['created']);
        $notification = $this->notifications('independant')->sole();
        $delivery = NotificationDelivery::sole();
        $this->assertSame(['delivered', 'email', 'email', 'accepted', 1], [$notification->status, $delivery->channel, $delivery->target_key, $delivery->status, $delivery->attempts]);
        Notification::assertSentToTimes($this->user('independant'), CampusReminder::class, 1);
        // Le courriel ne porte qu'un titre neutre et un lien : jamais l'intitulé de la tâche.
        Notification::assertSentTo($this->user('independant'), CampusReminder::class, function (CampusReminder $mail) use ($task) {
            $text = json_encode($mail->toMail($this->user('independant'))->toArray(), JSON_UNESCAPED_UNICODE);

            return $mail->title === 'Une tâche arrive à échéance demain' && str_ends_with($mail->url, '/taches') && ! str_contains($text, $task->title) && ! str_contains($text, 'fictive');
        });

        // Les clés uniques refusent tout doublon, même par insertion directe.
        foreach ([['user_notifications', ['user_id' => $notification->user_id, 'category' => 'task_due', 'type' => 'task.due.d1', 'title' => 'x', 'url' => '/taches', 'dedup_key' => $notification->dedup_key, 'scheduled_for' => now(), 'expires_at' => now()]],
            ['notification_deliveries', ['user_notification_id' => $notification->id, 'channel' => 'email', 'target_key' => 'email', 'next_attempt_at' => now()]]] as [$table, $row]) {
            try {
                DB::table($table)->insert($row);
                $this->fail('MySQL a accepté un doublon dans '.$table);
            } catch (QueryException $error) {
                $this->assertSame(1062, (int) $error->errorInfo[1]);
            }
        }

        // Interruption après réservation de l'envoi : le rejeu ne le reprend pas ; passé le délai, il est tracé « indéterminé », jamais renvoyé.
        $second = $this->task('independant', '2026-11-17T19:00', 'Seconde tâche fictive');
        app(NotificationPlanner::class)->run();
        app(NotificationDispatcher::class)->release();
        $stuck = NotificationDelivery::where('status', 'queued')->sole();
        $stuck->forceFill(['status' => 'processing', 'claimed_at' => now(), 'attempts' => 1])->save();
        $this->cycle();
        $this->assertSame('processing', $stuck->fresh()->status);
        $this->at('2026-11-16 08:12');
        $this->cycle();
        $this->cycle();
        $this->assertSame(['uncertain', 'worker_interrupted', 1], [$stuck->fresh()->status, $stuck->fresh()->last_error, $stuck->fresh()->attempts]);
        Notification::assertSentToTimes($this->user('independant'), CampusReminder::class, 1);
        $this->assertSame(2, count($this->center('independant')));
        $this->assertNotNull($second->fresh());

        // Boîte d'événements : un événement écrit pendant l'arrêt du traitement est repris au cycle suivant, une seule fois.
        $homework = $this->homework();
        $event = DomainEvent::where('type', 'homework.published')->sole();
        $this->assertNull($event->processed_at);
        $this->cycle();
        $this->cycle();
        $this->assertNotNull($event->fresh()->processed_at);
        $this->assertSame([1, 1, 0], [$this->notifications('apprenant-double', 'homework.published')->count(), $this->notifications('polyvalent', 'homework.published')->count(), $this->notifications('enseignant-a')->count()]);
        $this->assertSame(0, DomainEvent::whereNull('processed_at')->count());
        // Rejouer l'événement lui-même (traitement interrompu avant d'être marqué) ne recrée rien.
        DomainEvent::whereKey($event->id)->update(['processed_at' => null]);
        $this->assertSame(['events' => 1, 'created' => 0], app(NotificationPlanner::class)->consumeEvents(now()->toImmutable()));
        $this->assertSame('/taches', $this->notifications('apprenant-double', 'homework.published')->sole()->url);
        $this->assertStringNotContainsString('confidentiel', json_encode($this->notifications('apprenant-double')->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($homework->fresh());
    }

    public function test_r44_refusing_browser_notifications_blocks_nothing_and_push_carries_no_content(): void
    {
        // Canal non configuré : l'inscription d'un appareil n'existe pas, l'écran le dit, tout le reste fonctionne.
        $this->as('independant')->post('/notifications/appareils', ['endpoint' => self::ENDPOINT.'x'])->assertNotFound();
        $this->as('independant')->get('/notifications/preferences')->assertOk()->assertInertia(fn (Assert $page) => $page->component('NotificationSettings')
            ->where('channels.push', false)->where('channels.email', false)->where('channels.pushPublicKey', null)->has('categories', 8)->where('categories.0.mandatory', true)->where('devices', []));

        // L'utilisateur veut le push mais l'a refusé sur son téléphone (aucun appareil) : le centre interne reçoit quand même le rappel.
        config(['campus.notifications.push_private_key' => Vapid::generate(), 'campus.notifications.push_subject' => 'mailto:exploitant@example.test']);
        $this->preferences('independant', ['categories' => ['task_due' => ['enabled' => true, 'email' => false, 'push' => true]]]);
        $this->task('independant', '2026-11-17T18:00');
        $this->at('2026-11-16 08:01');
        $this->cycle();
        $this->assertSame([1, 0], [count($this->center('independant')), NotificationDelivery::count()]);
        foreach (['/', '/planning', '/taches', '/notes', '/notifications', '/notifications/preferences', '/profil'] as $url) {
            $this->as('independant')->get($url)->assertOk();
        }
        $this->as('independant')->get('/notifications/preferences')->assertInertia(fn (Assert $page) => $page->where('channels.push', true)
            ->where('channels.pushPublicKey', fn (string $key) => strlen($key) === 87 && ! str_contains($key, 'PRIVATE')));

        // Seul un service de push reconnu, en HTTPS, est accepté : aucune adresse arbitraire n'est jamais appelée par le serveur.
        foreach (['https://exemple-interne.test/push', 'http://fcm.googleapis.com/fcm/send/x', 'https://fcm.googleapis.com.exemple.test/x', 'https://utilisateur@fcm.googleapis.com/x',
            'https://fcm.googleapis.com:8443/x', 'https://127.0.0.1/x', 'https://notify.windows.com/x', 'fichier'] as $refused) {
            $this->assertFalse(WebPush::allowed($refused), $refused);
            $this->as('independant')->post('/notifications/appareils', ['endpoint' => $refused])->assertSessionHasErrors('endpoint');
        }
        $this->assertTrue(WebPush::allowed('https://db5p.notify.windows.com/w/?token=fictif'));
        $this->assertTrue(WebPush::allowed('https://updates.push.services.mozilla.com/wpush/v2/fictif'));
        $this->assertSame(0, PushSubscription::count());

        // Appareil activé : signal accepté, sans aucun contenu, avec identification du serveur.
        $this->as('independant')->post('/notifications/appareils', ['endpoint' => self::ENDPOINT.'un'])->assertSessionHasNoErrors();
        $this->as('independant')->post('/notifications/appareils', ['endpoint' => self::ENDPOINT.'un'])->assertSessionHasNoErrors();
        $device = PushSubscription::sole();
        $this->task('independant', '2026-11-17T19:00', 'Tâche fictive très privée');
        Http::fake([self::ENDPOINT.'*' => Http::response('', 201)]);
        $this->cycle();
        $this->cycle();
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::ENDPOINT.'un' && $request->method() === 'POST' && $request->body() === ''
            && str_starts_with($request->header('Authorization')[0], 'vapid t=') && (int) $request->header('TTL')[0] > 0 && strlen($request->header('Topic')[0]) === 32);
        $this->assertSame(['accepted', 1], [NotificationDelivery::sole()->status, NotificationDelivery::sole()->attempts]);
        $this->assertNotNull($device->fresh()->last_accepted_at);
        $this->assertStringNotContainsString('fcm.googleapis.com', json_encode($this->as('independant')->get('/notifications/preferences')->viewData('page')['props']));

        // Un autre compte ne retire pas cet appareil ; son propriétaire le peut, et plus rien ne part ensuite.
        $this->as('editeur')->delete('/notifications/appareils/'.$device->id)->assertNotFound();
        $this->as('independant')->delete('/notifications/appareils/'.$device->id)->assertSessionHasNoErrors();
        $this->task('independant', '2026-11-17T20:00', 'Troisième tâche fictive');
        $this->cycle();
        Http::assertSentCount(1);
        $this->assertSame([3, 'user'], [count($this->center('independant')), $device->fresh()->revoked_reason]);
        // Le service worker unique (lot 19) porte le traitement du push : message générique, sans jamais lire un contenu reçu.
        $worker = (string) file_get_contents(public_path('sw.js'));
        $push = substr($worker, (int) strpos($worker, "addEventListener('push'"), (int) strpos($worker, "addEventListener('notificationclick'") - (int) strpos($worker, "addEventListener('push'"));
        $this->assertStringContainsString('showNotification', $push);
        $this->assertStringNotContainsString('event.data', $push);
        $this->assertFileDoesNotExist(public_path('push-sw.js'));
    }

    public function test_n13_01_push_failures_are_bounded_invalid_endpoints_are_revoked_and_a_timeout_stays_uncertain(): void
    {
        $gone = $this->enablePush('independant', 'task_due');
        $flaky = $this->enablePush('editeur', 'task_due');
        $silent = $this->enablePush('plateforme', 'task_due');
        foreach (['independant', 'editeur', 'plateforme'] as $name) {
            $this->task($name, '2026-11-17T18:00');
        }
        Http::fake([
            self::ENDPOINT.'independant' => Http::response('', 410),
            self::ENDPOINT.'editeur' => Http::response('', 503),
            self::ENDPOINT.'plateforme' => fn () => throw new ConnectionException('Délai dépassé fictif'),
        ]);
        $this->at('2026-11-16 08:01');
        $this->cycle();
        $of = fn (PushSubscription $subscription) => NotificationDelivery::where('push_subscription_id', $subscription->id)->sole();

        // 410 : abonnement révoqué, plus jamais appelé.
        $this->assertSame(['failed', 'push_410', 'endpoint_gone'], [$of($gone)->status, $of($gone)->last_error, $gone->fresh()->revoked_reason]);
        // Aucune réponse : la livraison est incertaine, tracée, et n'est pas renvoyée à l'aveugle.
        $this->assertSame(['uncertain', 'push_no_response', 1], [$of($silent)->status, $of($silent)->last_error, $of($silent)->attempts]);
        // 503 : reprises à délais croissants (1, 5, 15 minutes), bornées à quatre tentatives, puis échec.
        $this->assertSame(['queued', 1, '2026-11-16 08:02:00'], [$of($flaky)->status, $of($flaky)->attempts, $of($flaky)->next_attempt_at->format('Y-m-d H:i:s')]);
        $this->cycle();
        $this->assertSame(1, $of($flaky)->attempts);
        foreach (['2026-11-16 08:02' => [2, 'queued', '2026-11-16 08:07:00'], '2026-11-16 08:07' => [3, 'queued', '2026-11-16 08:22:00'], '2026-11-16 08:22' => [4, 'failed', '2026-11-16 08:22:00']] as $time => [$attempts, $status, $next]) {
            $this->at($time);
            $this->cycle();
            $this->assertSame([$attempts, $status, $next], [$of($flaky)->attempts, $of($flaky)->status, $of($flaky)->next_attempt_at->format('Y-m-d H:i:s')]);
        }
        $this->assertSame('retries_exhausted', $of($flaky)->last_error);
        $this->at('2026-11-16 09:30');
        $this->cycle();
        // 1 (410) + 4 (503) : aucun autre appel, quel que soit le nombre de cycles. L'appel resté sans réponse n'est pas compté par le simulateur ; il n'a eu lieu qu'une fois (une seule tentative ci-dessus).
        Http::assertSentCount(5);
        $this->assertSame([1, 1, 1], [count($this->center('independant')), count($this->center('editeur')), count($this->center('plateforme'))]);
        $this->as('editeur')->get('/notifications/preferences')->assertInertia(fn (Assert $page) => $page->where('recentDeliveries.0.status', 'Échec')->where('recentDeliveries.0.channel', 'push'));
        // Les journaux d'envoi ne contiennent ni adresse d'abonnement ni contenu.
        $this->assertStringNotContainsString('abonnement-fictif', json_encode(NotificationDelivery::all()->toArray()));
    }

    public function test_r53_after_a_long_outage_stale_reminders_expire_and_useful_ones_are_kept_without_any_burst(): void
    {
        $this->enableEmail('apprenant-double', 'homework');
        $this->personalCourse();
        $this->publishedCourse();
        $this->task('independant', '2026-11-17T18:00', 'Tâche fictive échue pendant la panne');
        $useful = $this->task('independant', '2026-11-20T18:00', 'Tâche fictive encore utile');
        $homework = $this->homework('2026-11-20T18:00');
        // Un rappel déjà programmé au moment de la panne.
        $this->at('2026-11-16 10:01');
        app(NotificationPlanner::class)->run();
        $scheduled = $this->notifications('independant', 'course.checkin')->sole();
        $this->assertSame('scheduled', $scheduled->status);
        $before = UserNotification::count();

        // Panne de trois jours : ni planificateur ni file. Reprise le jeudi 19 à 10 h.
        $this->at('2026-11-19 10:00');
        $result = $this->cycle();
        $this->cycle();
        // Le rappel de cours programmé lundi a expiré ; il n'est ni remis ni envoyé.
        $this->assertSame(['expired', 'too_late'], [$scheduled->fresh()->status, $scheduled->fresh()->status_reason]);
        // Les événements de lundi sont consommés (plus rien en attente) mais n'alertent plus personne.
        $this->assertSame(0, DomainEvent::whereNull('processed_at')->count());
        $this->assertSame([], UserNotification::where('created_at', '>=', now()->subMinute())->whereIn('category', ['homework', 'schedule_change'])->get()->map->only(['type', 'user_id', 'dedup_key', 'status'])->all());
        // Aucun rappel de cours pour les lundis, mardis et mercredis manqués.
        $this->assertSame([], UserNotification::where('type', 'course.checkin')->where('created_at', '>=', now()->subMinute())->get()->map(fn ($n) => [$n->user_id, $n->anchor_at->toDateTimeString(), $n->status, $n->created_at->toDateTimeString(), $n->dedup_key])->all());
        // Alertes encore utiles conservées : la veille de l'échéance de vendredi (8 h ce matin, encore valide), pour la tâche et pour le devoir.
        $kept = UserNotification::where('created_at', '>=', now()->subMinute())->get();
        $this->assertEqualsCanonicalizing([[$this->user('independant')->id, $useful->id], [$this->user('apprenant-double')->id, $homework->id], [$this->user('polyvalent')->id, $homework->id]],
            $kept->map(fn ($notification) => [$notification->user_id, $notification->subject_uuid])->all());
        $this->assertSame(['task.due.d1'], $kept->pluck('type')->unique()->values()->all());
        $this->assertSame(['delivered'], $kept->pluck('status')->unique()->values()->all());
        $this->assertSame(3, $result['released']['delivered']);
        // Pas de rafale : trois rappels après trois jours d'arrêt, aucun courriel pour les anciens événements de devoir.
        $this->assertSame($before + 3, UserNotification::count());
        Notification::assertNothingSent();
        $this->assertSame(0, NotificationDelivery::count());
        // Le rappel de la tâche échue pendant la panne avait été programmé lundi : il a expiré, et aucun autre n'a été créé pour elle.
        $this->assertSame(['expired'], $this->notifications('independant')->where('subject_uuid', Task::where('title', 'Tâche fictive échue pendant la panne')->value('id'))->pluck('status')->all());

        // Un envoi resté en file pendant la panne expire au lieu de partir en retard.
        $late = UserNotification::create(['user_id' => $this->user('apprenant-double')->id, 'category' => 'homework', 'type' => 'homework.published', 'title' => 'Nouveau devoir pour votre groupe', 'url' => '/taches',
            'subject_uuid' => $homework->id, 'context' => ['institution_id' => $homework->institution_id, 'academic_year_id' => $homework->academic_year_id, 'group_id' => $homework->group_id],
            'dedup_key' => 'essai-fictif-expire', 'scheduled_for' => '2026-11-16 09:00:00', 'expires_at' => '2026-11-18 09:00:00', 'status' => 'delivered', 'delivered_at' => '2026-11-16 09:00:00']);
        $queued = NotificationDelivery::create(['user_notification_id' => $late->id, 'channel' => 'email', 'target_key' => 'email', 'next_attempt_at' => '2026-11-16 09:00:00']);
        $this->cycle();
        $this->assertSame(['expired', 'too_late', 1], [$queued->fresh()->status, $queued->fresh()->last_error, $queued->fresh()->attempts]);
        Notification::assertNothingSent();
    }

    public function test_n13_02_quiet_hours_in_the_user_timezone_delay_external_channels_only(): void
    {
        // Plage silencieuse 22 h – 7 h à Tokyo (UTC+9) = 13 h – 22 h UTC.
        config(['campus.notifications.email_enabled' => true]);
        $this->preferences('independant', ['timezone' => 'Asia/Tokyo', 'quiet_start' => '22:00:00', 'quiet_end' => '07:00:00', 'task_reminder_time' => '23:00:00',
            'categories' => ['task_due' => ['enabled' => true, 'email' => true, 'push' => false]]]);
        $this->preferences('editeur', ['quiet_start' => '13:00:00', 'quiet_end' => '15:00:00', 'task_reminder_time' => '14:00:00', 'categories' => ['task_due' => ['enabled' => true, 'email' => true, 'push' => false]]]);
        // Échéance mercredi 18 à 12 h UTC (21 h à Tokyo) : rappel « veille » mardi 17 à 23 h de Tokyo = mardi 17 à 14 h UTC.
        $this->task('independant', '2026-11-18T12:00');
        $this->task('editeur', '2026-11-18T18:00');
        $this->task('plateforme', '2026-11-18T18:00');
        $this->preferences('plateforme', ['task_reminder_time' => '14:00:00', 'categories' => ['task_due' => ['enabled' => true, 'email' => true, 'push' => false]]]);

        $this->at('2026-11-17 14:00');
        $this->cycle();
        // Le centre interne reçoit le rappel tout de suite ; le courriel attend la fin de la plage, calculée dans le fuseau de chacun.
        $this->assertSame([1, 1, 1], [count($this->center('independant')), count($this->center('editeur')), count($this->center('plateforme'))]);
        $delivery = fn (string $name) => NotificationDelivery::whereHas('notification', fn ($query) => $query->where('user_id', $this->user($name)->id))->sole();
        $this->assertSame(['queued', '2026-11-17 22:00:00', 0], [$delivery('independant')->status, $delivery('independant')->next_attempt_at->format('Y-m-d H:i:s'), $delivery('independant')->attempts]);
        $this->assertSame(['queued', '2026-11-17 15:00:00'], [$delivery('editeur')->status, $delivery('editeur')->next_attempt_at->format('Y-m-d H:i:s')]);
        // Sans plage silencieuse : envoi immédiat.
        $this->assertSame('accepted', $delivery('plateforme')->status);
        Notification::assertSentToTimes($this->user('plateforme'), CampusReminder::class, 1);
        Notification::assertNotSentTo($this->user('independant'), CampusReminder::class);

        $this->at('2026-11-17 15:00');
        $this->cycle();
        $this->assertSame(['accepted', 1], [$delivery('editeur')->status, $delivery('editeur')->attempts]);
        Notification::assertNotSentTo($this->user('independant'), CampusReminder::class);

        // La plage est élargie entre-temps : au réveil de l'envoi, il est de nouveau différé, sans compter de tentative.
        $this->preferences('independant', ['quiet_end' => '08:00:00']);
        $this->at('2026-11-17 22:00');
        $this->cycle();
        $this->assertSame(['queued', '2026-11-17 23:00:00', 0], [$delivery('independant')->status, $delivery('independant')->next_attempt_at->format('Y-m-d H:i:s'), $delivery('independant')->attempts]);
        $this->at('2026-11-17 23:00');
        $this->cycle();
        $this->assertSame('accepted', $delivery('independant')->status);
        Notification::assertSentToTimes($this->user('independant'), CampusReminder::class, 1);

        // Un rappel dont la validité s'achève pendant la plage silencieuse expire : il ne part pas au réveil.
        $this->preferences('admin-a', ['quiet_start' => '06:00:00', 'quiet_end' => '20:00:00', 'categories' => ['task_due' => ['enabled' => true, 'email' => true, 'push' => false]]]);
        $this->task('admin-a', '2026-11-19T12:00');
        $this->at('2026-11-19 08:00');
        $this->cycle();
        $this->assertSame(1, count($this->center('admin-a')));
        $this->at('2026-11-19 20:00');
        $this->cycle();
        $this->assertSame('expired', $delivery('admin-a')->status);
        Notification::assertNotSentTo($this->user('admin-a'), CampusReminder::class);
    }

    public function test_n13_03_preferences_are_validated_versioned_and_rechecked_just_before_sending(): void
    {
        $payload = ['expected_version' => 0, 'timezone' => 'Africa/Abidjan', 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'course_end_delay' => 15, 'task_day_before' => true, 'task_same_day' => false,
            'task_reminder_time' => '07:30', 'digest_enabled' => true, 'digest_time' => '06:45', 'categories' => ['task_due' => ['enabled' => true, 'email' => true, 'push' => false], 'grade' => ['enabled' => false, 'email' => false, 'push' => false]]];
        foreach ([['timezone' => 'Mars/Olympus'], ['quiet_end' => null], ['quiet_end' => '22:00'], ['course_end_delay' => 121], ['course_end_delay' => -1], ['task_reminder_time' => '25:00'],
            ['categories' => ['account' => ['enabled' => false, 'email' => false, 'push' => false]]], ['categories' => ['inconnue' => ['enabled' => true, 'email' => false, 'push' => false]]]] as $invalid) {
            $this->as('independant')->put('/notifications/preferences', [...$payload, ...$invalid])->assertSessionHasErrors();
        }
        $this->assertSame(0, NotificationPreference::count());
        $this->as('independant')->putJson('/notifications/preferences', $payload)->assertOk()->assertJson(['version' => 1]);
        $this->as('independant')->putJson('/notifications/preferences', $payload)->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);
        $saved = NotificationPreference::sole();
        $this->assertSame([$this->user('independant')->id, '22:00:00', 15, false, '07:30:00', true], [$saved->user_id, $saved->quiet_start, $saved->course_end_delay_minutes, $saved->task_same_day, $saved->task_reminder_time, $saved->digest_enabled]);
        $this->as('independant')->get('/notifications/preferences')->assertInertia(fn (Assert $page) => $page->where('preference.version', 1)->where('preference.courseEndDelay', 15)
            ->where('categories', fn ($categories) => collect($categories)->firstWhere('key', 'grade')['enabled'] === false && collect($categories)->firstWhere('key', 'account')['enabled'] === true));
        $this->assertStringNotContainsString('editeur', json_encode($this->as('independant')->get('/notifications/preferences')->viewData('page')['props']['devices']));
        try {
            DB::table('notification_preferences')->where('id', $saved->id)->update(['quiet_end' => null]);
            $this->fail('MySQL a accepté une plage silencieuse incomplète.');
        } catch (QueryException $error) {
            $this->assertSame(3819, (int) $error->errorInfo[1]);
        }

        // Catégorie désactivée : rien n'est créé ; désactivée entre la programmation et la remise : annulé pour préférence.
        NotificationPreference::query()->delete();
        $this->preferences('independant', ['categories' => ['task_due' => ['enabled' => false, 'email' => false, 'push' => false]]]);
        $this->task('independant', '2026-11-17T18:00');
        $this->task('editeur', '2026-11-17T18:00');
        $this->at('2026-11-16 08:01');
        app(NotificationPlanner::class)->run();
        $this->assertSame([0, 1], [$this->notifications('independant')->count(), $this->notifications('editeur')->count()]);
        $this->preferences('editeur', ['categories' => ['task_due' => ['enabled' => false, 'email' => false, 'push' => false]]]);
        app(NotificationDispatcher::class)->release();
        $this->assertSame(['cancelled', 'preference'], array_values($this->notifications('editeur')->sole()->only(['status', 'status_reason'])));

        // Courriel coupé entre la remise et l'envoi (plage silencieuse) : l'envoi est annulé au recontrôle.
        config(['campus.notifications.email_enabled' => true]);
        $this->preferences('plateforme', ['quiet_start' => '08:00:00', 'quiet_end' => '09:00:00', 'categories' => ['task_due' => ['enabled' => true, 'email' => true, 'push' => false]]]);
        $task = $this->task('plateforme', '2026-11-17T18:00');
        $this->cycle();
        $this->assertSame('queued', NotificationDelivery::sole()->status);
        $this->preferences('plateforme', ['quiet_start' => null, 'quiet_end' => null, 'categories' => ['task_due' => ['enabled' => true, 'email' => false, 'push' => false]]]);
        $this->at('2026-11-16 09:00');
        $this->cycle();
        $this->assertSame(['cancelled', 'preference'], [NotificationDelivery::sole()->status, NotificationDelivery::sole()->last_error]);
        // Tâche terminée entre la remise et l'envoi : même recontrôle, l'envoi et le rappel sont annulés.
        $this->preferences('admin-b', ['quiet_start' => '08:00:00', 'quiet_end' => '10:00:00', 'categories' => ['task_due' => ['enabled' => true, 'email' => true, 'push' => false]]]);
        $other = $this->task('admin-b', '2026-11-17T18:00');
        $this->at('2026-11-16 09:01');
        Task::whereKey($other->id)->update(['created_at' => '2026-11-16 07:00:00']);
        DB::table('notification_cursors')->update(['position' => '2026-11-16 07:00:00']);
        $this->cycle();
        $pending = NotificationDelivery::where('status', 'queued')->sole();
        Task::whereKey($other->id)->update(['status' => 'done']);
        $this->at('2026-11-16 10:00');
        $this->cycle();
        $this->assertSame(['cancelled', 'settled', 'cancelled'], [$pending->fresh()->status, $pending->fresh()->last_error, $pending->notification->fresh()->status]);
        Notification::assertNothingSent();
        $this->assertNotNull($task->fresh());
    }

    public function test_n13_04_schedule_homework_and_grade_events_notify_only_the_people_concerned_without_sensitive_content(): void
    {
        $course = $this->publishedCourse();
        $this->cycle();
        // Première publication (sept cours hebdomadaires) : une seule alerte par personne concernée.
        foreach (['apprenant-double', 'polyvalent', 'enseignant-a'] as $name) {
            $this->assertSame(1, $this->notifications($name, 'schedule.changed')->count(), $name);
        }
        $this->assertSame([0, 0], [$this->notifications('independant')->count(), $this->notifications('admin-b')->count()]);
        $this->assertGreaterThan(3, DomainEvent::where('type', 'schedule.occurrence.changed')->count());

        // Seconde publication, deux cours déplacés : une nouvelle alerte regroupée, pas deux.
        foreach (CourseOccurrence::where('course_series_id', $course->course_series_id)->orderBy('original_date')->skip(1)->take(2)->get() as $index => $occurrence) {
            $this->as('admin-a', 'A')->patch('/planning/occurrences/'.$occurrence->id, ['lock_version' => $occurrence->lock_version, 'action' => 'reschedule', 'date' => $occurrence->original_date->addDay()->toDateString(), 'start_time' => '14:00', 'end_time' => '15:00', 'reason' => 'Report fictif '.$index])->assertSessionHasNoErrors();
        }
        $this->publishSchedule();
        $this->cycle();
        $this->assertSame(2, $this->notifications('apprenant-double', 'schedule.changed')->count());
        $this->assertSame(['/planning?source=published', 'delivered'], array_values($this->notifications('apprenant-double', 'schedule.changed')->last()->only(['url', 'status'])));

        // Devoir puis résultat publié : les apprenants du groupe ; le message ne porte ni intitulé ni note.
        $this->homework();
        $assessmentKey = (string) Str::uuid();
        $this->as('enseignant-a', 'A')->post('/notes/evaluations', ['client_key' => $assessmentKey, 'group_id' => Group::where('code', 'A-G1')->firstOrFail()->id, 'subject_id' => Subject::where('institution_id', $this->member('enseignant-a')->institution_id)->firstOrFail()->id,
            'period_id' => Period::where('institution_id', $this->member('enseignant-a')->institution_id)->firstOrFail()->id, 'title' => 'Contrôle fictif secret', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'])->assertSessionHasNoErrors();
        $assessment = Assessment::where('client_key', $assessmentKey)->firstOrFail();
        $this->as('enseignant-a', 'A')->put('/notes/evaluations/'.$assessment->id.'/resultats', ['expected_version' => 1, 'results' => [['user_id' => $this->user('apprenant-double')->id, 'status' => 'graded', 'value' => '3,75']]])->assertSessionHasNoErrors();
        $this->as('enseignant-a', 'A')->post('/notes/evaluations/'.$assessment->id.'/publication', ['expected_version' => 2])->assertSessionHasNoErrors();
        // Appartenance retirée avant la remise : l'ancien inscrit n'est pas informé.
        $this->member('polyvalent')->revoke();
        $this->cycle();
        $this->assertSame(['Un résultat a été publié', null, '/notes?source=etablissement', 'delivered'], array_values($this->notifications('apprenant-double', 'grade.published')->sole()->only(['title', 'body', 'url', 'status'])));
        $this->assertSame('delivered', $this->notifications('apprenant-double', 'homework.published')->sole()->status);
        $this->assertSame(0, $this->notifications('polyvalent')->whereIn('type', ['grade.published', 'homework.published'])->whereIn('status', ['delivered', 'scheduled'])->count());
        $this->assertSame(0, $this->notifications('enseignant-a')->whereIn('category', ['grade', 'homework'])->count());
        // Correction : seul l'apprenant corrigé est informé, une fois.
        $this->as('enseignant-a', 'A')->put('/notes/evaluations/'.$assessment->id.'/resultats', ['expected_version' => 3, 'reason' => 'Correction fictive', 'results' => [['user_id' => $this->user('apprenant-double')->id, 'status' => 'graded', 'value' => '13']]])->assertSessionHasNoErrors();
        $this->cycle();
        $this->cycle();
        $this->assertSame(1, $this->notifications('apprenant-double', 'grade.corrected')->count());
        $all = json_encode(UserNotification::all()->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['3,75', '3.75', '"13"', 'secret', 'confidentiel'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $all);
        }

        // Synthèse quotidienne facultative : seulement si demandée et s'il y a quelque chose à signaler ; une par jour.
        $this->preferences('apprenant-double', ['digest_enabled' => true, 'digest_time' => '07:00:00']);
        $this->preferences('independant', ['digest_enabled' => true, 'digest_time' => '07:00:00']);
        $this->task('apprenant-double', '2026-11-17T18:00', 'Tâche fictive du jour');
        $this->at('2026-11-17 07:00');
        $this->cycle();
        $this->cycle();
        $digest = $this->notifications('apprenant-double', 'digest.daily')->sole();
        $this->assertSame(['delivered', 'Votre synthèse du jour', '/'], array_values($digest->only(['status', 'title', 'url'])));
        $this->assertStringStartsWith('1 tâche(s) à échéance aujourd’hui', $digest->body);
        $this->assertSame(0, $this->notifications('independant', 'digest.daily')->count());
        $this->as('apprenant-double')->post('/notifications/lecture')->assertSessionHasNoErrors();
        $this->as('apprenant-double')->get('/notifications')->assertInertia(fn (Assert $page) => $page->component('Notifications')->where('unread', 0));
        // Compte suspendu : plus aucune remise.
        $this->task('editeur', '2026-11-18T18:00');
        DB::table('users')->where('id', $this->user('editeur')->id)->update(['status' => 'suspended']);
        $this->at('2026-11-17 08:00');
        $this->cycle();
        $this->assertSame(['cancelled', 'account_inactive'], array_values($this->notifications('editeur')->sole()->only(['status', 'status_reason'])));
        $this->artisan('campus:notifications:run')->expectsOutputToContain('planned : events=0')->assertSuccessful();
    }
}
