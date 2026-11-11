<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Services\InvoicePdf;
use App\Domain\Sales\Services\InvoiceSnapshot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff PDF of an invoice (INV-001/003): the stored PDF for an issued invoice — exactly the document the client
 * got — or a live preview with a "DRAFT" watermark while it is still a draft.
 */
final class InvoicePdfController
{
    public function __invoke(Invoice $invoice, InvoiceSnapshot $snapshots, InvoicePdf $pdf): Response
    {
        Gate::authorize('view', $invoice);

        if ($invoice->pdf_path !== null && Storage::disk('local')->exists($invoice->pdf_path)) {
            return Storage::disk('local')->response($invoice->pdf_path, InvoicePdf::filename($invoice), ['Content-Type' => 'application/pdf']);
        }

        abort_unless($invoice->status === InvoiceStatus::Draft, 409, 'The frozen invoice PDF is missing. Accounts must review recovery; it will not be regenerated.');

        return response($pdf->render($snapshots->build($invoice)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.InvoicePdf::filename($invoice).'"',
        ]);
    }
}
