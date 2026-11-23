<?php

use App\Http\Controllers\AcademicReferenceController;
use App\Http\Controllers\Admin\CatalogueController;
use App\Http\Controllers\Admin\InstitutionController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\MembershipController;
use App\Http\Controllers\Admin\PlatformController;
use App\Http\Controllers\Admin\PrivacyRequestController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\GoogleSignInController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SecondFactorChallengeController;
use App\Http\Controllers\ContextController;
use App\Http\Controllers\CourseCheckInController;
use App\Http\Controllers\DevelopmentUiController;
use App\Http\Controllers\GradebookController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HomeworkController;
use App\Http\Controllers\Institution\ProvisionedAccountController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\OpportunityFollowUpController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SavedSearchController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleImportController;
use App\Http\Controllers\SchedulePublicationController;
use App\Http\Controllers\SecondFactorController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/aide', HelpController::class)->name('help');
// Supervision par une sonde externe : fermée sans jeton configuré.
Route::get('/sante/{check?}', HealthController::class)->where('check', '[a-z]+')->middleware('throttle:120,1')->name('health');
Route::get('/confidentialite', [PrivacyController::class, 'notice'])->name('privacy.notice');

Route::get('/developpement/composants', DevelopmentUiController::class)->name('development.components');

Route::get('/opportunites', [OpportunityController::class, 'index'])->name('opportunities');
Route::get('/hors-ligne', [OfflineController::class, 'show'])->name('offline');
Route::get('/opportunites/{opportunity}', [OpportunityController::class, 'show'])->whereUuid('opportunity')->name('opportunities.show');

Route::get('/invitation/{token}', [InvitationAcceptanceController::class, 'show'])
    ->middleware('throttle:30,1')->where('token', '[A-Za-z0-9_-]{20,64}')->name('invitations.show');

// Réponse de Google : sert à la connexion (visiteur), au rattachement et à la confirmation (compte connecté).
Route::get('/connexion/google/reponse', [GoogleSignInController::class, 'callback'])->middleware('throttle:30,1')->name('google.callback');

Route::middleware('guest')->group(function (): void {
    Route::get('/connexion/google', [GoogleSignInController::class, 'redirect'])->middleware('throttle:20,1')->name('google.start');
    Route::get('/connexion/google/creation', [GoogleSignInController::class, 'create'])->name('google.create');
    Route::post('/connexion/google/creation', [GoogleSignInController::class, 'store'])->middleware('throttle:10,1')->name('google.store');
    Route::get('/inscription', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/inscription', [RegisteredUserController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/connexion', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/connexion', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/mot-de-passe-oublie', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/mot-de-passe-oublie', [PasswordResetController::class, 'email'])->middleware('throttle:30,1')->name('password.email');
    Route::get('/reinitialisation/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reinitialisation', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.store');
    Route::get('/activation', [AccountActivationController::class, 'create'])->name('activation.create');
    Route::post('/activation', [AccountActivationController::class, 'store'])->middleware('throttle:30,1')->name('activation.store');
});

Route::middleware(['auth', 'auth.session', 'account.active'])->group(function (): void {
    Route::post('/deconnexion', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/second-facteur', [SecondFactorChallengeController::class, 'show'])->name('second-factor.challenge');
    Route::post('/second-facteur', [SecondFactorChallengeController::class, 'store'])->middleware('throttle:30,1')->name('second-factor.verify');
    Route::get('/verification-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verification-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/verification-email/renvoi', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')->name('verification.send');
    Route::get('/confirmation-mot-de-passe', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirmation-mot-de-passe', [ConfirmablePasswordController::class, 'store'])->name('password.confirm.store');
    Route::get('/confirmation-google', [GoogleSignInController::class, 'start'])->defaults('intent', 'confirm')->middleware('throttle:20,1')->name('google.confirm');

    Route::middleware('verified')->group(function (): void {
        Route::get('/planning', [ScheduleController::class, 'index'])->name('planning');
        Route::post('/planning/series', [ScheduleController::class, 'store'])->middleware('throttle:30,1')->name('planning.series.store');
        Route::post('/planning/series/{series}/fenetre', [ScheduleController::class, 'materialize'])->whereUuid('series')->middleware('throttle:30,1')->name('planning.series.window');
        Route::patch('/planning/series/{series}/futur', [ScheduleController::class, 'future'])->whereUuid('series')->middleware('throttle:30,1')->name('planning.series.future');
        Route::patch('/planning/occurrences/{occurrence}', [ScheduleController::class, 'occurrence'])->whereUuid('occurrence')->middleware('throttle:30,1')->name('planning.occurrences.update');
        Route::get('/planning/occurrences/{occurrence}/historique', [ScheduleController::class, 'history'])->whereUuid('occurrence')->name('planning.occurrences.history');
        Route::get('/retours', [CourseCheckInController::class, 'index'])->name('check-ins.index');
        Route::get('/planning/occurrences/{occurrence}/retour', [CourseCheckInController::class, 'show'])->whereUuid('occurrence')->name('planning.occurrences.check-in');
        Route::put('/planning/occurrences/{occurrence}/retour', [CourseCheckInController::class, 'update'])->whereUuid('occurrence')->middleware('throttle:60,1')->name('planning.occurrences.check-in.update');
        Route::get('/taches', [TaskController::class, 'index'])->name('tasks');
        Route::post('/taches', [TaskController::class, 'store'])->middleware('throttle:60,1')->name('tasks.store');
        Route::get('/taches/devoirs', [HomeworkController::class, 'index'])->name('homework.index');
        Route::post('/taches/devoirs', [HomeworkController::class, 'store'])->middleware('throttle:30,1')->name('homework.store');
        Route::patch('/taches/devoirs/{task}', [HomeworkController::class, 'update'])->whereUuid('task')->middleware('throttle:60,1')->name('homework.update');
        Route::delete('/taches/devoirs/{task}', [HomeworkController::class, 'destroy'])->whereUuid('task')->middleware('throttle:30,1')->name('homework.destroy');
        Route::post('/taches/devoirs/{task}/retablissement', [HomeworkController::class, 'restore'])->whereUuid('task')->middleware('throttle:30,1')->name('homework.restore');
        Route::patch('/taches/{task}', [TaskController::class, 'update'])->whereUuid('task')->middleware('throttle:120,1')->name('tasks.update');
        Route::put('/taches/{task}/progression', [TaskController::class, 'progress'])->whereUuid('task')->middleware('throttle:120,1')->name('tasks.progress');
        Route::delete('/taches/{task}', [TaskController::class, 'destroy'])->whereUuid('task')->middleware('throttle:60,1')->name('tasks.destroy');
        Route::post('/taches/{task}/restauration', [TaskController::class, 'restore'])->whereUuid('task')->middleware('throttle:60,1')->name('tasks.restore');
        Route::post('/taches/{task}/etapes', [TaskController::class, 'storeStep'])->whereUuid('task')->middleware('throttle:120,1')->name('tasks.steps.store');
        Route::patch('/taches/{task}/etapes/{step}', [TaskController::class, 'updateStep'])->whereUuid('task')->whereNumber('step')->middleware('throttle:120,1')->name('tasks.steps.update');
        Route::delete('/taches/{task}/etapes/{step}', [TaskController::class, 'destroyStep'])->whereUuid('task')->whereNumber('step')->middleware('throttle:120,1')->name('tasks.steps.destroy');
        Route::get('/notes', [GradeController::class, 'index'])->name('grades');
        Route::post('/notes', [GradeController::class, 'store'])->middleware('throttle:60,1')->name('grades.store');
        Route::get('/notes/evaluations', [GradebookController::class, 'index'])->name('gradebook.index');
        Route::post('/notes/evaluations', [GradebookController::class, 'store'])->middleware('throttle:30,1')->name('gradebook.store');
        Route::patch('/notes/evaluations/{assessment}', [GradebookController::class, 'update'])->whereUuid('assessment')->middleware('throttle:60,1')->name('gradebook.update');
        Route::put('/notes/evaluations/{assessment}/resultats', [GradebookController::class, 'results'])->whereUuid('assessment')->middleware('throttle:60,1')->name('gradebook.results');
        Route::post('/notes/evaluations/{assessment}/publication', [GradebookController::class, 'publish'])->whereUuid('assessment')->middleware('throttle:30,1')->name('gradebook.publish');
        Route::delete('/notes/evaluations/{assessment}', [GradebookController::class, 'destroy'])->whereUuid('assessment')->middleware('throttle:30,1')->name('gradebook.destroy');
        Route::get('/notes/coefficients', [GradebookController::class, 'coefficients'])->name('gradebook.coefficients');
        Route::put('/notes/coefficients/{subject}', [GradebookController::class, 'setCoefficient'])->whereNumber('subject')->middleware('throttle:60,1')->name('gradebook.coefficients.update');
        Route::patch('/notes/matieres/{subject}/coefficient', [GradeController::class, 'coefficient'])->whereNumber('subject')->middleware('throttle:60,1')->name('grades.subjects.coefficient');
        Route::patch('/notes/{grade}', [GradeController::class, 'update'])->whereUuid('grade')->middleware('throttle:120,1')->name('grades.update');
        Route::delete('/notes/{grade}', [GradeController::class, 'destroy'])->whereUuid('grade')->middleware('throttle:60,1')->name('grades.destroy');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/lecture', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/{notification}/lecture', [NotificationController::class, 'read'])->whereNumber('notification')->name('notifications.read');
        Route::get('/notifications/preferences', [NotificationController::class, 'settings'])->name('notifications.settings');
        Route::put('/notifications/preferences', [NotificationController::class, 'updateSettings'])->middleware('throttle:30,1')->name('notifications.settings.update');
        Route::post('/notifications/appareils', [NotificationController::class, 'subscribe'])->middleware('throttle:10,1')->name('notifications.devices.store');
        Route::delete('/notifications/appareils/{device}', [NotificationController::class, 'unsubscribe'])->whereNumber('device')->name('notifications.devices.destroy');
        Route::post('/taches/{task}/pieces-jointes', [AttachmentController::class, 'storeForTask'])->whereUuid('task')->middleware('throttle:30,1')->name('attachments.tasks.store');
        Route::post('/planning/occurrences/{occurrence}/retour/pieces-jointes', [AttachmentController::class, 'storeForCheckIn'])->whereUuid('occurrence')->middleware('throttle:30,1')->name('attachments.check-ins.store');
        Route::get('/pieces-jointes/signalements', [AttachmentController::class, 'reports'])->name('attachments.reports');
        Route::post('/pieces-jointes/signalements/{report}/decision', [AttachmentController::class, 'decide'])->whereNumber('report')->middleware('throttle:30,1')->name('attachments.reports.decide');
        Route::get('/pieces-jointes/{attachment}', [AttachmentController::class, 'download'])->whereUuid('attachment')->middleware('throttle:120,1')->name('attachments.download');
        Route::delete('/pieces-jointes/{attachment}', [AttachmentController::class, 'destroy'])->whereUuid('attachment')->middleware('throttle:60,1')->name('attachments.destroy');
        Route::post('/pieces-jointes/{attachment}/signalement', [AttachmentController::class, 'report'])->whereUuid('attachment')->middleware('throttle:10,1')->name('attachments.report');
        Route::get('/hors-ligne/instantane', [OfflineController::class, 'snapshot'])->middleware('throttle:120,1')->name('offline.snapshot');
        Route::post('/synchronisation/v1/operations', [SyncController::class, 'store'])->middleware('throttle:120,1')->name('sync.v1.operations');
        Route::get('/opportunites/alertes', [SavedSearchController::class, 'index'])->name('opportunities.alerts');
        Route::post('/opportunites/alertes', [SavedSearchController::class, 'store'])->middleware('throttle:120,1')->name('opportunities.alerts.store');
        Route::get('/opportunites/alertes/{search}', [SavedSearchController::class, 'show'])->whereNumber('search')->name('opportunities.alerts.show');
        Route::patch('/opportunites/alertes/{search}', [SavedSearchController::class, 'update'])->whereNumber('search')->middleware('throttle:120,1')->name('opportunities.alerts.update');
        Route::delete('/opportunites/alertes/{search}', [SavedSearchController::class, 'destroy'])->whereNumber('search')->middleware('throttle:120,1')->name('opportunities.alerts.destroy');
        Route::get('/opportunites/suivi', [OpportunityFollowUpController::class, 'index'])->name('opportunities.follow-up');
        Route::put('/opportunites/{opportunity}/favori', [OpportunityFollowUpController::class, 'favorite'])->whereUuid('opportunity')->middleware('throttle:120,1')->name('opportunities.favorite');
        Route::delete('/opportunites/{opportunity}/favori', [OpportunityFollowUpController::class, 'unfavorite'])->whereUuid('opportunity')->middleware('throttle:120,1')->name('opportunities.unfavorite');
        Route::put('/opportunites/{opportunity}/demarche', [OpportunityFollowUpController::class, 'save'])->whereUuid('opportunity')->middleware('throttle:120,1')->name('opportunities.application.save');
        Route::delete('/opportunites/{opportunity}/demarche', [OpportunityFollowUpController::class, 'destroy'])->whereUuid('opportunity')->middleware('throttle:120,1')->name('opportunities.application.destroy');
        Route::post('/opportunites/{opportunity}/demarche/pieces', [OpportunityFollowUpController::class, 'storeItem'])->whereUuid('opportunity')->middleware('throttle:120,1')->name('opportunities.application.items.store');
        Route::patch('/opportunites/{opportunity}/demarche/pieces/{item}', [OpportunityFollowUpController::class, 'updateItem'])->whereUuid('opportunity')->whereNumber('item')->middleware('throttle:120,1')->name('opportunities.application.items.update');
        Route::delete('/opportunites/{opportunity}/demarche/pieces/{item}', [OpportunityFollowUpController::class, 'destroyItem'])->whereUuid('opportunity')->whereNumber('item')->middleware('throttle:120,1')->name('opportunities.application.items.destroy');
        Route::get('/opportunites/profil', [OpportunityController::class, 'profile'])->name('opportunities.profile');
        Route::put('/opportunites/profil', [OpportunityController::class, 'updateProfile'])->middleware('throttle:30,1')->name('opportunities.profile.update');
        Route::post('/opportunites/{opportunity}/signalement', [OpportunityController::class, 'report'])->whereUuid('opportunity')->middleware('throttle:10,1')->name('opportunities.report');
        Route::prefix('/administration/catalogue')->group(function (): void {
            Route::get('/', [CatalogueController::class, 'index'])->name('admin.catalogue');
            Route::post('/', [CatalogueController::class, 'store'])->middleware('throttle:60,1')->name('admin.catalogue.store');
            Route::get('/signalements', [CatalogueController::class, 'reports'])->name('admin.catalogue.reports');
            Route::post('/signalements/{report}', [CatalogueController::class, 'handleReport'])->whereNumber('report')->middleware('throttle:60,1')->name('admin.catalogue.reports.handle');
            Route::get('/{opportunity}', [CatalogueController::class, 'show'])->whereUuid('opportunity')->name('admin.catalogue.show');
            Route::patch('/{opportunity}', [CatalogueController::class, 'update'])->whereUuid('opportunity')->middleware('throttle:60,1')->name('admin.catalogue.update');
            Route::post('/{opportunity}/transition', [CatalogueController::class, 'transition'])->whereUuid('opportunity')->middleware('throttle:60,1')->name('admin.catalogue.transition');
            Route::post('/{opportunity}/edition', [CatalogueController::class, 'edition'])->whereUuid('opportunity')->middleware('throttle:30,1')->name('admin.catalogue.edition');
        });
        Route::get('/planning/import', [ScheduleImportController::class, 'index'])->name('planning.import');
        Route::get('/planning/import/modele', [ScheduleImportController::class, 'template'])->name('planning.import.template');
        Route::post('/planning/import', [ScheduleImportController::class, 'store'])->middleware('throttle:20,1')->name('planning.import.store');
        Route::post('/planning/import/{batch}/analyse', [ScheduleImportController::class, 'analyze'])->whereUuid('batch')->middleware('throttle:20,1')->name('planning.import.analyze');
        Route::post('/planning/import/{batch}/application', [ScheduleImportController::class, 'apply'])->whereUuid('batch')->middleware('throttle:20,1')->name('planning.import.apply');
        Route::get('/planning/import/{batch}/rapport', [ScheduleImportController::class, 'report'])->whereUuid('batch')->name('planning.import.report');
        Route::get('/planning/publication', [SchedulePublicationController::class, 'index'])->name('planning.publication');
        Route::post('/planning/publication', [SchedulePublicationController::class, 'store'])->middleware('throttle:20,1')->name('planning.publication.store');
        Route::post('/planning/publication/{version}/retour', [SchedulePublicationController::class, 'rollback'])->whereNumber('version')->middleware('throttle:20,1')->name('planning.publication.rollback');
        Route::post('/planning/occurrences/{occurrence}/indisponibilite', [SchedulePublicationController::class, 'report'])->whereUuid('occurrence')->middleware('throttle:20,1')->name('planning.unavailability.report');
        Route::post('/planning/indisponibilites/{report}/decision', [SchedulePublicationController::class, 'decide'])->whereNumber('report')->middleware('throttle:30,1')->name('planning.unavailability.decide');
        Route::get('/etudes/referentiels', [AcademicReferenceController::class, 'index'])->name('academic.index');
        Route::post('/etudes/referentiels/years', [AcademicReferenceController::class, 'store'])->defaults('kind', 'years')->middleware(['password.confirm', 'throttle:60,1'])->name('academic.years.store');
        Route::post('/etudes/referentiels/{kind}', [AcademicReferenceController::class, 'store'])->middleware('throttle:60,1')->name('academic.store');
        Route::patch('/etudes/referentiels/{kind}/{reference}', [AcademicReferenceController::class, 'update'])->whereNumber('reference')->middleware('throttle:60,1')->name('academic.update');
        Route::patch('/etudes/fuseau', [AcademicReferenceController::class, 'timezone'])->middleware('privileged')->name('academic.timezone');
        Route::post('/contexte', [ContextController::class, 'update'])->name('context.update');
        Route::post('/invitation/{token}/acceptation', [InvitationAcceptanceController::class, 'store'])
            ->middleware('throttle:10,1')->where('token', '[A-Za-z0-9_-]{20,64}')->name('invitations.accept');
        Route::post('/profil/appartenances/{membership}/depart', [ProfileController::class, 'leaveMembership'])
            ->middleware('password.confirm')->whereNumber('membership')->name('profile.memberships.leave');

        Route::middleware('privileged')->prefix('/administration')->group(function (): void {
            Route::get('/etablissement', [InstitutionController::class, 'index'])->name('admin.institution');
            Route::get('/etablissement/membres/export', [InstitutionController::class, 'export'])->name('admin.institution.export');
            Route::get('/plateforme', [PlatformController::class, 'index'])->name('admin.platform');
            Route::get('/plateforme/demandes', [PrivacyRequestController::class, 'index'])->name('admin.privacy.requests');
            Route::post('/plateforme/demandes/{request}/traitement', [PrivacyRequestController::class, 'handle'])->whereUuid('request')->middleware('throttle:60,1,privacy-handling')->name('admin.privacy.requests.handle');
            Route::post('/plateforme/etablissements', [PlatformController::class, 'storeInstitution'])
                ->middleware('password.confirm')->name('admin.platform.institutions.store');
            Route::post('/invitations', [InvitationController::class, 'store'])
                ->middleware(['password.confirm', 'throttle:60,1'])->name('admin.invitations.store');
            Route::post('/invitations/{invitation}/revocation', [InvitationController::class, 'revoke'])
                ->whereNumber('invitation')->name('admin.invitations.revoke');
            Route::post('/invitations/{invitation}/decision', [InvitationController::class, 'review'])
                ->middleware('password.confirm')->whereNumber('invitation')->name('admin.invitations.review');
            Route::post('/appartenances/{membership}/retrait', [MembershipController::class, 'revoke'])
                ->middleware('password.confirm')->whereNumber('membership')->name('admin.memberships.revoke');
        });

        Route::get('/profil', [ProfileController::class, 'show'])->name('profile');
        Route::get('/profil/google/rattachement', [GoogleSignInController::class, 'start'])->defaults('intent', 'link')->middleware(['password.confirm', 'throttle:20,1'])->name('google.link');
        Route::get('/profil/second-facteur', [SecondFactorController::class, 'show'])->name('second-factor.show');
        Route::post('/profil/second-facteur/activation', [SecondFactorController::class, 'start'])->middleware(['password.confirm', 'throttle:10,1'])->name('second-factor.start');
        Route::post('/profil/second-facteur/confirmation', [SecondFactorController::class, 'confirm'])->middleware(['password.confirm', 'throttle:10,1'])->name('second-factor.confirm');
        Route::post('/profil/second-facteur/codes-de-secours', [SecondFactorController::class, 'recoveryCodes'])->middleware(['password.confirm', 'throttle:10,1'])->name('second-factor.recovery-codes');
        Route::delete('/profil/second-facteur', [SecondFactorController::class, 'destroy'])->middleware(['password.confirm', 'throttle:10,1'])->name('second-factor.destroy');
        Route::patch('/profil', [ProfileController::class, 'update'])->name('profile.update');
        Route::patch('/profil/fuseau', [ProfileController::class, 'updateTimezone'])->middleware('throttle:30,1')->name('profile.timezone');
        Route::delete('/profil/sessions', [ProfileController::class, 'destroyOtherSessions'])
            ->middleware('password.confirm')->name('profile.sessions.destroy-others');
        Route::delete('/profil/sessions/{handle}', [ProfileController::class, 'destroySession'])
            ->middleware('password.confirm')->where('handle', '[a-f0-9]{64}')->name('profile.sessions.destroy');

        Route::get('/confidentialite/parametres', [PrivacyController::class, 'settings'])->name('privacy.settings');
        Route::post('/confidentialite/notice', [PrivacyController::class, 'acknowledge'])->middleware('throttle:30,1,privacy-notice')->name('privacy.acknowledge');
        Route::post('/confidentialite/demandes', [PrivacyController::class, 'storeRequest'])->middleware('throttle:20,1,privacy-requests')->name('privacy.requests.store');
        Route::post('/confidentialite/demandes/{request}/annulation', [PrivacyController::class, 'cancelRequest'])->whereUuid('request')->middleware('throttle:30,1,privacy-requests-cancel')->name('privacy.requests.cancel');
        // Export et suppression : identité revérifiée par une nouvelle saisie du mot de passe.
        // Chaque limite de débit porte son propre compteur (troisième paramètre) : elle ne se cumule pas avec les autres actions du compte (voir A43).
        Route::post('/confidentialite/exports', [PrivacyController::class, 'storeExport'])->middleware(['password.confirm', 'throttle:10,1,privacy-exports'])->name('privacy.exports.store');
        Route::get('/confidentialite/exports/{export}', [PrivacyController::class, 'downloadExport'])->whereUuid('export')->middleware('throttle:30,1,privacy-downloads')->name('privacy.exports.download');
        Route::get('/confidentialite/suppression', [PrivacyController::class, 'deletion'])->middleware('password.confirm')->name('privacy.deletion');
        Route::delete('/confidentialite/compte', [PrivacyController::class, 'destroy'])->middleware(['password.confirm', 'throttle:5,1,privacy-erasure'])->name('privacy.account.destroy');

        Route::middleware('privileged')->prefix('/etablissement/{membership}/comptes')->whereNumber('membership')->group(function (): void {
            Route::get('/', [ProvisionedAccountController::class, 'index'])->name('institution.accounts.index');
            Route::post('/', [ProvisionedAccountController::class, 'store'])
                ->middleware(['password.confirm', 'throttle:30,1'])->name('institution.accounts.store');
            Route::post('/{user}/recuperation', [ProvisionedAccountController::class, 'recover'])
                ->middleware(['password.confirm', 'throttle:10,1'])->whereNumber('user')->name('institution.accounts.recover');
        });
    });
});
