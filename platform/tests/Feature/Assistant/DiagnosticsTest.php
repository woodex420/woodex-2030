<?php

declare(strict_types=1);

use App\Domain\Assistant\Assistant;
use App\Domain\Assistant\Diagnostics;
use App\Livewire\Admin\AssistantDrawer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('adds four permission-scoped diagnostics without removing the original nine questions', function () {
    $owner = $this->userWithRole('owner');
    $support = $this->userWithRole('support');
    expect(array_column(Assistant::QUESTIONS, 'id'))->toHaveCount(13)->toContain(
        'bills_to_pay', 'overdue_invoices', 'due_this_week', 'collected_this_month', 'follow_ups_due',
        'new_enquiries', 'quotes_waiting', 'tasks_overdue', 'milestones_due', 'runtime_diagnostics', 'connection_diagnostics', 'website_diagnostics', 'enquiry_diagnostics',
    );
    expect(array_column(Assistant::questionsFor($support), 'id'))->not->toContain('runtime_diagnostics', 'connection_diagnostics', 'website_diagnostics', 'enquiry_diagnostics');
    foreach (['runtime_diagnostics', 'connection_diagnostics', 'website_diagnostics', 'enquiry_diagnostics'] as $question) {
        expect(app(Assistant::class)->ask($question, $support)->refused)->toBeTrue();
    }
    Livewire::actingAs($owner)->test(AssistantDrawer::class)->call('askChip', 'runtime_diagnostics')
        ->assertSee('Runtime diagnostics')->assertSee('no fixes were executed')->assertSee('SELECT 1');
});

it('gates the diagnostic service itself as well as the Assistant chips', function () {
    $support = $this->userWithRole('support');
    expect(fn () => app(Diagnostics::class)->runtime($support))->toThrow(HttpException::class)
        ->and(fn () => app(Diagnostics::class)->connections($support))->toThrow(HttpException::class);
});

it('performs read-only runtime probes and does not dispatch fixes or provider requests', function () {
    $owner = $this->userWithRole('owner');
    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();
    Mail::fake();
    config(['session.same_site' => 'none', 'session.secure' => false, 'app.debug' => true]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $answer = app(Diagnostics::class)->runtime($owner);
    $text = json_encode($answer);
    expect($text)->toContain('SELECT 1 passed', DB::connection()->getDriverName(), 'cross-site cookies need Secure', 'disabling before a production release', 'Worker execution not tested');
    foreach ($queries as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});

it('never exposes credentials or labels configuration as a successful connection', function () {
    $owner = $this->userWithRole('owner');
    Http::fake();
    Queue::fake();
    Mail::fake();
    config([
        'services.telegram.bot_token' => 'private-fixture-telegram-token',
        'services.google.client_id' => 'private-fixture-google-id',
        'services.google.client_secret' => 'private-fixture-google-secret',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.password' => 'private-fixture-mail-password',
    ]);
    $answer = app(Diagnostics::class)->connections($owner);
    $text = json_encode($answer);
    expect($text)->toContain('NOT tested', 'No message sent', 'Not integrated', 'no provider call made')
        ->not->toContain('private-fixture-', 'smtp', 'Connected successfully');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});

it('redacts database exception details instead of echoing DSNs or credentials', function () {
    $owner = $this->userWithRole('owner');
    expect($owner->can('system.health'))->toBeTrue();
    DB::shouldReceive('connection')->withNoArgs()->once()->andThrow(new RuntimeException('private-fixture-dsn-password'));
    $answer = app(Diagnostics::class)->runtime($owner);
    expect(json_encode($answer))->toContain('Failed. Ask the owner')->not->toContain('private-fixture-dsn-password');
});

it('offers permission-scoped website findings and recommendations without leaking page content or writing', function () {
    $directory = sys_get_temp_dir().'/woodex-diagnostic-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($directory);
    $this->app->usePublicPath($directory);
    $html = '<html><head><title>private-fixture-customer-not-an-answer</title><meta name="robots" content="noindex"></head><body></body></html>';
    file_put_contents($directory.'/index.html', $html);
    try {
        Http::fake();
        Queue::fake();
        Mail::fake();
        $owner = $this->userWithRole('owner');
        $answer = app(Assistant::class)->ask('website_diagnostics', $owner);
        expect($answer->refused)->toBeFalse()->and(count($answer->rows))->toBe(6);
        expect(json_encode($answer))->toContain('Website diagnostics', '1 pages', 'Never remove noindex automatically', 'no pages edited or published')
            ->not->toContain('private-fixture-customer-not-an-answer');
        expect($answer->rows[1]['url'])->toContain('audit=metadata', 'finding=canonical');
        expect(file_get_contents($directory.'/index.html'))->toBe($html);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        expect(fn () => app(Diagnostics::class)->website($this->userWithRole('support')))->toThrow(HttpException::class);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('diagnoses enquiry capture without sending messages or exposing customer details', function () {
    Http::preventStrayRequests();
    Notification::fake();
    $owner = $this->userWithRole('owner');
    $answer = app(Assistant::class)->ask('contact form not saving', $owner);
    expect(json_encode($answer))->toContain('Enquiry diagnostics', 'last 7 days', 'no repair or live-site test', 'your own notification inbox');
    Notification::assertNothingSent();
    Http::assertNothingSent();
});
