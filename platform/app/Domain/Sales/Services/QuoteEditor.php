<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Exceptions\QuoteLocked;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\DescriptionTemplate;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteItem;
use App\Domain\Sales\Models\QuoteMilestone;
use App\Domain\Sales\Models\QuoteSection;
use App\Settings\SalesSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Every change the quote builder makes (QUO-003/004/005/015/020). Values arrive already parsed (paisa, basis
 * points, decimal-string quantities). Each change runs in a transaction, is refused for quotes that are no longer
 * editable, and ends with a server-side recalculation, so the stored totals are always right.
 */
final class QuoteEditor
{
    /** @var list<string> */
    private const DETAIL_FIELDS = [
        'title', 'type', 'site', 'intro_line', 'valid_until', 'discount_type', 'discount_value', 'other_charges_label', 'other_charges_minor',
        'tax_bp', 'shipping_minor', 'advance_bp', 'terms', 'notes', 'internal_notes', 'scope_html', 'signatories',
    ];

    /** @var list<string> */
    private const LINE_FIELDS = [
        'product_name', 'description', 'image_path', 'unit', 'qty', 'rate_minor', 'discount_type', 'discount_value', 'is_optional', 'internal_cost_minor',
    ];

    public function __construct(private readonly RecalculateQuote $recalculate) {}

    /**
     * @param  array<string, mixed>  $patch
     */
    public function updateFurnitureText(Quote $quote, array $patch): void
    {
        $this->change($quote, function () use ($quote, $patch): void {
            $text = FurnitureDocument::validatedText([...FurnitureDocument::text($quote->meta['furniture'] ?? null), ...$patch]);
            $quote->meta = [...($quote->meta ?? []), 'furniture' => $text];
            $quote->save();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateDetails(Quote $quote, array $attributes): void
    {
        $this->change($quote, function () use ($quote, $attributes): void {
            $values = array_intersect_key($attributes, array_flip(self::DETAIL_FIELDS));

            if (array_key_exists('discount_type', $values) && $values['discount_type'] === null) {
                $values['discount_value'] = 0;
            }

            Validator::make($values, ['shipping_minor' => ['sometimes', 'integer', 'min:0', 'max:999999999900'], 'advance_bp' => ['sometimes', 'integer', 'min:0', 'max:10000']])->validate();
            $quote->fill($values)->save();
        });
    }

    /** Applies current branding only through an explicit draft edit, using the same quote lock as every edit. */
    public function applyDocumentDesign(Quote $quote): void
    {
        $this->change($quote, function () use ($quote): void {
            $design = app(DocumentStyles::class)->quotationCopy($quote->business_line);
            // Adopt legacy hidden closing sections once; current designs use only the common block controls.
            if ($quote->business_line === BusinessLine::Furniture && ! DocumentStyles::flexible($quote->meta['document_design'] ?? null)) {
                foreach ($design['quote_blocks'] as &$block) {
                    $field = ['terms' => 'show_terms', 'notes' => 'show_notes', 'signatures' => 'show_signatories'][$block['key']] ?? null;
                    if ($field !== null && ($quote->meta['furniture'][$field] ?? 'yes') === 'no') {
                        $block['visible'] = false;
                    }
                }
                unset($block);
            }
            $quote->meta = array_merge($quote->meta ?? [], ['document_design' => $design, 'furniture' => ['signatories_text' => FurnitureDocument::signatureText($quote->signatories ?: app(SalesSettings::class)->signatories), ...($quote->meta['furniture'] ?? [])]]);
            $quote->save();
        });
    }

    /**
     * Per-quotation PDF design (Slice S): theme and/or block order edited in the builder, merged into the
     * design copy this quote carries. DocumentStyles respects the frozen legacy/flexible block policy; the brand master in Document design is never touched.
     *
     * @param  array{pdf_theme?: mixed, quote_blocks?: mixed, quote_layout?: mixed}  $patch
     */
    public function updateDocumentDesign(Quote $quote, array $patch): void
    {
        $this->change($quote, function () use ($quote, $patch): void {
            $design = $quote->meta['document_design'] ?? app(DocumentStyles::class)->quotationCopy($quote->business_line);

            if (array_key_exists('pdf_theme', $patch)) {
                $design['pdf_theme'] = DocumentStyles::theme($patch['pdf_theme']);
            }

            if (array_key_exists('quote_layout', $patch)) {
                $design['quote_layout'] = DocumentStyles::quoteLayout($patch['quote_layout']);
            }

            if (array_key_exists('quote_blocks', $patch)) {
                $design['quote_blocks'] = DocumentStyles::quotationBlocks($patch['quote_blocks'], $design);
            }

            $quote->meta = [...($quote->meta ?? []), 'document_design' => $design];
            $quote->save();
        });
    }

    /**
     * How the client prints on THIS quotation (Slice S). Overrides live on the quote; the contact record
     * is never mutated from the builder. An empty value clears the override (contact shows through).
     *
     * @param  array<string, mixed>  $override
     */
    public function updateClientOverride(Quote $quote, array $override): void
    {
        $this->change($quote, function () use ($quote, $override): void {
            $current = is_array($quote->meta['client_override'] ?? null) ? $quote->meta['client_override'] : [];

            foreach (['name', 'company', 'phone', 'email', 'address'] as $key) {
                if (! array_key_exists($key, $override)) {
                    continue;
                }

                $value = mb_substr(trim((string) $override[$key]), 0, 200);

                if ($value === '') {
                    unset($current[$key]);
                } else {
                    $current[$key] = $value;
                }
            }

            $quote->meta = [...($quote->meta ?? []), 'client_override' => $current];
            $quote->save();
        });
    }

    public function addDescriptionLine(QuoteSection $section, int $templateId): QuoteItem
    {
        return $this->change($this->quoteOf($section), function () use ($section, $templateId): QuoteItem {
            $this->refreshChild($section);
            $quote = $this->quoteOf($section);
            $line = $quote->business_line === BusinessLine::Furniture ? 'furniture' : 'interior';
            $template = DescriptionTemplate::query()->where('business_line', $line)->where('is_active', true)->findOrFail($templateId);

            return $this->addLine($section, ['description' => $template->body]);
        });
    }

    public function addSection(Quote $quote, string $name = 'New section'): QuoteSection
    {
        return $this->change($quote, function () use ($quote, $name): QuoteSection {
            $section = $quote->sections()->create([
                'name' => trim($name) !== '' ? trim($name) : 'New section',
                'position' => (int) $quote->sections()->max('position') + 1,
            ]);
            $this->blankLine($section, 0);

            return $section;
        });
    }

    /**
     * @param  array{name?: string, note?: string|null}  $attributes
     */
    public function updateSection(QuoteSection $section, array $attributes): void
    {
        $this->change($this->quoteOf($section), function () use ($section, $attributes): void {
            $this->refreshChild($section);
            $section->fill(array_intersect_key($attributes, array_flip(['name', 'note'])))->save();
        });
    }

    public function moveSection(QuoteSection $section, int $toIndex): void
    {
        $quote = $this->quoteOf($section);

        $this->change($quote, function () use ($quote, $section, $toIndex): void {
            $this->refreshChild($section);
            $this->reorder($quote->sections()->get(), $section, $toIndex);
        });
    }

    public function deleteSection(QuoteSection $section): void
    {
        $quote = $this->quoteOf($section);

        $this->change($quote, function () use ($quote, $section): void {
            $this->refreshChild($section);
            $section->delete();
            $this->renumber($quote->sections()->get());
        });
    }

    /**
     * Adds a line at the end of the section, or right after $after (Enter in the builder).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addLine(QuoteSection $section, array $attributes = [], ?QuoteItem $after = null): QuoteItem
    {
        $quote = $this->quoteOf($section);

        if ($after !== null && $after->quote_section_id !== $section->id) {
            throw new InvalidArgumentException('That line is in another section.');
        }

        return $this->change($quote, function () use ($section, $attributes, $after): QuoteItem {
            $this->refreshChild($section);
            if ($after !== null) {
                $this->refreshChild($after);
            }
            if ($after !== null && $after->quote_section_id !== $section->id) {
                throw new InvalidArgumentException('That line is in another section.');
            }
            Validator::make($attributes, ['product_name' => ['nullable', 'string', 'max:160']])->validate();
            $items = $section->items()->get();
            $index = $after !== null ? (int) $items->search(fn (QuoteItem $item): bool => $item->is($after)) + 1 : $items->count();

            $item = $section->items()->create(array_merge([
                'quote_id' => $section->quote_id,
                'description' => '',
                'unit' => 'job',
                'qty' => '1',
                'rate_minor' => 0,
                'position' => $index,
            ], array_intersect_key($attributes, array_flip([...self::LINE_FIELDS, 'catalog_item_id']))));

            $items->splice($index, 0, [$item]);
            $this->renumber($items);

            return $item;
        });
    }

    /**
     * A line from the rate book (QUO-001/003): description, unit, rate and internal cost are copied, so later rate
     * changes never alter existing quotes.
     */
    public function addCatalogLine(QuoteSection $section, CatalogItem $catalogItem): QuoteItem
    {
        return $this->addLine($section, [
            'catalog_item_id' => $catalogItem->id,
            'description' => $catalogItem->description,
            'image_path' => $catalogItem->image_path,
            'unit' => $catalogItem->unit,
            'qty' => '1',
            'rate_minor' => (int) $catalogItem->rate_minor,
            'internal_cost_minor' => $catalogItem->internal_cost_minor,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateLine(QuoteItem $item, array $attributes): void
    {
        $this->change($this->quoteOf($item), function () use ($item, $attributes): void {
            $this->refreshChild($item);
            $values = array_intersect_key($attributes, array_flip(self::LINE_FIELDS));
            Validator::make($values, ['product_name' => ['nullable', 'string', 'max:160']])->validate();

            if (array_key_exists('discount_type', $values) && $values['discount_type'] === null) {
                $values['discount_value'] = 0;
            }

            if (($values['discount_type'] ?? $item->discount_type) === DiscountType::Percent && (int) ($values['discount_value'] ?? $item->discount_value) > 10000) {
                throw new InvalidArgumentException('A line discount cannot be more than 100%.');
            }

            $item->fill($values)->save();
        });
    }

    /**
     * Moves a line within its section or into another section of the same quote (drag and drop, up/down buttons).
     */
    public function moveLine(QuoteItem $item, QuoteSection $to, int $toIndex): void
    {
        $quote = $this->quoteOf($item);

        if ($to->quote_id !== $quote->id) {
            throw new InvalidArgumentException('Lines can only move within the same quotation.');
        }

        $this->change($quote, function () use ($item, $to, $toIndex): void {
            $this->refreshChild($item);
            $this->refreshChild($to);
            if ($item->quote_id !== $to->quote_id) {
                throw new InvalidArgumentException('Lines can only move within the same quotation.');
            }
            $from = $item->quote_section_id;

            if ($from !== $to->id) {
                $item->quote_section_id = $to->id;
                $item->save();
                $this->renumber(QuoteItem::query()->where('quote_section_id', $from)->orderBy('position')->get());
            }

            $this->reorder($to->items()->get(), $item, $toIndex);
        });
    }

    public function deleteLine(QuoteItem $item): void
    {
        $this->change($this->quoteOf($item), function () use ($item): void {
            $this->refreshChild($item);
            $sectionId = $item->quote_section_id;
            $item->delete();
            $this->renumber(QuoteItem::query()->where('quote_section_id', $sectionId)->orderBy('position')->get());
        });
    }

    /**
     * Replaces the milestones with a standard plan (QUO-005). Switching to Custom keeps the current rows as the
     * starting point for editing.
     */
    public function setMilestonePlan(Quote $quote, MilestonePlanKey $plan): void
    {
        $this->change($quote, function () use ($quote, $plan): void {
            $quote->meta = array_merge($quote->meta ?? [], ['milestone_plan' => $plan->value]);
            $quote->save();

            if ($plan === MilestonePlanKey::Custom && $quote->milestones()->exists()) {
                return;
            }

            $quote->milestones()->delete();

            foreach (MilestonePlan::definition($plan) as $position => $milestone) {
                $quote->milestones()->create($milestone + ['position' => $position]);
            }
        });
    }

    public function addMilestone(Quote $quote): QuoteMilestone
    {
        return $this->change($quote, function () use ($quote): QuoteMilestone {
            $this->markCustom($quote);
            $remaining = max(0, 10000 - (int) $quote->milestones()->sum('percent_bp'));

            return $quote->milestones()->create([
                'name' => 'Payment '.($quote->milestones()->count() + 1),
                'percent_bp' => $remaining,
                'trigger' => 'manual',
                'position' => (int) $quote->milestones()->max('position') + 1,
            ]);
        });
    }

    /**
     * @param  array{name?: string, percent_bp?: int}  $attributes
     */
    public function updateMilestone(QuoteMilestone $milestone, array $attributes): void
    {
        $quote = $this->quoteOf($milestone);

        $this->change($quote, function () use ($quote, $milestone, $attributes): void {
            $this->refreshChild($milestone);
            $values = array_intersect_key($attributes, array_flip(['name', 'percent_bp']));

            if (isset($values['percent_bp']) && ($values['percent_bp'] < 0 || $values['percent_bp'] > 10000)) {
                throw new InvalidArgumentException('A milestone must be between 0% and 100%.');
            }

            $this->markCustom($quote);
            $milestone->fill($values)->save();
        });
    }

    public function deleteMilestone(QuoteMilestone $milestone): void
    {
        $quote = $this->quoteOf($milestone);

        $this->change($quote, function () use ($quote, $milestone): void {
            $this->refreshChild($milestone);
            if ($quote->milestones()->count() <= 1) {
                throw new InvalidArgumentException('A quotation needs at least one payment milestone.');
            }

            $this->markCustom($quote);
            $milestone->delete();
            $this->renumber($quote->milestones()->get());
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function change(Quote $quote, callable $callback): mixed
    {
        $revision = (int) $quote->revision;

        return DB::transaction(function () use ($quote, $callback, $revision): mixed {
            QuoteWriteLock::refresh($quote);
            if (! $quote->isEditable()) {
                throw QuoteLocked::for($quote);
            }
            if ((int) $quote->revision !== $revision) {
                throw new QuoteLocked('The revision changed. Reload the quotation before editing.');
            }
            $result = $callback();
            $quote->touch();
            $this->recalculate->handle($quote);

            return $result;
        });
    }

    private function refreshChild(QuoteSection|QuoteItem|QuoteMilestone $model): void
    {
        $fresh = $model->newQuery()->whereKey($model->id)->where('quote_id', $model->quote_id)->lockForUpdate()->firstOrFail();
        $model->setRawAttributes($fresh->getAttributes(), true);
        $model->unsetRelations();
    }

    private function quoteOf(QuoteSection|QuoteItem|QuoteMilestone $model): Quote
    {
        /** @var Quote $quote */
        $quote = Quote::query()->findOrFail($model->quote_id);

        return $quote;
    }

    private function blankLine(QuoteSection $section, int $position): QuoteItem
    {
        return $section->items()->create([
            'quote_id' => $section->quote_id,
            'description' => '',
            'unit' => 'job',
            'qty' => '1',
            'rate_minor' => 0,
            'position' => $position,
        ]);
    }

    private function markCustom(Quote $quote): void
    {
        if ($quote->milestonePlan() !== MilestonePlanKey::Custom) {
            $quote->meta = array_merge($quote->meta ?? [], ['milestone_plan' => MilestonePlanKey::Custom->value]);
            $quote->save();
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models  ordered by position
     * @param  TModel  $moving
     */
    private function reorder(Collection $models, Model $moving, int $toIndex): void
    {
        $list = $models->reject(fn (Model $model): bool => $model->is($moving))->values();
        $list->splice(max(0, min($toIndex, $list->count())), 0, [$moving]);
        $this->renumber($list);
    }

    /**
     * @param  Collection<int, covariant Model>  $models
     */
    private function renumber(Collection $models): void
    {
        foreach ($models->values() as $position => $model) {
            if ((int) $model->getAttribute('position') !== $position) {
                $model->setAttribute('position', $position);
                $model->save();
            }
        }
    }
}
