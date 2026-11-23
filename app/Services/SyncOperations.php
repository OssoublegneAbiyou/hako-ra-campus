<?php

namespace App\Services;

use App\Models\CourseCheckIn;
use App\Models\PersonalSpace;
use App\Models\SyncOperation;
use App\Models\Task;
use App\Models\User;
use App\Support\OperationFingerprint;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Synchronisation des brouillons personnels écrits hors connexion : création et modification de tâches personnelles, retour privé après cours.
 *
 * Chaque opération est appliquée par les mêmes services que les écrans en ligne : propriétaire, appartenance, droit sur le cours,
 * validité du parent et version sont donc revérifiés à chaque envoi. Une version dépassée n'écrase rien. Le résultat rendu est
 * enregistré une fois par compte et par opération, et rendu à l'identique si l'appareil renvoie la même opération.
 */
class SyncOperations
{
    private const MESSAGES = [
        'APPLIED' => 'Enregistré.',
        'VERSION_CONFLICT' => 'Cet élément a été modifié ailleurs depuis votre brouillon. Rien n’a été écrasé : comparez les deux versions.',
        'IDEMPOTENCY_KEY_REUSED' => 'Cet identifiant d’opération a déjà servi pour un autre contenu. Aucun nouvel élément n’a été créé.',
        'VALIDATION_FAILED' => 'Le contenu a été refusé. Votre brouillon reste sur cet appareil.',
        'PARENT_UNAVAILABLE' => 'L’élément concerné n’existe plus ou ne vous est plus accessible. Votre brouillon reste sur cet appareil.',
        'ACCESS_FORBIDDEN' => 'Cette action ne vous est plus autorisée. Votre brouillon reste sur cet appareil.',
    ];

    public function __construct(private Tasks $tasks, private CourseCheckIns $checkIns) {}

    /**
     * @param  array{operation_id: string, type: string, target_id?: string|null, base_version: int, fingerprint: string, payload: array<string, mixed>}  $operation
     * @return array{status: int, body: array<string, mixed>}
     */
    public function apply(User $user, array $operation): array
    {
        $target = $operation['target_id'] ?? null;
        try {
            $fingerprint = OperationFingerprint::of($operation['type'], $target, $operation['base_version'], $operation['payload']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['payload' => 'Le contenu contient une valeur qui ne peut pas être synchronisée.']);
        }
        if (! hash_equals($fingerprint, $operation['fingerprint'])) {
            throw ValidationException::withMessages(['fingerprint' => 'L’empreinte ne correspond pas au contenu envoyé.']);
        }

        return DB::transaction(function () use ($user, $operation, $target, $fingerprint): array {
            // Verrou de l'espace personnel : deux envois simultanés de la même opération passent l'un après l'autre.
            PersonalSpace::query()->where('owner_id', $user->id)->lockForUpdate()->first();
            $known = SyncOperation::query()->where('user_id', $user->id)->where('operation_id', $operation['operation_id'])->first();
            if ($known !== null) {
                if (! hash_equals($known->fingerprint, $fingerprint)) {
                    // Même clé, autre contenu : conflit, jamais un nouvel objet. Le premier résultat reste le seul enregistré.
                    return ['status' => 409, 'body' => ['operation_id' => $operation['operation_id'], 'outcome' => 'conflict', 'code' => 'IDEMPOTENCY_KEY_REUSED',
                        'message' => self::MESSAGES['IDEMPOTENCY_KEY_REUSED'], 'replayed' => false, 'result' => null, 'current' => null, 'errors' => (object) []]];
                }

                return $this->acknowledge($user, $known, true);
            }
            $done = $this->perform($user, $operation['type'], $operation['operation_id'], $target, $operation['base_version'], $operation['payload']);
            $row = SyncOperation::create([
                'user_id' => $user->id, 'operation_id' => $operation['operation_id'], 'type' => $operation['type'], 'context' => 'personal', 'fingerprint' => $fingerprint,
                'target_id' => $target, 'base_version' => $operation['base_version'], 'outcome' => $done['outcome'], 'code' => $done['code'],
                'result_id' => $done['result']['id'] ?? null, 'result_version' => $done['result']['version'] ?? null, 'result_status' => $done['result']['status'] ?? null,
                'errors' => $done['errors'] === [] ? null : $done['errors'], 'created_at' => now(),
            ]);

            return $this->acknowledge($user, $row, false);
        }, 3);
    }

    /**
     * Supprime les accusés trop anciens. Une opération rejouée ensuite reste sans effet : les services reconnaissent une création
     * ou une modification déjà faite, et refusent une version dépassée.
     */
    public function prune(int $days): int
    {
        return SyncOperation::query()->where('created_at', '<', now()->subDays(max(1, $days)))->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{outcome: string, code: string|null, result: array{id: string, version: int, status: string}|null, errors: array<string, list<string>>}
     */
    private function perform(User $user, string $type, string $operationId, ?string $target, int $baseVersion, array $payload): array
    {
        $rejected = fn (string $code, array $errors = []): array => ['outcome' => 'rejected', 'code' => $code, 'result' => null, 'errors' => $errors];
        try {
            $data = $this->validated($type, $target, $baseVersion, $payload);
            if ($type === 'check_in.save') {
                $checkIn = $this->checkIns->save($user, $target, ['action' => 'save', 'expected_version' => $baseVersion, ...$data])['checkIn'];

                return ['outcome' => 'applied', 'code' => null, 'errors' => [], 'result' => ['id' => $checkIn->course_occurrence_id, 'version' => $checkIn->lock_version, 'status' => $checkIn->status]];
            }
            $task = $type === 'task.create'
                ? $this->tasks->createPersonal($user, ['client_key' => $operationId, ...$data])['task']
                : $this->tasks->updatePersonal($user, $target, ['expected_version' => $baseVersion, ...$data])['task'];

            return ['outcome' => 'applied', 'code' => null, 'errors' => [], 'result' => ['id' => $task->id, 'version' => $task->lock_version, 'status' => $task->status]];
        } catch (ValidationException $exception) {
            return $rejected('VALIDATION_FAILED', $exception->errors());
        } catch (ModelNotFoundException) {
            return $rejected('PARENT_UNAVAILABLE');
        } catch (HttpExceptionInterface $exception) {
            return match ($exception->getStatusCode()) {
                409 => ['outcome' => 'conflict', 'code' => 'VERSION_CONFLICT', 'result' => null, 'errors' => []],
                404 => $rejected('PARENT_UNAVAILABLE'),
                403 => $rejected('ACCESS_FORBIDDEN'),
                default => throw $exception,
            };
        }
    }

    /**
     * Contrôle le contenu selon le type : uniquement les champs attendus, rien d'institutionnel.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validated(string $type, ?string $target, int $baseVersion, array $payload): array
    {
        $task = ['title' => 'required|string|max:255', 'description' => 'present|nullable|string|max:5000', 'due_at' => 'present|nullable|date_format:Y-m-d\TH:i',
            'priority' => 'required|in:'.implode(',', array_keys(Task::PRIORITIES)), 'personal_subject_id' => 'present|nullable|integer|min:1'];
        $rules = match ($type) {
            'task.create' => [...$task, 'source_occurrence_id' => 'present|nullable|uuid'],
            'task.update' => [...$task, 'status' => 'required|in:'.implode(',', array_keys(Task::STATUSES))],
            default => ['summary' => 'present|nullable|string|max:2000', 'work_to_do' => 'present|nullable|string|max:2000', 'points_to_review' => 'present|nullable|string|max:2000',
                'understanding' => 'present|nullable|integer|between:1,4'],
        };
        $shape = [];
        if (array_diff(array_keys($payload), array_keys($rules)) !== []) {
            $shape['payload'] = ['Ce brouillon contient un champ qui ne peut pas être synchronisé.'];
        }
        if (($type === 'task.create') !== ($target === null) || ($type === 'task.create' && $baseVersion !== 0) || ($type === 'task.update' && $baseVersion < 1)) {
            $shape['target_id'] = ['La cible de cette opération est incohérente.'];
        }
        if ($shape !== []) {
            throw ValidationException::withMessages($shape);
        }

        return Validator::make($payload, $rules)->validate();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function acknowledge(User $user, SyncOperation $row, bool $replayed): array
    {
        $code = $row->outcome === 'applied' ? 'APPLIED' : (string) $row->code;

        return ['status' => ['applied' => 200, 'conflict' => 409, 'rejected' => 422][$row->outcome], 'body' => [
            'operation_id' => $row->operation_id, 'outcome' => $row->outcome, 'code' => $code, 'message' => self::MESSAGES[$code] ?? 'La demande n’a pas pu être traitée.', 'replayed' => $replayed,
            'result' => $row->outcome === 'applied' ? ['id' => $row->result_id, 'version' => $row->result_version, 'status' => $row->result_status] : null,
            // L'état courant n'est jamais lu dans le registre : il est relu maintenant, chez le propriétaire seulement.
            'current' => $row->outcome === 'conflict' ? $this->current($user, $row) : null,
            'errors' => (object) ($row->errors ?? []),
        ]];
    }

    /**
     * État actuel de l'objet du compte, pour montrer les différences avec son brouillon. Uniquement ses propres données :
     * une tâche personnelle dont il est propriétaire, ou son propre retour. Aucune donnée du cours ni de l'établissement.
     *
     * @return array<string, mixed>|null
     */
    private function current(User $user, SyncOperation $row): ?array
    {
        if ($row->type === 'check_in.save') {
            $checkIn = CourseCheckIn::query()->where('owner_id', $user->id)->where('course_occurrence_id', $row->target_id)->first();

            return $checkIn ? ['version' => $checkIn->lock_version, 'status' => $checkIn->status, 'summary' => $checkIn->summary, 'work_to_do' => $checkIn->work_to_do,
                'points_to_review' => $checkIn->points_to_review, 'understanding' => $checkIn->understanding] : null;
        }
        $task = Task::query()->where('scope', 'personal')->where('owner_id', $user->id)->whereNull('deleted_at')
            ->when($row->type === 'task.create', fn ($query) => $query->where('client_key', $row->operation_id), fn ($query) => $query->whereKey($row->target_id))->first();
        $timezone = $user->personalSpace?->timezone ?? config('app.display_timezone');

        return $task ? ['version' => $task->lock_version, 'title' => $task->title, 'description' => $task->description, 'due_at' => $task->due_at?->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'priority' => $task->priority, 'status' => $task->status, 'personal_subject_id' => $task->personal_subject_id] : null;
    }
}
