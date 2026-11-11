<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| Phase 1 (audit finding S-01): every admin screen must refuse a signed-in staff member who lacks its
| permission. The support role is the lowest-privilege account, so it is the honest probe: a 200 here
| means the screen is open to everyone.
|
| Parameterless GET screens only — routes with a model id are covered by the policy/controller tests
| (quote PDF, invoice PDF, media, contact/company/project/lead show).
*/

it('gives the support role exactly the screens its permissions allow, and 403 everywhere else', function () {
    $support = $this->userWithRole('support');

    $allowed = [
        'admin.dashboard',
        'admin.notifications',
        'admin.leads.index',
        'admin.contacts.index',
        'admin.tasks.index',
        'admin.inbox',
        'admin.profile.security',
    ];

    // Slice J: /clients, /customers and /visitors are 301s into the Contacts hub, which support may open
    // (contacts.view) — so they redirect rather than refuse, like the older aliases.
    $redirects = ['admin.my-day', 'admin.pipeline', 'admin.companies.index', 'admin.visits.index', 'admin.crm.clients', 'admin.crm.customers', 'admin.crm.visitors'];

    $excludedPrefixes = ['login', 'logout', 'password.', 'two-factor.', 'google.', 'install'];

    $screens = collect(Route::getRoutes())
        ->filter(function ($route): bool {
            $uri = $route->uri();

            return in_array('GET', $route->methods(), true)
                && ($uri === 'admin' || str_starts_with($uri, 'admin/'))
                && ! str_contains($uri, '{')
                && $route->getName() !== null;
        })
        ->reject(function ($route) use ($excludedPrefixes): bool {
            foreach ($excludedPrefixes as $prefix) {
                if (str_starts_with((string) $route->getName(), $prefix)) {
                    return true;
                }
            }

            return false;
        });

    $mismatches = [];

    foreach ($screens as $route) {
        $name = (string) $route->getName();
        $status = $this->actingAs($support)->get(route($name))->getStatusCode();

        $expected = match (true) {
            in_array($name, $allowed, true) => 200,
            in_array($name, $redirects, true) => 301,
            default => 403,
        };

        if ($status !== $expected) {
            $mismatches[] = sprintf('%s: expected %d, got %d', $name, $expected, $status);
        }
    }

    expect($screens)->not->toBeEmpty()
        ->and($mismatches)->toBe([]);
});

it('sends every admin screen to the login page when nobody is signed in', function () {
    $excludedPrefixes = ['login', 'password.', 'two-factor.', 'google.', 'install'];

    $screens = collect(Route::getRoutes())
        ->filter(function ($route): bool {
            $uri = $route->uri();

            return in_array('GET', $route->methods(), true)
                && ($uri === 'admin' || str_starts_with($uri, 'admin/'))
                && ! str_contains($uri, '{')
                && $route->getName() !== null;
        })
        ->reject(function ($route) use ($excludedPrefixes): bool {
            foreach ($excludedPrefixes as $prefix) {
                if (str_starts_with((string) $route->getName(), $prefix)) {
                    return true;
                }
            }

            return false;
        });

    $mismatches = [];

    foreach ($screens as $route) {
        $name = (string) $route->getName();
        $response = $this->get(route($name));

        if ($response->getStatusCode() !== 302 || ! str_contains((string) $response->headers->get('Location'), 'login')) {
            $mismatches[] = $name.' → '.$response->getStatusCode();
        }
    }

    expect($mismatches)->toBe([]);
});
