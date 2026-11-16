<?php

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Actions\SendQuoteByWhatsApp;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\System\Enums\WhatsAppTemplateEvent;
use App\Domain\System\Enums\WhatsAppTemplateStatus;
use App\Domain\System\Models\WhatsAppTemplate;
use App\Integrations\WhatsApp\Actions\SubmitWhatsAppTemplate;
use App\Integrations\WhatsApp\Actions\SyncWhatsAppTemplates;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Livewire\Admin\System\WhatsAppTemplates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| WAP-005: the studio's WhatsApp templates, and which one carries which moment.
|
| Meta owns the wording and the approval; we own the mirror and the decision. Two things are under test above
| all: the mirror tells the truth about a template Meta rejected, and a mapped template is what the system
| actually sends — so no template name is hard-coded anywhere in the code.
*/

beforeEach(function () {
    Storage::fake('local');

    config()->set('services.whatsapp', [
        'token' => 'env-token',
        'phone_number_id' => '111111111111111',
        'waba_id' => '222222222222222',
        'app_secret' => 'env-secret',
        'verify_token' => 'env-verify',
        'api_version' => 'v23.0',
        'api_base' => 'https://graph.facebook.com',
        'quotation_template' => 'quotation',
    ]);
});

function metaTemplate(string $name, string $status = 'APPROVED', string $body = 'Salam {{1}}, your quotation: {{2}}', string $language = 'en'): array
{
    return [
        'id' => 'meta-'.md5($name.$language),
        'name' => $name,
        'language' => $language,
        'category' => 'UTILITY',
        'status' => $status,
        'components' => [['type' => 'BODY', 'text' => $body]],
        'rejected_reason' => $status === 'REJECTED' ? 'Misleading wording' : null,
    ];
}

function whatsappTemplatesResponse(array $templates, ?string $nextAfter = null): array
{
    $payload = ['data' => $templates];

    if ($nextAfter !== null) {
        $payload['paging'] = ['cursors' => ['after' => $nextAfter], 'next' => 'https://graph.facebook.com/next'];
    }

    return $payload;
}

it('mirrors what meta holds, including a rejection and the reason for it', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(whatsappTemplatesResponse([
            metaTemplate('quotation_ready'),
            metaTemplate('visit_reminder', 'REJECTED'),
        ])),
    ]);

    $result = app(SyncWhatsAppTemplates::class)->handle();

    expect($result['ok'])->toBeTrue()->and($result['created'])->toBe(2);

    $approved = WhatsAppTemplate::query()->where('name', 'quotation_ready')->sole();

    expect($approved->status)->toBe(WhatsAppTemplateStatus::Approved)
        ->and($approved->isUsable())->toBeTrue()
        ->and($approved->body())->toBe('Salam {{1}}, your quotation: {{2}}')
        ->and($approved->variables())->toBe([1, 2]);

    $rejected = WhatsAppTemplate::query()->where('name', 'visit_reminder')->sole();

    expect($rejected->status)->toBe(WhatsAppTemplateStatus::Rejected)
        ->and($rejected->isUsable())->toBeFalse()
        ->and($rejected->rejected_reason)->toBe('Misleading wording');
});

it('follows meta\'s paging instead of showing only the first page', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::sequence()
            ->push(whatsappTemplatesResponse([metaTemplate('first')], 'cursor-1'))
            ->push(whatsappTemplatesResponse([metaTemplate('second')])),
    ]);

    $result = app(SyncWhatsAppTemplates::class)->handle();

    expect($result['synced'])->toBe(2)
        ->and(WhatsAppTemplate::query()->pluck('name')->all())->toBe(['first', 'second']);
});

it('marks a template meta no longer returns as removed, rather than forgetting it', function () {
    WhatsAppTemplate::query()->create(['name' => 'gone_from_meta', 'language' => 'en', 'status' => WhatsAppTemplateStatus::Approved->value, 'created_at' => now()->subDay()]);

    Http::fake(['graph.facebook.com/*' => Http::response(whatsappTemplatesResponse([metaTemplate('still_there')]))]);

    $result = app(SyncWhatsAppTemplates::class)->handle();

    expect($result['removed'])->toBe(1)
        ->and(WhatsAppTemplate::query()->where('name', 'gone_from_meta')->sole()->status)->toBe(WhatsAppTemplateStatus::Deleted);
});

it('reports a missing business account id instead of silently showing an empty list', function () {
    config()->set('services.whatsapp.waba_id', null);

    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    expect(app(SyncWhatsAppTemplates::class)->handle())
        ->toMatchArray(['ok' => false, 'synced' => 0])
        ->and(app(SyncWhatsAppTemplates::class)->handle()['error'])->toContain('Business Account');
});

it('maps a moment onto a template and moves the mapping when the studio changes its mind', function () {
    $first = WhatsAppTemplate::query()->create(['name' => 'quotation_ready', 'language' => 'en', 'status' => WhatsAppTemplateStatus::Approved->value]);
    $second = WhatsAppTemplate::query()->create(['name' => 'quotation_ur', 'language' => 'ur', 'status' => WhatsAppTemplateStatus::Approved->value]);

    Livewire::actingAs($this->userWithRole('admin'))->test(WhatsAppTemplates::class)
        ->call('map', $first->id, WhatsAppTemplateEvent::Quotation->value)
        ->assertHasNoErrors();

    expect($first->refresh()->event_key)->toBe('quotation');

    Livewire::actingAs($this->userWithRole('admin'))->test(WhatsAppTemplates::class)
        ->call('map', $second->id, WhatsAppTemplateEvent::Quotation->value)
        ->assertHasNoErrors();

    expect($second->refresh()->event_key)->toBe('quotation')
        ->and($first->refresh()->event_key)->toBeNull();
});

it('refuses to map a template meta has not approved', function () {
    $pending = WhatsAppTemplate::query()->create(['name' => 'in_review', 'language' => 'en', 'status' => WhatsAppTemplateStatus::Pending->value]);

    Livewire::actingAs($this->userWithRole('admin'))->test(WhatsAppTemplates::class)
        ->call('map', $pending->id, WhatsAppTemplateEvent::Quotation->value)
        ->assertSee('Only an approved template can be mapped');

    expect($pending->refresh()->event_key)->toBeNull();
});

it('sends a new template to meta with an example for every variable', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'meta-new', 'status' => 'PENDING'], 200)]);

    $result = app(SubmitWhatsAppTemplate::class)->handle(
        'quotation_ready',
        'en',
        'UTILITY',
        'Salam {{1}}, your quotation is ready: {{2}}',
        ['Ali', 'https://woodex.com.pk/q/abc'],
    );

    expect($result['ok'])->toBeTrue()->and($result['template']->status)->toBe(WhatsAppTemplateStatus::Pending);

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return str_contains($request->url(), '222222222222222/message_templates')
            && $body['name'] === 'quotation_ready'
            && $body['language'] === 'en'
            && $body['category'] === 'UTILITY'
            && $body['components'][0]['type'] === 'BODY'
            && $body['components'][0]['example']['body_text'][0] === ['Ali', 'https://woodex.com.pk/q/abc'];
    });
});

it('catches the ordinary mistakes before spending a review at meta', function () {
    Http::fake(['*' => Http::response(['id' => 'x'], 200)]);

    $action = app(SubmitWhatsAppTemplate::class);

    expect($action->handle('Not A Name', 'en', 'UTILITY', 'Hello')['ok'])->toBeFalse()
        ->and($action->handle('ok_name', 'en', 'UTILITY', 'Hello', ['one example'])['ok'])->toBeFalse()
        ->and($action->handle('ok_name', 'en', 'UTILITY', 'Hello {{1}}')['ok'])->toBeFalse()
        ->and($action->handle('ok_name', 'en', 'UTILITY', 'Hello {{2}}', ['Ali'])['ok'])->toBeFalse()
        ->and($action->handle('ok_name', 'en', 'UTILITY', '')['ok'])->toBeFalse();

    // Every one of those was refused here, not by Meta: nothing was sent.
    Http::assertNothingSent();
});

it('shows the example boxes the moment a variable is typed', function () {
    Livewire::actingAs($this->userWithRole('admin'))->test(WhatsAppTemplates::class)
        ->set('new.body', 'Salam {{1}}, your quotation: {{2}}')
        ->assertSet('new.examples', ['', ''])
        ->set('new.body', 'Salam {{1}}')
        ->assertSet('new.examples', ['']);
});

it('sends the mapped template when a quotation goes out, not a name from the code', function () {
    $gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $gateway);

    WhatsAppTemplate::query()->create([
        'name' => 'quotation_ur',
        'language' => 'ur',
        'status' => WhatsAppTemplateStatus::Approved->value,
        'event_key' => WhatsAppTemplateEvent::Quotation->value,
        'components' => [['type' => 'BODY', 'text' => '{{1}} quotation: {{2}}']],
    ]);

    $sent = sendQuotationOutsideTheWindow($gateway);

    expect($sent['template'])->toBe('quotation_ur')->and($sent['language'])->toBe('ur');
});

it('falls back to the configured template when nothing is mapped', function () {
    $gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $gateway);

    expect(sendQuotationOutsideTheWindow($gateway)['template'])->toBe('quotation');
});

it('ignores a mapped template that meta no longer allows to be sent', function () {
    $gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $gateway);

    WhatsAppTemplate::query()->create([
        'name' => 'quotation_paused',
        'language' => 'en',
        'status' => WhatsAppTemplateStatus::Paused->value,
        'event_key' => WhatsAppTemplateEvent::Quotation->value,
    ]);

    expect(sendQuotationOutsideTheWindow($gateway)['template'])->toBe('quotation');
});

/**
 * A quotation whose 24-hour window has closed, so only a template may go out.
 *
 * @return array{template: ?string, language: string}
 */
function sendQuotationOutsideTheWindow(FakeWhatsAppGateway $gateway): array
{
    $sales = test()->userWithRole('sales');
    $lead = Lead::factory()->create(['owner_id' => $sales->id]);
    $lead->contact->update(['phone' => '0322 4000768', 'whatsapp' => '0322 4000768', 'consent_whatsapp' => true]);

    // The window is already closed, so Meta allows nothing but an approved template.
    Conversation::query()->create([
        'contact_id' => $lead->contact_id,
        'channel' => Channel::Whatsapp->value,
        'external_number' => '923224000768',
        'status' => ConversationStatus::Open->value,
        'window_expires_at' => now()->subHour(),
        'unread_count' => 0,
    ]);

    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $sales, $lead, null, 'Kitchen');

    // A quotation with a priced line, as SendQuote requires.
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Kitchen');
    $editor->addLine($section, ['description' => 'Kitchen cabinets', 'unit' => 'rft', 'qty' => '10', 'rate_minor' => 2_500_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));

    $version = app(SendQuote::class)->handle($quote->refresh(), $sales, 'link');

    app(SendQuoteByWhatsApp::class)->handle($quote->refresh(), $version, $sales);

    $sent = $gateway->sent[0] ?? [];

    return ['template' => $sent['template'] ?? null, 'language' => $sent['language'] ?? 'en'];
}
