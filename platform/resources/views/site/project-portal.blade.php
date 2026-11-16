@use('App\Support\Money')
@use('App\Domain\Sales\Services\InvoiceLinks')
@use('App\Domain\Projects\Services\PortalLinks')
@use('App\Domain\Sales\Enums\InvoiceStatus')
@php
    $lakh = app(\App\Settings\SalesSettings::class)->lakh_grouping;
    $money = fn (int $minor) => Money::ofMinor($minor)->format($lakh);
    [$handedDone, $handedTotal] = $progress;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#0f1e36">
    <title>{{ $project->name }} · {{ $project->code }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/site.css'])
</head>
<body class="bg-sand-50">
    <header class="bg-navy-900 text-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-5 py-5 md:px-8">
            <x-brand-logo subtitle="INTERIOR STUDIO" class="text-white" />
            <div class="flex items-center gap-3">
                <span class="hidden text-sm text-navy-100/80 sm:inline">{{ $project->contact?->first_name ? 'Hello '.$project->contact->first_name() : '' }}</span>
                @if ($whatsapp)
                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="text-sm text-navy-100/80 hover:text-white">Ask on WhatsApp</a>
                @endif
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-5 py-8 md:px-8 md:py-12">
        @if ($status === 'handed_over')
            <div class="mb-6 rounded-2xl border border-green-600/20 bg-green-50 p-5 text-green-900" role="status">
                <p class="text-lg font-medium">Thank you — the handover is signed.</p>
                <p class="mt-1 text-sm">Welcome to your new space. Anything that needs our attention is still covered: message us and we will come.</p>
            </div>
        @endif

        {{-- Where the job stands --}}
        <section class="rounded-2xl border border-line bg-white p-6">
            <p class="text-sm text-muted">{{ $project->code }} · {{ $project->business_line->label() }}</p>
            <h1 class="mt-1 text-3xl font-medium text-ink">{{ $project->name }}</h1>
            <p class="mt-2 text-ink/70">{{ $project->stage->clientLine() }}</p>

            <div class="mt-5 h-2 overflow-hidden rounded-full bg-sand-100" role="progressbar" aria-valuenow="{{ $project->progress_percent }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-navy-900" style="width: {{ $project->progress_percent }}%"></div>
            </div>

            <dl class="mt-6 grid grid-cols-2 gap-5 sm:grid-cols-4">
                <div>
                    <dt class="text-sm text-muted">Progress</dt>
                    <dd class="text-xl font-medium text-ink">{{ $project->progress_percent }}%</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted">Next milestone</dt>
                    <dd class="text-lg font-medium text-ink">{{ $project->nextMilestone()?->name ?? 'Finishing' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted">Target</dt>
                    <dd class="text-lg font-medium text-ink">{{ $project->target_date?->format('j M Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted">Outstanding</dt>
                    <dd class="text-xl font-medium {{ $project->balanceMinor() > 0 ? 'text-ink' : 'text-muted' }}">{{ $money($project->balanceMinor()) }}</dd>
                </div>
            </dl>

            @if ($project->manager)
                <p class="mt-5 text-sm text-muted">Your project manager: <span class="font-medium text-ink">{{ $project->manager->name }}</span></p>
            @endif
        </section>

        {{-- The diary, as far as the client may read it --}}
        <section class="mt-8">
            <h2 class="text-xl font-medium text-ink">From the site</h2>

            @if ($timeline->isEmpty())
                <p class="mt-3 rounded-2xl border border-line bg-white p-6 text-ink/70">Nothing has been shared yet. Photographs and notes appear here as the work moves.</p>
            @else
                <ol class="mt-4 space-y-4">
                    @foreach ($timeline as $update)
                        <li class="rounded-2xl border border-line bg-white p-5">
                            <p class="text-sm text-muted">{{ $update->created_at?->format('D j M Y, g:i a') }}@if ($update->progress_percent !== null) · {{ $update->progress_percent }}% done @endif</p>
                            <p class="mt-2 whitespace-pre-line text-ink">{{ $update->body }}</p>

                            @if ($update->getMedia('photos')->isNotEmpty())
                                <div class="mt-4 flex flex-wrap gap-3">
                                    @foreach ($update->getMedia('photos') as $photo)
                                        <img src="{{ PortalLinks::signed('projects.portal.document', $project, ['media' => $photo->id]) }}"
                                             alt="{{ $photo->getCustomProperty('original_name', $photo->name) }}"
                                             class="h-32 w-44 rounded-ui object-cover" loading="lazy">
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        {{-- Drawings and documents we chose to show --}}
        @if ($documents->isNotEmpty())
            <section class="mt-8">
                <h2 class="text-xl font-medium text-ink">Drawings and documents</h2>
                <ul class="mt-4 divide-y divide-line rounded-2xl border border-line bg-white">
                    @foreach ($documents as $file)
                        <li class="flex items-center justify-between gap-4 px-5 py-3">
                            <span class="text-ink">{{ $file->getCustomProperty('original_name', $file->file_name) }}</span>
                            <a href="{{ PortalLinks::signed('projects.portal.document', $project, ['media' => $file->id, 'download' => 1]) }}"
                               class="text-sm font-medium text-navy-900 hover:underline">Download</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Money --}}
        <section class="mt-8 grid gap-6 md:grid-cols-2">
            <div>
                <h2 class="text-xl font-medium text-ink">Invoices</h2>
                <ul class="mt-4 divide-y divide-line rounded-2xl border border-line bg-white">
                    @forelse ($invoices as $invoice)
                        <li class="flex items-center justify-between gap-4 px-5 py-3">
                            <div>
                                <p class="font-medium text-ink">{{ $invoice->number }}</p>
                                <p class="text-sm text-muted">
                                    {{ $invoice->status->label() }}@if ($invoice->due_date) · due {{ $invoice->due_date->format('j M Y') }}@endif
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium text-ink">{{ $money((int) $invoice->total_minor) }}</p>
                                @if ($invoice->status !== InvoiceStatus::Draft && $invoice->status !== InvoiceStatus::Void)
                                    <a href="{{ InvoiceLinks::signed('invoices.portal', $invoice) }}" class="text-sm text-navy-900 hover:underline">Open & pay</a>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-ink/70">No invoices yet.</li>
                    @endforelse
                </ul>
            </div>

            <div>
                <h2 class="text-xl font-medium text-ink">Receipts</h2>
                <ul class="mt-4 divide-y divide-line rounded-2xl border border-line bg-white">
                    @forelse ($receipts as $payment)
                        <li class="flex items-center justify-between gap-4 px-5 py-3">
                            <div>
                                <p class="font-medium text-ink">{{ $payment->receipt_number }}</p>
                                <p class="text-sm text-muted">{{ $payment->received_on?->format('j M Y') }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium text-ink">{{ $money((int) $payment->amount_minor) }}</p>
                                <a href="{{ PortalLinks::signed('projects.portal.receipt', $project, ['payment' => $payment->id]) }}"
                                   class="text-sm text-navy-900 hover:underline">Download</a>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-ink/70">Receipts appear here once a payment is confirmed.</li>
                    @endforelse
                </ul>
            </div>
        </section>

        {{-- Handover --}}
        @if ($project->handover_started_at !== null)
            <section id="handover" class="mt-8 rounded-2xl border border-line bg-white p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 class="text-xl font-medium text-ink">Handover</h2>
                    <p class="text-sm text-muted">{{ $handedDone }} of {{ $handedTotal }} complete</p>
                </div>

                <ul class="mt-4 space-y-2">
                    @foreach ($handover as $item)
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border {{ $item->isDone() ? 'border-green-600 bg-green-600 text-white' : 'border-line' }}">
                                @if ($item->isDone())<span aria-hidden="true">✓</span>@endif
                            </span>
                            <span class="{{ $item->isDone() ? 'text-muted line-through' : 'text-ink' }}">{{ $item->label }}</span>
                        </li>
                    @endforeach
                </ul>

                @if ($project->client_ok_at !== null)
                    <p class="mt-6 rounded-ui bg-green-50 px-4 py-3 text-green-900">
                        Signed for by {{ $project->client_ok_name }} on {{ $project->client_ok_at->format('j M Y') }}. Thank you.
                    </p>
                @else
                    <form method="post" action="{{ PortalLinks::signed('projects.portal.handover', $project) }}" class="mt-6 space-y-4">
                        @csrf
                        @if ($errors->handover->isNotEmpty())
                            <div class="rounded-ui bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
                                @foreach ($errors->handover->all() as $error)<p>{{ $error }}</p>@endforeach
                            </div>
                        @endif

                        <label class="block">
                            <span class="text-sm text-muted">Type your name to sign for the handover</span>
                            <input type="text" name="name" value="{{ old('name') }}" required maxlength="120"
                                   class="mt-1 h-11 w-full rounded-ui border border-line bg-white px-3 text-ink">
                        </label>

                        <label class="flex items-center gap-2 text-ink">
                            <input type="checkbox" name="confirm" value="1" class="size-4 rounded border-line text-navy-900" required>
                            <span>I have checked the space and I accept the handover.</span>
                        </label>

                        <button type="submit" class="inline-flex h-11 items-center rounded-full bg-navy-900 px-6 text-sm font-medium text-white hover:bg-navy-800">Sign the handover</button>
                    </form>
                @endif
            </section>
        @endif

        <footer class="mt-12 border-t border-line pt-6 text-sm text-muted">
            <p class="font-medium text-ink">{{ $business->legal_name }}</p>
            <p>{{ $business->address }} · {{ $business->phone }} · {{ $business->email }}</p>
            <p class="mt-2">This page is private to you; please do not share the link.</p>
        </footer>
    </main>
</body>
</html>
