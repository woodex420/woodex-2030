@php
    use App\Domain\Sales\Enums\InvoiceStatus;
    $lakh = app(\App\Settings\SalesSettings::class)->lakh_grouping;
    $money = fn (int $minor) => \App\Support\Money::ofMinor($minor)->format($lakh);
    $settled = in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Void], true);
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
    <title>Invoice {{ $s['number'] }} · {{ $s['business']['name'] }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/site.css'])
</head>
<body class="bg-sand-50">
    <header class="bg-navy-900 text-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-5 py-5 md:px-8">
            <x-brand-logo subtitle="INTERIOR STUDIO" class="text-white" />
            <div class="flex items-center gap-3">
                <a href="{{ $pdfUrl }}" class="btn-studio">Download PDF <span aria-hidden="true">↗</span></a>
                @if ($whatsapp)
                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="text-sm text-navy-100/80 hover:text-white">Ask on WhatsApp</a>
                @endif
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-5 py-8 md:px-8 md:py-12">
        @if ($flash === 'proof_submitted')
            <div class="mb-6 rounded-2xl border border-green-600/20 bg-green-50 p-5 text-green-900" role="status">
                <p class="text-lg font-medium">Thank you — we have your payment details.</p>
                <p class="mt-1 text-sm">Our accounts team will match them with the bank and confirm within one working day.</p>
            </div>
        @elseif ($invoice->status === InvoiceStatus::Paid)
            <div class="mb-6 rounded-2xl border border-green-600/20 bg-green-50 p-5 text-green-900" role="status">
                <p class="text-lg font-medium">This invoice is paid. Thank you.</p>
                <p class="mt-1 text-sm">Your receipt is on its way by email.</p>
            </div>
        @elseif ($invoice->status === InvoiceStatus::Void)
            <div class="mb-6 rounded-2xl border border-line bg-white p-5" role="status">
                <p class="text-lg font-medium text-ink">This invoice was cancelled.</p>
                <p class="mt-1 text-sm text-body">Please ignore it; no payment is expected.</p>
            </div>
        @endif

        <div class="rounded-3xl bg-white p-6 ring-1 ring-line md:p-8">
            <p class="text-xs tracking-[0.18em] text-muted uppercase">{{ $invoice->kind->label() }} invoice</p>
            <h1 class="mt-2 text-3xl text-ink md:text-4xl">{{ $s['number'] }}</h1>
            <p class="mt-3 text-body">
                For <span class="font-medium text-ink">{{ $s['client']['name'] }}</span>
                @if (! empty($s['project'])) · {{ $s['project']['code'] }} {{ $s['project']['name'] }}@endif
            </p>
            <p class="mt-1 text-sm text-muted">
                Issued {{ $s['issue_date'] ?? '—' }}@if (! empty($s['due_date'])) · due {{ $s['due_date'] }}@endif
            </p>

            <dl class="mt-6 grid gap-3 border-t border-line pt-6 sm:grid-cols-3">
                <div><dt class="text-xs text-muted uppercase">Invoice total</dt><dd class="text-xl text-ink">{{ $s['total'] }}</dd></div>
                <div><dt class="text-xs text-muted uppercase">Paid</dt><dd class="text-xl text-ink">{{ $s['paid'] }}</dd></div>
                <div><dt class="text-xs text-muted uppercase">Balance</dt><dd class="text-xl text-ink">{{ $s['balance'] }}</dd></div>
            </dl>
            <p class="mt-2 text-sm text-muted">
                Status: <span class="font-medium text-ink">{{ $invoice->status->label() }}</span>
                @if (! empty($s['quote'])) · against quotation {{ $s['quote']['number'] }}@endif
            </p>

            <h2 class="mt-8 text-lg text-ink">What this covers</h2>
            <ul class="mt-3 divide-y divide-line">
                @foreach ($s['items'] as $item)
                    <li class="flex items-start justify-between gap-4 py-3">
                        <span class="text-body">@if (!empty($item['product_name']))<strong class="block">{{ $item['product_name'] }}</strong>@endif{{ $item['description'] }}</span>
                        <span class="shrink-0 font-medium text-ink">{{ $item['line_total'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        @if (! empty($s['bank']) && ! $settled)
            <div class="mt-6 rounded-3xl bg-navy-900 p-6 text-white md:p-8">
                <h2 class="text-xl text-white">Send the payment to</h2>
                <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div><dt class="text-xs tracking-wide text-navy-300 uppercase">Title</dt><dd class="mt-1 text-white">{{ $s['bank']['title'] }}</dd></div>
                    <div><dt class="text-xs tracking-wide text-navy-300 uppercase">Bank</dt><dd class="mt-1 text-white">{{ $s['bank']['bank_name'] }}@if (! empty($s['bank']['branch']))<br><span class="text-sm text-navy-100/70">{{ $s['bank']['branch'] }}</span>@endif</dd></div>
                    <div>
                        <dt class="text-xs tracking-wide text-navy-300 uppercase">{{ ! empty($s['bank']['iban']) ? 'IBAN' : 'Account number' }}</dt>
                        <dd class="mt-1 font-medium text-white">{{ $s['bank']['iban'] ?: $s['bank']['account_number'] }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-sm text-navy-100/70">After the transfer, tell us below — a screenshot or a bank slip is enough, and we will confirm it the same day.</p>
            </div>
        @endif

        @if (! $settled)
            <section id="pay" class="mt-6 scroll-mt-6 rounded-3xl bg-white p-6 ring-1 ring-line md:p-8">
                <h2 class="text-2xl">I have paid</h2>
                <p class="mt-2 text-sm text-body">Tell us how much you sent and when. Our accounts team checks it against the bank and sends your receipt.</p>
                <form method="POST" action="{{ $proofUrl }}" enctype="multipart/form-data" class="mt-5 grid gap-4 sm:grid-cols-2">
                    @csrf
                    <div>
                        <label for="amount" class="mb-1 block text-sm">Amount paid (PKR)</label>
                        <input id="amount" name="amount" type="number" step="0.01" min="1" required value="{{ old('amount', (int) ($invoice->balance_minor / 100)) }}"
                               class="h-11 w-full rounded-ui border border-line px-3 text-ink focus:outline-2 focus:outline-offset-2 focus:outline-navy-300">
                    </div>
                    <div>
                        <label for="received_on" class="mb-1 block text-sm">Date of payment</label>
                        <input id="received_on" name="received_on" type="date" required max="{{ now()->toDateString() }}" value="{{ old('received_on', now()->toDateString()) }}"
                               class="h-11 w-full rounded-ui border border-line px-3 text-ink focus:outline-2 focus:outline-offset-2 focus:outline-navy-300">
                    </div>
                    <div>
                        <label for="method" class="mb-1 block text-sm">How you paid</label>
                        <select id="method" name="method" class="h-11 w-full rounded-ui border border-line px-3 text-ink">
                            @foreach ($methods as $value => $label)
                                <option value="{{ $value }}" @selected(old('method') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="reference" class="mb-1 block text-sm">Reference / transaction id (optional)</label>
                        <input id="reference" name="reference" maxlength="120" value="{{ old('reference') }}" class="h-11 w-full rounded-ui border border-line px-3 text-ink">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="proof" class="mb-1 block text-sm">Screenshot or bank slip (optional, up to 10 MB)</label>
                        <input id="proof" name="proof" type="file" accept="image/*,application/pdf" class="w-full text-sm text-body file:mr-3 file:rounded-full file:border-0 file:bg-navy-900 file:px-4 file:py-2 file:text-sm file:text-white">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="notes" class="mb-1 block text-sm">Anything we should know (optional)</label>
                        <textarea id="notes" name="notes" rows="3" maxlength="1000" class="w-full rounded-ui border border-line px-3 py-2 text-ink">{{ old('notes') }}</textarea>
                    </div>

                    @if ($errors->proof->any())
                        <div class="sm:col-span-2 rounded-ui bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
                            @foreach ($errors->proof->all() as $error)<p>{{ $error }}</p>@endforeach
                        </div>
                    @endif

                    <div class="sm:col-span-2">
                        <button type="submit" class="inline-flex h-11 items-center rounded-full bg-navy-900 px-6 text-sm font-medium text-white hover:bg-navy-800">Send payment details</button>
                    </div>
                </form>
            </section>
        @endif

        <footer class="mt-12 border-t border-line pt-6 text-sm text-muted">
            <p class="font-medium text-ink">{{ $s['business']['legal_name'] }}</p>
            <p>{{ $s['business']['address'] }} · {{ $s['business']['phone'] }} · {{ $s['business']['email'] }}</p>
            <p class="mt-2">This page is private to you; please do not share the link.</p>
        </footer>
    </main>
</body>
</html>
