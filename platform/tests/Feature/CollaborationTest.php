<?php

use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Notifications\MentionedNotification;
use App\Livewire\Admin\Crm\LeadShow;
use App\Livewire\Admin\NotificationBell;
use App\Livewire\Admin\NotificationsPage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('sales', ['name' => 'Bilal Ahmed', 'email' => 'bilal@woodex.test']);
    $this->lead = Lead::factory()->create(['owner_id' => $this->owner->id]);
    $this->actingAs($this->owner);
});

it('notifies teammates mentioned in a note, but not the author', function () {
    Notification::fake();
    $sara = $this->userWithRole('sales_manager', ['name' => 'Sara Khan', 'email' => 'sara.khan@woodex.test']);
    $omar = $this->userWithRole('designer_pm', ['name' => 'Omar Farooq', 'email' => 'omar@woodex.test']);

    Livewire::test(LeadShow::class, ['lead' => $this->lead])
        ->set('logType', 'note')
        ->set('logBody', 'Client wants a turnkey quote. @Sara please approve the discount; cc @omar and me @Bilal. Email: x@omar.pk')
        ->call('logActivity')
        ->assertHasNoErrors();

    Notification::assertSentTo([$sara, $omar], MentionedNotification::class);
    Notification::assertNotSentTo($this->owner, MentionedNotification::class);
    expect($this->lead->activities()->where('type', 'note')->latest('id')->first()->data['mentions'])->toEqualCanonicalizing([$sara->id, $omar->id]);
});

it('shows notifications in the bell, opens the deep link and marks everything read', function () {
    $this->owner->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'data' => ['title' => 'New lead from the website', 'body' => 'Ayesha · DHA', 'url' => route('admin.leads.show', $this->lead)],
    ]);
    $id = $this->owner->notifications()->value('id');

    Livewire::test(NotificationBell::class)
        ->assertSee('New lead from the website')
        ->call('open', $id)
        ->assertRedirect(route('admin.leads.show', $this->lead));

    expect($this->owner->unreadNotifications()->count())->toBe(0);

    $this->get(route('admin.notifications'))->assertOk()->assertSee('New lead from the website');
    Livewire::test(NotificationsPage::class)->set('filter', 'unread')->assertSee('No notifications');
});

it('uploads files to a lead privately and lets only people who see the lead download them', function () {
    Livewire::test(LeadShow::class, ['lead' => $this->lead])
        ->set('uploads', [UploadedFile::fake()->create('floor-plan.pdf', 300, 'application/pdf')])
        ->assertHasNoErrors()
        ->assertSee('floor-plan.pdf');

    $media = $this->lead->fresh()->getFirstMedia('files');
    expect($media)->not->toBeNull()
        ->and($media->disk)->toBe('local')
        ->and($this->lead->activities()->latest('id')->value('body'))->toContain('floor-plan.pdf');

    $this->get(route('admin.media.show', $media))->assertOk();

    $this->actingAs($this->userWithRole('sales'));
    $this->get(route('admin.media.show', $media))->assertForbidden();
});

it('rejects files that are too big or of an unknown type', function () {
    Livewire::test(LeadShow::class, ['lead' => $this->lead])
        ->set('uploads', [UploadedFile::fake()->create('virus.exe', 10)])
        ->assertHasErrors('uploads.0')
        ->set('uploads', [UploadedFile::fake()->create('huge.pdf', 21000, 'application/pdf')])
        ->assertHasErrors('uploads.0');

    expect($this->lead->getMedia('files'))->toHaveCount(0);
});
