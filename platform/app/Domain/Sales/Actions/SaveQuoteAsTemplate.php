<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Data\QuoteBlueprint;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Enums\TemplateKind;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\FurnitureDocument;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Template management through the builder (QUO-002): save a quote's sections, lines, terms, scope, notes and
 * milestone plan as a new template, or replace the content of an existing one. Client data, discounts and
 * charges are never copied into templates.
 */
final class SaveQuoteAsTemplate
{
    public function handle(Quote $quote, string $name, ?QuoteTemplate $replace = null): QuoteTemplate
    {
        if ($replace !== null && ($replace->business_line === BusinessLine::Furniture) !== ($quote->business_line === BusinessLine::Furniture)) {
            throw ValidationException::withMessages(['templateId' => 'Replace a template from the same business line, or save a new template.']);
        }
        $plan = $quote->milestonePlan();

        $design = $quote->meta['document_design'] ?? null;
        $blocks = DocumentStyles::quotationBlocks($design['quote_blocks'] ?? null, $design);
        $furniture = FurnitureDocument::text($quote->meta['furniture'] ?? null);
        foreach ($blocks as &$block) {
            $field = ['terms' => 'show_terms', 'notes' => 'show_notes', 'signatures' => 'show_signatories'][$block['key']] ?? null;
            if ($field !== null && $quote->business_line === BusinessLine::Furniture) {
                if (DocumentStyles::flexible($design)) {
                    $furniture[$field] = $block['visible'] ? 'yes' : 'no';
                } elseif ($furniture[$field] === 'no') {
                    $block['visible'] = false;
                }
            }
        }
        unset($block);

        $attributes = [
            'name' => trim($name),
            'business_line' => $quote->business_line,
            'milestone_plan' => $plan,
            'terms' => $quote->terms,
            'notes' => $quote->notes,
            'scope_html' => $quote->scope_html,
            'blueprint' => [...QuoteBlueprint::fromQuote($quote), 'furniture' => [...$furniture, 'advance_bp' => (int) $quote->advance_bp], 'presentation' => [
                'quote_layout' => DocumentStyles::quoteLayout($quote->meta['document_design']['quote_layout'] ?? null),
                'pdf_theme' => DocumentStyles::theme($quote->meta['document_design']['pdf_theme'] ?? null),
                'quote_blocks' => $blocks,
            ]],
            'is_active' => true,
        ];

        if ($replace !== null) {
            $replace->update($attributes);

            return $replace->refresh();
        }

        return QuoteTemplate::create($attributes + [
            'slug' => $this->uniqueSlug($name),
            'kind' => $plan === MilestonePlanKey::Design ? TemplateKind::Design : TemplateKind::Execution,
            'description' => "Saved from {$quote->label()}.",
            'position' => (int) QuoteTemplate::query()->max('position') + 1,
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'template';
        $slug = $base;
        $n = 2;

        while (QuoteTemplate::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-".$n++;
        }

        return $slug;
    }
}
