<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Marketing\Models\SocialPost;
use App\Domain\Marketing\Social\SocialPublisher;
use Illuminate\Console\Command;

/**
 * P7 (SOC-006): the scheduler's heartbeat. Approved posts whose time has come are handed to the
 * publisher; with no connected accounts yet (the owner's Meta/LinkedIn approvals pending), due
 * posts keep waiting and the command says so — nothing is churned into failures by a clock.
 */
class PublishSocialPosts extends Command
{
    protected $signature = 'woodex:social-publish';

    protected $description = 'Publish due, approved social posts to the studio\'s connected accounts.';

    public function handle(SocialPublisher $publisher): int
    {
        $due = SocialPost::query()
            ->where('status', SocialPost::SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        if ($due->isEmpty()) {
            $this->line('No posts are due.');

            return self::SUCCESS;
        }

        if (! $publisher->canPublish()) {
            $this->line(sprintf('%d post(s) are due and waiting — no social account is connected yet (Meta/LinkedIn approvals pending).', $due->count()));

            return self::SUCCESS;
        }

        foreach ($due as $post) {
            $error = $publisher->publish($post);

            if ($error === null) {
                $post->update(['status' => SocialPost::PUBLISHED, 'published_at' => now(), 'error' => null]);
                $this->info('Published: '.mb_substr($post->text, 0, 60));
            } else {
                $post->update(['status' => SocialPost::FAILED, 'error' => mb_substr($error, 0, 500)]);
                $this->error('Failed: '.$error);
            }
        }

        return self::SUCCESS;
    }
}
