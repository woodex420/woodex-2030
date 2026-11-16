@php
    use App\Domain\Sales\Enums\QuoteStatus;
    use App\Support\Money;
    $lakh = app(\App\Settings\SalesSettings::class)->lakh_grouping;
    $money = fn (int $minor) => Money::ofMinor($minor)->format($lakh);
    $qty = fn (string $q) => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
    $t = $s['totals'];
    $currentFurniture = ($s['business_line'] ?? '') === 'furniture' && (int) ($s['furniture_revision'] ?? 1) >= 5;
    $currentIdentity = $currentFurniture || (($s['business_line'] ?? '') !== 'furniture' && (int) ($s['interior_revision'] ?? 1) >= 3);
    $displayBrand = $currentIdentity ? ($s['document_design']['brand_name'] ?? $s['company']['brand']) : $s['company']['brand'];
    $valid = $s['valid_until'] ? \Carbon\CarbonImmutable::parse($s['valid_until']) : null;
    $daysLeft = $valid ? (int) today()->diffInDays($valid, false) : null;
    $status = $quote->status;
    $open = $isLatest && in_array($status, [QuoteStatus::Sent, QuoteStatus::Viewed], true) && ($daysLeft === null || $daysLeft >= 0);
    $flash = session('portal_status');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#0f1e36">
    <title>Quotation {{ $s['label'] }} · {{ $displayBrand }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/site.css'])
</head>
<body class="bg-sand-50">
    <header class="bg-navy-900 text-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-5 py-5 md:px-8">
            @if ($currentIdentity)
                <span class="inline-flex items-center gap-2.5 text-white">
                    @if (!empty($s['document_design']['logo_data_uri']) && preg_match('/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/', $s['document_design']['logo_data_uri']))
                        <img src="{{ $s['document_design']['logo_data_uri'] }}" alt="{{ $displayBrand }} logo" style="width:40px;height:40px;object-fit:contain;background:#fff;padding:4px;border-radius:4px">
                    @endif
                    <span class="font-heading text-xl">{{ $displayBrand }}</span>
                </span>
            @else
                <x-brand-logo :subtitle="($s['business_line'] ?? '') === 'furniture' ? 'FURNITURE' : 'INTERIOR STUDIO'" class="text-white" />
            @endif
            @if ($whatsapp)
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn-studio">Ask on WhatsApp <span aria-hidden="true">↗</span></a>
            @endif
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-5 py-8 md:px-8 md:py-12">
        {{-- State banners --}}
        @if ($flash === 'accepted' || $status === QuoteStatus::Accepted)
            <div class="mb-6 rounded-2xl border border-green-600/20 bg-green-50 p-5 text-green-900" role="status">
                <p class="text-lg font-medium">Thank you{{ $quote->accepted_name ? ', '.$quote->accepted_name : '' }}. Your acceptance is confirmed.</p>
                <p class="mt-1 text-sm">Accepted on {{ $quote->accepted_at?->timezone('Asia/Karachi')->format('j M Y, g:i a') }}. {{ ($quote->meta['furniture_billing'] ?? '') === 'full' ? 'Our team will contact you shortly with your Furniture invoice and the next steps.' : 'Our team will contact you shortly with the advance invoice and the next steps.' }}</p>
            </div>
        @elseif ($flash === 'changes_requested' || $status === QuoteStatus::ChangesRequested)
            <div class="mb-6 rounded-2xl border border-amber-600/20 bg-amber-50 p-5 text-amber-900" role="status">
                <p class="text-lg font-medium">We received your comments.</p>
                <p class="mt-1 text-sm">Your consultant will update the quotation and send you the new version on this same link.</p>
            </div>
        @elseif (! $isLatest || $status === QuoteStatus::Draft || $status === QuoteStatus::PendingApproval || $status === QuoteStatus::Approved)
            <div class="mb-6 rounded-2xl border border-navy-200 bg-navy-50 p-5 text-navy-900" role="status">
                <p class="text-lg font-medium">An updated quotation is being prepared.</p>
                <p class="mt-1 text-sm">You can still read the version below. The new version will appear on this link as soon as it is ready.</p>
            </div>
        @elseif ($daysLeft !== null && $daysLeft < 0 || $status === QuoteStatus::Expired)
            <div class="mb-6 rounded-2xl border border-red-600/20 bg-red-50 p-5 text-red-900" role="status">
                <p class="text-lg font-medium">This quotation expired on {{ $valid?->format('j M Y') }}.</p>
                <p class="mt-1 text-sm">Prices change with the market. Message us and we will re-issue it with current rates.</p>
            </div>
        @elseif (in_array($status, [QuoteStatus::Declined, QuoteStatus::Superseded, QuoteStatus::Void], true))
            <div class="mb-6 rounded-2xl border border-line bg-white p-5" role="status">
                <p class="text-lg font-medium text-ink">This quotation is no longer active.</p>
                <p class="mt-1 text-sm">Please contact us for the latest version.</p>
            </div>
        @endif

        {{-- Summary --}}
        <section class="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-line md:p-10">
            <div class="flex flex-col gap-8 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-[13px] font-medium tracking-[0.12em] text-navy-400 uppercase">Quotation · {{ $s['label'] }}</p>
                    <h1 class="mt-3 text-[clamp(30px,4.5vw,48px)]">{{ $s['title'] }}</h1>
                    <p class="mt-3 text-body">Prepared for <span class="font-medium text-ink">{{ $s['client']['name'] }}</span>@if ($s['client']['company']) · {{ $s['client']['company'] }}@endif</p>
                </div>
                <div class="md:shrink-0 md:text-right">
                    <p class="text-sm text-muted">Total</p>
                    <p class="font-heading text-[clamp(30px,4vw,44px)] leading-none whitespace-nowrap text-ink">{{ $money($t['total_minor']) }}</p>
                    @if ($valid)
                        <p class="mt-3 inline-flex rounded-full px-3 py-1 text-[13px] whitespace-nowrap {{ $daysLeft !== null && $daysLeft < 0 ? 'bg-red-50 text-red-800' : ($daysLeft !== null && $daysLeft <= 3 ? 'bg-amber-50 text-amber-800' : 'bg-sand-100 text-navy-800') }}">
                            Valid until {{ $valid->format('j M Y') }}@if ($daysLeft !== null && $daysLeft >= 0) · {{ $daysLeft === 0 ? 'last day' : $daysLeft.' '.($daysLeft === 1 ? 'day' : 'days').' left' }}@endif
                        </p>
                    @endif
                </div>
            </div>

            <div class="mt-8 flex flex-wrap gap-3">
                @if ($open)
                    <a href="#accept" class="inline-flex items-center gap-2 rounded-full bg-navy-900 px-6 py-3 text-sm font-medium text-white hover:bg-navy-800">Accept quotation</a>
                    <a href="#changes" class="inline-flex items-center gap-2 rounded-full border border-navy-200 px-6 py-3 text-sm font-medium text-navy-900 hover:bg-navy-50">Request changes</a>
                @endif
                <a href="{{ $pdfUrl }}" class="inline-flex items-center gap-2 rounded-full border border-navy-200 px-6 py-3 text-sm font-medium text-navy-900 hover:bg-navy-50">Download PDF</a>
            </div>
        </section>

        @if ($s['scope_html'])
            <section class="mt-8 rounded-3xl bg-white p-6 ring-1 ring-line md:p-10">
                <h2 class="text-2xl">Scope of work</h2>
                <div class="mt-4 space-y-3 text-body [&_ul]:list-disc [&_ul]:pl-5">{!! $s['scope_html'] !!}</div>
            </section>
        @endif

        {{-- Price --}}
        <section class="mt-8 rounded-3xl bg-white p-6 ring-1 ring-line md:p-10">
            <h2 class="text-2xl">Price</h2>
            @foreach ($s['sections'] as $section)
                <div class="mt-6">
                    <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2">
                        <h3 class="text-lg">{{ $section['name'] }}</h3>
                        @if (count($s['sections']) > 1)<span class="text-sm font-medium text-ink">{{ $money($section['subtotal_minor']) }}</span>@endif
                    </div>
                    @if ($section['note'])<p class="mt-1 text-sm text-muted">{{ $section['note'] }}</p>@endif
                    <ul class="divide-y divide-line">
                        @foreach ($section['items'] as $item)
                            <li class="flex flex-col gap-1 py-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6 {{ $item['is_optional'] ? 'text-muted' : '' }}">
                                <div class="min-w-0">
                                    @if (($item['image'] ?? null) !== null)
                                        <img src="{{ $item['image'] }}" alt="" class="float-left me-3 size-14 rounded-md object-cover">
                                    @endif
                                    <p class="{{ $item['is_optional'] ? 'italic' : 'text-ink' }}">{!! nl2br(e($item['description'])) !!}</p>
                                    <p class="text-sm text-muted">{{ $qty($item['qty']) }} {{ $item['unit_label'] }} × {{ $money($item['rate_minor']) }}@if ($item['discount_minor'] > 0) · discount {{ $money($item['discount_minor']) }}@endif @if ($item['is_optional']) · optional, not included in the total @endif</p>
                                </div>
                                <p class="shrink-0 font-medium {{ $item['is_optional'] ? '' : 'text-ink' }} sm:text-right">{{ $money($item['line_total_minor']) }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            <dl class="mt-8 ml-auto max-w-sm space-y-2 text-sm">
                <div class="flex justify-between"><dt>Subtotal</dt><dd class="text-ink">{{ $money($t['subtotal_minor']) }}</dd></div>
                @if ($t['discount_minor'] > 0)<div class="flex justify-between"><dt>{{ $t['discount_label'] }}</dt><dd class="text-ink">− {{ $money($t['discount_minor']) }}</dd></div>@endif
                @if ($currentFurniture)
                    @if ($t['other_charges_minor'] + ($t['shipping_minor'] ?? 0) !== 0)<div class="flex justify-between"><dt>Rent / Transport</dt><dd class="text-ink">{{ $money($t['other_charges_minor'] + ($t['shipping_minor'] ?? 0)) }}</dd></div>@endif
                @else
                    @if ($t['other_charges_minor'] > 0)<div class="flex justify-between"><dt>{{ $t['other_charges_label'] }}</dt><dd class="text-ink">{{ $money($t['other_charges_minor']) }}</dd></div>@endif
                @endif
                @if ($t['tax_minor'] > 0)<div class="flex justify-between"><dt>Tax</dt><dd class="text-ink">{{ $money($t['tax_minor']) }}</dd></div>@endif
                @if ($t['rounding_minor'] !== 0)<div class="flex justify-between"><dt>{{ $currentFurniture ? 'Previous adjustment' : 'Rounding' }}</dt><dd class="text-ink">{{ $money($t['rounding_minor']) }}</dd></div>@endif
                <div class="flex justify-between border-t border-line pt-3 text-base font-medium text-ink"><dt>Total</dt><dd>{{ $money($t['total_minor']) }}</dd></div>
            </dl>
            @unless ($currentFurniture)<p class="mt-2 text-right text-sm text-muted italic">{{ $t['total_words'] }}</p>@endunless
        </section>

        <div class="mt-8 grid gap-8 md:grid-cols-2">
            @if ($currentFurniture || $s['milestones'] !== [])
                <section class="rounded-3xl bg-white p-6 ring-1 ring-line md:p-8">
                    <h2 class="text-2xl">Payment schedule</h2>
                    <ul class="mt-4 divide-y divide-line text-sm">
                        @if ($currentFurniture)
                            <li class="flex justify-between gap-4 py-2.5"><span>Advance with work order · {{ $qty((string) ($t['advance_bp'] / 100)) }}%</span><span class="font-medium text-ink">{{ $money($t['advance_minor']) }}</span></li>
                            <li class="flex justify-between gap-4 py-2.5"><span>Remaining after advance · {{ $qty((string) ((10000 - $t['advance_bp']) / 100)) }}%</span><span class="font-medium text-ink">{{ $money($t['after_advance_minor']) }}</span></li>
                        @else
                        @foreach ($s['milestones'] as $milestone)
                            <li class="flex justify-between gap-4 py-2.5"><span>{{ $milestone['name'] }} · {{ rtrim(rtrim(number_format($milestone['percent_bp'] / 100, 2), '0'), '.') }}%</span><span class="font-medium text-ink">{{ $money($milestone['amount_minor']) }}</span></li>
                        @endforeach
                        @endif
                    </ul>
                </section>
            @endif
            @if ($s['terms'] !== [])
                <section class="rounded-3xl bg-white p-6 ring-1 ring-line md:p-8">
                    <h2 class="text-2xl">Terms</h2>
                    <ol class="mt-4 list-decimal space-y-1.5 pl-5 text-sm">
                        @if ($currentFurniture)
                            @foreach (\App\Domain\Sales\Services\FurnitureDocument::commercialTerms($s) as [$label, $value])<li><strong>{{ $label }}:</strong> {{ $value }}</li>@endforeach
                        @else
                            @foreach ($s['terms'] as $term)<li>{{ $term }}</li>@endforeach
                        @endif
                    </ol>
                </section>
            @endif
        </div>

        @if ($open)
            <div class="mt-8 grid gap-8 md:grid-cols-2">
                <section id="accept" class="scroll-mt-6 rounded-3xl bg-navy-900 p-6 text-white md:p-8">
                    <h2 class="text-2xl text-white">Accept this quotation</h2>
                    <p class="mt-2 text-sm text-navy-100/80">Type your full name to accept {{ $s['label'] }} for {{ $money($t['total_minor']) }} with the terms above.</p>
                    <form method="POST" action="{{ $acceptUrl }}" class="mt-5 space-y-4">
                        @csrf
                        <input type="hidden" name="revision" value="{{ $s['revision'] }}">
                        <div>
                            <label for="accept-name" class="mb-1 block text-sm">Full name</label>
                            <input id="accept-name" name="name" required minlength="3" maxlength="160" value="{{ old('name') }}" autocomplete="name" class="h-11 w-full rounded-ui border-0 bg-white px-3 text-ink placeholder:text-muted focus:outline-2 focus:outline-offset-2 focus:outline-sand-200">
                        </div>
                        <div>
                            <label for="accept-phone" class="mb-1 block text-sm">Phone / WhatsApp (optional)</label>
                            <input id="accept-phone" name="phone" type="tel" maxlength="30" value="{{ old('phone') }}" autocomplete="tel" class="h-11 w-full rounded-ui border-0 bg-white px-3 text-ink placeholder:text-muted focus:outline-2 focus:outline-offset-2 focus:outline-sand-200">
                        </div>
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" name="confirm" value="1" required class="mt-1 size-4">
                            <span>I accept this quotation, the payment schedule and the terms.</span>
                        </label>
                        @if ($errors->accept->any())
                            <div class="rounded-ui bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">@foreach ($errors->accept->all() as $error)<p>{{ $error }}</p>@endforeach</div>
                        @endif
                        <button type="submit" class="inline-flex h-11 items-center rounded-full bg-white px-6 text-sm font-medium text-navy-900 hover:bg-sand-100">Accept quotation</button>
                    </form>
                </section>

                <section id="changes" class="scroll-mt-6 rounded-3xl bg-white p-6 ring-1 ring-line md:p-8">
                    <h2 class="text-2xl">Request changes</h2>
                    <p class="mt-2 text-sm">Tell us what to change: items, finishes, quantities or the budget. We will send an updated version on this link.</p>
                    <form method="POST" action="{{ $changesUrl }}" class="mt-5 space-y-4">
                        @csrf
                        <input type="hidden" name="revision" value="{{ $s['revision'] }}">
                        <label for="changes-comment" class="sr-only">Your comments</label>
                        <textarea id="changes-comment" name="comment" rows="5" required minlength="5" maxlength="2000" class="w-full rounded-ui border border-line px-3 py-2 text-ink">{{ old('comment') }}</textarea>
                        @if ($errors->changes->any())
                            <div class="rounded-ui bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">@foreach ($errors->changes->all() as $error)<p>{{ $error }}</p>@endforeach</div>
                        @endif
                        <button type="submit" class="inline-flex h-11 items-center rounded-full border border-navy-200 px-6 text-sm font-medium text-navy-900 hover:bg-navy-50">Send my comments</button>
                    </form>
                </section>
            </div>
        @endif

        <footer class="mt-12 border-t border-line pt-6 text-sm text-muted">
            <p class="font-medium text-ink">{{ $s['company']['legal_name'] }}</p>
            <p>{{ $s['company']['address'] }} · {{ $s['company']['phone'] }} · {{ $s['company']['email'] }}</p>
            <p class="mt-2">Issued {{ \Carbon\CarbonImmutable::parse($s['issued_on'])->format('j M Y') }}@if ($s['prepared_by']) by {{ $s['prepared_by'] }}@endif. This page is private to you; please do not share the link.</p>
        </footer>
    </main>
</body>
</html>
