<?php

namespace App\Http\Controllers;

use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\UserNotification;
use App\Services\NotificationPreferences;
use App\Support\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Centre interne de notifications, préférences et appareils du compte connecté.
 * Le centre fonctionne sans aucun canal externe : refuser le Web Push ou le courriel ne bloque rien.
 */
class NotificationController extends Controller
{
    public function index(Request $r): Response
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $list = UserNotification::query()->where('user_id', $r->user()->id)->where('status', 'delivered')->orderByDesc('delivered_at')->orderByDesc('id')->paginate(30)->withQueryString();

        return Inertia::render('Notifications', [
            'notifications' => $list->getCollection()->map(fn (UserNotification $notification): array => [
                'id' => $notification->id, 'category' => $notification->category, 'categoryLabel' => NotificationPreferences::CATEGORIES[$notification->category]['label'] ?? 'Information',
                'title' => $notification->title, 'body' => $notification->body, 'url' => $notification->url,
                'deliveredAt' => $notification->delivered_at->utc()->toIso8601String(), 'read' => $notification->read_at !== null,
            ])->all(),
            'pagination' => ['page' => $list->currentPage(), 'total' => $list->lastPage(), 'previous' => $list->previousPageUrl(), 'next' => $list->nextPageUrl()],
            'unread' => UserNotification::query()->where('user_id', $r->user()->id)->where('status', 'delivered')->whereNull('read_at')->count(),
        ]);
    }

    /**
     * Marque une notification comme lue puis ouvre sa page : « lu » n'est enregistré que sur une action de la personne.
     */
    public function read(Request $r, int $notification): RedirectResponse
    {
        $found = UserNotification::query()->where('user_id', $r->user()->id)->where('status', 'delivered')->findOrFail($notification);
        if ($found->read_at === null) {
            $found->forceFill(['read_at' => now()])->save();
        }

        // L'adresse vient toujours du serveur et reste interne.
        return redirect()->to(str_starts_with($found->url, '/') && ! str_starts_with($found->url, '//') ? $found->url : '/notifications');
    }

    public function readAll(Request $r): RedirectResponse
    {
        UserNotification::query()->where('user_id', $r->user()->id)->where('status', 'delivered')->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'Toutes les notifications sont marquées comme lues.');
    }

    public function settings(Request $r, NotificationPreferences $preferences): Response
    {
        $user = $r->user();
        $preference = $preferences->for($user);
        $time = fn (?string $value): string => $value === null ? '' : substr($value, 0, 5);

        return Inertia::render('NotificationSettings', [
            'preference' => [
                'version' => $preference->lock_version, 'timezone' => $preferences->timezone($user), 'quietStart' => $time($preference->quiet_start), 'quietEnd' => $time($preference->quiet_end),
                'courseEndDelay' => $preference->course_end_delay_minutes, 'taskDayBefore' => $preference->task_day_before, 'taskSameDay' => $preference->task_same_day,
                'taskReminderTime' => $time($preference->task_reminder_time), 'digestEnabled' => $preference->digest_enabled, 'digestTime' => $time($preference->digest_time),
                'favoriteFirstDays' => (int) $preference->favorite_first_days, 'favoriteSecondDays' => (int) $preference->favorite_second_days,
            ],
            'categories' => $preferences->categories()->map(fn (array $category, string $key): array => ['key' => $key, ...$category, ...$preferences->category($user, $key)])->values()->all(),
            'channels' => [...$preferences->channels(), 'hasVerifiedEmail' => $user->email !== null && $user->hasVerifiedEmail()],
            'devices' => PushSubscription::query()->where('user_id', $user->id)->whereNull('revoked_at')->orderByDesc('id')->get()
                ->map(fn (PushSubscription $device): array => ['id' => $device->id, 'label' => $device->device_label, 'createdAt' => $device->created_at->utc()->toIso8601String(),
                    'lastAcceptedAt' => $device->last_accepted_at?->utc()->toIso8601String()])->all(),
            'recentDeliveries' => NotificationDelivery::query()->whereHas('notification', fn ($query) => $query->where('user_id', $user->id))->orderByDesc('id')->limit(10)->get()
                ->map(fn (NotificationDelivery $delivery): array => ['id' => $delivery->id, 'channel' => $delivery->channel, 'status' => NotificationDelivery::STATUSES[$delivery->status],
                    'at' => $delivery->updated_at->utc()->toIso8601String()])->all(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function updateSettings(Request $r, NotificationPreferences $preferences): RedirectResponse|JsonResponse
    {
        $optional = array_keys(array_filter(NotificationPreferences::CATEGORIES, fn (array $category) => ! $category['mandatory']));
        $data = $r->validate([
            'expected_version' => 'required|integer|min:0', 'timezone' => 'required|timezone:all',
            'quiet_start' => 'nullable|date_format:H:i|required_with:quiet_end', 'quiet_end' => 'nullable|date_format:H:i|required_with:quiet_start|different:quiet_start',
            'course_end_delay' => 'required|integer|min:0|max:120', 'task_day_before' => 'required|boolean', 'task_same_day' => 'required|boolean',
            'task_reminder_time' => 'required|date_format:H:i', 'digest_enabled' => 'required|boolean', 'digest_time' => 'required|date_format:H:i',
            'favorite_first_days' => 'sometimes|integer|min:0|max:30', 'favorite_second_days' => 'sometimes|integer|min:0|max:30',
            'categories' => 'present|array', 'categories.*' => 'array:enabled,email,push', 'categories.*.enabled' => 'required|boolean', 'categories.*.email' => 'required|boolean', 'categories.*.push' => 'required|boolean',
        ]);
        if (array_diff(array_keys($data['categories']), $optional) !== []) {
            throw ValidationException::withMessages(['categories' => 'Cette catégorie ne se règle pas.']);
        }
        $preference = DB::transaction(function () use ($r, $data): NotificationPreference {
            $r->user()->newQuery()->whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $preference = NotificationPreference::query()->where('user_id', $r->user()->id)->lockForUpdate()->first() ?? new NotificationPreference(['user_id' => $r->user()->id]);
            if ((int) $data['expected_version'] !== $preference->lock_version) {
                throw new ConflictHttpException('Vos préférences ont été modifiées ailleurs. Rechargez la page avant de les enregistrer.');
            }
            $preference->fill([
                'timezone' => $data['timezone'], 'quiet_start' => $data['quiet_start'] ?? null, 'quiet_end' => $data['quiet_end'] ?? null, 'course_end_delay_minutes' => (int) $data['course_end_delay'],
                'task_day_before' => (bool) $data['task_day_before'], 'task_same_day' => (bool) $data['task_same_day'], 'task_reminder_time' => $data['task_reminder_time'],
                'digest_enabled' => (bool) $data['digest_enabled'], 'digest_time' => $data['digest_time'],
                'favorite_first_days' => (int) ($data['favorite_first_days'] ?? $preference->favorite_first_days), 'favorite_second_days' => (int) ($data['favorite_second_days'] ?? $preference->favorite_second_days),
                'categories' => collect($data['categories'])->map(fn (array $category): array => ['enabled' => (bool) $category['enabled'], 'email' => (bool) $category['email'], 'push' => (bool) $category['push']])->all(),
                'lock_version' => $preference->lock_version + 1,
            ])->save();

            return $preference;
        }, 3);
        $preferences->forget();
        if ($r->expectsJson()) {
            return response()->json(['version' => $preference->lock_version], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', 'Préférences de notification enregistrées.');
    }

    /**
     * Enregistre l'abonnement Web Push de cet appareil, après consentement dans le navigateur. Seule l'adresse d'un service de push reconnu est acceptée.
     */
    public function subscribe(Request $r, NotificationPreferences $preferences): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['endpoint' => 'required|string|max:2000']);
        abort_unless($preferences->channels()['push'], 404);
        if (! WebPush::allowed($data['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => 'Ce navigateur utilise un service de notification non reconnu. Le centre de notifications reste disponible.']);
        }
        $hash = hash('sha256', $data['endpoint']);
        $agent = (string) $r->userAgent();
        $label = match (true) {
            str_contains($agent, 'Firefox') => 'Firefox', str_contains($agent, 'Edg') => 'Edge', str_contains($agent, 'Chrome') => 'Chrome', str_contains($agent, 'Safari') => 'Safari', default => 'Navigateur',
        }.(preg_match('/Android|iPhone|iPad|Mobile/', $agent) ? ' (mobile)' : ' (ordinateur)');
        // Un même appareil ne garde qu'un abonnement : s'il change de compte, l'abonnement suit le compte connecté.
        $subscription = PushSubscription::query()->updateOrCreate(['endpoint_hash' => $hash],
            ['user_id' => $r->user()->id, 'endpoint' => $data['endpoint'], 'device_label' => $label, 'revoked_at' => null, 'revoked_reason' => null]);
        if ($r->expectsJson()) {
            return response()->json(['id' => $subscription->id], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', 'Notifications activées sur cet appareil.');
    }

    public function unsubscribe(Request $r, int $device): RedirectResponse
    {
        $subscription = PushSubscription::query()->where('user_id', $r->user()->id)->findOrFail($device);
        if ($subscription->revoked_at === null) {
            $subscription->forceFill(['revoked_at' => now(), 'revoked_reason' => 'user'])->save();
        }

        return back()->with('status', 'Cet appareil ne recevra plus de notifications.');
    }
}
