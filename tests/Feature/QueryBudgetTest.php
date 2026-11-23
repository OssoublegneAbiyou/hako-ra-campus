<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Membership;
use App\Models\PersonalSubject;
use App\Models\Subject;
use App\Models\User;
use App\Services\ActiveContext;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lot 23 : le nombre de requêtes SQL des écrans fréquents ne doit pas croître avec le nombre de lignes affichées (pas de « N+1 »),
 * et rester sous un plafond. Mesuré sur la base de test, avec des données fictives.
 */
class QueryBudgetTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(8, 0));
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

    private function as(string $name, ?string $letter = null): static
    {
        $membership = $letter === 'P' ? $this->user($name)->memberships()->where('scope', 'platform')->firstOrFail()->id : ($letter ? $this->member($name, $letter)->id : null);

        return $this->freshDevice()->actingAs($this->user($name))->withSession([...($membership ? [ActiveContext::SESSION_KEY => $membership] : []), 'auth.password_confirmed_at' => now()->unix()]);
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2: string}>  $pages
     * @return array<string, int>
     */
    private function queries(array $pages): array
    {
        $counts = [];
        foreach ($pages as [$name, $context, $url]) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->as($name, $context)->get($url)->assertOk();
            $counts[$name.' '.$url] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $counts;
    }

    private function grow(int $from, int $to): void
    {
        $subject = PersonalSubject::firstOrCreate(['owner_id' => $this->user('apprenant-double')->id, 'name' => 'Matière fictive de mesure'], ['code' => 'MES']);
        $teacher = $this->member('enseignant-a');
        $group = Group::where('code', 'A-G1')->firstOrFail()->id;
        $institutionSubject = Subject::where('institution_id', $teacher->institution_id)->firstOrFail()->id;
        $course = fn (int $i, array $extra) => ['request_key' => (string) Str::uuid(), 'title' => 'Cours fictif '.$i, 'kind' => 'once', 'valid_from' => '2026-11-1'.(6 + $i % 4), 'valid_until' => '2026-11-1'.(6 + $i % 4),
            'start_time' => sprintf('%02d:00', $i % 20), 'end_time' => sprintf('%02d:30', $i % 20), 'timezone' => 'Africa/Abidjan', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [],
            'group_ids' => [], 'confirm_conflicts' => true, 'conflict_reason' => 'Mesure fictive des requêtes', ...$extra];
        for ($i = $from; $i < $to; $i++) {
            $this->as('apprenant-double')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'Tâche fictive '.$i, 'due_at' => '2026-11-2'.($i % 8).'T10:00'])->assertSessionHasNoErrors();
            $this->as('apprenant-double')->post('/notes', ['client_key' => (string) Str::uuid(), 'title' => 'Note fictive '.$i, 'personal_subject_id' => $subject->id, 'status' => 'graded', 'value' => '12', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'])->assertSessionHasNoErrors();
            $this->as('apprenant-double')->post('/planning/series', $course($i, []))->assertSessionHasNoErrors();
            $this->as('admin-a', 'A')->post('/planning/series', $course($i, ['group_ids' => [$group], 'subject_id' => $institutionSubject, 'teacher_membership_id' => $teacher->id]))->assertSessionHasNoErrors();
            $this->as('enseignant-a', 'A')->post('/taches/devoirs', ['client_key' => (string) Str::uuid(), 'group_id' => $group, 'subject_id' => $institutionSubject, 'title' => 'Devoir fictif '.$i, 'priority' => 'normal'])->assertSessionHasNoErrors();
            $this->as('admin-a', 'A')->post('/administration/invitations', ['role' => 'learner'])->assertSessionHasNoErrors();
        }
        $props = $this->as('admin-a', 'A')->get('/planning/publication')->viewData('page')['props'];
        $this->as('admin-a', 'A')->post('/planning/publication', ['request_key' => (string) Str::uuid(), 'expected_version' => $props['current']['version'] ?? 0, 'preview_hash' => $props['preview']['hash'], 'reason' => 'Publication fictive de mesure'])->assertSessionHasNoErrors();
    }

    public function test_frequent_screens_use_a_bounded_number_of_queries_whatever_the_number_of_rows(): void
    {
        $pages = [
            ['apprenant-double', null, '/'], ['apprenant-double', null, '/taches'], ['apprenant-double', null, '/planning?view=week&date=2026-11-16'], ['apprenant-double', null, '/notes'],
            ['apprenant-double', null, '/retours'], ['apprenant-double', null, '/notifications'], ['apprenant-double', null, '/opportunites'], ['apprenant-double', null, '/hors-ligne/instantane'],
            ['apprenant-double', 'A', '/planning?view=week&date=2026-11-16'], ['enseignant-a', 'A', '/planning?view=week&date=2026-11-16'], ['enseignant-a', 'A', '/taches/devoirs'],
            ['admin-a', 'A', '/planning?view=week&date=2026-11-16'], ['admin-a', 'A', '/planning/publication'], ['admin-a', 'A', '/administration/etablissement'], ['admin-a', 'A', '/'],
        ];
        $this->grow(0, 2);
        $small = $this->queries($pages);
        $this->grow(2, 12);
        $large = $this->queries($pages);
        foreach ($small as $page => $queries) {
            // Six fois plus de lignes : au plus deux requêtes de plus (pagination, compteur) ; jamais une requête par ligne.
            $this->assertLessThanOrEqual($queries + 2, $large[$page], 'Requêtes croissantes avec les données sur '.$page.' : '.$queries.' puis '.$large[$page]);
            // Plafond par écran : au-delà, l'écran est à revoir.
            $this->assertLessThanOrEqual(60, $large[$page], $page);
        }
    }
}
