<?php

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Actions\ChangeProjectStage;
use App\Domain\Projects\Actions\PostProjectUpdate;
use App\Domain\Projects\Actions\RaiseMilestoneInvoice;
use App\Domain\Projects\Actions\ReachProjectMilestone;
use App\Domain\Projects\Enums\ProjectStage;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Mail\ProjectMail;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectUpdate;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Livewire\Admin\Projects\ProjectBoard;
use App\Livewire\Admin\Projects\ProjectShow;
use App\Livewire\Admin\Projects\ProjectsIndex;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
| P4 part 1: the project workspace (PRJ-002…PRJ-005). A project appears when a client accepts, moves along the
| stages, carries the milestones the money hangs on, and holds the diary from the site — which stays inside the
| studio until somebody shares it with the client.
*/
beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    Storage::fake('local');
    Notification::fake();

    $this->sales = $this->userWithRole('sales');
    $this->pm = $this->userWithRole('designer_pm');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'city' => 'Lahore', 'area' => 'Model Town']);
    $this->lead->contact->forceFill(['email' => 'ayesha@example.com'])->save();

    BankAccount::query()->create(['business_line' => BusinessLine::Interior, 'title' => 'WOODEX INTERIOR', 'bank_name' => 'Bank Alfalah', 'account_number' => '0000-0000-0000', 'iban' => 'PK00ALFA0000000000000000', 'is_default' => true, 'is_active' => true]);

    $this->quote = sentProjectQuote($this->lead, $this->sales);
    $this->post(QuoteLinks::signed('quotes.portal.accept', $this->quote), ['revision' => $this->quote->revision, 'name' => 'Ayesha Khan', 'confirm' => '1'])->assertRedirect();

    $this->project = Project::query()->sole();
});

/** A sent, accepted quotation worth PKR 1,000,000 on the 50/40/10 plan, and the project it opened. */
function sentProjectQuote(Lead $lead, User $sales, MilestonePlanKey $plan = MilestonePlanKey::Execution, int $rupees = 1_000_000): Quote
{
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $sales, $lead, null, 'Lounge and kitchen');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling, walnut veneer', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => $rupees * 100]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->setMilestonePlan($quote->refresh(), $plan);
    app(RecalculateQuote::class)->handle($quote->refresh(), $sales);

    return app(SendQuote::class)->handle($quote->refresh(), $sales)->quote->refresh();
}

it('opens the project when the client accepts, ready to be worked on', function () {
    expect($this->project->stage)->toBe(ProjectStage::Planning)
        ->and($this->project->status)->toBe(ProjectStatus::Active)
        ->and($this->project->progress_percent)->toBe(0)
        ->and($this->project->milestones)->toHaveCount(3)
        ->and($this->project->invoicedMinor())->toBe(500_000 * 100) // the advance alone, so far
        ->and($this->project->collectedMinor())->toBe(0)
        ->and($this->project->nextMilestone()?->name)->not->toBeNull();
});

it('moves a project along the stages and tells the client in one line', function () {
    Mail::fake();

    Livewire::actingAs($this->pm)
        ->test(ProjectBoard::class)
        ->call('moveProject', $this->project->id, 'execution');

    $project = $this->project->fresh();

    expect($project->stage)->toBe(ProjectStage::Execution)
        ->and($project->status)->toBe(ProjectStatus::Active);

    Mail::assertQueued(ProjectMail::class, fn (ProjectMail $mail): bool => $mail->heading === $project->code.' is now at Execution');

    // What they are told is the studio's own line about the stage, not a code.
    Mail::assertQueued(ProjectMail::class, fn (ProjectMail $mail): bool => $mail->body === ProjectStage::Execution->clientLine());
});

it('closes the job when it reaches completed, and leaves a note on the timeline', function () {
    app(ChangeProjectStage::class)->handle($this->project, ProjectStage::Completed, $this->pm);

    $project = $this->project->fresh();

    expect($project->stage)->toBe(ProjectStage::Completed)
        ->and($project->status)->toBe(ProjectStatus::Completed)
        ->and($project->progress_percent)->toBe(100)
        ->and($project->completed_at)->not->toBeNull();

    $note = Activity::query()->where('type', ActivityType::StageChange)->latest('id')->firstOrFail();

    expect($note->body)->toContain($project->code)
        ->and($note->body)->toContain('Completed')
        ->and($note->contact_id)->toBe($project->contact_id);
});

it('keeps a note from the site inside the studio until it is shared', function () {
    Mail::fake();

    $update = app(PostProjectUpdate::class)->handle($this->project, $this->pm, ['body' => 'Wood work in the lounge is complete.']);

    expect($update->client_visible)->toBeFalse()
        ->and($update->shared_at)->toBeNull();
    Mail::assertNothingQueued();

    Livewire::actingAs($this->pm)->test(ProjectShow::class, ['project' => $this->project])->call('share', $update->id);

    expect($update->fresh()->client_visible)->toBeTrue()
        ->and($update->fresh()->shared_at)->not->toBeNull();
    Mail::assertQueued(ProjectMail::class);

    // Sharing twice is harmless: the client gets one email.
    Livewire::actingAs($this->pm)->test(ProjectShow::class, ['project' => $this->project])->call('share', $update->id);
    Mail::assertQueuedCount(1);
});

it('posts an update with photographs, lifts the progress and writes it on the timeline', function () {
    Mail::fake();

    // Livewire parks an uploaded photograph on its temporary disk first, and expires anything it thinks is
    // older than five minutes: with the clock frozen a month ahead, every upload would arrive empty.
    $this->travelBack();

    $component = Livewire::actingAs($this->pm)->test(ProjectShow::class, ['project' => $this->project]);

    $component->set([
        'body' => 'Electrical rough-in finished, photographs attached.',
        'progressPercent' => 45,
        'photos' => [UploadedFile::fake()->image('site-one.jpg', 800, 600), UploadedFile::fake()->image('site-two.jpg', 800, 600)],
        'shareWithClient' => true,
    ])
        ->call('post')
        ->assertHasNoErrors();

    $update = ProjectUpdate::query()->sole();

    expect($update->body)->toBe('Electrical rough-in finished, photographs attached.')
        ->and($update->progress_percent)->toBe(45)
        ->and($update->client_visible)->toBeTrue()
        ->and($update->getMedia('photos'))->toHaveCount(2)
        ->and($this->project->fresh()->progress_percent)->toBe(45);

    $note = Activity::query()->where('type', ActivityType::Note)->latest('id')->firstOrFail();
    expect($note->body)->toContain($this->project->code);

    Mail::assertQueued(ProjectMail::class);

    // The form is empty again, ready for the next one.
    expect($component->get('body'))->toBe('')
        ->and($component->get('photos'))->toBe([]);
});

it('marks a milestone reached and raises its invoice once, as a draft', function () {
    $milestone = $this->project->milestones()->orderBy('position')->get()[1]; // the 40% "during work" one

    app(ReachProjectMilestone::class)->handle($milestone, $this->pm);

    expect($milestone->fresh()->reached_at)->not->toBeNull()
        ->and($this->project->fresh()->progress_percent)->toBe(40);

    $first = app(RaiseMilestoneInvoice::class)->handle($milestone->fresh(), $this->pm);
    $again = app(RaiseMilestoneInvoice::class)->handle($milestone->fresh(), $this->pm);

    expect($first->id)->toBe($again->id)
        ->and($first->status)->toBe(InvoiceStatus::Draft) // nothing reaches a client unsent
        ->and($first->project_milestone_id)->toBe($milestone->id)
        ->and((int) $first->total_minor)->toBe(400_000 * 100)
        ->and($first->number)->toBe($this->project->code.'-2'); // -1 is the advance
});

it('raises the milestone invoice from the project screen', function () {
    $milestone = $this->project->milestones()->orderBy('position')->get()[2];

    $component = Livewire::actingAs($this->pm)->test(ProjectShow::class, ['project' => $this->project]);

    // The work reaches the milestones one after another: 50% + 40% + 10% is the whole job.
    foreach ($this->project->milestones as $each) {
        $component->call('toggleMilestone', $each->id)->assertHasNoErrors();
    }

    $component->call('raiseInvoice', $milestone->id)->assertHasNoErrors();

    expect(Invoice::query()->where('project_milestone_id', $milestone->id)->sole()->status)->toBe(InvoiceStatus::Draft)
        ->and($this->project->fresh()->progress_percent)->toBe(100);
});

it('shows the project to the team, and only lets the people who run the work change it', function () {
    Livewire::actingAs($this->sales)->test(ProjectShow::class, ['project' => $this->project])->assertOk();

    Livewire::actingAs($this->sales)->test(ProjectsIndex::class)->assertOk();

    // A salesperson may look at a project but not move it: that is the PM's job.
    Livewire::actingAs($this->sales)->test(ProjectShow::class, ['project' => $this->project])
        ->call('setStage', 'design')
        ->assertForbidden();

    Livewire::actingAs($this->pm)->test(ProjectShow::class, ['project' => $this->project])
        ->call('setStage', 'design')
        ->assertHasNoErrors();

    expect($this->project->fresh()->stage)->toBe(ProjectStage::Design);
});

it('hides projects from people who do not work on them', function () {
    $editor = $this->userWithRole('editor');

    $this->actingAs($editor)->get(route('admin.projects.index'))->assertForbidden();
    $this->actingAs($editor)->get(route('admin.projects.board'))->assertForbidden();
    $this->actingAs($this->pm)->get(route('admin.projects.index'))->assertOk();
    $this->actingAs($this->pm)->get(route('admin.projects.board'))->assertOk();
});
