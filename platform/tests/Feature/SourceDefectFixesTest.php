<?php

declare(strict_types=1);

use App\Domain\Website\Services\SiteRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/*
| Defects found in the WOODEX OS source while it was evaluated for Woodex Furniture (docs/laravel-os/01, D1, D2
| and migration finding M1), fixed here with the test that would have caught each.
*/

it('refuses an unsigned WhatsApp webhook post when WhatsApp is not connected, instead of failing', function () {
    $this->postJson('/webhooks/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => []])
        ->assertForbidden();
});

it('refuses the WhatsApp verification handshake when no verify token is configured, instead of failing', function () {
    $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=anything&hub_challenge=123')
        ->assertForbidden();
});

it('never answers a form post or a Livewire action with a website redirect', function () {
    $router = app(SiteRouter::class);

    // The address a Livewire 4 action posts to: before the fix a 404 raised inside the action came back as a 301.
    expect($router->respond(Request::create('/livewire-2a76fda2/update', 'POST')))->toBeNull()
        ->and($router->respond(Request::create('/some-form', 'POST')))->toBeNull()
        ->and($router->respond(Request::create('/livewire-2a76fda2/livewire.min.js', 'GET')))->toBeNull();

    // A visitor reading a page address without its trailing slash is still sent to the canonical form.
    $redirect = $router->respond(Request::create('/about', 'GET'));
    expect($redirect?->getStatusCode())->toBe(301)
        ->and((string) $redirect?->headers->get('Location'))->toEndWith('/about/');
});

it('rolls the Telegram outbox migration back to nothing', function () {
    $migration = require database_path('migrations/2026_10_18_100000_create_telegram_pending_messages_table.php');
    expect(Schema::hasTable('pending_telegram_messages'))->toBeTrue();

    $migration->down();
    expect(Schema::hasTable('pending_telegram_messages'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('pending_telegram_messages'))->toBeTrue();
});
