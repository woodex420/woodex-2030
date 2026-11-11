<div>
    <x-admin.page-header title="Website settings" description="The website's analytics, the chat widget and what is live. No analytics tag is emitted until an ID is filled in here, and none for signed-in staff; the chat bubble appears only while its switch is on."
                         :breadcrumbs="['Website' => null, 'Settings' => null]" />

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <form wire:submit="save" class="contents">
            <x-admin.card title="Tracking" class="xl:col-span-2">
                <div class="space-y-4">
                    <x-admin.field label="Google Analytics 4 measurement ID" for="ga4_id" hint="Analytics → Admin → Data streams. Looks like G-XXXXXXXXXX. Only used when Tag Manager is empty.">
                        <input id="ga4_id" type="text" wire:model="ga4_id" class="wx-input" placeholder="G-XXXXXXXXXX" autocomplete="off">
                    </x-admin.field>

                    <x-admin.field label="Google Tag Manager container" for="gtm_id" hint="Recommended: one container for Analytics, Ads and any other tag, changed without a deploy. Looks like GTM-XXXXXXX.">
                        <input id="gtm_id" type="text" wire:model="gtm_id" class="wx-input" placeholder="GTM-XXXXXXX" autocomplete="off">
                    </x-admin.field>

                    <x-admin.field label="Microsoft Clarity project ID" for="clarity_id" hint="Free session recordings and heatmaps: they show where visitors hesitate on a page.">
                        <input id="clarity_id" type="text" wire:model="clarity_id" class="wx-input" autocomplete="off">
                    </x-admin.field>

                    <x-admin.field label="Google Search Console verification" for="google_site_verification" hint="Search Console → URL prefix → HTML tag: paste the content of the meta tag here (P-13).">
                        <input id="google_site_verification" type="text" wire:model="google_site_verification" class="wx-input" autocomplete="off">
                    </x-admin.field>

                    <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="tracking_enabled" class="rounded border-gray-300">
                        Load analytics on the public website
                    </label>

                    <label class="flex items-start gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="chat_widget_enabled" class="mt-1 rounded border-gray-300">
                        <span>
                            Web chat widget on the public site
                            <span class="block text-theme-xs text-gray-500">A chat bubble on every page. A rule-based flow asks for name, number and service, creates the lead, alerts the team, and the team replies from Conversations — no WhatsApp number needed. Off by default (A0).</span>
                        </span>
                    </label>

                    <p class="text-theme-xs text-gray-500">
                        Analytics does reach the site: every page loads <code class="text-xs">/site/tags.js</code>,
                        which answers with the IDs above — and with nothing at all while the switch is off or for
                        signed-in staff. Search Console verification is the one exception: Google reads the meta
                        tag without running JavaScript, so verify the property with a DNS record instead.
                    </p>
                </div>
            </x-admin.card>

            <x-admin.card title="What is live">
                <ul class="space-y-2 text-theme-sm">
                    @foreach ([['Google Analytics 4', $live['ga4']], ['Google Tag Manager', $live['gtm']], ['Microsoft Clarity', $live['clarity']], ['Search Console verified', $live['gsc']]] as [$name, $on])
                        <li class="flex items-center justify-between gap-3">
                            <span class="text-gray-600 dark:text-gray-300">{{ $name }}</span>
                            <x-admin.badge :color="$on ? 'success' : 'gray'" :dot="true">{{ $on ? 'Connected' : 'Not set' }}</x-admin.badge>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-4 text-theme-xs text-gray-500">
                    Events sent to Analytics (docs/09 §11.3): enquiry form sent, call tapped, WhatsApp opened, estimator used, visit booked.
                    Mark them as conversions in GA4 → Events.
                </p>
                <div class="mt-6 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <x-admin.button type="submit" icon="save">Save everything above</x-admin.button>
                </div>
            </x-admin.card>
        </form>
    </div>
</div>
