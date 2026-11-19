<?php

declare(strict_types=1);

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Services\CategoryContent;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Services\StorefrontMedia;
use App\Http\Controllers\Api\V1\UnknownAddress;
use App\Http\Middleware\ApiV1;
use App\Http\Requests\Api\StorePublicLeadRequest;
use App\Http\Requests\Api\StoreQuoteRequest;
use App\Settings\BusinessSettings;
use App\Support\Api\ApiErrors;
use App\Support\RequestNumber;
use Illuminate\Database\DeadlockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;

/*
| The contract of /api/v1 (master list T-063).
|
| The rules are docs/woodex-os/29-API-V1-RULES.md. The contract is resources/api/v1.openapi.json: every address,
| what it takes, and every answer it can give, key by key. This file holds the application to it, in both
| directions:
|
|   - every route under /api/v1 is in the contract, and everything in the contract is a route. A route added
|     without its entry fails here by name; so does an entry without its route.
|   - every answer the contract documents is asked for and compared with it key by key: the successes, a refused
|     field, a record that is not there, too many requests, a part that is switched off, a fault. An answer that
|     carries a key the contract does not name fails, and so does one that lacks a key it names.
|   - every answer this file asks for is documented.
|
| Tried against what it protects (release report 30): with one address left in its old shape, the comparison
| names that address and the keys.
*/

/** What each address is documented to answer, and the code an error must carry. The contract must say the same. */
const V1_ANSWERS = [
    'api.v1.leads.store' => [
        200 => null, 201 => null, 202 => null, 400 => 'IDEMPOTENCY_KEY_REQUIRED', 404 => 'NOT_FOUND', 409 => 'IDEMPOTENCY_KEY_REUSED',
        415 => 'UNSUPPORTED_MEDIA_TYPE', 422 => 'VALIDATION_FAILED', 429 => 'TOO_MANY_REQUESTS', 500 => 'SERVER_ERROR',
    ],
    'api.v1.leads.handoff' => [
        200 => null, 400 => 'PARAMETER_NOT_ALLOWED', 404 => 'NOT_FOUND', 409 => 'CONFLICT', 415 => 'UNSUPPORTED_MEDIA_TYPE',
        422 => 'VALIDATION_FAILED', 429 => 'TOO_MANY_REQUESTS', 500 => 'SERVER_ERROR', 503 => 'UNAVAILABLE',
    ],
    'api.v1.quote-requests.store' => [
        201 => null, 202 => null, 400 => 'IDEMPOTENCY_KEY_REQUIRED', 404 => 'NOT_FOUND', 409 => 'IDEMPOTENCY_KEY_REUSED',
        415 => 'UNSUPPORTED_MEDIA_TYPE', 422 => 'VALIDATION_FAILED', 429 => 'TOO_MANY_REQUESTS', 500 => 'SERVER_ERROR',
    ],
    'api.v1.catalogue.categories' => [200 => null, 400 => 'PARAMETER_NOT_ALLOWED', 429 => 'TOO_MANY_REQUESTS', 500 => 'SERVER_ERROR', 503 => 'UNAVAILABLE'],
    'api.v1.catalogue.categories.hero' => [200 => null, 400 => 'PARAMETER_NOT_ALLOWED', 404 => 'NOT_FOUND', 429 => 'TOO_MANY_REQUESTS', 500 => 'SERVER_ERROR'],
    'api.v1.media' => [200 => null, 400 => 'PARAMETER_NOT_ALLOWED', 429 => 'TOO_MANY_REQUESTS', 500 => 'SERVER_ERROR'],
    'api.v1.media.file' => [200 => null, 400 => 'PARAMETER_NOT_ALLOWED', 404 => 'NOT_FOUND', 429 => 'TOO_MANY_REQUESTS', 500 => 'SERVER_ERROR'],
];

dataset('every documented answer', function () {
    foreach (V1_ANSWERS as $operation => $answers) {
        foreach ($answers as $status => $code) {
            yield "{$operation} answers {$status}" => [$operation, $status, $code];
        }
    }
});

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    config(['modules.enabled' => 'lead_api,quote_requests', 'woodex.category_sync_enabled' => true]);

    $business = app(BusinessSettings::class);
    $business->whatsapp = '+923001234567';
    $business->save();

    CatalogItem::create(['description' => 'SYNTHETIC Furniture desk', 'category' => 'Synthetic', 'unit' => 'each',
        'rate_minor' => 123456, 'business_line' => 'furniture', 'is_active' => true, 'storefront_product_id' => 'synthetic-desk']);

    $this->contract = ApiContract::read();

    $this->key = fn (): array => ['Idempotency-Key' => (string) Str::uuid()];
    $this->enquiry = fn (array $more = []): array => ['name' => 'Ayesha Khan', 'email' => 'ayesha@example.test', 'message' => 'A desk for six.', ...$more];
    $this->basket = fn (array $more = []): array => ['name' => 'Bilal Ahmed', 'email' => 'bilal@example.test', 'consent' => true,
        'items' => [['product_id' => 'synthetic-desk', 'qty' => 2]], ...$more];

    // An enquiry whose visitor chose WhatsApp: its answer carries the token of the hand-off.
    $this->token = fn (): string => (string) $this->postJson('/api/v1/leads', ($this->enquiry)(['consent' => true, 'preferred_channel' => 'whatsapp']), ($this->key)())
        ->assertCreated()->json('data.handoff.token');

    // A category page with a picture and a question, published as an editor publishes it.
    $this->publishedPage = function (): array {
        $admin = $this->userWithRole('admin');
        $category = Category::where('slug', 'executive-tables')->sole();
        $service = app(CategoryContent::class);
        $form = [...$service->defaults($category), 'hero_alt' => 'A desk by a window', 'intro_text' => '**Made to order**',
            'carousel_mode' => 'automatic', 'faqs' => [['question' => 'How long does it take?', 'answer' => 'Three weeks.']]];
        $revision = $service->saveDraft($category, $form, UploadedFile::fake()->image('hero.png')->store('commerce/category-heroes', 'local'), 0, $admin);
        $this->actingAs($admin)->get(route('admin.commerce.category-content.preview', $revision))->assertOk();
        $service->publish($category, $revision->id, 1, $admin);

        return [$category->id, $revision->id];
    };

    // A photograph of the shop, replaced in the library and published.
    $this->publishedPhoto = function (): Media {
        // (A made-up picture is a file for as long as somebody holds it: each is kept in a variable while it is read.)
        $original = UploadedFile::fake()->image('original.png', 20, 20);
        $replacement = UploadedFile::fake()->image('new.png', 30, 30);
        Storage::disk('public')->put('website/original.png', file_get_contents($original->getRealPath()));
        $media = Media::create(['disk' => 'public', 'path' => 'website/original.png', 'title' => 'Original']);
        $media->forceFill(['storefront_key' => '/img/original.png'])->save();
        app(StorefrontMedia::class)->replace($media, file_get_contents($replacement->getRealPath()), $media->path, true, $this->userWithRole('owner'));

        return $media->fresh();
    };

    // Something breaks while an address under /api/v1 is being answered: a fault of the program, or the database
    // ending the request for another writer. The sentence is what must never reach a client.
    $this->breakIt = function (bool $anotherWriter = false): void {
        Event::listen(RouteMatched::class, function (RouteMatched $event) use ($anotherWriter): void {
            if (ApiErrors::covers($event->request) && $event->route->getName() !== UnknownAddress::ROUTE) {
                throw $anotherWriter
                    ? new DeadlockException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')
                    : new RuntimeException('SQLSTATE[HY000]: General error in /var/www/platform/app/Secret.php on line 7');
            }
        });
    };

    // The same request until the address has had enough, and once more.
    $this->tooMany = function (int $allowed, Closure $ask): TestResponse {
        for ($sent = 0; $sent < $allowed; $sent++) {
            $ask();
        }

        return $ask();
    };

    /*
    | How each documented answer is brought about. $plain is a request the address answers with a success.
    */
    $this->ask = function (string $operation, int $status): TestResponse {
        $enquiry = fn (array $body, array $headers) => $this->postJson('/api/v1/leads', $body, $headers);
        $basket = fn (array $body, array $headers) => $this->postJson('/api/v1/quote-requests', $body, $headers);
        $handoff = fn (array $body) => $this->postJson('/api/v1/leads/whatsapp-handoff', $body);

        $plain = match ($operation) {
            'api.v1.leads.store' => fn () => $enquiry(($this->enquiry)(), ($this->key)()),
            'api.v1.leads.handoff' => function () use ($handoff) {
                $token = ($this->token)();

                return fn () => $handoff(['token' => $token]);
            },
            'api.v1.quote-requests.store' => fn () => $basket(($this->basket)(), ($this->key)()),
            'api.v1.catalogue.categories' => function () {
                ($this->publishedPage)();

                return fn () => $this->getJson('/api/v1/catalogue/categories');
            },
            'api.v1.catalogue.categories.hero' => function () {
                [$category, $revision] = ($this->publishedPage)();

                return fn () => $this->get("/api/v1/catalogue/categories/{$category}/hero/{$revision}");
            },
            'api.v1.media' => function () {
                ($this->publishedPhoto)();

                return fn () => $this->getJson('/api/v1/media');
            },
            'api.v1.media.file' => function () {
                $media = ($this->publishedPhoto)();

                return fn () => $this->get("/api/v1/media/{$media->id}/{$media->storefront_version_id}");
            },
        };
        // The ones that need something made first hand back the request itself.
        $plain = in_array($operation, ['api.v1.leads.store', 'api.v1.quote-requests.store'], true) ? $plain : $plain();
        $address = '/api/v1'.$this->contract->operations()[$operation]['path'];
        $path = str_replace(['{category}', '{revision}', '{media}', '{version}'], '1', $address);
        // The same address with numbers that name nothing.
        $missing = str_replace(['{category}', '{revision}', '{media}', '{version}'], '987654', $address);
        $isCreate = $this->contract->operations()[$operation]['method'] === 'POST';

        return match (true) {
            // What every address answers the same way.
            $status === 500 => (function () use ($plain) {
                ($this->breakIt)();

                return $plain();
            })(),
            $status === 429 => ($this->tooMany)(match ($operation) {
                'api.v1.leads.store', 'api.v1.quote-requests.store' => 5,
                'api.v1.leads.handoff' => 20,
                default => 120,
            }, fn () => $isCreate ? $this->postJson($path, []) : $this->get($path)),
            $status === 415 => $this->post($path, ['name' => 'Ayesha Khan'], ($this->key)()),
            $status === 400 && ! $isCreate => $this->get($path.'?sort=name'),

            // An enquiry.
            [$operation, $status] === ['api.v1.leads.store', 201] => $plain(),
            [$operation, $status] === ['api.v1.leads.store', 200] => (function () use ($enquiry, $plain) {
                $plain()->assertCreated();

                // The same visitor again, another request: it joins the enquiry that is open.
                return $enquiry(($this->enquiry)(['message' => 'And two chairs.']), ($this->key)());
            })(),
            [$operation, $status] === ['api.v1.leads.store', 202] => $enquiry(($this->enquiry)(['company_website' => 'https://robot.example']), ($this->key)()),
            [$operation, $status] === ['api.v1.leads.store', 400] => $enquiry(($this->enquiry)(), []),
            [$operation, $status] === ['api.v1.leads.store', 404] => (function () use ($plain) {
                config(['modules.enabled' => '']);

                return $plain();
            })(),
            [$operation, $status] === ['api.v1.leads.store', 409] => (function () use ($enquiry) {
                $key = ($this->key)();
                $enquiry(($this->enquiry)(), $key)->assertCreated();

                return $enquiry(($this->enquiry)(['message' => 'Something else altogether.']), $key);
            })(),
            [$operation, $status] === ['api.v1.leads.store', 422] => $enquiry([], ($this->key)()),

            // The hand-off.
            [$operation, $status] === ['api.v1.leads.handoff', 200] => $plain(),
            [$operation, $status] === ['api.v1.leads.handoff', 400] => $this->postJson($path.'?token='.str_repeat('a', 64), []),
            [$operation, $status] === ['api.v1.leads.handoff', 404] => $handoff(['token' => str_repeat('0', 64)]),
            [$operation, $status] === ['api.v1.leads.handoff', 409] => (function () use ($plain) {
                ($this->breakIt)(anotherWriter: true);

                return $plain();
            })(),
            [$operation, $status] === ['api.v1.leads.handoff', 422] => $handoff(['token' => 'not-a-token']),
            [$operation, $status] === ['api.v1.leads.handoff', 503] => (function () use ($plain) {
                $business = app(BusinessSettings::class);
                $business->whatsapp = '';
                $business->save();

                return $plain();
            })(),

            // A basket.
            [$operation, $status] === ['api.v1.quote-requests.store', 201] => $plain(),
            [$operation, $status] === ['api.v1.quote-requests.store', 202] => $basket(($this->basket)(['company_website' => 'https://robot.example']), ($this->key)()),
            [$operation, $status] === ['api.v1.quote-requests.store', 400] => $basket(($this->basket)(), ['Idempotency-Key' => 'not-a-uuid']),
            [$operation, $status] === ['api.v1.quote-requests.store', 404] => (function () use ($plain) {
                config(['modules.enabled' => 'lead_api']);

                return $plain();
            })(),
            [$operation, $status] === ['api.v1.quote-requests.store', 409] => (function () use ($basket) {
                $key = ($this->key)();
                $basket(($this->basket)(), $key)->assertCreated();

                return $basket(($this->basket)(['items' => [['product_id' => 'synthetic-desk', 'qty' => 3]]]), $key);
            })(),
            [$operation, $status] === ['api.v1.quote-requests.store', 422] => $basket(($this->basket)(['items' => [['product_id' => 'no-such-product', 'qty' => 1]]]), ($this->key)()),

            // What the shop reads.
            [$operation, $status] === ['api.v1.catalogue.categories', 503] => (function () use ($plain) {
                config(['woodex.category_sync_enabled' => false]);

                return $plain();
            })(),
            $status === 404 => $this->get($missing),
            $status === 200 => $plain(),
        };
    };
});

/* ------------------------------------------------------------------------------------------------------------------
| 1. The routes and the contract name the same addresses.
*/

it('names every route under /api/v1 in the contract, and has a route for everything the contract names', function () {
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! Str::startsWith($route->uri(), 'api/v1') || $route->getName() === UnknownAddress::ROUTE) {
            continue;
        }

        expect($route->getName())->toStartWith('api.v1.', "the route {$route->uri()} has no name of its own")
            ->and($route->getActionName())->toStartWith('App\\Http\\Controllers\\Api\\V1\\', "the route {$route->uri()} is answered from outside Api\\V1");

        $routes[$route->getName()] = implode('|', array_diff($route->methods(), ['HEAD'])).' /'.ltrim(Str::after($route->uri(), 'api/v1'), '/');
    }

    $contract = array_map(fn (array $entry): string => $entry['method'].' '.$entry['path'], $this->contract->operations());
    ksort($routes);

    // Read the difference: a name on the left only is a route without its contract; on the right only, the reverse.
    expect($routes)->toBe($contract)->and(count($routes))->toBe(7);
});

it('ends the group with one route for whatever nothing else owns, for every method', function () {
    $inGroup = array_values(array_filter(Route::getRoutes()->getRoutes(), fn ($route): bool => Str::startsWith($route->uri(), 'api/v1')));
    $last = end($inGroup);

    expect($last->getName())->toBe(UnknownAddress::ROUTE)
        ->and($last->methods())->toContain('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS')
        ->and($last->uri())->toBe('api/v1/{unknown?}');
});

it('gives every address its door, with what the contract says the address takes', function () {
    foreach ($this->contract->operations() as $name => $entry) {
        $doors = array_values(array_filter(Route::getRoutes()->getByName($name)->gatherMiddleware(), fn ($middleware): bool => is_string($middleware) && preg_match('/^api\.v1(:|$)/', $middleware) === 1));

        expect($doors)->toHaveCount(1, "{$name} carries the middleware api.v1 once");

        $takes = ApiV1::read(str_contains($doors[0], ':') ? explode(',', Str::after($doors[0], ':')) : []);

        expect($takes['query'])->toBe($this->contract->parameters($name, 'query'), "{$name}: the query parameters the route takes are the ones the contract names")
            ->and($takes['key'])->toBe($this->contract->parameters($name, 'header', requiredOnly: true) === ['Idempotency-Key'], "{$name}: a key is required where the contract says so, and only there");
    }

    // The creates that write a record ask for a key. The hand-off does not: its token is its key.
    expect($this->contract->parameters('api.v1.leads.store', 'header', requiredOnly: true))->toBe(['Idempotency-Key'])
        ->and($this->contract->parameters('api.v1.quote-requests.store', 'header', requiredOnly: true))->toBe(['Idempotency-Key'])
        ->and($this->contract->parameters('api.v1.leads.handoff', 'header'))->toBe([]);
});

/* ------------------------------------------------------------------------------------------------------------------
| 2. The contract is written so that it can be held.
*/

it('is written in words this test can check, with no fraction and no loose object in any answer', function () {
    $document = $this->contract->document();

    expect($document['openapi'])->toStartWith('3.1')
        ->and($this->contract->schema('ErrorCode')['enum'])->toBe(array_keys(ApiErrors::CODES), 'the codes of the contract are the codes of the application, in the same order')
        ->and($this->contract->schema('RequestNumber')['pattern'])->toBe('^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$');

    foreach ($this->contract->operations() as $name => $entry) {
        expect($entry['operation']['security'])->toBe([], "{$name} needs no sign-in, and says so");

        foreach ($this->contract->statuses($name) as $status) {
            $answer = $this->contract->answer($name, $status);

            expect($answer['headers'])->toContain('X-Request-Id');

            if ($status >= 400) {
                expect($answer['schema'])->toBe(['$ref' => '#/components/schemas/Error'], "{$name} {$status} is the one error shape");
            } elseif ($answer['schema'] !== null) {
                expect(array_keys($this->contract->resolve($answer['schema'])['properties']))->toBe(['data', 'meta'], "{$name} {$status} is {data, meta}");
            } else {
                expect($answer['types'])->toBe(['image/*'], "{$name} {$status} is a picture");
            }
        }
    }

    $problems = [];

    foreach ($this->contract->schemasOfAnswers() as $where => $schema) {
        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, ApiContract::KEYWORDS, true)) {
                $problems[] = "{$where}: \"{$keyword}\" is a word this test cannot check";
            }
        }
        if (in_array('number', (array) ($schema['type'] ?? []), true)) {
            $problems[] = "{$where}: a fraction. Money is a whole number of minor units; anything else that is not whole is text";
        }
        if (in_array('object', (array) ($schema['type'] ?? []), true) && ! array_key_exists('additionalProperties', $schema)) {
            $problems[] = "{$where}: an object that may hold keys nobody named";
        }
        foreach ($schema['properties'] ?? [] as $property => $inner) {
            $inner = $this->contract->resolve($inner);
            if (str_ends_with((string) $property, '_at') && ($inner['format'] ?? null) !== 'date-time') {
                $problems[] = "{$where}.{$property}: a moment that is not said to be written as one";
            }
            if (str_ends_with((string) $property, '_minor') && ($inner['type'] ?? null) !== 'integer') {
                $problems[] = "{$where}.{$property}: money that is not a whole number";
            }
            if (preg_match('/(^|_)(amount|price|subtotal|rate|cost|balance|tax|discount)$/', (string) $property) === 1) {
                $problems[] = "{$where}.{$property}: money in a key that does not end in _minor";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('documents the fields each create reads, and no others', function () {
    $named = fn (string $schema): array => array_keys($this->contract->schema($schema)['properties']);
    $read = fn (string $request): array => array_values(array_unique(array_map(fn (string $rule): string => Str::before($rule, '.'), array_keys((new $request)->rules()))));
    $trap = (string) config('woodex.public_leads.honeypot_field');

    expect($named('EnquiryBody'))->toEqualCanonicalizing([...$read(StorePublicLeadRequest::class), $trap])
        ->and($named('BasketBody'))->toEqualCanonicalizing([...$read(StoreQuoteRequest::class), $trap])
        ->and($named('BasketLine'))->toBe(['product_id', 'qty'])
        ->and($named('HandoffBody'))->toBe(['token'])
        ->and($this->contract->schema('EnquiryBody')['properties']['source']['enum'])->toBe(array_map(fn (LeadSource $source): string => $source->value, LeadSource::publicSources()));
});

/* ------------------------------------------------------------------------------------------------------------------
| 3. Every documented answer, key by key.
*/

it('documents every answer this file asks for, and asks for every answer that is documented', function () {
    $documented = array_map(function (array $entry): array {
        $statuses = array_map(intval(...), array_keys($entry['operation']['responses']));
        sort($statuses);

        return $statuses;
    }, $this->contract->operations());

    $asked = array_map(function (array $answers): array {
        $statuses = array_keys($answers);
        sort($statuses);

        return $statuses;
    }, V1_ANSWERS);
    ksort($asked);

    expect($asked)->toBe($documented);
});

it('answers as the contract says, key by key', function (string $operation, int $status, ?string $code) {
    $response = ($this->ask)($operation, $status);
    $documented = $this->contract->answer($operation, $status);

    expect($response->getStatusCode())->toBe($status, (string) $response->getContent());

    // Every answer: its number, no cookie, and nothing kept for a search engine.
    $number = $response->headers->get(RequestNumber::HEADER);
    expect(RequestNumber::looksLikeOne($number))->toBeTrue()
        ->and($response->headers->getCookies())->toBe([], 'an answer of /api/v1 sets no cookie')
        ->and((string) $response->headers->get('X-Robots-Tag'))->toContain('noindex');

    if ($documented['schema'] === null) {
        // A picture: its bytes, said to be an image, and not to be guessed at.
        expect((string) $response->headers->get('Content-Type'))->toStartWith('image/')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

        return;
    }

    $body = json_decode((string) $response->getContent(), false, flags: JSON_THROW_ON_ERROR);

    expect($this->contract->differences($body, $documented['schema']))->toBe([])
        ->and((string) $response->headers->get('Content-Type'))->toStartWith('application/json')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($body->meta->request_id)->toBe($number, 'the number in the answer is the number in the header');

    if ($code !== null) {
        expect($body->error->code)->toBe($code)
            ->and($body->error->message)->not->toBe('');
    }

    if ($status === 429) {
        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
    }
})->with('every documented answer');

it('says which field was refused, by its name', function () {
    $refused = $this->postJson('/api/v1/leads', ['name' => 'A', 'email' => 'not-an-address'], ($this->key)())->assertStatus(422);

    expect(array_keys($refused->json('error.details')))->toBe(['name', 'email'])
        ->and($refused->json('error.details.email.0'))->toContain('email');

    // A line of a basket is named by its place.
    $line = $this->postJson('/api/v1/quote-requests', ($this->basket)(['items' => [['product_id' => 'synthetic-desk', 'qty' => 1], ['product_id' => 'gone', 'qty' => 1]]]), ($this->key)())->assertStatus(422);

    expect(array_keys($line->json('error.details')))->toBe(['items.1.product_id']);

    // The robot check is a field like any other.
    config(['services.turnstile.secret_key' => 'synthetic-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

    expect(array_keys($this->postJson('/api/v1/leads', ($this->enquiry)(['turnstile_token' => 'used']), ($this->key)())->assertStatus(422)->json('error.details')))->toBe(['turnstile_token']);
});

it('says which parameter was refused, and that the address takes none', function () {
    $refused = $this->getJson('/api/v1/media?status=draft&page=2')->assertStatus(400);

    expect($refused->json('error.code'))->toBe('PARAMETER_NOT_ALLOWED')
        ->and($refused->json('error.details'))->toBe(['refused' => ['status', 'page'], 'allowed' => []]);
});

it('refuses a body that is not a JSON object before it reads it', function () {
    foreach (['"just text"', '[1, 2, 3]', '{"name": "Ayesha"', ''] as $body) {
        $refused = $this->call('POST', '/api/v1/leads/whatsapp-handoff', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body);

        expect($refused->getStatusCode())->toBe(400, "the body {$body}")
            ->and($refused->json('error.code'))->toBe('BAD_REQUEST');
    }
});

/* ------------------------------------------------------------------------------------------------------------------
| 4. What no address owns, and an address asked the wrong way.
*/

it('answers 405 with the method the address takes, to every other method', function () {
    foreach ($this->contract->operations() as $name => $entry) {
        $path = str_replace(['{category}', '{revision}', '{media}', '{version}'], '1', '/api/v1'.$entry['path']);

        foreach (array_diff(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], [$entry['method']]) as $method) {
            $response = $this->json($method, $path, ['name' => 'Ayesha Khan']);

            expect($response->getStatusCode())->toBe(405, "{$method} {$path}")
                ->and($response->headers->get('Allow'))->toBe($entry['method'])
                ->and($this->contract->differences(json_decode((string) $response->getContent()), $this->contract->schema('Error')))->toBe([])
                ->and($response->json('error.code'))->toBe('METHOD_NOT_ALLOWED')
                ->and($response->headers->getCookies())->toBe([]);
        }
    }
});

it('answers 404 in the one error shape, without a cookie, where nothing is', function (string $method, string $path) {
    $response = $this->call($method, $path);

    expect($response->getStatusCode())->toBe(404)
        ->and($this->contract->differences(json_decode((string) $response->getContent()), $this->contract->schema('Error')))->toBe([])
        ->and($response->json('error.code'))->toBe('NOT_FOUND')
        ->and($response->json('meta.request_id'))->toBe($response->headers->get(RequestNumber::HEADER))
        ->and($response->headers->getCookies())->toBe([], 'the website\'s catch-all, which starts a session, never sees an address under /api/v1');
})->with([
    'the group itself' => ['GET', '/api/v1'],
    'an address nobody owns' => ['GET', '/api/v1/no-such-address'],
    'the same, sent to' => ['POST', '/api/v1/no-such-address'],
    'deeper' => ['DELETE', '/api/v1/leads/7/notes'],
    'a list that does not exist' => ['GET', '/api/v1/leads/7'],
    'a picture whose numbers are words' => ['GET', '/api/v1/media/first/latest'],
    'beside the categories' => ['GET', '/api/v1/catalogue'],
]);

it('answers 404 to every method where a part is switched off, as if it had never been there', function () {
    config(['modules.enabled' => '']);

    foreach (['/api/v1/leads', '/api/v1/leads/whatsapp-handoff', '/api/v1/quote-requests'] as $path) {
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            $response = $this->json($method, $path, ($this->enquiry)(), ($this->key)());

            expect($response->getStatusCode())->toBe(404, "{$method} {$path}")
                ->and($response->json('error.code'))->toBe('NOT_FOUND')
                ->and($response->headers->has('Allow'))->toBeFalse();
        }
    }

    // What the shop reads does not hang on the enquiry module.
    $this->getJson('/api/v1/media')->assertOk();
    $this->getJson('/api/v1/catalogue/categories')->assertOk();
});

it('tells a browser that asks first what the address takes, and nothing about another website', function () {
    $asked = $this->call('OPTIONS', '/api/v1/media');

    expect($asked->getStatusCode())->toBe(204)
        ->and($asked->headers->get('Allow'))->toBe('GET')
        ->and($asked->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

/* ------------------------------------------------------------------------------------------------------------------
| 5. Nothing of the inside reaches a client.
*/

it('says nothing about a fault but the number of the request, with debugging on or off', function (bool $debug) {
    config(['app.debug' => $debug]);
    ($this->breakIt)();

    $response = $this->getJson('/api/v1/media')->assertStatus(500);

    expect((string) $response->getContent())->not->toContain('SQLSTATE')->not->toContain('/var/www')->not->toContain('Secret.php')
        ->not->toContain('RuntimeException')->not->toContain('trace')->not->toContain('"file"')->not->toContain('"line"')
        ->and(array_keys($response->json()))->toBe(['error', 'meta'])
        ->and($response->json('error.details'))->toBe([]);
})->with(['debugging off' => false, 'debugging on' => true]);

it('does not name a class of the application when a record is not there', function () {
    foreach (['/api/v1/media/987654/1', '/api/v1/catalogue/categories/987654/hero/1'] as $path) {
        $response = $this->get($path)->assertNotFound();

        expect((string) $response->getContent())->not->toContain('App\\\\')->not->toContain('Models')->not->toContain('query results')
            ->and($response->json('error.message'))->toBe(ApiErrors::CODES['NOT_FOUND']);
    }
});

it('leaves the older enquiry address in the shape its callers read', function () {
    $made = $this->postJson('/api/public/leads', ($this->enquiry)())->assertCreated();

    expect(array_keys($made->json()))->toBe(['ok', 'reference', 'message', 'whatsapp_url'])
        ->and(array_keys($this->postJson('/api/public/leads', [])->assertStatus(422)->json()))->toBe(['message', 'errors']);
});
