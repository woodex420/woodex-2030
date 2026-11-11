<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Services\SiteRouter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

/**
 * Asks the public website for a URL.
 *
 * The pages live at URLs that end in a slash and that is the only canonical form, but Laravel's HTTP test client
 * builds its request with trim(url($uri), '/') — it always removes the slash. Tests therefore hand the request to
 * the SiteRouter directly, which is the same object the 404 handler calls in production.
 */
trait InteractsWithPublicWebsite
{
    protected function siteGet(string $uri, ?User $user = null): TestResponse
    {
        $request = Request::create($uri, 'GET');

        // Sign the user in (when a page is opened as staff): rebinding the request instance re-installs the
        // framework's own user resolver, which reads the session — so the user comes from the auth guard.
        if ($user !== null) {
            $this->actingAs($user);
        }

        $this->app->instance('request', $request);

        $response = $this->app->make(SiteRouter::class)->respond($request);

        $response ??= new Response((string) view('errors.404')->render(), 404);

        return $this->createTestResponse($response, $request);
    }

    /**
     * The home page is a page in the CMS like any other (slug "/"), so a test that needs a front page has to put
     * one there first — there is no route or template behind it any more.
     */
    protected function publishHomePage(array $seo = []): Page
    {
        return Page::factory()
            ->published()
            ->asType(PageType::Home)
            ->withSlug('/')
            ->when($seo !== [], fn ($factory) => $factory->withSeo($seo))
            ->create(['title' => 'Home', 'excerpt' => 'The studio, in one page.']);
    }

    /** url() trims the trailing slash; canonical URLs need it. */
    protected function siteUrl(string $path): string
    {
        return Page::urlFor($path);
    }
}
