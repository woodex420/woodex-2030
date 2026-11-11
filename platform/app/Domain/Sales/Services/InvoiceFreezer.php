<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The frozen document of an issued invoice (INV-003): the snapshot the client was shown, the PDF bytes and the
 * hash that proves they have not changed. Every path that issues an invoice asks for this — the advance at
 * acceptance, a milestone the job reached, and a recurring charge — so all three behave identically.
 *
 * Freezing twice is safe: the existing document is verified and returned, and a tampered one is refused rather
 * than silently re-rendered.
 */
final class InvoiceFreezer
{
    public function __construct(
        private readonly InvoiceSnapshot $snapshot,
        private readonly InvoicePdf $pdf,
    ) {}

    public function freeze(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->snapshot !== null || $invoice->pdf_path !== null || $invoice->pdf_sha256 !== null) {
                if ($invoice->snapshot === null || $invoice->pdf_path === null || $invoice->pdf_sha256 === null
                    || ! Storage::disk('local')->exists($invoice->pdf_path)
                    || ! hash_equals($invoice->pdf_sha256, hash('sha256', Storage::disk('local')->get($invoice->pdf_path)))) {
                    throw new RuntimeException('The frozen invoice needs a reviewed document repair.');
                }

                return $invoice;
            }

            if ($invoice->status !== InvoiceStatus::Issued) {
                throw new QuoteActionDenied('Only an issued invoice can be frozen.');
            }

            $snapshot = $this->snapshot->build($invoice->load(['items', 'contact', 'company', 'bankAccount', 'project', 'quote']));
            $bytes = $this->pdf->render($snapshot);
            $path = 'invoices/'.$invoice->public_id.'/'.InvoicePdf::filename($invoice);

            // Never overwrite an unreferenced artifact left by an earlier failed issue.
            // Existing valid frozen documents return above without registering cleanup.
            if (Storage::disk('local')->exists($path)) {
                throw new RuntimeException('An unreferenced invoice PDF needs reviewed document repair.');
            }
            DB::afterRollBack(fn () => Storage::disk('local')->delete($path));
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new RuntimeException('The invoice PDF could not be stored.');
            }

            $invoice->forceFill([
                'snapshot' => $snapshot,
                'pdf_path' => $path,
                'pdf_sha256' => hash('sha256', $bytes),
            ])->save();

            return $invoice;
        });
    }
}
