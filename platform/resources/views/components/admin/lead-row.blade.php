@props(['lead'])
@use('App\Support\Phone')
{{-- One-line lead with call/WhatsApp actions, used by My Day and dashboard lists. The slot shows status on the right. --}}
<li {{ $attributes->merge(['class' => 'flex items-center gap-3 px-5 py-3 hover:bg-gray-50 dark:hover:bg-white/[0.02]']) }}>
    <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate class="min-w-0 flex-1">
        <span class="block truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $lead->contact->name }}</span>
        <span class="block truncate text-theme-xs text-gray-500">{{ $lead->ref }} · {{ $lead->stage->name }}{{ $lead->service ? ' · '.\Illuminate\Support\Str::headline($lead->service) : '' }}</span>
    </a>
    <div class="hidden shrink-0 sm:block">{{ $slot }}</div>
    <div class="flex shrink-0 items-center gap-1">
        @if ($lead->contact->phone)
            <a href="{{ Phone::telUrl($lead->contact->phone) }}" class="flex size-8 items-center justify-center rounded-lg text-navy-700 hover:bg-navy-50 dark:text-gray-300 dark:hover:bg-white/5" title="Call {{ Phone::display($lead->contact->phone) }}"><x-icon name="phone" class="size-4" /></a>
        @endif
        @if ($wa = Phone::whatsappUrl($lead->contact->whatsappNumber()))
            <a href="{{ $wa }}" target="_blank" rel="noopener" class="flex size-8 items-center justify-center rounded-lg text-success-600 hover:bg-success-50 dark:hover:bg-success-500/10" title="WhatsApp"><x-icon name="whatsapp" class="size-4" /></a>
        @endif
    </div>
</li>
