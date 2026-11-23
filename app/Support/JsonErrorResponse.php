<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class JsonErrorResponse
{
    public static function render(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();
        if (! $request->expectsJson() || $status < 400) {
            return $response;
        }
        [$code,$message] = match ($status) {
            401 => ['SESSION_ABSENT', 'Votre session est absente ou expirée. Reconnectez-vous.'],
            403 => ['ACCESS_FORBIDDEN', 'Cette action n’est pas autorisée.'],
            404 => ['RESOURCE_NOT_FOUND', 'Cette ressource est introuvable.'],
            409 => ['VERSION_CONFLICT', 'La référence a changé. Rechargez les données avant de réessayer.'],
            413 => ['QUOTA_EXCEEDED', 'La taille autorisée est dépassée.'],
            423 => ['FILE_NOT_AVAILABLE', 'Ce fichier n’est pas disponible : il attend son analyse antivirus ou a été refusé.'],
            419 => ['SESSION_EXPIRED', 'Votre session a expiré. Rechargez la page.'],
            422 => ['VALIDATION_FAILED', 'Vérifiez les champs indiqués.'],
            429 => ['RATE_LIMITED', 'Trop de demandes. Réessayez plus tard.'],
            default => ['REQUEST_FAILED', 'La demande n’a pas pu être traitée.'],
        };
        $id = (string) Str::uuid();
        $errors = $exception instanceof ValidationException ? $exception->errors() : [];
        $headers = ['X-Request-ID' => $id, 'Cache-Control' => 'no-store, private'];
        if ($response->headers->has('Retry-After')) {
            $headers['Retry-After'] = $response->headers->get('Retry-After');
        }

        return response()->json(['code' => $code, 'message' => $message, 'errors' => (object) $errors, 'request_id' => $id], $status, $headers);
    }
}
