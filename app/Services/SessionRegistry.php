<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionRegistry
{
    /**
     * La consultation et la révocation ciblée exigent le pilote de session « database ».
     */
    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return list<array{handle: string, ipAddress: string|null, userAgent: string, lastActiveAt: string, current: bool}>
     */
    public function listFor(User $user, string $currentSessionId): array
    {
        if (! $this->supported()) {
            return [];
        }

        return $this->table()->where('user_id', $user->getKey())->orderByDesc('last_activity')->limit(50)->get()
            ->map(fn (object $session): array => [
                'handle' => $this->handle($session->id),
                'ipAddress' => $session->ip_address,
                'userAgent' => Str::limit((string) $session->user_agent, 120),
                'lastActiveAt' => Carbon::createFromTimestampUTC($session->last_activity)->toIso8601String(),
                'current' => hash_equals($session->id, $currentSessionId),
            ])->all();
    }

    /**
     * Révoque une autre session du titulaire. La session courante se ferme par la déconnexion.
     */
    public function revoke(User $user, string $handle, string $currentSessionId): bool
    {
        if (! $this->supported()) {
            return false;
        }
        foreach ($this->table()->where('user_id', $user->getKey())->pluck('id') as $id) {
            if (hash_equals($this->handle($id), $handle) && ! hash_equals($id, $currentSessionId)) {
                return $this->table()->where('user_id', $user->getKey())->where('id', $id)->delete() === 1;
            }
        }

        return false;
    }

    public function revokeOthers(User $user, string $currentSessionId): int
    {
        return $this->supported()
            ? $this->table()->where('user_id', $user->getKey())->where('id', '!=', $currentSessionId)->delete()
            : 0;
    }

    public function revokeAll(User $user): int
    {
        return $this->supported() ? $this->table()->where('user_id', $user->getKey())->delete() : 0;
    }

    /**
     * Identifiant public non réutilisable comme cookie de session.
     */
    private function handle(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private function table(): Builder
    {
        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));
    }
}
