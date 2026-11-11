<?php

use App\Support\Install\Installer;
use App\Support\Install\SiteState;

it('models configured feature state outside the source without an installer bypass', function () {
    $site = app(SiteState::class);

    expect($site->basePath)->toStartWith(sys_get_temp_dir().'/woodex-feature-state-')
        ->and($site->isConfigured())->toBeTrue()
        ->and($site->usesExternalState())->toBeTrue()
        ->and(app()->environmentPath())->toBe($site->environmentDirectory())
        ->and($site->environmentDirectory())->toStartWith(sys_get_temp_dir().'/woodex-feature-state-')
        ->and(app(Installer::class)->hasCode())->toBeFalse();

    expect(file_get_contents($site->environmentDirectory().'/.env'))
        ->toBe("# Synthetic installed-state marker only; QA runtime comes from process environment.\n");
});

it('keeps the real installer redirect when the fixture is deliberately unconfigured', function () {
    $site = app(SiteState::class);
    unlink($site->environmentDirectory().'/.env');

    expect($site->isConfigured())->toBeFalse();
    $this->get('/admin/login')->assertRedirect(url('/install'));
});

it('executes console helpers immediately and returns their real exit code', function () {
    expect($this->artisan('list', ['--raw' => true]))->toBe(0);
});
