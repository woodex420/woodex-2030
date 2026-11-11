<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Marketing;

use App\Domain\Marketing\Models\SocialPost;
use App\Domain\Marketing\Social\ProposeCaptions;
use App\Domain\Marketing\Social\SocialPublisher;
use App\Domain\Website\Models\Media;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The social planner (P7, SOC-005/006/009): one composition — caption in English and Urdu,
 * photographs from the shared media library, link, first comment, hashtags — headed for several
 * networks. The walk is draft → in review → approved → scheduled → published; an editor's post
 * needs someone else's approval. AI caption drafts join when the studio's model key exists (the
 * BOT pattern); publishing itself waits for the owner's Meta/LinkedIn approvals, and a scheduled
 * post honestly waits with it.
 */
#[Layout('layouts::admin')]
#[Title('Social planner')]
class SocialPlannerIndex extends Component
{
    use InteractsWithAdmin;

    public string $text = '';

    public string $textUr = '';

    public string $linkUrl = '';

    public string $firstComment = '';

    public string $hashtags = '';

    /** @var list<string> */
    public array $targets = [];

    /** @var list<int> */
    public array $mediaIds = [];

    public ?string $scheduledAt = null;

    public bool $showCompose = false;

    public function mount(): void
    {
        abort_unless($this->user()->can('social.manage'), 403);
    }

    public function compose(): void
    {
        $this->reset(['text', 'textUr', 'linkUrl', 'firstComment', 'hashtags', 'targets', 'mediaIds', 'scheduledAt']);
        $this->showCompose = true;
    }

    public function saveDraft(): void
    {
        $this->persist(SocialPost::DRAFT);
    }

    public function submitForReview(): void
    {
        $this->persist(SocialPost::IN_REVIEW);
    }

    public function approve(int $postId): void
    {
        $post = SocialPost::query()->findOrFail($postId);

        if ($post->created_by === $this->user()->id) {
            $this->toast('Someone else approves a post — that is the point of the review step.');

            return;
        }

        if ($post->status !== SocialPost::IN_REVIEW) {
            return;
        }

        $post->update(['status' => SocialPost::APPROVED]);
        $this->toast('Approved — schedule it when you are ready.');
    }

    public function schedule(int $postId): void
    {
        $post = SocialPost::query()->findOrFail($postId);

        if ($post->status !== SocialPost::APPROVED && $post->status !== SocialPost::SCHEDULED) {
            return;
        }

        if (trim((string) $this->scheduledAt) === '') {
            $this->toast('Pick a date and time first.', 'error');

            return;
        }

        $post->update([
            'status' => SocialPost::SCHEDULED,
            'scheduled_at' => $this->scheduledAt,
            'error' => null,
        ]);

        $this->scheduledAt = null;
        $this->toast('Scheduled — it waits for its time (and for a connected account, until the approvals land).');
    }

    public function withdraw(int $postId): void
    {
        SocialPost::query()->findOrFail($postId)->update(['status' => SocialPost::DRAFT, 'scheduled_at' => null]);
        $this->toast('Back to draft.');
    }

    public function remove(int $postId): void
    {
        SocialPost::query()->findOrFail($postId)->delete();
        $this->toast('Post deleted.');
    }

    /**
     * Ask the studio's model (only when a key exists) to draft the English and Urdu captions.
     */
    public function draftCaptions(ProposeCaptions $proposer): void
    {
        $draft = $proposer->propose($this->text);

        if ($draft === null) {
            $this->toast('The model could not be reached — check the key on the Settings screen.', 'error');

            return;
        }

        $this->text = $draft['text'];
        $this->textUr = $draft['text_ur'];
        $this->toast('Draft applied — read it, then make it yours.');
    }

    public function render(): View
    {
        return view('livewire.admin.marketing.social-planner', [
            'posts' => SocialPost::query()->with('author')->orderByDesc('id')->limit(50)->get(),
            'media' => Media::query()->orderByDesc('id')->limit(100)->get(['id', 'title', 'alt', 'path']),
            'aiReady' => app(ProposeCaptions::class)->isConfigured(),
            'connected' => app(SocialPublisher::class)->connectedAccounts(),
        ]);
    }

    private function persist(string $status): void
    {
        $this->validate([
            'text' => ['required', 'string', 'max:2200'],
            'textUr' => ['nullable', 'string', 'max:2200'],
            'linkUrl' => ['nullable', 'url', 'max:500'],
            'firstComment' => ['nullable', 'string', 'max:500'],
            'hashtags' => ['nullable', 'string', 'max:500'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['in:'.implode(',', SocialPost::NETWORKS)],
            'mediaIds' => ['array'],
            'mediaIds.*' => ['integer', 'exists:website_media,id'],
        ], [], ['text' => 'caption', 'targets' => 'networks']);

        SocialPost::query()->create([
            'text' => trim($this->text),
            'text_ur' => trim($this->textUr) !== '' ? trim($this->textUr) : null,
            'link_url' => trim($this->linkUrl) !== '' ? trim($this->linkUrl) : null,
            'first_comment' => trim($this->firstComment) !== '' ? trim($this->firstComment) : null,
            'hashtags' => trim($this->hashtags) !== '' ? trim($this->hashtags) : null,
            'media_ids' => array_values(array_map('intval', $this->mediaIds)),
            'targets' => array_values($this->targets),
            'status' => $status,
            'created_by' => $this->user()->id,
        ]);

        $this->reset(['text', 'textUr', 'linkUrl', 'firstComment', 'hashtags', 'targets', 'mediaIds']);
        $this->showCompose = false;
        $this->toast($status === SocialPost::IN_REVIEW ? 'Submitted for review.' : 'Draft saved.');
    }
}
