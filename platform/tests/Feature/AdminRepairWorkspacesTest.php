<?php

declare(strict_types=1);

use App\Domain\Accounts\Models\Vendor;
use App\Domain\Automation\Models\AutomationSequence;
use App\Domain\Automation\Models\AutomationStep;
use App\Livewire\Admin\Automation\SequencesIndex;
use App\Livewire\Admin\GlobalSearch;
use App\Support\AdminNavigation;
use Livewire\Livewire;

it('keeps exactly three Accounts front doors without removing the original tools', function () {
    $owner = $this->userWithRole('owner');
    $group = collect(AdminNavigation::for($owner))->firstWhere('title', 'Accounts');
    expect(collect($group['items'])->pluck('label')->all())->toBe(['Ledger & reports', 'Payables', 'Income & expenses']);
    $this->actingAs($owner);
    foreach (['chart', 'journals', 'trial', 'profit', 'periods', 'methods'] as $tab) {
        $this->get(route('admin.accounts.workspace', ['tab' => $tab]))->assertOk()->assertSee('Ledger &amp; reports', false);
        $this->get(route('admin.accounts.'.$tab))->assertOk();
    }
});

it('does not expose the accounting workspace to a role without accounting permission', function () {
    $this->actingAs($this->userWithRole('support'))->get(route('admin.accounts.workspace'))->assertForbidden();
});

it('searches authorised screens including redirects and retained accounting bookmarks', function () {
    $this->actingAs($this->userWithRole('owner'));
    Livewire::test(GlobalSearch::class)->set('term', 'redirect')->assertViewHas('pages', fn ($pages) => $pages->contains('url', route('admin.website.redirects')));
    Livewire::test(GlobalSearch::class)->set('term', 'journals')->assertViewHas('pages', fn ($pages) => $pages->contains('url', route('admin.accounts.journals')));
    Livewire::test(GlobalSearch::class)->assertViewHas('pages', fn ($pages) => $pages->contains(fn ($page) => $page['url'] === route('admin.website.builder') && $page['fullDocument']));
    $this->actingAs($this->userWithRole('support'));
    Livewire::test(GlobalSearch::class)->set('term', 'journals')->assertViewHas('pages', fn ($pages) => $pages->isEmpty());
    Livewire::test(GlobalSearch::class)->set('term', 'import')->assertViewHas('pages', fn ($pages) => $pages->isEmpty());
});

it('searches vendor records only for accounting viewers and keeps queries bounded', function () {
    Vendor::query()->create(['name' => 'Synthetic searchable supplier', 'is_active' => true]);
    $this->actingAs($this->userWithRole('owner'));
    Livewire::test(GlobalSearch::class)->set('term', 'searchable supplier')->assertSee('Synthetic searchable supplier')
        ->assertViewHas('records', fn ($records) => $records->count() === 1 && $records->first()['group'] === 'Vendors');
    Livewire::test(GlobalSearch::class)->set('term', 's')->assertViewHas('records', fn ($records) => $records->isEmpty());
    $this->actingAs($this->userWithRole('support'));
    Livewire::test(GlobalSearch::class)->set('term', 'searchable supplier')->assertDontSee('Synthetic searchable supplier')
        ->assertViewHas('records', fn ($records) => $records->isEmpty());
});

it('restores missing defaults paused and never overwrites studio changes or runs clients', function () {
    $existing = AutomationSequence::query()->where('slug', 'lead-not-reached')->firstOrFail();
    AutomationSequence::query()->whereKeyNot($existing->id)->delete();
    $existing->forceFill([
        'slug' => 'lead-not-reached', 'name' => 'Owner edited wording', 'description' => 'Preserve this definition',
        'trigger' => 'lead_created', 'is_active' => true, 'is_builtin' => false, 'exits_on_reply' => false,
    ])->save();
    $step = $existing->steps()->firstOrFail();
    $step->forceFill(['wait_minutes' => 123])->save();
    $before = $existing->fresh()->getAttributes();
    Livewire::actingAs($this->userWithRole('owner'))->test(SequencesIndex::class)->call('restoreMissingDefaults')->assertHasNoErrors();
    expect($existing->fresh()->getAttributes())->toBe($before)
        ->and($step->fresh()->wait_minutes)->toBe(123)
        ->and(AutomationSequence::query()->count())->toBe(6)
        ->and(AutomationSequence::query()->whereKeyNot($existing->id)->where('is_active', true)->count())->toBe(0);
    $this->assertDatabaseCount('automation_enrolments', 0);
    $steps = AutomationStep::query()->count();
    Livewire::test(SequencesIndex::class)->call('restoreMissingDefaults')->assertHasNoErrors();
    expect(AutomationSequence::query()->count())->toBe(6)->and(AutomationStep::query()->count())->toBe($steps);
});

it('allows only the owner to restore defaults even when another role can manage automation', function () {
    $manager = $this->userWithRole('sales_manager');
    $manager->givePermissionTo('automation.manage');
    Livewire::actingAs($manager)->test(SequencesIndex::class)->call('restoreMissingDefaults')->assertForbidden();
    $this->assertDatabaseCount('automation_sequences', 6);
});
