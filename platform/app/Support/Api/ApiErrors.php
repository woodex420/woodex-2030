<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Support\TwoWritersMet;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The one handler of whatever goes wrong under /api/v1 (master list T-063).
 *
 * Registered first in bootstrap/app.php. A refused field, a record that is not there, a wrong method, too many
 * requests, a part that is switched off and a fault of the program all leave in one shape (ApiAnswer::error), each
 * with one of the codes below and with the number of the request.
 *
 * What a client is told is decided here and not by the exception: the sentence of a code is fixed, and only a
 * Problem, which the application wrote on purpose, brings its own. So the name of a class, a line of SQL or a
 * path on the server can never be part of an answer, with debugging switched on or off. (Before this, an address
 * of a photograph that is not there answered "No query results for model [App\Domain\Website\Models\Media] 1".)
 */
final class ApiErrors
{
    /**
     * Every code an answer can carry, with the sentence that goes with it. A closed list: a client may decide by
     * the code. The rules document says when each is given.
     */
    public const array CODES = [
        'BAD_REQUEST' => 'The request cannot be read.',
        'PARAMETER_NOT_ALLOWED' => 'This address does not take that parameter.',
        'IDEMPOTENCY_KEY_REQUIRED' => 'Send an Idempotency-Key header with a new UUID for each thing you create.',
        'UNAUTHENTICATED' => 'Sign in first.',
        'FORBIDDEN' => 'You may not do this.',
        'NOT_FOUND' => 'Nothing is at this address.',
        'METHOD_NOT_ALLOWED' => 'This address does not take that method.',
        'CONFLICT' => TwoWritersMet::MESSAGE,
        'IDEMPOTENCY_KEY_REUSED' => 'This Idempotency-Key was already used for a request with other content. Make a new key for a new request.',
        'PAYLOAD_TOO_LARGE' => 'The request is too large.',
        'UNSUPPORTED_MEDIA_TYPE' => 'Send the body as JSON, with Content-Type: application/json.',
        'VALIDATION_FAILED' => 'The submitted data is invalid.',
        'TOO_MANY_REQUESTS' => 'Too many requests. Wait before you try again.',
        'SERVER_ERROR' => 'Something went wrong on our side. Give the request number when you ask for help.',
        'UNAVAILABLE' => 'This is not available at the moment. Try again later.',
    ];

    /** The code of a status that somebody else's code answered with (the framework, a package). */
    private const array BY_STATUS = [
        400 => 'BAD_REQUEST', 401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED',
        409 => 'CONFLICT', 413 => 'PAYLOAD_TOO_LARGE', 415 => 'UNSUPPORTED_MEDIA_TYPE', 422 => 'VALIDATION_FAILED',
        429 => 'TOO_MANY_REQUESTS', 500 => 'SERVER_ERROR', 503 => 'UNAVAILABLE',
    ];

    /** Is this request for an address under /api/v1? */
    public static function covers(Request $request): bool
    {
        return $request->is('api/v1', 'api/v1/*');
    }

    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! self::covers($request)) {
            return null;
        }

        if ($exception instanceof Problem) {
            return ApiAnswer::error($exception->problem, $exception->getMessage(), $exception->getStatusCode(), $exception->details, $exception->getHeaders());
        }

        if ($exception instanceof ValidationException) {
            return self::answer('VALIDATION_FAILED', 422, $exception->errors());
        }

        // The database ended this request because somebody else was writing the same records (T-093).
        if (TwoWritersMet::ended($exception)) {
            return self::answer('CONFLICT', 409);
        }

        if ($exception instanceof AuthenticationException) {
            return self::answer('UNAUTHENTICATED', 401);
        }

        if (! $exception instanceof HttpExceptionInterface) {
            return self::answer('SERVER_ERROR', 500);
        }

        // A status this list does not know is answered as its family: a refusal as 400, a fault as 500.
        $status = $exception->getStatusCode();
        $status = isset(self::BY_STATUS[$status]) ? $status : ($status >= 500 ? 500 : 400);

        // Allow, Retry-After and the limits of the throttle are part of the answer; they hold no text of ours.
        return self::answer(self::BY_STATUS[$status], $status, [], $exception->getHeaders());
    }

    /**
     * @param  array<string, list<string>>  $details
     * @param  array<string, mixed>  $headers
     */
    public static function answer(string $code, int $status, array $details = [], array $headers = []): JsonResponse
    {
        return ApiAnswer::error($code, self::CODES[$code], $status, $details, $headers);
    }
}
