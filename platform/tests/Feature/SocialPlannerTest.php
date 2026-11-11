<?php

use App\Domain\Marketing\Models\SocialPost;
use App\Domain\Marketing\Social\ProposeCaptions;
use App\Domain\Website\Models\Media;
use App\Livewire\Admin\Marketing\SocialPlannerIndex;
use App\Models\User;
use App\Settings\BotSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| P7 (SOC-005/006/009/010): the social planner, keyless-first. Composing, the review step,
| scheduling and the shared media library are fully internal; publishing waits for the owner's
| Meta/LinkedIn approvals, and due posts wait honestly instead of failing; AI caption drafts ride
| the studio's model key when one exists.
*/

beforeEach(function () {
    $this->manager = User::factory()->create();
    $this->manager->givePermissionTo('social.manage');

    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo('social.manage');

    SocialPost::query()->delete();
    app(BotSettings::class)->fill(['provider' => 'none', 'api_key' => null])->save();
});

it('composes a draft with photographs from the shared media library, then submits it for review', function () {
    $photo = Media::factory()->create();

    Livewire::actingAs($this->manager)
        ->test(SocialPlannerIndex::class)
        ->call('compose')
        ->set('text', 'A ten-marla home in DHA, handed over on the date we promised.')
        ->set('textUr', 'ڈی ایچ اے میں دس مرلہ کا گھر، وعدے کے دن حوالے۔')
        ->set('targets', ['facebook', 'instagram'])
        ->set('mediaIds', [$photo->id])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $draft = SocialPost::query()->first();
    expect($draft)->not->toBeNull()
        ->and($draft->status)->toBe('draft')
        ->and($draft->targets)->toBe(['facebook', 'instagram'])
        ->and($draft->media_ids)->toBe([$photo->id])
        ->and($draft->text_ur)->toBe('ڈی ایچ اے میں دس مرلہ کا گھر، وعدے کے دن حوالے۔')
        ->and($draft->media())->toHaveCount(1);

    // A post without a network is refused.
    Livewire::actingAs($this->manager)
        ->test(SocialPlannerIndex::class)
        ->call('compose')
        ->set('text', 'Nowhere to go')
        ->set('targets', [])
        ->call('saveDraft')
        ->assertHasErrors('targets');
});

it('walks the review flow: an editor cannot approve their own post', function () {
    Livewire::actingAs($this->editor)
        ->test(SocialPlannerIndex::class)
        ->call('compose')
        ->set('text', 'A finished room.')
        ->set('targets', ['linkedin'])
        ->call('submitForReview');

    $post = SocialPost::query()->first();
    expect($post->status)->toBe('in_review')
        ->and($post->created_by)->toBe($this->editor->id);

    // The author cannot approve their own post.
    Livewire::actingAs($this->editor)
        ->test(SocialPlannerIndex::class)
        ->call('approve', $post->id)
        ->assertDispatched('toast', message: 'Someone else approves a post — that is the point of the review step.');
    expect($post->fresh()->status)->toBe('in_review');

    // Someone else can.
    Livewire::actingAs($this->manager)
        ->test(SocialPlannerIndex::class)
        ->call('approve', $post->id)
        ->assertDispatched('toast', message: 'Approved — schedule it when you are ready.');
    expect($post->fresh()->status)->toBe('approved');
});

it('schedules an approved post, and the scheduler waits while no account is connected', function () {
    $post = SocialPost::query()->create([
        'text' => 'Due now.',
        'targets' => ['facebook'],
        'media_ids' => [],
        'status' => SocialPost::APPROVED,
        'created_by' => $this->manager->id,
    ]);

    Livewire::actingAs($this->manager)
        ->test(SocialPlannerIndex::class)
        ->set('scheduledAt', now()->subMinutes(5)->format('Y-m-d\TH:i'))
        ->call('schedule', $post->id)
        ->assertDispatched('toast', message: 'Scheduled — it waits for its time (and for a connected account, until the approvals land).');

    expect($post->fresh()->status)->toBe('scheduled');

    Artisan::call('woodex:social-publish');
    $output = Artisan::output();

    expect($output)->toContain('due and waiting')
        ->and($post->fresh()->status)->toBe('scheduled');   // not churned into a failure by a clock
});

it('keeps the planner to social managers', function () {
    $staff = User::factory()->create();

    Livewire::actingAs($staff)
        ->test(SocialPlannerIndex::class)
        ->assertStatus(403);
});

it('drafts captions with the studio key, and stays silent without one', function () {
    // One fake for the whole test: keyless, nothing is asked of anyone; with the key, the model answers.
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"text": "A calm room in natural materials.", "text_ur": "قدرتی لکڑی سے بنے پرسکون کمرے۔"}']]]])]);

    // Keyless: the button is hidden and nothing is asked of anyone.
    Livewire::actingAs($this->manager)
        ->test(SocialPlannerIndex::class)
        ->assertDontSee('Draft both captions with AI');

    Http::assertNothingSent();

    // With the key: the model proposes, the studio keeps the pen.
    app(BotSettings::class)->fill(['provider' => 'openai', 'api_key' => 'sk-test', 'model' => ''])->save();

    Livewire::actingAs($this->manager)
        ->test(SocialPlannerIndex::class)
        ->call('compose')
        ->assertSee('Draft both captions with AI')
        ->set('text', 'notes about a handover')
        ->call('draftCaptions')
        ->assertDispatched('toast', message: 'Draft applied — read it, then make it yours.');

    expect(app(ProposeCaptions::class)->propose('anything'))->toBe(['text' => 'A calm room in natural materials.', 'text_ur' => 'قدرتی لکڑی سے بنے پرسکون کمرے۔']);
});
