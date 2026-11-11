@props(['label', 'field', 'value' => '', 'format' => 'markdown'])
<div x-data="{ insert(before, after = '') { const input = $refs.editor; const start = input.selectionStart; const end = input.selectionEnd; const selected = input.value.slice(start, end); input.setRangeText(before + selected + after, start, end, 'end'); input.dispatchEvent(new Event('input', { bubbles: true })); input.focus(); } }">
    <label class="wx-label" for="form.{{ $field }}">{{ $label }}</label>
    @if($format === 'markdown')
        <div class="mb-2 flex flex-wrap gap-2" role="toolbar" aria-label="{{ $label }} formatting">
            <x-admin.button variant="secondary" x-on:click="insert('**', '**')" aria-label="Bold">Bold</x-admin.button>
            <x-admin.button variant="secondary" x-on:click="insert('*', '*')" aria-label="Italic">Italic</x-admin.button>
            <x-admin.button variant="secondary" x-on:click="insert('\n## ')" aria-label="Heading">Heading</x-admin.button>
            <x-admin.button variant="secondary" x-on:click="insert('\n- ')" aria-label="Bullet list">• List</x-admin.button>
            <x-admin.button variant="secondary" x-on:click="insert('[', '](/shop)')" aria-label="Insert link">Link</x-admin.button>
        </div>
    @endif
    <textarea x-ref="editor" id="form.{{ $field }}" wire:model.live.debounce.400ms="form.{{ $field }}" rows="8" class="wx-input" aria-describedby="{{ $field }}-help"></textarea>
    <p id="{{ $field }}-help" class="mt-1 text-sm text-gray-500">Select text then choose formatting, or type directly. Save draft to retain changes.</p>
    @error('form.'.$field)<p role="alert" class="text-red-600">{{ $message }}</p>@enderror
    <details class="mt-2 rounded-lg border p-3" open>
        <summary class="cursor-pointer font-semibold">Content preview</summary>
        <div class="mt-3 space-y-2 break-words [&_h2]:text-xl [&_h2]:font-semibold [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:list-decimal [&_ol]:pl-6 [&_a]:underline">
            {!! app(\App\Domain\Commerce\Services\CategoryContent::class)->formattedText($value ?? '', $format) !!}
        </div>
    </details>
</div>
