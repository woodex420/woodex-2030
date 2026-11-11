<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Actions\DraftCaseStudy;
use App\Domain\Projects\Actions\OpenPortalWithCode;
use App\Domain\Projects\Actions\PostProjectUpdate;
use App\Domain\Projects\Actions\RequestClientReviews;
use App\Domain\Projects\Actions\RequestPortalAccess;
use App\Domain\Projects\Actions\StartHandover;
use App\Domain\Projects\Enums\ProjectStage;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Mail\PortalCodeMail;
use App\Domain\Projects\Mail\ProjectMail;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\PortalLinks;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Livewire\Admin\Projects\ProjectShow;
use App\Models\User;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
| P4 part 2: the client's side of a job (PRJ-006…PRJ-008). A client has no password: a signed link, or six digits
| by email. They read what the studio chose to share, sign for the space, and — with their consent — the job
| becomes a draft case study for a person to write up.
*/
beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
    Mail::fake();

    $this->sales = $this->userWithRole('sales');
    $this->pm = $this->userWithRole('designer_pm');
    $this->editor = $this->userWithRole('editor');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'city' => 'Lahore', 'area' => 'Model Town']);
    $this->lead->contact->forceFill(['email' => 'ayesha@example.com'])->save();

    $quote = portalProjectQuote($this->lead, $this->sales);
    $this->post(QuoteLinks::signed('quotes.portal.accept', $quote), ['revision' => $quote->revision, 'name' => 'Ayesha Khan', 'confirm' => '1'])->assertRedirect();

    $this->project = Project::query()->sole();
    $this->quote = $quote;
});

/** A sent quotation of PKR 1,000,000 on the 50/40/10 plan, accepted by the client. */
function portalProjectQuote(Lead $lead, User $sales): Quote
{
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $sales, $lead, null, 'Lounge and kitchen');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling, walnut veneer', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => 100_000_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->setMilestonePlan($quote->refresh(), MilestonePlanKey::Execution);
    app(RecalculateQuote::class)->handle($quote->refresh(), $sales);

    return app(SendQuote::class)->handle($quote->refresh(), $sales)->quote->refresh();
}

it('shows the client their project on a signed link, and only what the studio shared', function () {
    $shared = app(PostProjectUpdate::class)->handle($this->project, $this->pm, ['body' => 'Wood work complete.', 'progress_percent' => 40], [UploadedFile::fake()->image('wood.jpg', 800, 600)], true);
    $internal = app(PostProjectUpdate::class)->handle($this->project, $this->pm, ['body' => 'Client is difficult about the handles.']);

    $this->get(PortalLinks::signed('projects.portal', $this->project))
        ->assertOk()
        ->assertSee($this->project->name)
        ->assertSee('Wood work complete.')
        ->assertSee('40%')
        ->assertDontSee('difficult about the handles');

    // The photograph is served from the portal, not from the admin.
    $photo = $shared->getMedia('photos')->first();

    $this->get(PortalLinks::signed('projects.portal.document', $this->project, ['media' => $photo->id]))->assertOk();

    expect($internal->fresh()->client_visible)->toBeFalse();
});

it('hides a cancelled project from the client', function () {
    $this->project->forceFill(['status' => ProjectStatus::Cancelled])->save();

    $this->get(PortalLinks::signed('projects.portal', $this->project))->assertNotFound();
});

it('shows a document on the portal only once the team shares it', function () {
    $this->project->addMedia(UploadedFile::fake()->create('drawing.pdf', 200, 'application/pdf'))
        ->withCustomProperties(['original_name' => 'drawing.pdf'])
        ->toMediaCollection('files');

    $file = $this->project->getMedia('files')->first();

    $this->get(PortalLinks::signed('projects.portal.document', $this->project, ['media' => $file->id]))->assertNotFound();

    Livewire::actingAs($this->pm)->test(ProjectShow::class, ['project' => $this->project])
        ->call('toggleFileVisibility', $file->id)
        ->assertHasNoErrors();

    $this->get(PortalLinks::signed('projects.portal.document', $this->project, ['media' => $file->id]))->assertOk();

    $this->get(PortalLinks::signed('projects.portal', $this->project))->assertSee('drawing.pdf');
});

it('starts the handover checklist from Settings and lets the team tick it', function () {
    $component = Livewire::actingAs($this->pm)->test(ProjectShow::class, ['project' => $this->project]);

    $component->call('startHandover')->assertHasNoErrors();

    expect($this->project->fresh()->handoverItems->count())->toBe(count(app(SalesSettings::class)->handover_checklist))
        ->and($this->project->fresh()->handover_started_at)->not->toBeNull();

    $first = $this->project->fresh()->handoverItems->first();

    $component->call('tickHandoverItem', $first->id)->assertHasNoErrors();
    expect($first->fresh()->isDone())->toBeTrue();

    $component->set('newItem', 'AC vents cleaned')->call('addHandoverItem')->assertHasNoErrors();
    expect($this->project->fresh()->handoverItems->count())->toBe(count(app(SalesSettings::class)->handover_checklist) + 1);

    // Starting twice never duplicates the studio lines.
    $component->call('startHandover');
    expect($this->project->fresh()->handoverItems->count())->toBe(count(app(SalesSettings::class)->handover_checklist) + 1);
});

it('lets the client sign for the space, and not before every line is ticked', function () {
    app(StartHandover::class)->handle($this->project);

    $page = PortalLinks::signed('projects.portal.handover', $this->project);

    $this->post($page, ['name' => 'Ayesha Khan', 'confirm' => '1'])
        ->assertRedirect()
        ->assertSessionHasErrorsIn('handover');

    expect($this->project->fresh()->client_ok_at)->toBeNull();

    foreach ($this->project->handoverItems as $item) {
        $item->forceFill(['done_at' => now(), 'done_by' => $this->pm->id])->save();
    }

    $this->post($page, ['name' => 'Ayesha Khan', 'confirm' => '1'])->assertRedirect();
    $this->post($page, ['name' => '  Ayesha Khan  ', 'confirm' => '1'])->assertRedirect();

    $project = $this->project->fresh();

    expect($project->client_ok_at)->not->toBeNull()
        ->and($project->client_ok_name)->toBe('Ayesha Khan')
        ->and($project->handover_completed_at)->not->toBeNull()
        ->and($project->stage)->toBe(ProjectStage::Completed)
        ->and($project->status)->toBe(ProjectStatus::Completed);
});

it('opens the portal with a code when the link is lost, and never with a wrong one', function () {
    app(RequestPortalAccess::class)->handle('ayesha@example.com', '127.0.0.1');

    Mail::assertQueued(PortalCodeMail::class, fn (PortalCodeMail $mail): bool => $mail->name === $this->lead->contact->name);

    $code = null;
    Mail::assertQueued(PortalCodeMail::class, function (PortalCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return strlen($mail->code) === 6;
    });

    expect(app(OpenPortalWithCode::class)->handle('ayesha@example.com', '000000'))->toBeNull()
        ->and(app(OpenPortalWithCode::class)->handle('ayesha@example.com', (string) $code))->not->toBeNull();

    // A code works once: the second use finds nothing.
    expect(app(OpenPortalWithCode::class)->handle('ayesha@example.com', (string) $code))->toBeNull();
});

it('asks for a public review a few days after the handover, and only with a review link', function () {
    app(StartHandover::class)->handle($this->project);

    foreach ($this->project->handoverItems as $item) {
        $item->forceFill(['done_at' => now(), 'done_by' => $this->pm->id])->save();
    }

    $this->post(PortalLinks::signed('projects.portal.handover', $this->project), ['name' => 'Ayesha Khan', 'confirm' => '1'])->assertRedirect();

    // Straight after the handover nothing is asked for: the family is still moving in.
    expect($this->artisan('woodex:review-requests'))->toBe(0);
    expect($this->project->fresh()->review_requested_at)->toBeNull();

    $this->travel(3)->days();
    expect($this->artisan('woodex:review-requests'))->toBe(0);

    // No Google link in Settings means no email: an email asking for a review has to carry one.
    expect($this->project->fresh()->review_requested_at)->not->toBeNull();
    Mail::assertNotQueued(ProjectMail::class);

    // With the link filled in, the next handover gets one.
    $settings = app(BusinessSettings::class);
    $settings->google_review_url = 'https://g.page/r/woodex/review';
    $settings->save();

    $second = Project::query()->create([
        'code' => 'WI-99999', 'name' => 'Second job', 'contact_id' => $this->lead->contact_id,
        'business_line' => BusinessLine::Interior, 'stage' => ProjectStage::Handover, 'status' => ProjectStatus::Active,
        'client_ok_at' => now()->subDays(10), 'value_minor' => 100_000_00,
    ]);

    expect($this->artisan('woodex:review-requests'))->toBe(0);

    expect($second->fresh()->review_requested_at)->not->toBeNull();
    Mail::assertQueued(ProjectMail::class, fn (ProjectMail $mail): bool => str_contains((string) $mail->body, 'https://g.page/r/woodex/review'));
});

it('turns a finished job into a draft case study, with the client consent', function () {
    app(PostProjectUpdate::class)->handle($this->project, $this->pm, ['body' => 'Lounge panelling finished.'], [UploadedFile::fake()->image('lounge.jpg', 800, 600)], true);

    $admin = $this->userWithRole('admin'); // runs the work and writes pages

    $component = Livewire::actingAs($admin)->test(ProjectShow::class, ['project' => $this->project]);

    // Without consent: nothing is written about a home whose owner has not said yes.
    $component->call('draftCaseStudy')->assertHasNoErrors();
    expect(Page::query()->count())->toBe(0);

    $component->call('toggleConsent', true)->assertHasNoErrors();
    expect($this->project->fresh()->case_study_consent)->toBeTrue();

    // Only the website team writes pages: a PM can run the job but not publish it.
    Livewire::actingAs($this->pm)->test(ProjectShow::class, ['project' => $this->project])
        ->call('draftCaseStudy')
        ->assertStatus(403);

    $page = app(DraftCaseStudy::class)->handle($this->project->fresh(), $this->editor);

    expect($page->status)->toBe(PageStatus::Draft)
        ->and($page->type->value)->toBe('project')
        ->and($page->slug)->toStartWith('/projects/')
        ->and($page->title)->toBe($this->project->name)
        ->and($page->blocks->pluck('type.value')->all())->toContain('hero', 'gallery', 'specs')
        ->and($this->project->fresh()->case_study_page_id)->toBe($page->id)
        ->and(Media::query()->count())->toBeGreaterThan(0);

    // Idempotent: pressing again refreshes the same draft.
    $again = app(DraftCaseStudy::class)->handle($this->project->fresh(), $this->editor);
    expect($again->id)->toBe($page->id);
});

it('emails the client the link to their own page', function () {
    Livewire::actingAs($this->pm)->test(ProjectShow::class, ['project' => $this->project])
        ->call('sendPortalLink')
        ->assertHasNoErrors();

    Mail::assertQueued(ProjectMail::class, fn (ProjectMail $mail): bool => str_contains((string) $mail->url, '/p/'.$this->project->public_id));
});

it('asks for a code only for people who have a project with us', function () {
    $stranger = Contact::factory()->create(['email' => 'nobody@example.com']);

    expect(app(RequestPortalAccess::class)->handle('nobody@example.com', '127.0.0.1'))->toBe(['sent' => false]);
    Mail::assertNothingQueued();

    expect(app(RequestPortalAccess::class)->handle('ayesha@example.com', '127.0.0.1'))->toBe(['sent' => true]);
});

it('keeps the review request quiet until the client has signed', function () {
    expect(app(RequestClientReviews::class)->handle())->toBe([])
        ->and($this->project->fresh()->review_requested_at)->toBeNull();
});
