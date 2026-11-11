<?php

use App\Domain\Crm\Actions\AssignLead;
use App\Domain\Crm\Actions\CheckLeadSla;
use App\Domain\Crm\Actions\ManageTask;
use App\Domain\Crm\Actions\ScoreLead;
use App\Domain\Crm\Actions\SendReminders;
use App\Domain\Crm\Enums\TaskRecurrence;
use App\Domain\Crm\Models\AssignmentRule;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\Task;
use App\Domain\Crm\Notifications\SlaBreachedNotification;
use App\Domain\Crm\Notifications\TaskReminderNotification;
use App\Domain\Crm\Notifications\VisitReminderNotification;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use App\Support\BusinessHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

describe('assignment rules (CRM-009)', function () {
    beforeEach(function () {
        // Tuesday 11:00 Karachi: the studio is open.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 11:00', 'Asia/Karachi'));
        $this->ali = $this->userWithRole('sales', ['name' => 'Ali']);
        $this->sara = $this->userWithRole('sales', ['name' => 'Sara']);
        $this->omar = $this->userWithRole('sales', ['name' => 'Omar']);
    });

    it('routes matching leads round robin among the rule\'s people', function () {
        AssignmentRule::create([
            'name' => 'Projects in DHA',
            'conditions' => ['business_lines' => ['project'], 'cities' => ['DHA']],
            'user_ids' => [$this->ali->id, $this->sara->id],
        ]);

        $owners = collect(range(1, 3))->map(function () {
            $lead = Lead::factory()->create(['owner_id' => null, 'business_line' => 'project', 'area' => 'DHA Phase 6']);

            return app(AssignLead::class)->handle($lead)?->id;
        });

        expect($owners->all())->toBe([$this->ali->id, $this->sara->id, $this->ali->id]);
        expect(Lead::query()->latest('id')->first()->activities()->value('body'))->toContain('rule: Projects in DHA');
    });

    it('skips rules that do not match and respects the workload cap', function () {
        AssignmentRule::create(['name' => 'Furniture', 'position' => 1, 'conditions' => ['business_lines' => ['furniture']], 'user_ids' => [$this->omar->id]]);
        AssignmentRule::create(['name' => 'Capped', 'position' => 2, 'role' => 'sales', 'strategy' => 'least_loaded', 'max_open_leads' => 1]);
        Lead::factory()->create(['owner_id' => $this->ali->id]);
        Lead::factory()->create(['owner_id' => $this->sara->id]);

        $lead = Lead::factory()->create(['owner_id' => null, 'business_line' => 'interior']);

        expect(app(AssignLead::class)->handle($lead)?->id)->toBe($this->omar->id);
    });

    it('skips working-hours-only rules while the studio is closed', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 22:30', 'Asia/Karachi'));
        AssignmentRule::create(['name' => 'Daytime', 'position' => 1, 'working_hours_only' => true, 'user_ids' => [$this->ali->id]]);
        AssignmentRule::create(['name' => 'After hours', 'position' => 2, 'user_ids' => [$this->sara->id]]);

        $lead = Lead::factory()->create(['owner_id' => null]);

        expect(app(AssignLead::class)->handle($lead)?->id)->toBe($this->sara->id);
    });
});

it('gives each source its own first-response target and skips holidays', function () {
    $sales = app(SalesSettings::class);
    $sales->sla_minutes_by_source = ['whatsapp' => 5];
    $sales->save();

    $hours = new BusinessHours('10:00', '19:00', [1, 2, 3, 4, 5, 6], holidays: ['2026-12-25']);
    // Thursday 24 Dec 20:00 → Friday is a holiday → Saturday 26 Dec 10:00 + 30 min grace.
    expect($hours->dueAt(CarbonImmutable::parse('2026-12-24 20:00', 'Asia/Karachi'))->format('Y-m-d H:i'))->toBe('2026-12-26 10:30')
        ->and($sales->slaMinutesFor('whatsapp'))->toBe(5)
        ->and($sales->slaMinutesFor('website_form'))->toBe(15)
        ->and(app(BusinessSettings::class)->hours()->holidays)->toContain('2026-12-25');
});

it('escalates a missed response to the owner role after the escalation delay, once', function () {
    Notification::fake();
    $owner = $this->userWithRole('owner');
    $lead = Lead::factory()->create([
        'sla_due_at' => now()->subMinutes(90),
        'sla_breached_at' => now()->subMinutes(61),
        'first_response_at' => null,
    ]);

    expect(app(CheckLeadSla::class)->escalate())->toBe(1)
        ->and(app(CheckLeadSla::class)->escalate())->toBe(0)
        ->and($lead->fresh()->sla_escalated_at)->not->toBeNull();

    Notification::assertSentTo($owner, SlaBreachedNotification::class, fn ($n) => $n->escalated === true);
});

it('scores leads from the rules in Settings', function () {
    $lead = Lead::factory()->make(['budget_band' => '15m-plus', 'area_sqft' => 5000, 'business_line' => 'project', 'city' => 'Lahore', 'service' => null, 'timeline' => null, 'source' => 'website_form', 'lead_type' => 'new']);

    // Defaults: base 20 + budget 30 + size 15 + project 10.
    expect(app(ScoreLead::class)->score($lead))->toBe(75);

    $sales = app(SalesSettings::class);
    $rules = $sales->scoring_rules;
    $rules['cities'] = ['lahore' => 10];
    $sales->scoring_rules = $rules;
    $sales->hot_score = 85;
    $sales->save();

    $lead->score = app(ScoreLead::class)->score($lead);
    expect($lead->score)->toBe(85)->and($lead->isHot())->toBeTrue();
});

it('creates the next occurrence when a repeating task is completed', function () {
    $user = $this->userWithRole('sales');
    $task = app(ManageTask::class)->create('Weekly pipeline review', now()->addHour(), $user, $user, recurrence: TaskRecurrence::Weekly);

    app(ManageTask::class)->complete($task);
    app(ManageTask::class)->reopen($task);
    app(ManageTask::class)->complete($task);

    $next = Task::query()->where('status', 'open')->sole();
    expect($next->due_at->toDateString())->toBe(now()->addHour()->addWeek()->toDateString())
        ->and($next->recurrence)->toBe(TaskRecurrence::Weekly);
});

it('sends task and visit reminders once', function () {
    Notification::fake();
    $user = $this->userWithRole('sales');
    $task = app(ManageTask::class)->create('Call back Mr. Raza', now()->addMinutes(20), $user, $user);
    $lead = Lead::factory()->create(['owner_id' => $user->id]);
    $visit = $lead->siteVisits()->create(['contact_id' => $lead->contact_id, 'scheduled_at' => now()->addHours(20), 'assigned_to' => $user->id, 'status' => 'scheduled']);

    expect(app(SendReminders::class)->handle())->toBe(['tasks' => 1, 'visits' => 1])
        ->and(app(SendReminders::class)->handle())->toBe(['tasks' => 0, 'visits' => 0]);

    $this->travel(19)->hours();
    expect(app(SendReminders::class)->visits())->toBe(1);

    Notification::assertSentTo($user, TaskReminderNotification::class);
    Notification::assertSentToTimes($user, VisitReminderNotification::class, 2);
    expect($visit->fresh()->reminders)->toHaveKeys(['staff_d1', 'staff_2h'])
        ->and($task->fresh()->reminded_at)->not->toBeNull();
});
