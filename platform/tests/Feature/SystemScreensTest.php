<?php

use App\Domain\Crm\Actions\TrashRecords;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\System\Jobs\RunV21Import;
use App\Domain\System\Models\IntegrationLog;
use App\Livewire\Admin\Crm\ContactShow;
use App\Livewire\Admin\Crm\LeadShow;
use App\Livewire\Admin\System\DataImport;
use App\Livewire\Admin\System\Integrations;
use App\Livewire\Admin\System\RecycleBin;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('bins a lead from its page and restores it from the recycle bin', function () {
    $admin = $this->userWithRole('admin');
    $this->actingAs($admin);
    $lead = Lead::factory()->create();

    Livewire::test(LeadShow::class, ['lead' => $lead])->call('deleteLead')->assertRedirect(route('admin.leads.index'));
    expect($lead->fresh()->trashed())->toBeTrue();

    $this->get(route('admin.recycle-bin'))->assertOk()->assertSee($lead->ref);
    Livewire::test(RecycleBin::class)->call('restore', 'lead', $lead->id);
    expect($lead->fresh()->trashed())->toBeFalse();
});

it('bins a contact together with its enquiries and restores them together', function () {
    $this->actingAs($this->userWithRole('admin'));
    $lead = Lead::factory()->create();
    $contact = $lead->contact;

    Livewire::test(ContactShow::class, ['contact' => $contact])->call('deleteContact');
    expect($contact->fresh()->trashed())->toBeTrue()->and($lead->fresh()->trashed())->toBeTrue();

    Livewire::test(RecycleBin::class)->set('tab', 'contacts')->assertSee($contact->name)->call('restore', 'contact', $contact->id);
    expect($contact->fresh()->trashed())->toBeFalse()->and($lead->fresh()->trashed())->toBeFalse();
});

it('lets only the owner erase a contact\'s personal data', function () {
    $lead = Lead::factory()->create(['message' => 'Call me at home, House 4 DHA']);
    $contact = $lead->contact;
    app(TrashRecords::class)->deleteContact($contact);

    $this->actingAs($this->userWithRole('admin'));
    Livewire::test(RecycleBin::class)->call('erase', $contact->id)->assertForbidden();

    $this->actingAs($this->userWithRole('owner'));
    Livewire::test(RecycleBin::class)->set('tab', 'contacts')->call('erase', $contact->id)->assertSee('Erased');

    $erased = Contact::withTrashed()->find($contact->id);
    expect($erased->name)->toBe("Erased contact #{$contact->id}")
        ->and($erased->phone)->toBeNull()
        ->and($erased->email)->toBeNull()
        ->and(Lead::withTrashed()->find($lead->id)->message)->toBeNull();
});

it('keeps the recycle bin away from sales users', function () {
    $this->actingAs($this->userWithRole('sales'));
    $this->get(route('admin.recycle-bin'))->assertForbidden();
});

it('shows integration status and the outbound log without secrets', function () {
    config(['services.telegram.bot_token' => '123:SECRET-TOKEN']);
    IntegrationLog::create(['integration' => 'telegram', 'action' => 'sendMessage', 'ok' => false, 'status_code' => 403, 'response' => ['description' => 'bot was blocked by the user'], 'created_at' => now()]);
    $this->actingAs($this->userWithRole('owner'));

    $this->get(route('admin.integrations'))->assertOk()
        ->assertSee('Telegram bot')
        ->assertSee('bot was blocked by the user')
        ->assertDontSee('SECRET-TOKEN');

    Livewire::test(Integrations::class)->call('testTelegram')->assertDispatched('toast', type: 'warning');
});

it('queues a dry run from the data import screen and requires one before the real import', function () {
    Queue::fake();
    Storage::fake('local');
    config(['database.connections.legacy.database' => 'woodex_v21']);
    $this->actingAs($this->userWithRole('admin'));

    $this->get(route('admin.data-import'))->assertOk()->assertSee('No reports yet');

    Livewire::test(DataImport::class)
        ->set('confirmation', 'IMPORT')
        ->call('import')
        ->assertHasErrors('confirmation')
        ->set('steps', ['clients', 'leads'])
        ->call('dryRun')
        ->assertDispatched('toast');

    Queue::assertPushed(RunV21Import::class, fn (RunV21Import $job) => $job->dryRun && $job->only === ['clients', 'leads']);

    Storage::disk('local')->put('imports/20261016-101500-dry-run/report.json', json_encode(['dry_run' => true, 'started_at' => now()->toAtomString(), 'seconds' => 2.5, 'counts' => ['clients' => ['created' => 12]], 'warnings' => 1, 'duplicates' => 0]));
    cache()->forget(RunV21Import::STATUS_KEY);

    Livewire::test(DataImport::class)
        ->assertSee('12 created')
        ->set('confirmation', 'IMPORT')
        ->call('import')
        ->assertHasNoErrors();

    Queue::assertPushed(RunV21Import::class, fn (RunV21Import $job) => ! $job->dryRun);
});
