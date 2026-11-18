<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Data\QuoteBlueprint;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Enums\TemplateKind;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\FurnitureDocument;
use App\Domain\Sales\Services\MilestonePlan;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Html;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates a draft quotation from a template or blank (QUO-003). The number is allocated on this first save:
 * WI-10100+ for interior/project work, WF-10050+ for furniture (QUO-006). Sections, items, terms, scope and the
 * payment-milestone plan come from the template; totals are calculated on the server (QUO-004).
 */
final class CreateQuote
{
    public function __construct(
        private readonly Numbering $numbering,
        private readonly RecalculateQuote $recalculate,
        private readonly LogQuoteEvent $logQuoteEvent,
        private readonly SalesSettings $settings,
    ) {}

    public function handle(
        Contact $contact,
        BusinessLine $line,
        User $by,
        ?Lead $lead = null,
        ?QuoteTemplate $template = null,
        ?string $title = null,
    ): Quote {
        if ($lead !== null && $lead->contact_id !== $contact->id) {
            throw new InvalidArgumentException('That enquiry belongs to another client.');
        }

        if ($template !== null && ($template->business_line === BusinessLine::Furniture) !== ($line === BusinessLine::Furniture)) {
            throw new InvalidArgumentException('Choose a quotation template from the same business line.');
        }

        $quote = DB::transaction(function () use ($contact, $line, $by, $lead, $template, $title): Quote {
            $plan = $template !== null
                ? $template->milestone_plan
                : MilestonePlanKey::Execution;
            $design = $plan === MilestonePlanKey::Design || $template?->kind === TemplateKind::Design;

            $documentDesign = app(DocumentStyles::class)->quotationCopy($line);
            $presentation = $template?->blueprint['presentation'] ?? null;
            if (is_array($presentation)) {
                $documentDesign = [...$documentDesign,
                    'quote_layout' => DocumentStyles::quoteLayout($presentation['quote_layout'] ?? null),
                    'pdf_theme' => DocumentStyles::theme($presentation['pdf_theme'] ?? null),
                    'quote_blocks' => DocumentStyles::blocks('quote', $presentation['quote_blocks'] ?? DocumentStyles::quoteLayoutBlocks(DocumentStyles::quoteLayout($presentation['quote_layout'] ?? null)), true),
                ];
            }
            if ($line === BusinessLine::Furniture && ! isset($template?->blueprint['furniture']) && in_array($presentation['quote_layout'] ?? '', ['', 'classic'], true)) {
                $documentDesign['quote_layout'] = 'furniture';
            }
            if ($line === BusinessLine::Furniture && $template !== null) {
                foreach ($documentDesign['quote_blocks'] as &$block) {
                    $field = ['terms' => 'show_terms', 'notes' => 'show_notes', 'signatures' => 'show_signatories'][$block['key']] ?? null;
                    if ($field !== null && ($template->blueprint['furniture'][$field] ?? 'yes') === 'no') {
                        $block['visible'] = false;
                    }
                }
                unset($block);
            }
            $terms = trim((string) $template?->terms) !== '' ? $template->terms : ($line === BusinessLine::Furniture ? FurnitureDocument::TERMS : ($design ? $this->settings->design_terms : $this->settings->execution_terms));
            if ($line === BusinessLine::Furniture && ! isset($template?->blueprint['furniture']) && in_array($terms, [$this->settings->execution_terms, $this->settings->design_terms, "50% advance payment with work order.\nRates are exclusive of GST / PST.\nDelivery time as mutually agreed."], true)) {
                $terms = FurnitureDocument::TERMS;
            }
            $quote = Quote::create([
                'number' => $this->numbering->next($line->quoteSequenceKey()),
                'sequence_key' => $line->quoteSequenceKey(),
                'option_label' => '',
                'revision' => 0,
                'lead_id' => $lead?->id,
                'contact_id' => $contact->id,
                'company_id' => $lead->company_id ?? $contact->company_id,
                'template_id' => $template?->id,
                'business_line' => $line,
                'title' => trim((string) $title) !== '' ? trim((string) $title) : ($template->name ?? 'Quotation'),
                'status' => QuoteStatus::Draft,
                'currency' => 'PKR',
                'valid_until' => today()->addDays($line === BusinessLine::Furniture ? 12 : $this->settings->quote_validity_days),
                'terms' => $terms,
                'advance_bp' => $line === BusinessLine::Furniture ? (int) ($template?->blueprint['furniture']['advance_bp'] ?? 7500) : 0,
                'notes' => $template?->notes,
                'scope_html' => Html::sanitize($template?->scope_html),
                'owner_id' => $by->id,
                'meta' => ['milestone_plan' => $plan->value, 'document_design' => $documentDesign, 'furniture' => FurnitureDocument::text(['delivery_text' => $line === BusinessLine::Furniture ? '15–20 days' : '', 'signatories_text' => FurnitureDocument::signatureText($this->settings->signatories), ...($template?->blueprint['furniture'] ?? [])]), 'furniture_billing' => $line === BusinessLine::Furniture ? 'full' : 'milestone'],
            ]);

            $sections = $template !== null ? QuoteBlueprint::sections($template->blueprint) : [];

            if ($sections === []) {
                $sections = [['name' => 'Work items', 'note' => null, 'items' => [[
                    'description' => '', 'unit' => $line === BusinessLine::Furniture ? 'nos' : 'job', 'qty' => '1', 'rate_minor' => 0,
                    'catalog_item_id' => null, 'is_optional' => false, 'internal_cost_minor' => null,
                ]]]];
            }

            foreach ($sections as $s => $section) {
                $model = $quote->sections()->create(['name' => $section['name'], 'note' => $section['note'], 'position' => $s]);

                foreach ($section['items'] as $i => $item) {
                    if ($line === BusinessLine::Furniture && empty($item['product_name'])) {
                        $item['product_name'] = mb_substr($item['description'], 0, 160);
                        $item['description'] = mb_strlen($item['description']) > 160 ? $item['description'] : '';
                    }
                    $model->items()->create($item + ['quote_id' => $quote->id, 'position' => $i]);
                }
            }

            foreach (MilestonePlan::definition($plan) as $position => $milestone) {
                $quote->milestones()->create($milestone + ['position' => $position]);
            }

            $this->recalculate->handle($quote);

            $this->logQuoteEvent->handle(
                $quote,
                'created',
                $by,
                $template !== null
                    ? "Quotation {$quote->label()} started from the \"{$template->name}\" template."
                    : "Quotation {$quote->label()} started.",
                array_filter(['template' => $template?->name]),
            );

            return $quote;
        });

        return $quote->refresh();
    }
}
