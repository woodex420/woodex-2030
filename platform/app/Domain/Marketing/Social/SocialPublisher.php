<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Social;

use App\Domain\Marketing\Models\SocialPost;

/**
 * The social planner's publishing half (P7, SOC-005): where a scheduled post goes the moment its
 * time arrives. Today it honestly reports that no account is connected — the Meta app review and
 * LinkedIn access are the owner's to start (docs/16 §8), and until they land, scheduled posts wait
 * rather than pretending. The transport itself (graph API, LinkedIn Community Management) slots in
 * behind connectedAccounts() the day the approvals do.
 */
final class SocialPublisher
{
    /**
     * The networks with a live, connected account. Empty until the owner's Meta/LinkedIn
     * approvals land and the studio connects its pages.
     *
     * @return list<string>
     */
    public function connectedAccounts(): array
    {
        // No account store exists yet (SOC-001 is the owner-gated Meta app review) — honest by default.
        return [];
    }

    public function canPublish(): bool
    {
        return $this->connectedAccounts() !== [];
    }

    /**
     * Send one post to its targets. Returns null on success, or the honest reason it could not go.
     */
    public function publish(SocialPost $post): ?string
    {
        $connected = $this->connectedAccounts();
        $missing = array_values(array_diff((array) $post->targets, $connected));

        if ($missing !== []) {
            return 'No connected account for: '.implode(', ', $missing).'. Connect the studio\'s pages once the Meta/LinkedIn approvals land.';
        }

        return null; // unreachable today; the transport lands with the first connected account
    }
}
