<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteItem;
use App\Domain\Sales\Models\QuoteMilestone;
use App\Domain\Sales\Models\QuoteSection;
use App\Domain\Sales\Services\DocumentStyles;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;

/**
 * "Duplicate" in the builder (QUO-003): a new draft with a new number from the same series, the same client,
 * sections, lines, discounts, charges, milestones and texts. Tracking, approval and acceptance data are not copied.
 */
final class DuplicateQuote
{
    public function __construct(
        private readonly Numbering $numbering,
        private readonly RecalculateQuote $recalculate,
        private readonly LogQuoteEvent $logQuoteEvent,
        private readonly SalesSettings $settings,
    ) {}

    public function handle(Quote $source, User $by): Quote
    {
        $source->loadMissing(['sections.items', 'milestones']);

        $copy = DB::transaction(function () use ($source, $by): Quote {
            $copy = Quote::create([
                'number' => $this->numbering->next($source->sequence_key),
                'sequence_key' => $source->sequence_key,
                'option_label' => '',
                'revision' => 0,
                'lead_id' => $source->lead_id,
                'contact_id' => $source->contact_id,
                'company_id' => $source->company_id,
                'template_id' => $source->template_id,
                'business_line' => $source->business_line,
                'title' => $source->title,
                'status' => QuoteStatus::Draft,
                'currency' => $source->currency,
                'discount_type' => $source->discount_type,
                'discount_value' => $source->discount_value,
                'other_charges_minor' => $source->other_charges_minor,
                'other_charges_label' => $source->other_charges_label,
                'shipping_minor' => $source->shipping_minor,
                'advance_bp' => $source->advance_bp,
                'tax_bp' => $source->tax_bp,
                'valid_until' => today()->addDays($this->settings->quote_validity_days),
                'terms' => $source->terms,
                'notes' => $source->notes,
                'internal_notes' => $source->internal_notes,
                'scope_html' => $source->scope_html,
                'owner_id' => $by->id,
                'meta' => ['milestone_plan' => $source->milestonePlan()->value, 'duplicated_from' => $source->id, 'furniture' => $source->meta['furniture'] ?? [], 'furniture_billing' => $source->meta['furniture_billing'] ?? 'milestone', 'document_design' => app(DocumentStyles::class)->quotationCopy($source->business_line)],
            ]);

            $source->sections->each(function (QuoteSection $section) use ($copy): void {
                $newSection = $copy->sections()->create($section->only(['name', 'note', 'position']));

                $section->items->each(fn (QuoteItem $item) => $newSection->items()->create($item->only([
                    'catalog_item_id', 'product_name', 'description', 'unit', 'qty', 'rate_minor', 'discount_type', 'discount_value',
                    'internal_cost_minor', 'is_optional', 'position',
                ]) + ['quote_id' => $copy->id]));
            });

            $source->milestones->each(fn (QuoteMilestone $milestone) => $copy->milestones()->create(
                $milestone->only(['name', 'percent_bp', 'trigger', 'due_days', 'position']),
            ));

            $this->recalculate->handle($copy);

            $this->logQuoteEvent->handle($copy, 'created', $by, "Quotation {$copy->label()} created as a copy of {$source->label()}.", [
                'duplicated_from' => $source->label(),
            ]);

            return $copy;
        });

        return $copy->refresh();
    }
}
