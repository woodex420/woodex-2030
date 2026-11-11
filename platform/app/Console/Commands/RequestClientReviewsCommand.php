<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Projects\Actions\RequestClientReviews;
use Illuminate\Console\Command;

class RequestClientReviewsCommand extends Command
{
    protected $signature = 'woodex:review-requests';

    protected $description = 'Ask clients for a public review a few days after they signed the handover (PRJ-007).';

    public function handle(RequestClientReviews $reviews): int
    {
        $sent = $reviews->handle();

        if ($sent !== []) {
            $this->components->info('Review requested: '.implode(', ', $sent).'.');
        }

        return self::SUCCESS;
    }
}
