<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Api\ApiErrors;
use App\Support\Api\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door of every address under /api/v1 (master list T-063; the rules are docs/woodex-os/29-API-V1-RULES.md).
 *
 * Each route names it once, with what that address takes:
 *
 *   api.v1                          no query parameter, no key
 *   api.v1:key                      a create: the Idempotency-Key header is required
 *   api.v1:query=page|per_page      the query parameters this address takes, and no others
 *
 * What is refused here, before the request is looked at any further:
 *   - a query parameter the address does not name (400, PARAMETER_NOT_ALLOWED). It is refused, not ignored: a
 *     client that asked for "?status=draft" and was given the usual list would believe it had been filtered.
 *   - a body that is not JSON (415), or is JSON but not an object (400). JSON only is also what keeps a form on
 *     somebody else's website from posting here: a browser asks first (CORS) before it sends JSON.
 *   - a create without its key, or with a key that is not a UUID (400, IDEMPOTENCY_KEY_REQUIRED).
 *
 * tests/Feature/Api/V1ContractTest.php requires every route under /api/v1 to carry this, and what it is given here
 * to be what the contract (resources/api/v1.openapi.json) says about that address.
 */
final class ApiV1
{
    public const string KEY_HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next, string ...$takes): Response
    {
        ['query' => $query, 'key' => $key] = self::read($takes);

        $refused = array_values(array_diff(array_map(strval(...), array_keys($request->query())), $query));

        if ($refused !== []) {
            throw new Problem(400, 'PARAMETER_NOT_ALLOWED', ApiErrors::CODES['PARAMETER_NOT_ALLOWED'], ['refused' => $refused, 'allowed' => $query]);
        }

        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            if (! $request->isJson()) {
                throw new Problem(415, 'UNSUPPORTED_MEDIA_TYPE', ApiErrors::CODES['UNSUPPORTED_MEDIA_TYPE']);
            }

            $body = json_decode($request->getContent(), true);

            // "[]" is let through: it is what an empty form looks like when a program writes it down.
            if (! is_array($body) || ($body !== [] && array_is_list($body))) {
                throw new Problem(400, 'BAD_REQUEST', 'The body is not a JSON object.');
            }
        }

        if ($key && ! Str::isUuid((string) $request->header(self::KEY_HEADER))) {
            throw new Problem(400, 'IDEMPOTENCY_KEY_REQUIRED', ApiErrors::CODES['IDEMPOTENCY_KEY_REQUIRED']);
        }

        return $next($request);
    }

    /**
     * What a route gave this middleware, read the same way by the contract test.
     *
     * @param  array<int, string>  $takes
     * @return array{query: list<string>, key: bool}
     */
    public static function read(array $takes): array
    {
        $query = [];
        $key = false;

        foreach ($takes as $take) {
            if ($take === 'key') {
                $key = true;
            } elseif (str_starts_with($take, 'query=')) {
                $query = array_values(array_filter(explode('|', substr($take, 6)), static fn (string $name): bool => $name !== ''));
            }
        }

        return ['query' => $query, 'key' => $key];
    }
}
