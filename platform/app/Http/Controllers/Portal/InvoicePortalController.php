<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Sales\Actions\SubmitPaymentProof;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Services\InvoiceLinks;
use App\Domain\Sales\Services\InvoicePdf;
use App\Domain\Sales\Services\InvoiceSnapshot;
use App\Settings\BusinessSettings;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The client's invoice page (INV-005/006): a signed, expiring link without login. It shows what is owed, where to
 * send it (bank details of the business line), the PDF, and a form to report a payment with a screenshot or slip.
 */
final class InvoicePortalController
{
    public function show(Request $request, Invoice $invoice, InvoiceSnapshot $snapshots, BusinessSettings $business): View
    {
        abort_if($invoice->status === InvoiceStatus::Draft, 404);

        return view('site.invoice', [
            'invoice' => $invoice,
            's' => $snapshots->build($invoice),
            'pdfUrl' => InvoiceLinks::signed('invoices.portal.pdf', $invoice),
            'proofUrl' => InvoiceLinks::signed('invoices.portal.proof', $invoice),
            'whatsapp' => Phone::whatsappUrl(Phone::normalize($business->whatsapp), "Assalam o Alaikum, I have a question about invoice {$invoice->number}."),
            'methods' => PaymentMethod::options(),
            'status' => $request->session()->get('portal_status'),
        ]);
    }

    public function pdf(Invoice $invoice): StreamedResponse
    {
        $path = $invoice->pdf_path;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, InvoicePdf::filename($invoice), ['Content-Type' => 'application/pdf']);
    }

    public function proof(Request $request, Invoice $invoice, SubmitPaymentProof $submit): RedirectResponse
    {
        $page = url(InvoiceLinks::signed('invoices.portal', $invoice));

        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1', 'max:100000000', 'regex:/^\d{1,14}(?:\.\d{1,2})?$/'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'string', 'in:'.implode(',', array_keys(PaymentMethod::options()))],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,pdf'],
        ], [], [
            'amount' => 'amount paid',
            'received_on' => 'date of payment',
            'method' => 'how you paid',
            'proof' => 'payment proof',
        ]);

        if ($validator->fails()) {
            return redirect()->to($page.'#pay')->withErrors($validator, 'proof')->withInput();
        }

        $submit->handle($invoice, [
            'amount_minor' => Money::ofRupees((string) $request->input('amount'))->minor,
            'received_on' => $request->input('received_on'),
            'method' => (string) $request->input('method'),
            'reference' => $request->input('reference'),
            'notes' => $request->input('notes'),
        ], $request->file('proof'));

        return redirect()->to($page)->with('portal_status', 'proof_submitted');
    }
}
