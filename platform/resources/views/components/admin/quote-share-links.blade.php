@props(['portalUrl', 'pdfUrl', 'whatsappShare' => null])
<div x-data="{
    copied: '', error: '',
    absolute(value) { return new URL(value, window.location.href).href },
    async copy(value, kind) {
        this.error = '';
        const text = this.absolute(value);
        let done = false;
        try {
            if (navigator.clipboard) { await navigator.clipboard.writeText(text); done = true }
        } catch {}
        if (!done) {
            const previous = document.activeElement;
            const field = document.createElement('textarea');
            field.value = text;
            field.readOnly = true;
            field.style.cssText = 'position:fixed;opacity:0;pointer-events:none;';
            this.$root.append(field);
            field.select();
            try { done = document.execCommand('copy') } catch {}
            field.remove();
            previous?.focus({preventScroll:true});
        }
        if (done) {
            this.copied = kind;
            setTimeout(() => this.copied = '', 2000);
        } else { this.error = 'Copy failed. Select the link and copy it manually.' }
    },
    whatsapp(value, portal) {
        const target = new URL(value, window.location.href);
        const client = new URL(portal, window.location.href);
        const text = (target.searchParams.get('text') || '').split('\n').map(line => {
            try { if (new URL(line).pathname === client.pathname) return client.href } catch {}
            return line;
        }).join('\n');
        target.searchParams.set('text', text);
        return target.href;
    }
}" class="space-y-3">
    @foreach (['page' => ['Client page link', $portalUrl], 'pdf' => ['Client PDF link', $pdfUrl]] as $kind => [$label, $url])
        <div>
            <label class="block text-theme-xs text-gray-500">{{ $label }}
                <span class="mt-1 flex items-center gap-2">
                    <input type="text" readonly value="{{ $url }}" :value="absolute(@js($url))" aria-label="{{ $label }}" class="wx-input h-9 min-w-0 flex-1 text-theme-xs" x-on:focus="$el.select()">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-2 text-theme-xs dark:border-gray-700" x-on:click="copy(@js($url), '{{ $kind }}')" x-text="copied === '{{ $kind }}' ? 'Copied' : 'Copy {{ $kind === 'pdf' ? 'PDF' : 'link' }}'" aria-label="Copy {{ $label }}">Copy</button>
                </span>
            </label>
        </div>
    @endforeach
    <p x-show="error" x-text="error" class="text-theme-xs text-red-700" role="alert"></p>
    <div class="flex flex-wrap gap-3 text-theme-xs">
        <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="text-brand-600">Open client page</a>
        <a href="{{ $pdfUrl }}" target="_blank" rel="noopener" class="text-brand-600">Open shared PDF</a>
        @if ($whatsappShare)
            <a href="{{ $whatsappShare }}" :href="whatsapp(@js($whatsappShare), @js($portalUrl))" target="_blank" rel="noopener" class="text-success-700">Share on WhatsApp</a>
        @endif
    </div>
    @if (app()->environment('local'))
        <p class="text-theme-xs text-amber-700">Preview links may require Arena access. Share with external clients from the deployed site, not this temporary sandbox.</p>
    @endif
</div>
