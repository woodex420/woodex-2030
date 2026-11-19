<?php

declare(strict_types=1);

use App\Support\Api\ApiAnswer;
use App\Support\RequestNumber;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Support\ApiContract;

/*
| The one builder of the answers of /api/v1 (App\Support\Api\ApiAnswer; master list T-063).
|
| No address answers in pages yet: the first list of records will be the products (T-105). The rule for a page is
| written and held here, so that the first list starts from it: meta.page, meta.per_page, meta.total and three
| links, compared with the contract's PageMeta and PageLinks.
*/

beforeEach(function () {
    // Twelve records, asked for by the page, by a route that names the query parameters it takes.
    Route::get('/__records', function () {
        $perPage = (int) request()->query('per_page', 25);
        $number = (int) request()->query('page', 1);
        $records = array_map(fn (int $id): array => ['id' => $id, 'internal' => 'not for a client'], range(1, 12));

        return ApiAnswer::page(
            new LengthAwarePaginator(array_slice($records, ($number - 1) * $perPage, $perPage), count($records), $perPage, $number, ['path' => 'https://admin.example/api/v1/records']),
            fn (array $record): array => ['id' => $record['id']],
        );
    })->middleware('api.v1:query=page|per_page|category');
});

it('writes one page of a list as the contract says, with links that hold on any site', function () {
    $contract = ApiContract::read();
    $answer = $this->getJson('/__records?category=desks&page=2&per_page=5')->assertOk();
    $body = json_decode((string) $answer->getContent());

    expect(array_keys(get_object_vars($body)))->toBe(['data', 'meta', 'links'])
        ->and($contract->differences($body->meta, $contract->schema('PageMeta')))->toBe([])
        ->and($contract->differences($body->links, $contract->schema('PageLinks')))->toBe([])
        ->and($answer->json('data'))->toBe([['id' => 6], ['id' => 7], ['id' => 8], ['id' => 9], ['id' => 10]])
        ->and($answer->json('meta'))->toBe(['request_id' => $answer->headers->get(RequestNumber::HEADER), 'page' => 2, 'per_page' => 5, 'total' => 12])
        // An address on this site without its name, with what was asked for kept.
        ->and($answer->json('links'))->toBe([
            'self' => '/api/v1/records?category=desks&page=2&per_page=5',
            'next' => '/api/v1/records?category=desks&page=3&per_page=5',
            'prev' => '/api/v1/records?category=desks&page=1&per_page=5',
        ]);
});

it('ends the links where the list ends', function () {
    expect($this->getJson('/__records?page=1&per_page=5')->json('links.prev'))->toBeNull()
        ->and($this->getJson('/__records?page=3&per_page=5')->json('links.next'))->toBeNull()
        ->and($this->getJson('/__records?page=3&per_page=5')->json('data'))->toBe([['id' => 11], ['id' => 12]])
        // A page beyond the end is empty, and says how long the list is.
        ->and($this->getJson('/__records?page=9&per_page=5')->json())->toMatchArray(['data' => []])
        ->and($this->getJson('/__records?page=9&per_page=5')->json('meta.total'))->toBe(12);
});

it('lets through the query parameters a route names, and refuses any other', function () {
    $this->getJson('/__records?page=1&per_page=5&category=desks')->assertOk();
    $this->getJson('/__records?page=1&sort=name')->assertStatus(400);
    $this->getJson('/__records?filter[status]=draft')->assertStatus(400);
});

it('refuses to send a fraction, wherever it stands in the answer', function () {
    expect(fn () => ApiAnswer::one(['total_minor' => 10500.5]))->toThrow(LogicException::class, 'data.total_minor')
        ->and(fn () => ApiAnswer::whole([['lines' => [['qty' => 1], ['qty' => 2.5]]]]))->toThrow(LogicException::class, 'data.0.lines.1.qty')
        ->and(ApiAnswer::one(['total_minor' => 1050050, 'currency' => 'PKR', 'qty' => '2.5'])->getStatusCode())->toBe(200);
});

it('writes a moment in UTC, to the second, whatever the clock of the site', function () {
    expect(config('app.timezone'))->toBe('Asia/Karachi')
        ->and(ApiAnswer::time(Carbon::parse('2026-11-19 09:15:30.987654', 'Asia/Karachi')))->toBe('2026-11-19T04:15:30Z')
        ->and(ApiAnswer::time(new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('America/New_York'))))->toBe('2026-01-01T05:00:00Z')
        ->and(ApiAnswer::time(null))->toBeNull();
});

it('keeps a list a list and an object an object when they are empty', function () {
    expect(ApiAnswer::whole([])->getContent())->toContain('"data":[]')->toContain('"total":0')
        ->and(ApiAnswer::error('NOT_FOUND', 'Nothing is at this address.', 404)->getContent())->toContain('"details":{}')
        ->and(ApiAnswer::one([])->getContent())->toContain('"data":{}');
});

it('gives every answer the number of the request, and says nobody is to keep it', function () {
    foreach ([ApiAnswer::one(['reference' => 'L-1']), ApiAnswer::whole([]), ApiAnswer::error('NOT_FOUND', 'Nothing is at this address.', 404)] as $answer) {
        expect(json_decode((string) $answer->getContent(), true)['meta']['request_id'])->toBe(RequestNumber::current())
            ->and((string) $answer->headers->get('Cache-Control'))->toContain('no-store')
            ->and((string) $answer->headers->get('X-Robots-Tag'))->toContain('noindex');
    }

    // An address may say otherwise, and is heard.
    expect((string) ApiAnswer::one(['reference' => 'L-1'], 201, ['Cache-Control' => 'no-store, private'])->headers->get('Cache-Control'))->toContain('private');
});
