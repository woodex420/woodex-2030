<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Projects;

use App\Domain\Accounts\Actions\PostProjectExpense;
use App\Domain\Accounts\Actions\ReverseJournalEntry;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Actions\AcceptHandover;
use App\Domain\Projects\Actions\ChangeProjectStage;
use App\Domain\Projects\Actions\DraftCaseStudy;
use App\Domain\Projects\Actions\PostProjectUpdate;
use App\Domain\Projects\Actions\RaiseMilestoneInvoice;
use App\Domain\Projects\Actions\ReachProjectMilestone;
use App\Domain\Projects\Actions\RequestClientReviews;
use App\Domain\Projects\Actions\ShareProjectUpdate;
use App\Domain\Projects\Actions\StartHandover;
use App\Domain\Projects\Enums\ProjectExpenseCategory;
use App\Domain\Projects\Enums\ProjectStage;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Mail\ProjectMail;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\PortalLinks;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Livewire\WithPagination;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The workspace for one job (PRJ-002…PRJ-005): where it stands, what is owed, the milestones the money hangs on,
 * the diary from the site with its photographs, and the files.
 *
 * Everything the client is told comes from here, and nothing leaves the studio unless somebody presses Share.
 */
#[Layout('layouts::admin')]
class ProjectShow extends Component
{
    use InteractsWithAdmin, WithFileUploads, WithPagination;

    public Project $project;

    /** @var array<int, TemporaryUploadedFile> */
    public array $photos = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public string $body = '';

    public ?int $progressPercent = null;

    public ?int $milestoneId = null;

    public bool $shareWithClient = false;

    public string $notes = '';

    public string $newItem = '';

    public string $signatureName = '';

    /** PRJ-009: cost tracking — expense line entry. */
    public string $expenseDescription = '';

    public string $expenseCategory = 'material';

    public string $expenseQty = '1';

    public string $expenseUnit = '';

    public string $expenseAmount = '';

    public string $expenseIncurredOn = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->progressPercent = (int) $project->progress_percent;
        $this->notes = (string) $project->notes;
    }

    public function setStage(string $value): void
    {
        $this->authorize('update', $this->project);

        $stage = ProjectStage::tryFrom($value);

        if ($stage === null) {
            return;
        }

        app(ChangeProjectStage::class)->handle($this->project, $stage, $this->user());
        $this->project->refresh();

        $this->toast($this->project->code.' is now at '.$stage->label().'.');
    }

    public function setStatus(string $value): void
    {
        $this->authorize('update', $this->project);

        $status = ProjectStatus::tryFrom($value);

        if ($status === null) {
            return;
        }

        $this->project->forceFill([
            'status' => $status,
            'completed_at' => $status === ProjectStatus::Completed ? ($this->project->completed_at ?? now()) : null,
        ])->save();

        $this->project->refresh();
        $this->toast($this->project->code.' is '.$status->label().'.');
    }

    /** Save the site address, the target date and the internal note. */
    public function saveDetails(): void
    {
        $this->authorize('update', $this->project);

        $this->project->forceFill(['notes' => $this->notes])->save();
        $this->toast('Project details saved.');
    }

    /**
     * PRJ-009 + slice I: record a purchase against the job **and book it**. Until slice I the row lived only on
     * this screen, so the ledger never learned what the work cost. The cost and its journal are now one act: if
     * the books refuse it (closed period, missing account) the row is rolled back rather than left unposted.
     */
    public function addExpense(): void
    {
        $this->authorize('update', $this->project);

        $this->validate([
            'expenseDescription' => ['required', 'string', 'max:200'],
            'expenseCategory' => ['required', 'in:'.implode(',', ProjectExpenseCategory::values())],
            'expenseQty' => ['required', 'numeric', 'gt:0'],
            'expenseUnit' => ['nullable', 'string', 'max:20'],
            'expenseAmount' => ['required', 'numeric', 'min:0.01'],
            'expenseIncurredOn' => ['required', 'date'],
        ]);

        try {
            DB::transaction(function (): void {
                $expense = $this->project->expenses()->create([
                    'description' => $this->expenseDescription,
                    'category' => $this->expenseCategory,
                    'qty' => (float) $this->expenseQty,
                    'unit' => $this->expenseUnit !== '' ? $this->expenseUnit : null,
                    'amount_minor' => Money::ofRupees($this->expenseAmount)->minor,
                    'incurred_on' => $this->expenseIncurredOn,
                    'created_by' => $this->user()->id,
                ]);

                app(PostProjectExpense::class)->handle($expense->refresh(), $this->user());
            });
        } catch (\Throwable $exception) {
            $this->toast('Not recorded — '.$exception->getMessage(), 'error');

            return;
        }

        $this->reset('expenseDescription', 'expenseQty', 'expenseUnit', 'expenseAmount');
        $this->project->refresh();
        $this->toast('Expense recorded and posted — the margin and the books both know.');
    }

    /**
     * Removing a cost reverses its journal first. The row leaves the project list, the books keep both halves
     * of the story (entry and reversal) — the same rule the invoice screen follows for a void.
     */
    public function deleteExpense(int $expenseId): void
    {
        $this->authorize('update', $this->project);

        $expense = $this->project->expenses()->findOrFail($expenseId);

        if ($expense->journal_entry_id !== null) {
            // A direct query, not the relation: screens run with lazy loading off.
            $entry = JournalEntry::query()->find($expense->journal_entry_id);

            if ($entry !== null && ! $entry->isReversed() && ! $entry->isReversal()) {
                app(ReverseJournalEntry::class)->handle($entry, $this->user(), 'Project cost removed: '.$expense->description);
            }
        }

        $expense->delete();
        $this->project->refresh();
        $this->toast('Expense removed — its journal entry was reversed, not deleted.');
    }

    public function toggleMilestone(int $milestoneId): void
    {
        $this->authorize('update', $this->project);

        $milestone = $this->project->milestones()->findOrFail($milestoneId);
        $reached = $milestone->reached_at === null;

        app(ReachProjectMilestone::class)->handle($milestone, $this->user(), $reached);

        $this->project->refresh();
        $this->progressPercent = (int) $this->project->progress_percent;

        $this->toast($milestone->name.($reached ? ' reached.' : ' reopened.'));
    }

    public function raiseInvoice(int $milestoneId): void
    {
        $this->authorize('update', $this->project);

        $milestone = $this->project->milestones()->findOrFail($milestoneId);
        $invoice = app(RaiseMilestoneInvoice::class)->handle($milestone, $this->user());

        $this->project->refresh();
        $this->toast('Draft invoice '.$invoice->number.' is ready — check it and send it from Sales → Invoices.');
    }

    public function post(): void
    {
        $this->authorize('update', $this->project);

        $this->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
            'progressPercent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'photos' => ['array', 'max:10'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic', 'max:15360'],
        ], [
            'photos.*.max' => 'Each photograph can be up to 15 MB.',
            'photos.*.mimes' => 'Photographs must be JPG, PNG, WebP or HEIC.',
        ]);

        app(PostProjectUpdate::class)->handle(
            $this->project,
            $this->user(),
            [
                'body' => $this->body,
                'progress_percent' => $this->progressPercent,
                'project_milestone_id' => $this->milestoneId,
            ],
            $this->photos,
            $this->shareWithClient,
        );

        $this->reset(['body', 'photos', 'milestoneId', 'shareWithClient']);
        $this->project->refresh();
        $this->progressPercent = (int) $this->project->progress_percent;

        $this->toast($this->shareWithClient ? 'Update posted and shared with the client.' : 'Update posted.');
    }

    public function share(int $updateId): void
    {
        $this->authorize('update', $this->project);

        $update = $this->project->updates()->findOrFail($updateId);
        app(ShareProjectUpdate::class)->handle($update);

        $this->toast('Shared with the client.');
    }

    public function deleteUpdate(int $updateId): void
    {
        $this->authorize('update', $this->project);

        $this->project->updates()->findOrFail($updateId)->delete();
        $this->toast('Update deleted.');
    }

    public function updatedUploads(): void
    {
        $this->authorize('update', $this->project);

        $this->validate([
            'uploads' => ['array', 'max:10'],
            'uploads.*' => ['file', 'max:20480', 'extensions:'.implode(',', Lead::ATTACHMENT_EXTENSIONS)],
        ], [
            'uploads.*.max' => 'Each file can be up to 20 MB.',
            'uploads.*.extensions' => 'Allowed: photos, PDF, DWG/DXF drawings, Excel, Word and PowerPoint files.',
        ]);

        foreach ($this->uploads as $upload) {
            $this->project->addMedia($upload->getRealPath())
                ->usingFileName($upload->hashName())
                ->usingName(pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME))
                ->withCustomProperties([
                    'uploaded_by' => $this->user()->id,
                    'original_name' => $upload->getClientOriginalName(),
                ])
                ->toMediaCollection('files');
        }

        $this->uploads = [];
        $this->toast('File uploaded.');
    }

    public function deleteFile(int $mediaId): void
    {
        $this->authorize('update', $this->project);

        $media = $this->project->media()->where('collection_name', 'files')->findOrFail($mediaId);
        assert($media instanceof Media);
        $media->delete();

        $this->toast('File deleted.');
    }

    /** Email the client the door to their own page (PRJ-006). */
    public function sendPortalLink(): void
    {
        $this->authorize('update', $this->project);

        $email = $this->project->contact?->email;

        if ($email === null || $email === '') {
            $this->toast('This client has no email address on file: share the link on WhatsApp instead.', 'error');

            return;
        }

        Mail::to($email)->queue(new ProjectMail(
            projectId: $this->project->id,
            heading: 'Follow '.$this->project->code.' as it happens',
            body: sprintf(
                "Here is your own page for %s: the photographs from the site, the drawings we share with you, your invoices and receipts, and the checklist we hand over with the keys.\n\nNothing to remember: this link is yours.",
                $this->project->name,
            ),
            url: PortalLinks::absolute($this->project),
            buttonLabel: 'Open my project',
        ));

        $this->toast('Portal link sent to '.$email.'.');
    }

    /** The checklist the studio hands the keys over with (PRJ-007). */
    public function startHandover(bool $reset = false): void
    {
        $this->authorize('update', $this->project);

        app(StartHandover::class)->handle($this->project, $reset);
        $this->project->refresh();
        $this->newItem = '';

        $this->toast($reset ? 'Checklist reset to the studio list.' : 'Handover checklist started.');
    }

    public function addHandoverItem(): void
    {
        $this->authorize('update', $this->project);

        $this->validate(['newItem' => ['required', 'string', 'max:200']], attributes: ['newItem' => 'item']);

        $this->project->handoverItems()->create([
            'label' => trim($this->newItem),
            'position' => (int) ($this->project->handoverItems()->max('position') ?? 0) + 1,
        ]);

        $this->newItem = '';
        $this->project->refresh();
    }

    public function tickHandoverItem(int $itemId): void
    {
        $this->authorize('update', $this->project);

        $item = $this->project->handoverItems()->findOrFail($itemId);

        $item->forceFill([
            'done_at' => $item->done_at === null ? now() : null,
            'done_by' => $item->done_at === null ? $this->user()->id : null,
        ])->save();

        $this->project->refresh();
    }

    public function removeHandoverItem(int $itemId): void
    {
        $this->authorize('update', $this->project);

        $this->project->handoverItems()->findOrFail($itemId)->delete();
        $this->project->refresh();
    }

    /** A file the client may see on the portal; the rest stay in the studio. */
    public function toggleFileVisibility(int $mediaId): void
    {
        $this->authorize('update', $this->project);

        $media = $this->project->media()->where('collection_name', 'files')->findOrFail($mediaId);
        assert($media instanceof Media);

        $visible = ! (bool) $media->getCustomProperty('visible_to_client');
        $media->setCustomProperty('visible_to_client', $visible)->save();

        $this->toast($visible ? 'The client can see this file now.' : 'Hidden from the client.');
    }

    public function toggleConsent(bool $consent): void
    {
        $this->authorize('update', $this->project);

        $this->project->forceFill(['case_study_consent' => $consent])->save();
        $this->project->refresh();
    }

    /** A finished job becomes a draft portfolio page for a person to write up (PRJ-008). */
    public function draftCaseStudy(): void
    {
        $this->authorize('update', $this->project);
        abort_unless($this->user()->can('website.edit'), 403);

        if (! $this->project->case_study_consent) {
            $this->toast('Tick the client consent first: a case study is published in their space.', 'error');

            return;
        }

        $page = app(DraftCaseStudy::class)->handle($this->project, $this->user());
        $this->project->refresh();

        $this->toast('Draft case study created: '.$page->title.'.');
    }

    /** The client signed on paper or in person; record it here. */
    public function recordHandoverSignature(): void
    {
        $this->authorize('update', $this->project);

        $name = trim($this->signatureName);

        if ($name === '') {
            $this->toast('Type the name the client signed with.', 'error');

            return;
        }

        try {
            app(AcceptHandover::class)->handle($this->project, $name, $this->user());
            $this->signatureName = '';
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->project->refresh();
        $this->toast('Handover signed by '.$this->project->client_ok_name.'.');
    }

    public function render(): View
    {
        $project = $this->project->load(['contact', 'company', 'quote', 'lead', 'manager', 'invoices', 'handoverItems.doneBy', 'caseStudyPage']);

        $updates = $project->updates()
            ->with(['author:id,name', 'milestone:id,name', 'media'])
            ->paginate(10, pageName: 'updates');

        return view('livewire.admin.projects.project-show', [
            'project' => $project,
            'milestones' => $project->milestones()->with('quoteMilestone')->get(),
            'updates' => $updates,
            'files' => $project->getMedia('files'),
            'stages' => ProjectStage::cases(),
            'statuses' => ProjectStatus::cases(),
            'staff' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'portalUrl' => PortalLinks::absolute($this->project),
            'portalRelative' => PortalLinks::portal($this->project),
            'whatsappShare' => PortalLinks::whatsappShare($this->project),
            'reviewDueOn' => app(RequestClientReviews::class)->dueOn($this->project),
            'progress' => $project->handoverProgress(),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
            'expenses' => $project->expenses()->with('author:id,name')->get(),
            'quotedCostMinor' => $project->quotedCostMinor(),
            'spentMinor' => $project->spentMinor(),
            'marginMinor' => (int) ($project->quote->total_minor ?? 0) - $project->spentMinor(),
            'overQuoted' => $project->quotedCostMinor() !== null && $project->spentMinor() > $project->quotedCostMinor(),
        ])->title($project->code.' · '.$project->name);
    }
}
