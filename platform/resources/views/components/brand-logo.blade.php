@props(['subtitle' => 'OS', 'compact' => false])
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    <x-brand-mark />
    @unless ($compact)
        <span class="font-heading text-[21px] leading-none font-medium tracking-[0.055em]">
            WOODEX
            <small class="mt-1 block font-sans text-[8px] font-normal tracking-[0.42em]">{{ $subtitle }}</small>
        </span>
    @endunless
</span>
