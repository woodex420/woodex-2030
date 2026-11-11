<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use Illuminate\Validation\ValidationException;

/** Fail closed for new client-facing documents; does not restrict draft editing or historical downloads. */
final class DocumentReadiness
{
    /** @return list<string> */
    public function problems(): array
    {
        if (app()->runningUnitTests() && app()->environment('testing') && config('documents.test_bypass') === true) {
            return [];
        }

        $problems = [];
        if (config('documents.owner_confirmed') !== true) {
            $problems[] = 'Owner confirmation of Furniture identity, commercial terms, signatories and document defaults is required before issuing documents.';
        }

        $business = app(BusinessSettings::class);
        foreach (['legal_name', 'brand_name', 'address', 'phone', 'email'] as $field) {
            if (blank($business->{$field})) {
                $problems[] = 'Complete business '.$field.' in Settings.';
            }
        }

        $sales = app(SalesSettings::class);
        foreach (['execution_terms', 'design_terms', 'invoice_terms'] as $field) {
            if (blank($sales->{$field})) {
                $problems[] = 'Confirm sales '.$field.' in Settings.';
            }
        }
        $signers = array_filter($sales->signatories, fn ($signer): bool => is_array($signer)
            && filled($signer['name'] ?? null) && filled($signer['title'] ?? null));
        if ($signers === []) {
            $problems[] = 'Add an authorized signatory in Settings.';
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
