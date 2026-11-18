<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Projects\Mail\ProjectMail;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\PortalLinks;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;

/**
 * Asking a happy client for a public review (PRJ-007): a few days after they signed for the space, while the
 * relief is still fresh, and only once per project.
 *
 * Nothing is sent while the Google link is empty in Settings -> Business — an email asking for a review has to
 * carry one. The job still counts as asked-for, so filling the link in later does not send a late surprise.
 */
final class RequestClientReviews
{
    public function __construct(
        private readonly SalesSettings $sales,
        private readonly BusinessSettings $business,
    ) {}

    /**
     * @return list<string> the projects we wrote to
     */
    public function handle(): array
    {
        $due = Project::query()
            ->with('contact')
            ->whereNotNull('client_ok_at')
            ->whereNull('review_requested_at')
            ->where('client_ok_at', '<=', now()->subDays(max(0, (int) $this->sales->review_request_days)))
            ->get();

        $sent = [];

        foreach ($due as $project) {
            $email = $project->contact?->email;

            if ($email !== null && $email !== '' && $this->business->google_review_url !== '') {
                Mail::to($email)->queue(new ProjectMail(
                    projectId: $project->id,
                    heading: 'Thank you for choosing '.$this->business->brand_name,
                    body: sprintf(
                        "We hope %s feels like yours now. If the work we did for you is worth telling people about, a review takes one minute and helps the next family find us:\n\n%s",
                        $project->name,
                        $this->business->google_review_url,
                    ),
                    url: $this->business->google_review_url,
                    buttonLabel: 'Write a review',
                ));

                Activity::create([
                    'contact_id' => $project->contact_id,
                    'lead_id' => $project->lead_id,
                    'type' => ActivityType::Note,
                    'body' => sprintf('%s: review requested by email.', $project->code),
                    'data' => ['project_id' => $project->id],
                    'occurred_at' => now(),
                ]);
            }

            $project->forceFill(['review_requested_at' => now()])->save();
            $sent[] = $project->code;
        }

        return $sent;
    }

    /** Used by the project screen: when the review email is due. */
    public function dueOn(Project $project): ?CarbonInterface
    {
        return $project->client_ok_at?->addDays(max(0, (int) $this->sales->review_request_days));
    }

    public function portalLink(Project $project): string
    {
        return PortalLinks::absolute($project);
    }
}
