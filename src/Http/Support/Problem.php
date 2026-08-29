<?php

declare(strict_types=1);

namespace Padosoft\Routines\Http\Support;

use Illuminate\Http\JsonResponse;

/**
 * Risposte di errore in `application/problem+json` (RFC 9457).
 *
 * Lo stesso formato dell'Admin API di iam-server, e non per uniformità estetica: un client che
 * sa leggere un problem+json lo sa leggere per tutto l'ecosistema, e `detail` è sempre una frase
 * che si può mostrare a una persona senza rielaborarla.
 */
final class Problem
{
    /** @param array<string, list<string>> $errors */
    public static function validation(string $detail, array $errors = []): JsonResponse
    {
        return self::make('validation', 'Il dato inviato non è valido', 422, $detail, ['errors' => $errors]);
    }

    public static function notFound(string $detail = 'La risorsa richiesta non esiste.'): JsonResponse
    {
        return self::make('not-found', 'Non trovata', 404, $detail);
    }

    public static function forbidden(string $detail): JsonResponse
    {
        return self::make('forbidden', 'Non autorizzato', 403, $detail);
    }

    public static function conflict(string $detail): JsonResponse
    {
        return self::make('conflict', 'Conflitto', 409, $detail);
    }

    /** @param array<string, mixed> $extra */
    public static function make(string $type, string $title, int $status, string $detail, array $extra = []): JsonResponse
    {
        return new JsonResponse(array_merge([
            'type' => 'https://padosoft.dev/problems/'.$type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $extra), $status, ['Content-Type' => 'application/problem+json']);
    }
}
