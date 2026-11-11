<?php

use App\Domain\System\Models\TelegramLink;
use App\Domain\System\Notifications\StaffInvitation;
use App\Livewire\Admin\System\SettingsPage;
use App\Livewire\Admin\System\UsersIndex;
use App\Models\User;
use App\Settings\BusinessSettings;
use App\Settings\NotificationSettings;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('invites a team member who receives a set-password link', function () {
    Notification::fake();
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(UsersIndex::class)
        ->call('openInvite')
        ->set('name', 'Fatima Noor')
        ->set('email', 'Fatima@Woodex.test')
        ->set('phone', '0300 1234567')
        ->set('role', 'sales')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $user = User::query()->where('email', 'fatima@woodex.test')->sole();

    expect($user->hasRole('sales'))->toBeTrue()
        ->and($user->phone)->toBe('+923001234567')
        ->and($user->is_active)->toBeTrue();

    Notification::assertSentTo($user, StaffInvitation::class, function (StaffInvitation $notification) use ($user) {
        $mail = $notification->toMail($user);

        return str_contains($mail->actionUrl, '/reset-password/'.$notification->token)
            && str_contains($mail->actionUrl, urlencode('fatima@woodex.test'));
    });
});

it('only lets owners hand out the owner role', function () {
    Notification::fake();
    $this->actingAs($this->userWithRole('admin'));

    Livewire::test(UsersIndex::class)
        ->call('openInvite')
        ->set('name', 'New Owner')
        ->set('email', 'owner2@woodex.test')
        ->set('role', 'owner')
        ->call('save')
        ->assertHasErrors(['role']);

    expect(User::query()->where('email', 'owner2@woodex.test')->exists())->toBeFalse();
});

it('never removes the last active owner and nobody can deactivate themselves', function () {
    $owner = $this->userWithRole('owner');
    $admin = $this->userWithRole('admin');

    $this->actingAs($owner);

    Livewire::test(UsersIndex::class)->call('toggleActive', $owner->id)->assertDispatched('toast', type: 'error');
    expect($owner->fresh()->is_active)->toBeTrue();

    Livewire::test(UsersIndex::class)
        ->call('openEdit', $owner->id)
        ->set('role', 'admin')
        ->call('save')
        ->assertHasErrors(['role']);
    expect($owner->fresh()->hasRole('owner'))->toBeTrue();

    $this->actingAs($admin);

    Livewire::test(UsersIndex::class)->call('toggleActive', $owner->id)->assertDispatched('toast', type: 'error');
    expect($owner->fresh()->is_active)->toBeTrue();
});

it('deactivates a leaver and changes roles', function () {
    $owner = $this->userWithRole('owner');
    $sales = $this->userWithRole('sales');

    $this->actingAs($owner);

    Livewire::test(UsersIndex::class)
        ->call('openEdit', $sales->id)
        ->set('role', 'sales_manager')
        ->set('title', 'Sales Manager')
        ->call('save')
        ->assertHasNoErrors();

    expect($sales->fresh()->hasRole('sales_manager'))->toBeTrue()
        ->and($sales->fresh()->hasRole('sales'))->toBeFalse()
        ->and($sales->fresh()->title)->toBe('Sales Manager');

    TelegramLink::query()->create(['user_id' => $sales->id, 'chat_id' => 777, 'is_active' => true, 'linked_at' => now()]);

    Livewire::test(UsersIndex::class)->call('toggleActive', $sales->id);

    expect($sales->fresh()->is_active)->toBeFalse()
        ->and($sales->fresh()->routeNotificationForTelegram())->toBeNull()
        ->and(TelegramLink::query()->where('user_id', $sales->id)->value('is_active'))->toBeFalse();
});

it('keeps users and settings behind their permissions', function () {
    $sales = $this->userWithRole('sales');

    $this->actingAs($sales)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($sales)->get(route('admin.settings'))->assertForbidden();
    $this->actingAs($this->userWithRole('admin'))->get(route('admin.settings'))->assertOk()->assertSee('Working hours');
    $this->actingAs($this->userWithRole('owner'))->get(route('admin.users.index'))->assertOk()->assertSee('Invite user');
});

it('saves business settings with normalised numbers and working days', function () {
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(SettingsPage::class)
        ->set('business.phone', '0322 4200168')
        ->set('business.whatsapp', '+92 322 4000768')
        ->set('business.working_days', ['1', '2', '3', '4', '5'])
        ->set('business.closes_at', '20:00')
        ->call('saveBusiness')
        ->assertHasNoErrors();

    $settings = app(BusinessSettings::class);
    $settings->refresh();

    expect($settings->phone)->toBe('+923224200168')
        ->and($settings->whatsapp)->toBe('+923224000768')
        ->and($settings->working_days)->toBe([1, 2, 3, 4, 5])
        ->and($settings->closes_at)->toBe('20:00');
});

it('rejects a closing time before opening time', function () {
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(SettingsPage::class)
        ->set('business.opens_at', '18:00')
        ->set('business.closes_at', '09:00')
        ->call('saveBusiness')
        ->assertHasErrors(['business.closes_at']);
});

it('saves sales rules: discount limits as percentages, lost reasons and budget bands', function () {
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(SettingsPage::class)
        ->set('tab', 'sales')
        ->set('sales.discount_limits.sales', '7.5')
        ->set('sales.lost_reasons', "Budget too low\n\nChose another company\nBudget too low")
        ->set('sales.sla_first_response_minutes', 20)
        ->call('addBand')
        ->set('sales.budget_bands.5', ['key' => '30m-plus', 'label' => 'Rs 3 crore+'])
        ->call('saveSales')
        ->assertHasNoErrors();

    $settings = app(SalesSettings::class);
    $settings->refresh();

    expect($settings->discount_limits_bp['sales'])->toBe(750)
        ->and($settings->lost_reasons)->toBe(['Budget too low', 'Chose another company'])
        ->and($settings->sla_first_response_minutes)->toBe(20)
        ->and($settings->budget_bands)->toHaveKey('30m-plus', 'Rs 3 crore+');
});

it('rejects budget band keys with spaces', function () {
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(SettingsPage::class)
        ->set('sales.budget_bands.0.key', 'Under 1m')
        ->call('saveSales')
        ->assertHasErrors(['sales.budget_bands.0.key']);
});

it('saves notification escalation roles', function () {
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(SettingsPage::class)
        ->set('notifications.unassigned_lead_roles', ['owner'])
        ->set('notifications.quiet_hours_start', '22:00')
        ->call('saveNotifications')
        ->assertHasNoErrors();

    $settings = app(NotificationSettings::class);
    $settings->refresh();

    expect($settings->unassigned_lead_roles)->toBe(['owner'])
        ->and($settings->quiet_hours_start)->toBe('22:00');
});
