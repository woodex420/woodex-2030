<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Projects\Actions\AcceptHandover;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectHandoverItem;
use App\Domain\Projects\Models\ProjectUpdate;
use App\Domain\Projects\Services\PortalLinks;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\InvoiceLinks;
use App\Domain\Sales\Services\ReceiptPdf;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The client's own page for a job (PRJ-006/007): what is happening on site, the photographs, the drawings we chose
 * to show, the invoices and receipts, and the handover checklist they sign for. Reached by a signed link or by a
 * six-digit code — never by a password.
 */
final class ProjectPortalController
{
    public function show(Request $request, Project $project, BusinessSettings $business): View
    {
        abort_if($project->status === ProjectStatus::Cancelled, 404);

        $project->loadMissing(['contact', 'manager', 'quote', 'invoices', 'handoverItems']);

        $lakh = app(SalesSettings::class)->lakh_grouping;

        return view('site.project-portal', [
            'project' => $project,
            'timeline' => $project->clientTimeline(),
            'documents' => $project->clientDocuments(),
            'invoices' => $project->invoices->sortByDesc('issue_date')->values(),
            'receipts' => $this->receipts($project),
            'handover' => $project->handoverItems,
            'progress' => $project->handoverProgress(),
            'money' => fn (int $minor): string => Money::ofMinor($minor)->format($lakh),
            'whatsapp' => Phone::whatsappUrl(Phone::normalize($business->whatsapp), "Assalam o Alaikum, I have a question about {$project->code}."),
            'business' => $business,
            'status' => $request->session()->get('portal_status'),
        ]);
    }

    /** A document or photograph the team shared, streamed from the private disk. */
    public function document(Request $request, Project $project, int $media): StreamedResponse
    {
        // Two kinds of file reach a client: a document the team chose to show, and the photographs of an update
        // that was shared — a shared update is client-visible by definition (D-87).
        $shared = $project->getMedia('files')->firstWhere('id', $media);

        if ($shared !== null) {
            abort_unless((bool) $shared->getCustomProperty('visible_to_client'), 404);

            return $request->boolean('download') ? $shared->toResponse($request) : $shared->toInlineResponse($request);
        }

        $photo = $project->updates()
            ->where('client_visible', true)
            ->get()
            ->flatMap(fn (ProjectUpdate $update) => $update->getMedia('photos'))
            ->firstWhere('id', $media);

        abort_if($photo === null, 404);

        return $request->boolean('download') ? $photo->toResponse($request) : $photo->toInlineResponse($request);
    }

    /** The receipt for a payment on this project, for clients who keep their own file. */
    public function receipt(Project $project, int $payment): StreamedResponse
    {
        $paid = $project->invoices()->with('payments')->get()
            ->flatMap(fn ($invoice) => $invoice->payments)
            ->firstWhere('id', $payment);

        abort_if($paid === null || $paid->receipt_pdf_path === null || ! Storage::disk('local')->exists((string) $paid->receipt_pdf_path), 404);

        return Storage::disk('local')->download((string) $paid->receipt_pdf_path, ReceiptPdf::filename($paid), ['Content-Type' => 'application/pdf']);
    }

    /** The client signs for the space: their name is the signature. */
    public function handover(Request $request, Project $project, AcceptHandover $accept): RedirectResponse
    {
        $page = url(PortalLinks::signed('projects.portal', $project));

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'confirm' => ['accepted'],
        ], [], ['name' => 'your name', 'confirm' => 'confirmation']);

        if ($validator->fails()) {
            return redirect()->to($page.'#handover')->withErrors($validator, 'handover')->withInput();
        }

        try {
            $accept->handle($project, (string) $request->input('name'));
        } catch (ValidationException $exception) {
            return redirect()->to($page.'#handover')
                ->withErrors(['confirm' => (string) collect($exception->errors())->flatten()->first()], 'handover')
                ->withInput();
        }

        return redirect()->to($page)->with('portal_status', 'handed_over');
    }

    /**
     * Money in, and the receipt that proves it.
     *
     * @return Collection<int, Payment>
     */
    private function receipts(Project $project): Collection
    {
        return $project->invoices()
            ->with('payments')
            ->get()
            ->flatMap(fn ($invoice) => $invoice->payments)
            ->whereNotNull('receipt_pdf_path')
            ->sortByDesc('received_on')
            ->values();
    }

    /** Invoice links the client can open from here (each one signed for itself). */
    public static function invoiceLink(Invoice $invoice): string
    {
        return InvoiceLinks::signed('invoices.portal', $invoice);
    }

    public static function handoverItemLabel(ProjectHandoverItem $item): string
    {
        return $item->label;
    }
}
