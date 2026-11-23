<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_navigation_has_five_primary_destinations_notes_and_no_administration(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('campus.context.kind', 'individual')
            ->where('campus.context.label', 'Espace individuel')
            ->where('campus.administration', [])
            ->has('campus.navigation', 6)
            ->where('campus.navigation.0.label', 'Accueil')
            ->where('campus.navigation.1.label', 'Planning')
            ->where('campus.navigation.2.label', 'Tâches')
            ->where('campus.navigation.3.label', 'Opportunités')
            ->where('campus.navigation.4.label', 'Profil')
            ->where('campus.navigation.5.label', 'Notes'));
    }

    public function test_every_primary_destination_is_a_real_module_and_none_is_simulated(): void
    {
        // Plus aucun écran provisoire : chaque entrée mène à un module livré.
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('campus.navigation', fn ($items) => collect($items)->every(fn ($item) => $item['pending'] === false && $item['href'] !== null)));
        $this->get('/opportunites?institution_id=987')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Opportunities')->where('campus.context.kind', 'individual'));
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route) => $route->getName() === 'grades.pending'));
    }

    public function test_development_routes_and_fixtures_are_unavailable_in_production(): void
    {
        app()->instance('env', 'production');

        foreach (['/developpement/composants'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->get('/profil')->assertRedirect('/connexion');
        $this->get('/planning')->assertRedirect('/connexion');
        $this->get('/taches')->assertRedirect('/connexion');
        $this->get('/notes')->assertRedirect('/connexion');
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('campus.development', false)
            ->where('campus.navigation.0.href', '/')
            ->where('campus.navigation.4.href', '/profil')
            ->where('campus.navigation.1.href', '/planning')
            ->where('campus.navigation.2.href', '/taches')
            ->where('campus.navigation.2.pending', false)
            ->where('campus.navigation.5.href', '/notes')
            ->where('campus.navigation.3.href', '/opportunites'));
        // Le catalogue publié reste public en production.
        $this->get('/opportunites')->assertOk();
    }

    public function test_fixture_pagination_is_bounded_and_available_only_in_development(): void
    {
        $this->get('/developpement/composants?page=99')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ComponentLab')
                ->where('page', 2)
                ->has('examples', 1)
                ->where('examples.0.id', 'exemple-3'));
    }

    public function test_administration_entry_requires_a_permission(): void
    {
        $user = User::factory()->create(['name' => 'Personne fictive', 'email' => 'navigation@example.test']);
        $this->actingAs($user);

        // Sans rôle : aucune entrée d'administration, même si les routes existent.
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.administration', []));

        Gate::define('edit-catalogue', fn (User $user): bool => false);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.administration', []));

        // La permission seule fait apparaître l'entrée ; la page elle-même revérifie le rôle réel.
        Gate::define('edit-catalogue', fn (User $user): bool => true);
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('campus.administration', 1)
            ->where('campus.administration.0.label', 'Catalogue')
            ->where('campus.administration.0.href', '/administration/catalogue'));
        $this->assertTrue(Route::has('admin.catalogue'));
    }
}
