<?php

namespace App\Http\Controllers;

use App\Models\SyncOperation;
use App\Services\OfflineSnapshot;
use App\Services\SyncOperations;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Point d'entrée JSON versionné de la synchronisation des brouillons personnels. Authentification : la session du site et son jeton CSRF,
 * comme toute écriture de Campus ; il n'existe ni jeton d'API ni accès sans session. Une opération ne désigne jamais un autre compte :
 * le propriétaire est toujours le compte de la session.
 */
class SyncController extends Controller
{
    private const MAX_BYTES = 32_768;

    public function store(Request $r, SyncOperations $operations): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];
        abort_if(strlen($r->getContent()) > self::MAX_BYTES, 413);
        // Le corps est lu tel qu'il a été envoyé : l'empreinte porte sur le contenu exact écrit sur l'appareil.
        $raw = json_decode($r->getContent(), true);
        $integer = fn (string $attribute, mixed $value, Closure $fail) => is_int($value) && $value >= 0 && $value <= 4_000_000_000 ? null : $fail('Version invalide.');
        $data = Validator::make(is_array($raw) ? $raw : [], [
            'operation_id' => 'required|uuid', 'account_key' => 'required|string|size:32',
            // Seul l'espace personnel se synchronise : toute opération d'établissement se fait en ligne, sur ses propres écrans.
            'context' => 'required|in:personal', 'type' => 'required|in:'.implode(',', SyncOperation::TYPES),
            'target_id' => 'nullable|uuid', 'base_version' => ['present', $integer], 'fingerprint' => ['required', 'regex:/^[0-9a-f]{64}$/'], 'payload' => 'required|array',
        ])->validate();
        if (! hash_equals(OfflineSnapshot::accountKey($r->user()), $data['account_key'])) {
            // Brouillon écrit par un autre compte sur cet appareil : jamais appliqué au compte connecté, et rien n'est enregistré.
            return response()->json(['operation_id' => $data['operation_id'], 'outcome' => 'rejected', 'code' => 'ACCOUNT_MISMATCH',
                'message' => 'Ce brouillon a été écrit avec un autre compte.', 'replayed' => false, 'result' => null, 'current' => null, 'errors' => (object) []], 403, $headers);
        }
        $acknowledgement = $operations->apply($r->user(), $data);

        return response()->json($acknowledgement['body'], $acknowledgement['status'], $headers);
    }
}
