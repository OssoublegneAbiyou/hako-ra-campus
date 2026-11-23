<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ActiveContext;
use App\Support\PrivilegedAccess;
use App\Support\Scanners\ClamdScanner;
use App\Support\Scanners\Scanner;
use App\Support\Scanners\UnavailableScanner;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Antivirus des pièces jointes : sans configuration explicite, le scanner « indisponible » bloque toute diffusion.
        $this->app->bind(Scanner::class, fn () => config('campus.attachments.scanner') === 'clamd'
            ? new ClamdScanner((string) config('campus.attachments.clamd_host'), (int) config('campus.attachments.clamd_port'), (int) config('campus.attachments.scan_timeout_seconds'))
            : new UnavailableScanner);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(10)->letters()->numbers());

        // Aucun Gate::before : aucun rôle, même plateforme, n'obtient un passe-droit global.
        // Ces portes n'affichent que les entrées d'administration ; chaque route applique ses propres policies.
        foreach (['manage-institution' => 'institution_admin', 'edit-catalogue' => 'catalogue_editor', 'manage-platform' => 'platform_admin'] as $ability => $role) {
            Gate::define($ability, fn (User $user): bool => PrivilegedAccess::available()
                && app(ActiveContext::class)->current(request())?->role === $role);
        }
    }
}
