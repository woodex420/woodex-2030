<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Support\RequestNumber;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use LogicException;
use stdClass;

/**
 * The one builder of every JSON answer under /api/v1 (master list T-063).
 *
 * The rules are docs/woodex-os/29-API-V1-RULES.md; the contract, address by address and key by key, is
 * resources/api/v1.openapi.json, and tests/Feature/Api/V1ContractTest.php holds every answer to it.
 *
 *   a success   {"data": …, "meta": {"request_id": "4F7K-9Q2M-T8RB"}}
 *   a page      {"data": […], "meta": {"request_id": …, "page": 1, "per_page": 25, "total": 0}, "links": {…}}
 *   an error    {"error": {"code": "NOT_FOUND", "message": "…", "details": {}}, "meta": {"request_id": …}}
 *
 * The request number is the one in the X-Request-Id header (App\Support\RequestNumber, T-062): the same number
 * finds the request's lines in the log. An error is made here and nowhere else: App\Support\Api\ApiErrors turns
 * whatever goes wrong under /api/v1 into error().
 *
 * Two rules about values are kept here, because a client cannot repair them afterwards:
 *   - money is a whole number of the smallest unit (paisa), in a key that ends in _minor, with its currency beside
 *     it. No answer carries a fraction: send() refuses one.
 *   - a moment is written in UTC, to the second, ending in Z: time() writes it.
 */
final class ApiAnswer
{
    /** On every JSON answer unless the address says otherwise: not kept by anyone in between, not listed by a search engine. */
    private const array HEADERS = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow'];

    /**
     * One thing: a record that was made, a result, or one document made of several lists.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function one(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return self::send(['data' => $data === [] ? new stdClass : $data, 'meta' => self::meta()], $status, $headers);
    }

    /**
     * A list that a client needs whole to work with (the photographs that were replaced, for one). It is never cut
     * into pages: a client that read page one before a change and page two after it would hold a list that never
     * existed. Its size is in meta.total.
     *
     * @param  array<int, mixed>  $list
     * @param  array<string, string>  $headers
     */
    public static function whole(array $list, array $headers = []): JsonResponse
    {
        $list = array_values($list);

        return self::send(['data' => $list, 'meta' => [...self::meta(), 'total' => count($list)]], 200, $headers);
    }

    /**
     * One page of a list of records. The links are addresses on this site without its name, so that they are as
     * true for the shop's own server, which hands these answers on, as they are here.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  (callable(mixed): mixed)|null  $each  what a record looks like in the answer
     */
    public static function page(LengthAwarePaginator $page, ?callable $each = null): JsonResponse
    {
        $path = '/'.ltrim((string) parse_url($page->path(), PHP_URL_PATH), '/');
        $asked = array_diff_key(request()->query(), ['page' => true, 'per_page' => true]);
        $address = static fn (?int $number): ?string => $number === null || $number < 1 || $number > max(1, $page->lastPage())
            ? null
            : $path.'?'.http_build_query([...$asked, 'page' => $number, 'per_page' => $page->perPage()]);

        return self::send([
            'data' => array_values($each === null ? $page->items() : array_map($each, $page->items())),
            'meta' => [...self::meta(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'links' => [
                'self' => $address($page->currentPage()),
                'next' => $address($page->currentPage() + 1),
                'prev' => $address($page->currentPage() - 1),
            ],
        ], 200, []);
    }

    /**
     * What went wrong, for a program (the code, one of ApiErrors::CODES) and for a person (the message).
     *
     * @param  array<string, list<string>>  $details  by name: the messages of a refused field, or the names that are meant
     * @param  array<string, mixed>  $headers
     */
    public static function error(string $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'error' => ['code' => $code, 'message' => $message, 'details' => (object) $details],
            'meta' => self::meta(),
        ], $status, $headers + self::HEADERS);
    }

    /** A moment as every answer writes it: UTC, to the second, "2026-11-19T04:15:00Z". */
    public static function time(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** @return array{request_id: string} */
    private static function meta(): array
    {
        return ['request_id' => RequestNumber::current()];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private static function send(array $body, int $status, array $headers): JsonResponse
    {
        self::refuseFractions($body['data'], 'data');

        return new JsonResponse($body, $status, $headers + self::HEADERS);
    }

    /** A fraction in an answer is a fault of the program, found here and not by a client's arithmetic. */
    private static function refuseFractions(mixed $value, string $where): void
    {
        if (is_float($value)) {
            throw new LogicException("An answer of /api/v1 carries a fraction at {$where}. Money is a whole number of minor units in a key ending in _minor; anything else that is not whole is written as text.");
        }

        if (is_iterable($value)) {
            foreach ($value as $key => $inner) {
                self::refuseFractions($inner, $where.'.'.$key);
            }
        }
    }
}
