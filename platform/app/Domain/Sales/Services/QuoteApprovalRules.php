<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Data\ApprovalAssessment;
use App\Domain\Sales\Models\Quote;
use App\Models\User;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\DB;

/**
 * When a quotation needs approval before it is sent (QUO-007): the total discount (line discounts + quote discount,
 * as a share of the price before discounts) above the sender's role limit (Settings → discount limits), or a
 * margin below the minimum (when internal costs are known). The owner's limit is 100%.
 */
final class QuoteApprovalRules
{
    public function __construct(private readonly SalesSettings $settings) {}

    public function assess(Quote $quote, User $sender): ApprovalAssessment
    {
        $lineDiscounts = (int) $quote->items()->where('is_optional', false)->sum('discount_minor');
        $gross = (int) $quote->subtotal_minor + $lineDiscounts;
        $discount = $lineDiscounts + (int) $quote->discount_minor;
        $discountBp = $gross > 0 ? (int) round($discount * 10000 / $gross) : 0;
        $limitBp = $this->limitFor($sender);
        $minMarginBp = $this->settings->min_margin_bp;
        $marginBp = $quote->margin_bp;

        $reasons = [];
        if ($discountBp > $limitBp) {
            $reasons[] = 'Discount '.self::percent($discountBp).' is above the '.self::percent($limitBp).' limit for your role.';
        }
        if ($minMarginBp !== null && $marginBp !== null && (int) $quote->internal_cost_minor > 0 && $marginBp < $minMarginBp) {
            $reasons[] = 'Margin '.self::percent($marginBp).' is below the '.self::percent($minMarginBp).' minimum.';
        }

        return new ApprovalAssessment(
            needed: $reasons !== [],
            reasons: $reasons,
            discountBp: $discountBp,
            limitBp: $limitBp,
            marginBp: $marginBp,
            minMarginBp: $minMarginBp,
            fingerprint: $this->fingerprint($quote, $lineDiscounts),
        );
    }

    public function limitFor(User $user): int
    {
        if ($user->hasRole('owner')) {
            return 10000;
        }

        $limits = $this->settings->discount_limits_bp;

        return max([0, ...array_map(fn (string $role): int => (int) ($limits[$role] ?? 0), $user->getRoleNames()->all())]);
    }

    /** True when the quote carries an approval for exactly its current figures. */
    public function isApprovedAsIs(Quote $quote): bool
    {
        $approved = $quote->meta['approval']['fingerprint'] ?? null;

        return $quote->approved_at !== null && is_string($approved)
            && hash_equals($approved, $this->fingerprint($quote, (int) $quote->items()->where('is_optional', false)->sum('discount_minor')));
    }

    public static function percent(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2), '0'), '.').'%';
    }

    /** The figures an approval covers: changing any of them needs a new approval. */
    private function fingerprint(Quote $quote, int $lineDiscounts): string
    {
        $content = [
            'schema' => 2,
            'quote' => $quote->only(['id', 'revision', 'number', 'title', 'option_label', 'contact_id', 'company_id', 'owner_id', 'business_line', 'currency', 'valid_until', 'subtotal_minor', 'discount_type', 'discount_value', 'discount_minor', 'other_charges_label', 'other_charges_minor', 'tax_bp', 'tax_minor', 'rounding_minor', 'total_minor', 'internal_cost_minor', 'margin_bp', 'scope_html', 'terms', 'notes', 'signatories']),
            'document_design' => $quote->meta['document_design'] ?? null,
            'line_discounts' => $lineDiscounts,
            'recipient' => $quote->contact()->withTrashed()->first()?->only(['id', 'name', 'phone', 'whatsapp', 'email', 'company_id', 'address', 'city', 'area']),
            'company' => $quote->company()->first()?->only(['id', 'name']),
            'sections' => DB::table('quote_sections')->where('quote_id', $quote->id)->orderBy('id')->get(['id', 'name', 'note', 'position'])->all(),
            'items' => DB::table('quote_items')->where('quote_id', $quote->id)->orderBy('id')->get(['id', 'quote_section_id', 'position', 'description', 'unit', 'qty', 'rate_minor', 'discount_type', 'discount_value', 'discount_minor', 'line_total_minor', 'is_optional', 'internal_cost_minor'])->all(),
            'milestones' => DB::table('quote_milestones')->where('quote_id', $quote->id)->orderBy('id')->get(['id', 'name', 'position', 'percent_bp', 'amount_minor', 'trigger'])->all(),
        ];

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
    }
}
