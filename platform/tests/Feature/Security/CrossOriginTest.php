<?php

declare(strict_types=1);

/*
| Cross-origin rules (document 10 §4; master list T-160; config/cors.php).
|
| A browser lets another website read an answer only when the answer says so. Here only the public enquiry
| endpoints say so, only to the websites named in PUBLIC_LEADS_ALLOWED_ORIGINS, and never with the visitor's
| cookies. The admin, its Livewire endpoint and the sign-in say nothing to any other website. None of this was
| tested. Each test fails when the list of paths is widened, an origin becomes "*", or cookies are allowed.
*/

const OUR_SITE = 'https://woodex.com.pk';
const OTHER_SITE = 'https://elsewhere.example';

beforeEach(function () {
    config(['cors.allowed_origins' => [OUR_SITE], 'modules.enabled' => 'lead_api']);
});

/** What a browser asks before it lets a page on $origin send a request with a JSON body. */
function askFirst(string $path, string $origin, string $method = 'POST', string $headers = 'content-type')
{
    return test()->call('OPTIONS', $path, server: [
        'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method, 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => $headers,
    ]);
}

function enquiryFrom(string $origin)
{
    return test()->withHeaders(['Origin' => $origin])->postJson('/api/public/leads', [
        'name' => 'Ayesha Khan', 'phone' => '0322-4000768', 'service' => 'office-furniture', 'consent' => true,
    ]);
}

it('lets the named website send an enquiry, and says so to the browser', function () {
    $asked = askFirst('/api/public/leads', OUR_SITE);

    expect($asked->headers->get('Access-Control-Allow-Origin'))->toBe(OUR_SITE)
        ->and(strtoupper((string) $asked->headers->get('Access-Control-Allow-Methods')))->toContain('POST')
        ->and(strtolower((string) $asked->headers->get('Access-Control-Allow-Headers')))->toContain('content-type');

    $sent = enquiryFrom(OUR_SITE)->assertCreated();

    expect($sent->headers->get('Access-Control-Allow-Origin'))->toBe(OUR_SITE);
});

it('never names a website that is not on the list, and never everybody', function () {
    // With one website on the list the answer may still name that one; the browser of a visitor to another
    // website compares the two and refuses. What must never come back is the other website's name, or "*".
    foreach ([askFirst('/api/public/leads', OTHER_SITE), enquiryFrom(OTHER_SITE)] as $answer) {
        expect($answer->headers->get('Access-Control-Allow-Origin'))->not->toBe(OTHER_SITE)->not->toBe('*');
    }

    // With two websites on the list the answer names the asker only when it is one of them.
    config(['cors.allowed_origins' => [OUR_SITE, 'https://woodexfurniture.pk']]);

    expect(askFirst('/api/public/leads', OTHER_SITE)->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and(enquiryFrom(OTHER_SITE)->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and(askFirst('/api/public/leads', 'https://woodexfurniture.pk')->headers->get('Access-Control-Allow-Origin'))->toBe('https://woodexfurniture.pk');
});

it('names no website at all until one is configured', function () {
    config(['cors.allowed_origins' => []]);

    expect(askFirst('/api/public/leads', OUR_SITE)->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and(enquiryFrom(OUR_SITE)->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('never lets another website use the visitor\'s cookies', function () {
    foreach ([askFirst('/api/public/leads', OUR_SITE), enquiryFrom(OUR_SITE), askFirst('/api/public/leads', OTHER_SITE)] as $answer) {
        expect($answer->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
    }

    expect(config('cors.supports_credentials'))->toBeFalse();
});

it('lets the named website send only the headers the form needs', function () {
    $allowed = strtolower((string) askFirst('/api/public/leads', OUR_SITE, headers: 'authorization, cookie, x-csrf-token')->headers->get('Access-Control-Allow-Headers'));

    expect($allowed)->not->toContain('authorization')->not->toContain('cookie')->not->toContain('x-csrf-token');
});

it('lets the named website send the key of a create under /api/v1, and read whether it was answered before', function () {
    // The key of a create travels in a header (master list T-063), and a browser asks before it sends one.
    $asked = askFirst('/api/v1/leads', OUR_SITE, headers: 'content-type, idempotency-key');

    expect(strtolower((string) $asked->headers->get('Access-Control-Allow-Headers')))->toContain('idempotency-key')->toContain('content-type');

    $sent = $this->withHeaders(['Origin' => OUR_SITE])->postJson('/api/v1/leads', ['name' => 'Ayesha Khan', 'phone' => '0322-4000768'], idempotencyKey())->assertCreated();

    expect($sent->headers->get('Access-Control-Allow-Origin'))->toBe(OUR_SITE)
        ->and(strtolower((string) $sent->headers->get('Access-Control-Expose-Headers')))->toContain('idempotent-replayed')->toContain('x-request-id');
});

it('opens what the shop reads to no other website: the shop asks through its own server', function () {
    foreach (['/api/v1/media', '/api/v1/catalogue/categories', '/api/v1/no-such-address'] as $path) {
        expect(askFirst($path, OUR_SITE, 'GET')->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
            ->and($this->withHeaders(['Origin' => OUR_SITE])->getJson($path)->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    }
});

it('says nothing to any other website from the admin, its Livewire endpoint or the sign-in', function (string $method, string $path) {
    // Even the website that may send enquiries gets no word here.
    $asked = askFirst($path, OUR_SITE, $method);
    $sent = $this->call($method, $path, server: ['HTTP_ORIGIN' => OUR_SITE]);

    foreach ([$asked, $sent] as $answer) {
        expect($answer->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
            ->and($answer->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
    }
})->with([
    'the sign-in page' => ['GET', '/admin/login'],
    'the sign-in itself' => ['POST', '/admin/login'],
    'the dashboard' => ['GET', '/admin'],
    'the Livewire endpoint' => ['POST', '/livewire/update'],
    'a client page' => ['GET', '/q/01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'the health address' => ['GET', '/up'],
]);

it('opens only the enquiry endpoints to other websites, by name', function () {
    // The list is short on purpose. A wider entry here ("api/*", "*") would open whatever is added later.
    expect(config('cors.paths'))->toBe(['api/public/*', 'api/v1/leads', 'api/v1/leads/whatsapp-handoff', 'api/v1/quote-requests'])
        ->and(config('cors.allowed_origins_patterns'))->toBe([])
        ->and(config('cors.allowed_methods'))->not->toContain('*')->not->toContain('DELETE')->not->toContain('PUT')->not->toContain('PATCH')
        ->and(config('cors.allowed_headers'))->not->toContain('*');
});
