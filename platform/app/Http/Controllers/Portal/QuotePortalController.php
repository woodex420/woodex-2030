<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\RecordQuoteView;
use App\Domain\Sales\Actions\RequestQuoteChanges;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteVersion;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\Sales\Services\QuotePdf;
use App\Settings\BusinessSettings;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The client's quotation page (QUO-009): a signed, expiring link without login. It always shows the latest sent
 * revision (a frozen snapshot), tracks views (QUO-010) and lets the client accept or ask for changes. Staff who are
 * signed in on the same site can open it without being counted as client views.
 */
final class QuotePortalController
{
    public function show(Request $request, Quote $quote, RecordQuoteView $views, BusinessSettings $business): View
    {
        $version = $this->latestVersion($quote);

        if ($request->user() === null) {
            $views->handle($quote, $version, $request->ip(), $request->userAgent());
        }

        $snapshot = $version->snapshot;

        return view('site.quote', [
            'quote' => $quote,
            's' => $snapshot,
            'version' => $version,
            'isLatest' => $quote->revision === $version->revision,
            'acceptUrl' => QuoteLinks::signed('quotes.portal.accept', $quote),
            'changesUrl' => QuoteLinks::signed('quotes.portal.changes', $quote),
            'pdfUrl' => QuoteLinks::signed('quotes.portal.pdf', $quote),
            'whatsapp' => Phone::whatsappUrl(Phone::normalize($business->whatsapp), "Assalam o Alaikum, I have a question about quotation {$snapshot['label']} ({$snapshot['title']})."),
        ]);
    }

    public function pdf(Request $request, Quote $quote, RecordQuoteView $views): StreamedResponse
    {
        $version = $this->latestVersion($quote);
        abort_if($version->pdf_path === null || ! Storage::disk('local')->exists($version->pdf_path), 404);

        if ($request->user() === null) {
            $views->handle($quote, $version, $request->ip(), $request->userAgent(), 'downloaded');
        }

        return Storage::disk('local')->download($version->pdf_path, QuotePdf::filename($version->snapshot), ['Content-Type' => 'application/pdf']);
    }

    public function accept(Request $request, Quote $quote, AcceptQuote $accept): RedirectResponse
    {
        $page = url(QuoteLinks::signed('quotes.portal', $quote));
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'confirm' => ['accepted'],
            'revision' => ['required', 'integer', 'min:0'],
        ], [
            'confirm.accepted' => 'Please tick the box to confirm that you accept the quotation and its terms.',
        ], ['name' => 'full name']);

        if ($validator->fails()) {
            return redirect()->to($page.'#accept')->withErrors($validator, 'accept')->withInput();
        }

        try {
            $accept->handle($quote, (string) $request->input('name'), $request->input('phone'), $request->ip(), $request->userAgent(), revision: (int) $request->input('revision'));
        } catch (QuoteActionDenied $exception) {
            return redirect()->to($page.'#accept')->withErrors(['name' => $exception->getMessage()], 'accept');
        }

        return redirect()->to($page)->with('portal_status', 'accepted');
    }

    public function changes(Request $request, Quote $quote, RequestQuoteChanges $changes): RedirectResponse
    {
        $page = url(QuoteLinks::signed('quotes.portal', $quote));
        $validator = Validator::make($request->all(), [
            'comment' => ['required', 'string', 'min:5', 'max:2000'],
            'revision' => ['required', 'integer', 'min:0'],
        ], [], ['comment' => 'your comments']);

        if ($validator->fails()) {
            return redirect()->to($page.'#changes')->withErrors($validator, 'changes')->withInput();
        }

        try {
            $changes->handle($quote, (string) $request->input('comment'), $request->ip(), $request->userAgent(), (int) $request->input('revision'));
        } catch (QuoteActionDenied $exception) {
            return redirect()->to($page.'#changes')->withErrors(['comment' => $exception->getMessage()], 'changes');
        }

        return redirect()->to($page)->with('portal_status', 'changes_requested');
    }

    private function latestVersion(Quote $quote): QuoteVersion
    {
        /** @var QuoteVersion|null $version */
        $version = $quote->versions()->reorder('revision', 'desc')->first(); // the relation orders ascending
        abort_if($version === null, 404);

        return $version;
    }
}
