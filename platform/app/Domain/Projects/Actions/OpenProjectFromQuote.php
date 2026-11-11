<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Enums\ProjectStage;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMilestone;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Quote;
use Illuminate\Support\Facades\DB;

/**
 * The project record that follows an accepted quotation (QUO-011, PRJ-001).
 *
 * One project per quotation, carrying the code of the quotation so a number that the client already knows is
 * reused, and the payment stages of the quotation as project milestones. Called once, on acceptance: a quotation
 * accepted twice (or reopened and accepted again) never opens a second project.
 */
final class OpenProjectFromQuote
{
    public function handle(Quote $quote): Project
    {
        return DB::transaction(function () use ($quote): Project {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $existing = Project::withTrashed()->where('quote_id', $quote->id)->first();

            if ($existing !== null) {
                if ($existing->trashed()) {
                    throw new QuoteActionDenied('This quotation already has an archived project. Ask the owner to review it.');
                }

                return $existing;
            }

            if ($quote->status !== QuoteStatus::Accepted) {
                throw new QuoteActionDenied('Only an accepted quotation can open a project.');
            }

            $quote->loadMissing(['contact', 'company', 'lead', 'milestones']);
            $lead = $quote->lead;

            $project = Project::query()->create([
                'code' => $quote->number,
                'name' => $quote->title,
                'contact_id' => $quote->contact_id,
                'company_id' => $quote->company_id,
                'quote_id' => $quote->id,
                'lead_id' => $quote->lead_id,
                'business_line' => $quote->business_line,
                'site_address' => $lead?->address,
                'city' => $lead?->city,
                'area' => $lead?->area,
                'pm_id' => $quote->owner_id,
                'stage' => ProjectStage::Planning,
                'status' => ProjectStatus::Active,
                'start_date' => today(),
                'value_minor' => (int) $quote->total_minor,
                'notes' => 'Opened automatically when '.$quote->number.' was accepted.',
            ]);

            foreach ($quote->milestones as $position => $milestone) {
                ProjectMilestone::query()->create([
                    'project_id' => $project->id,
                    'quote_milestone_id' => $milestone->id,
                    'name' => $milestone->name,
                    'percent_bp' => $milestone->percent_bp,
                    'amount_minor' => $milestone->amount_minor,
                    'trigger' => $milestone->trigger,
                    'position' => $position,
                ]);
            }

            return $project;
        });
    }
}
