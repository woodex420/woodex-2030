@props(['name', 'title', 'description' => null, 'submit' => null, 'width' => 'max-w-lg', 'unsaved' => ''])
{{--
    Livewire-driven modal: render it inside @if ($showX) … @endif and pass name="showX".
    Escape, the close button and the backdrop all set the property back to false. The backdrop does not while the
    modal holds text that is not saved: a click that slips beside the form is not a decision to throw it away.

    With submit="…" the modal is a form, and a form holds work: it is marked data-unsaved, so that leaving the
    screen with text in it asks first (T-043, resources/js/admin/unsaved.js). unsaved="saveDraft" names the
    actions that save it: besides its submit, or alone where the modal has fields and no form. unsaved="off" is
    for a form that is not work, and goes on the written list in tests/Feature/UnsavedChangesTest.php.
--}}
@php $tag = $submit ? 'form' : 'div'; @endphp
<div class="fixed inset-0 z-[9999] flex items-end justify-center bg-gray-900/50 p-4 backdrop-blur-[2px] sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="{{ $name }}-title"
     x-data x-on:keydown.escape.window="$wire.set('{{ $name }}', false)"
     x-on:click.self="window.wxUnsaved?.holds($el) || $wire.set('{{ $name }}', false)">
    <{{ $tag }} @if ($submit) wire:submit="{{ $submit }}" @endif @if ($submit || $unsaved !== '') data-unsaved="{{ $unsaved }}" @endif
        {{ $attributes->merge(['class' => "w-full {$width} max-h-[92vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-theme-lg dark:bg-gray-900"]) }}>
        <div class="mb-5 flex items-start justify-between gap-4">
            <div>
                <h2 id="{{ $name }}-title" class="text-theme-xl font-medium text-gray-900 dark:text-white">{{ $title }}</h2>
                @if ($description)
                    <p class="mt-0.5 text-theme-xs text-gray-500">{{ $description }}</p>
                @endif
            </div>
            <button type="button" wire:click="$set('{{ $name }}', false)" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" aria-label="Close">
                <x-icon name="x" />
            </button>
        </div>

        {{ $slot }}

        @isset($footer)
            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">{{ $footer }}</div>
        @endisset
    </{{ $tag }}>
</div>
