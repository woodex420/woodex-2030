<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#0f1e36">
    <title>Woodex Interior · Woodex OS</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/site.css', 'resources/js/site.js'])
</head>
<body class="min-h-screen bg-navy-900 text-sand-100">
    <main class="mx-auto flex min-h-screen max-w-6xl flex-col justify-between gap-16 px-6 py-10 md:px-10">
        <header class="flex items-center justify-between">
            <x-brand-logo subtitle="INTERIOR STUDIO" class="text-white" />
            <a href="{{ route('login') }}" class="btn-studio">Staff sign in <span aria-hidden="true">↗</span></a>
        </header>

        <section class="grid gap-12 md:grid-cols-2 md:items-end">
            <div>
                <p class="mb-8 text-[13px] font-medium">● The new Woodex website is on its way</p>
                <h1 class="text-[clamp(46px,6vw,88px)] leading-[1.04] text-white">Thoughtful interiors.<br>Natural materials.</h1>
                <p class="mt-8 max-w-md text-navy-100/80">Interior design, fit-out, renovation, architecture and custom furniture in Lahore since 2016.
                    Tell us about your space and our team will get back to you.</p>
            </div>

            {{-- The form is plain HTML (visible before/without JS); Alpine only enhances it. --}}
            <form x-data="leadForm()" @submit.prevent="submit" class="rounded-2xl bg-white p-6 text-body shadow-2xl md:p-8">
                <div x-show="!done" class="space-y-4">
                    <h2 class="text-2xl">Start your project</h2>
                    <input type="text" name="company_website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                    <div>
                        <label class="mb-1 block text-sm text-ink" for="lf-name">Name</label>
                        <input id="lf-name" name="name" required autocomplete="name" class="h-11 w-full rounded-ui border border-line px-3">
                        <p class="mt-1 text-xs text-red-600" x-text="errors.name?.[0]"></p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-ink" for="lf-phone">Phone / WhatsApp</label>
                        <input id="lf-phone" name="phone" type="tel" required autocomplete="tel" placeholder="0322 4000768" class="h-11 w-full rounded-ui border border-line px-3">
                        <p class="mt-1 text-xs text-red-600" x-text="errors.phone?.[0]"></p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-ink" for="lf-service">Service</label>
                        <select id="lf-service" name="service" class="h-11 w-full rounded-ui border border-line px-3">
                            <option value="interior-design">Interior design</option>
                            <option value="renovation">Renovation</option>
                            <option value="fit-out">Office fit-out</option>
                            <option value="architecture">Architecture</option>
                            <option value="furniture">Custom furniture</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-ink" for="lf-message">Message</label>
                        <textarea id="lf-message" name="message" rows="3" class="w-full rounded-ui border border-line px-3 py-2"></textarea>
                    </div>
                    <label class="flex items-start gap-2 text-xs">
                        <input type="checkbox" name="consent" class="mt-0.5"> I agree to be contacted by Woodex on WhatsApp, phone or email about my enquiry.
                    </label>
                    <p class="text-sm text-red-600" x-cloak x-show="error" x-text="error"></p>
                    {{-- Disabled until Alpine starts: without JS the form cannot reach the JSON API (D-13). --}}
                    <button type="submit" disabled :disabled="sending" class="flex h-11 w-full items-center justify-center rounded-full bg-ink text-sm font-medium text-white hover:bg-navy-900 disabled:opacity-60">
                        <span x-text="sending ? 'Sending…' : 'Send enquiry'">Send enquiry</span>
                    </button>
                    <noscript><p class="text-xs text-red-600">Please turn on JavaScript to send this form.</p></noscript>
                </div>
                <div x-cloak x-show="done" class="py-6 text-center" role="status">
                    <h2 class="text-2xl">Thank you!</h2>
                    <p class="mt-3">Your reference is <strong x-text="reference"></strong>. Our team will contact you shortly.</p>
                    <a :href="whatsappUrl" x-show="whatsappUrl" target="_blank" rel="noopener" class="mt-6 inline-flex h-11 items-center rounded-full bg-ink px-6 text-sm font-medium text-white">Continue on WhatsApp</a>
                </div>
            </form>
        </section>

        <footer class="text-xs text-navy-100/60">© {{ date('Y') }} Woodex Interior · M-71 Zainab Tower, Model Town Link Road, Lahore</footer>
    </main>
</body>
</html>
