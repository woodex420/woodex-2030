<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Domain\Sales\Services\QuoteEditor;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo quotations for the local preview (P2): a few drafts built from the real templates for the demo leads at the
 * Quotation and Negotiation stages. Runs once; never outside APP_ENV=local.
 */
class DemoQuotesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            throw new RuntimeException('DemoQuotesSeeder only runs with APP_ENV=local.');
        }

        if (Quote::withTrashed()->exists()) {
            return;
        }

        $owner = User::role('owner')->first();
        $leads = Lead::query()
            ->with(['contact', 'owner'])
            ->whereHas('stage', fn ($q) => $q->whereIn('key', ['quotation', 'negotiation', 'site_visit']))
            ->orderBy('id')
            ->limit(4)
            ->get();

        // [template, quote discount type, value, measured quantities for the "1 sft" template placeholders]
        $plans = [
            ['renovation', null, null, []],
            ['interior', DiscountType::Percent, 500, ['1200', '3500', '240', '120', '180', '60', '80']],
            ['furniture', null, null, []],
            ['interior-designing', DiscountType::Amount, 2_500_000, []],
        ];

        foreach ($leads as $index => $lead) {
            [$slug, $discountType, $discountValue, $quantities] = $plans[$index];
            $template = QuoteTemplate::query()->where('slug', $slug)->first();
            $by = $lead->owner ?? $owner;

            if ($template === null || $by === null) {
                continue;
            }

            $quote = app(CreateQuote::class)->handle($lead->contact, $template->business_line, $by, $lead, $template, "{$template->name} for {$lead->contact->name}");

            $editor = app(QuoteEditor::class);

            foreach ($quote->items()->get()->values() as $i => $item) {
                if (isset($quantities[$i])) {
                    $editor->updateLine($item, ['qty' => $quantities[$i]]);
                }
            }

            if ($discountType !== null) {
                $editor->updateDetails($quote, ['discount_type' => $discountType, 'discount_value' => $discountValue]);
            }
        }
    }
}
