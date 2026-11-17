<?php

declare(strict_types=1);

use App\Support\AdminNavigation;
use App\Support\ContentPolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
| Content-Security-Policy on every page (master list T-068; defect D-012; App\Support\ContentPolicy).
|
| The policy tells the browser what a page may run. These tests pin what is sent, and look at each page as the
| browser's rule would: a script written into a page must carry the number of that answer, and no event handler
| may be written into the markup. That a real browser then blocks nothing is asked by the crawl and the journeys
| (scripts/os-screens.mjs, scripts/os-journeys.mjs), which fail on the first thing a browser refuses.
*/

beforeEach(function () {
    config(['modules.enabled' => 'lead_api', 'woodex.business_lines' => ['furniture'], 'app.debug' => false]);
});

/** The number this answer's policy names. */
function numberOf(TestResponse $answer, string $header = 'Content-Security-Policy'): string
{
    preg_match("/script-src [^;]*'nonce-([^']+)'/", (string) $answer->headers->get($header), $found);

    return $found[1] ?? '';
}

/** What a browser under the policy would refuse on this page: a script without the number, a handler in the markup. */
function refusedOn(TestResponse $answer): array
{
    $html = (string) $answer->getContent();
    $number = numberOf($answer);
    $refused = [];

    preg_match_all('/<script\b([^>]*)>/i', $html, $scripts, PREG_SET_ORDER);

    foreach ($scripts as [$tag, $attributes]) {
        $own = preg_match('/\bsrc\s*=\s*"(?:\/(?!\/)|'.preg_quote((string) config('app.url'), '/').')/i', $attributes) === 1;
        $marked = $number !== '' && str_contains($attributes, 'nonce="'.$number.'"');
        $data = preg_match('/\btype\s*=\s*"application\/(?:ld\+)?json"/i', $attributes) === 1; // data, not script

        if (! $own && ! $marked && ! $data) {
            $refused[] = 'a script without the number: '.mb_substr($tag, 0, 90);
        }
    }

    if (preg_match_all('/<[a-z][^<>]*\s(on[a-z]+)\s*=\s*["\']/i', $html, $handlers)) {
        $refused[] = 'handlers in the markup: '.implode(', ', array_unique($handlers[1]));
    }
    if (preg_match('/(?:href|src|action)\s*=\s*["\']\s*javascript:/i', $html) === 1) {
        $refused[] = 'a javascript: address';
    }

    return $refused;
}

it('sends the same policy with every page: what it allows, word for word', function () {
    $answer = $this->get('/admin/login')->assertOk();
    $number = numberOf($answer);

    expect(strlen($number))->toBeGreaterThanOrEqual(20)
        ->and($answer->headers->get('Content-Security-Policy'))->toBe(
            "default-src 'self'; "
            ."script-src 'self' 'nonce-{$number}' 'unsafe-eval'; "
            ."style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: blob: https:; "
            ."font-src 'self' data:; "
            ."connect-src 'self'; "
            ."frame-src 'self' blob:; "
            ."worker-src 'self' blob:; "
            ."object-src 'none'; "
            ."base-uri 'self'; "
            ."form-action 'self'; "
            ."frame-ancestors 'self'"
        )
        ->and($answer->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('never lets a script written into a page run without the number, nor a script from anywhere else', function () {
    $scripts = collect(explode('; ', (string) $this->get('/admin/login')->headers->get('Content-Security-Policy')))
        ->first(fn (string $part): bool => str_starts_with($part, 'script-src '));

    expect($scripts)->not->toContain("'unsafe-inline'")->not->toContain('*')->not->toContain('http:')->not->toContain('https:')->not->toContain('data:');
});

it('makes a new number for every answer', function () {
    $first = numberOf($this->get('/admin/login'));
    $second = numberOf($this->get('/admin/login'));

    expect($first)->not->toBe('')->and($second)->not->toBe('')->and($first)->not->toBe($second);
});

it('marks the sign-in page\'s own scripts with the number, and writes no handler into the markup', function () {
    $answer = $this->get('/admin/login')->assertOk();

    expect(refusedOn($answer))->toBe([])
        ->and((string) $answer->getContent())->toContain('nonce="'.numberOf($answer).'"');
});

it('does the same on every screen of the menu', function (string $role) {
    $person = $this->userWithRole($role);
    $this->actingAs($person);
    $refused = [];
    $screens = 0;

    foreach (collect(AdminNavigation::for($person))->flatMap(fn (array $group): array => $group['items']) as $item) {
        $answer = $this->followingRedirects()->get(route($item['route']))->assertOk();
        $screens++;

        if (numberOf($answer) === '') {
            $refused[] = $item['label'].': no policy';
        }
        foreach (refusedOn($answer) as $what) {
            $refused[] = $item['label'].': '.$what;
        }
    }

    expect($screens)->toBeGreaterThan(5)->and($refused)->toBe([]);
})->with(['owner', 'sales', 'accountant']);

it('finds in no view a script without the number, a handler in the markup or a javascript: address', function () {
    // The screens above are the ones a menu leads to. This reads every view there is, so that a page nobody listed
    // (a report, a dialog, an error page) cannot carry what the browser would refuse.
    // Two views are not under the policy yet: the old page builder and studio of the website module (T-115).
    $notYet = ['admin/website/studio.blade.php'];
    $found = [];
    $read = 0;

    foreach (File::allFiles(resource_path('views')) as $file) {
        $view = str_replace('\\', '/', $file->getRelativePathname());
        $source = $file->getContents();
        $read++;
        $here = [];

        preg_match_all('/<script\b(?![^>]*\bsrc=)([^>]*)>/i', $source, $scripts);
        foreach ($scripts[1] as $attributes) {
            if (! str_contains($attributes, 'nonce="{{ Vite::cspNonce() }}"') && preg_match('/application\/(?:ld\+)?json/', $attributes) !== 1) {
                $here[] = 'a script without the number';
            }
        }
        if (preg_match_all('/<[a-zA-Z][^<>]*\s(on[a-z]+)\s*=\s*["\']/', $source, $handlers)) {
            $here[] = 'handler '.implode(', ', array_unique($handlers[1]));
        }
        if (preg_match('/(?:href|src|action)\s*=\s*["\']\s*javascript:/i', $source) === 1) {
            $here[] = 'a javascript: address';
        }

        if ($here !== [] && ! in_array($view, $notYet, true)) {
            $found[] = $view.': '.implode('; ', array_unique($here));
        }
        if ($here === [] && in_array($view, $notYet, true)) {
            $found[] = $view.' is clean now: take it off the list in this test';
        }
    }

    expect($read)->toBeGreaterThan(200)->and($found)->toBe([]);
});

it('sends it with a refusal and with a fault too', function () {
    Route::middleware('web')->get('/__fault', fn () => throw new RuntimeException('a fault'));
    $this->actingAs($this->userWithRole('sales'));

    foreach ([
        'a page that does not exist' => $this->get('/admin/no-such-screen')->assertNotFound(),
        'a screen this person may not open' => $this->get(route('admin.settings'))->assertForbidden(),
        'a fault' => $this->get('/__fault')->assertStatus(500),
        'a switched-off module' => $this->get('/admin/website/builder')->assertNotFound(),
    ] as $what => $answer) {
        expect(numberOf($answer))->not->toBe('', $what.': no policy')
            ->and(refusedOn($answer))->toBe([], $what);
    }
});

it('sends no script policy with what is not a page', function () {
    $json = $this->getJson('/api/v1/leads')->assertStatus(405);
    $health = $this->get('/up');

    expect($json->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and(str_starts_with((string) $health->headers->get('Content-Type'), 'text/html') ? numberOf($health) !== '' : ! $health->headers->has('Content-Security-Policy'))->toBeTrue();
});

it('can be set to report only, or switched off, by a setting — and takes nothing else for an answer', function () {
    config(['woodex.security.csp' => 'report']);
    $reporting = $this->get('/admin/login');

    expect($reporting->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and(numberOf($reporting, 'Content-Security-Policy-Report-Only'))->not->toBe('');

    config(['woodex.security.csp' => 'off']);
    $off = $this->get('/admin/login');

    expect($off->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($off->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();

    config(['woodex.security.csp' => 'whatever']);

    expect(numberOf($this->get('/admin/login')))->not->toBe('')->and(ContentPolicy::mode())->toBe('enforce');
});

it('lets the same pages be framed, or not, as the older header says', function (string $frameOptions, ?string $expected) {
    config(['woodex.security.frame_options' => $frameOptions]);

    $policy = (string) $this->get('/admin/login')->headers->get('Content-Security-Policy');

    if ($expected === null) {
        expect($policy)->not->toContain('frame-ancestors');
    } else {
        expect($policy)->toEndWith('frame-ancestors '.$expected);
    }
})->with([
    'by itself only' => ['SAMEORIGIN', "'self'"],
    'by nobody' => ['DENY', "'none'"],
    'header switched off (an embedded preview)' => ['', null],
]);

it('lets a form be sent on to Google only where the Google module is on, and to nowhere else', function () {
    // A browser holds the redirect that follows a form to form-action. Two forms are answered with a redirect to
    // Google's sign-in (GoogleOAuthController): with only 'self' there, "Connect Google" would do nothing.
    $formAction = function (): string {
        preg_match('/form-action ([^;]*)/', (string) $this->get('/admin/login')->headers->get('Content-Security-Policy'), $found);

        return $found[1] ?? '';
    };

    expect($formAction())->toBe("'self'");

    config(['modules.enabled' => 'lead_api,google']);

    expect($formAction())->toBe("'self' https://accounts.google.com")
        // The address in the policy is the one the controller really sends the browser to.
        ->and(File::get(app_path('Http/Controllers/Auth/GoogleOAuthController.php')))->toContain("redirect()->away('".ContentPolicy::GOOGLE_SIGN_IN.'/')
        // And these are the only forms in the views that are answered from outside: every other form posts and stays.
        ->and(collect(File::allFiles(app_path()))
            ->filter(fn ($file) => str_contains($file->getContents(), 'redirect()->away('))
            ->map(fn ($file) => $file->getRelativePathname())->sort()->values()->all())
        ->toBe(['Http/Controllers/Admin/ConnectorOAuthController.php', 'Http/Controllers/Auth/GoogleOAuthController.php']);
});

it('leaves the public website module\'s own pages alone where that module is on, and nothing else', function () {
    config(['modules.enabled' => 'lead_api,website']);

    // The admin keeps its policy…
    expect(numberOf($this->get('/admin/login')))->not->toBe('');

    // …a public page of the website does not get this one (it loads the owner's own tags from other servers)…
    expect(numberOf($this->get('/a-public-page-of-the-website')))->toBe('');

    // …the client's own pages keep theirs, whatever they answer…
    config(['modules.enabled' => 'lead_api,website,client_portal']);
    expect(numberOf($this->get('/portal')))->not->toBe('', 'the client\'s page lost its policy')
        ->and(numberOf($this->get('/q/no-such-quotation')))->not->toBe('', 'a quotation page lost its policy')
        // …also the answer to a form of theirs.
        ->and(numberOf($this->post('/portal/code')))->not->toBe('', 'the answer to the client\'s code form lost its policy');

    // …and the old page builder inside the admin, a part of that module, is not covered yet either.
    $this->actingAs($this->userWithRole('owner'));
    expect(numberOf($this->get('/admin/website/builder')))->toBe('');
});
