<?php

use App\Domain\System\Actions\SetChangeRequestStatus;
use App\Domain\System\Enums\ChangeRequestStatus;
use App\Domain\System\Enums\ChangeRequestType;
use App\Domain\System\Models\ChangeRequest;
use App\Domain\System\Models\TelegramLink;
use App\Domain\System\Notifications\ChangeRequestUpdatedNotification;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use App\Livewire\Admin\System\ChangeRequests;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| SYS-010 (P5 S6): the change-requests board. /idea and /issue sent from Telegram land here; moving a
| card notifies the person who asked. The loop under test: report from the phone, hear the outcome there.
*/

it('lists telegram ideas on the board and moves them along the flow', function () {
    $owner = $this->userWithRole('owner');
    $sales = $this->userWithRole('sales', ['name' => 'Bilal Ahmed']);
    Notification::fake();

    $request = ChangeRequest::query()->create([
        'type' => ChangeRequestType::Idea,
        'title' => 'Add a kitchen filter to the portfolio',
        'body' => 'Add a kitchen filter to the portfolio',
        'status' => ChangeRequestStatus::New,
        'source' => 'telegram',
        'requested_by' => $sales->id,
    ]);

    Livewire::actingAs($owner)
        ->test(ChangeRequests::class)
        ->assertSee('CR-'.str_pad((string) $request->id, 4, '0', STR_PAD_LEFT))
        ->assertSee('Add a kitchen filter to the portfolio')
        ->assertSee('Bilal Ahmed')
        ->assertSee('Telegram')
        ->call('setStatus', $request->id, 'accepted')
        ->assertDispatched('toast');

    expect($request->refresh()->status)->toBe(ChangeRequestStatus::Accepted);

    Notification::assertSentTo($sales, ChangeRequestUpdatedNotification::class, function (ChangeRequestUpdatedNotification $notification) use ($request): bool {
        return $notification->request->id === $request->id;
    });
});

it('closes a card with a note and tells the requester what happened', function () {
    $owner = $this->userWithRole('owner');
    $sales = $this->userWithRole('sales');
    Notification::fake();

    $request = ChangeRequest::query()->create([
        'type' => ChangeRequestType::Issue,
        'title' => 'The quote PDF shows the wrong phone number',
        'status' => ChangeRequestStatus::New,
        'source' => 'telegram',
        'requested_by' => $sales->id,
    ]);

    // new → done is not a move this card offers; the flow is accepted → planned → done.
    Livewire::actingAs($owner)
        ->test(ChangeRequests::class)
        ->call('setStatus', $request->id, 'done');

    expect($request->refresh()->status)->toBe(ChangeRequestStatus::New);

    Livewire::actingAs($owner)
        ->test(ChangeRequests::class)
        ->call('setStatus', $request->id, 'rejected')
        ->set('note', 'The PDF comes from the WhatsApp template, not the app')
        ->call('saveNote');

    $request->refresh();

    expect($request->status)->toBe(ChangeRequestStatus::Rejected)
        ->and($request->resolution_note)->toBe('The PDF comes from the WhatsApp template, not the app')
        ->and($request->resolved_at)->not->toBeNull();

    Notification::assertSentTo($sales, ChangeRequestUpdatedNotification::class, function (ChangeRequestUpdatedNotification $notification) use ($request): bool {
        return $notification->request->id === $request->id;
    });
});

it('sends the outcome to the requester linked on Telegram, phrased for the phone', function () {
    $owner = $this->userWithRole('owner', ['name' => 'Imtiaz Ahmad']);
    $sales = $this->userWithRole('sales');
    TelegramLink::query()->create(['user_id' => $sales->id, 'chat_id' => 620, 'is_active' => true, 'linked_at' => now()]);

    $request = ChangeRequest::query()->create([
        'type' => ChangeRequestType::Idea,
        'title' => 'Show completed projects on the website',
        'status' => ChangeRequestStatus::Accepted,
        'source' => 'telegram',
        'requested_by' => $sales->id,
    ]);

    app(SetChangeRequestStatus::class)->handle($request, ChangeRequestStatus::Planned, $owner);

    expect($request->refresh()->status)->toBe(ChangeRequestStatus::Planned);

    $notification = new ChangeRequestUpdatedNotification($request->refresh(), $owner);
    $telegram = $notification->toTelegram($sales);

    expect($telegram->text)->toContain($request->reference())
        ->and($telegram->text)->toContain('Planned')
        ->and($telegram->text)->toContain('Imtiaz Ahmad')
        ->and($telegram->text)->toContain('Show completed projects on the website');
});

it('keeps the board to the owner and admins', function () {
    $sales = $this->userWithRole('sales');

    $this->actingAs($sales)->get('/admin/system/change-requests')->assertForbidden();

    $owner = $this->userWithRole('owner');
    $this->actingAs($owner)->get('/admin/system/change-requests')->assertOk();
});

it('shows on the board what was just sent with /idea from Telegram', function () {
    config(['services.telegram.webhook_secret' => 'hook-secret']);
    $telegram = FakeTelegramGateway::bind();
    $owner = $this->userWithRole('owner');
    TelegramLink::query()->create(['user_id' => $owner->id, 'chat_id' => 500, 'is_active' => true, 'linked_at' => now()]);

    $this->postJson('/webhooks/telegram/hook-secret', [
        'update_id' => 700,
        'message' => [
            'message_id' => 701,
            'chat' => ['id' => 500, 'type' => 'private'],
            'from' => ['id' => 500, 'first_name' => 'Imtiaz'],
            'text' => '/idea Remember the source of every lead in the funnel report',
        ],
    ], headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

    $replies = $telegram->textsFor(500);
    expect((string) end($replies))->toContain('Saved as CR-');

    $entry = ChangeRequest::query()->where('source', 'telegram')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->requested_by)->toBe($owner->id);

    Livewire::actingAs($owner)
        ->test(ChangeRequests::class)
        ->assertSee('Remember the source of every lead in the funnel report');
});
