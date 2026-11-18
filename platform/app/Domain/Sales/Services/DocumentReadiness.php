<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Enums\BusinessLine;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use Illuminate\Validation\ValidationException;

/**
 * Fail closed for new client-facing documents; does not restrict draft editing or historical downloads.
 *
 * Every problem is written for the person who pressed "Send" or "Issue": what is missing, and the tab of Settings
 * it is filled in on. Only what this installation prints is asked for — the execution and design terms belong to
 * the Interior brand's quotations, and a business that sells Furniture alone is not asked for them.
 */
final class DocumentReadiness
{
    /** The business fields every document prints, with the words a person knows them by. */
    private const array BUSINESS_FIELDS = [
        'legal_name' => 'the legal name',
        'brand_name' => 'the brand name',
        'address' => 'the business address',
        'phone' => 'the phone number',
        'email' => 'the e-mail address',
    ];

    /**
     * The standard terms this installation prints, by the brands on sale.
     *
     * @return array<string, string> setting => the words a person knows it by
     */
    public static function termsAskedFor(): array
    {
        $terms = in_array(BusinessLine::Interior, BusinessLine::offeredBrands(), true)
            ? ['execution_terms' => 'the execution terms', 'design_terms' => 'the design terms']
            : [];

        return [...$terms, 'invoice_terms' => 'the invoice terms'];
    }

    /** @return list<string> */
    public function problems(): array
    {
        if (app()->runningUnitTests() && app()->environment('testing') && config('documents.test_bypass') === true) {
            return [];
        }

        $problems = [];
        if (config('documents.owner_confirmed') !== true) {
            $problems[] = 'The owner has not yet confirmed the business identity, the terms and the signatories that documents carry. Whoever runs the server records the confirmation there (DOCUMENTS_OWNER_CONFIRMED).';
        }

        $business = app(BusinessSettings::class);
        foreach (self::BUSINESS_FIELDS as $field => $words) {
            if (blank($business->{$field})) {
                $problems[] = "Fill in {$words} under Settings → Business.";
            }
        }

        $sales = app(SalesSettings::class);
        foreach (self::termsAskedFor() as $field => $words) {
            if (blank($sales->{$field})) {
                $problems[] = "Fill in {$words} under Settings → Sales rules.";
            }
        }
        $signers = array_filter($sales->signatories, fn ($signer): bool => is_array($signer)
            && filled($signer['name'] ?? null) && filled($signer['title'] ?? null));
        if ($signers === []) {
            $problems[] = 'Add a signatory, with name and title, under Settings → Sales rules.';
        }

        return $problems;
    }

    public function ensureReady(string $errorKey = 'issue'): void
    {
        if (($problems = $this->problems()) !== []) {
            throw ValidationException::withMessages([$errorKey => $problems]);
        }
    }
}
