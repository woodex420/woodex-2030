<div>
    <x-admin.page-header title="Settings" description="Business details appear on quotations, invoices and the website. Sales rules drive SLAs, discounts and reports."
                         :breadcrumbs="['System' => null, 'Settings' => null]" />

    <div class="wx-settings-tabs mb-6 flex max-w-full flex-wrap gap-1 rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-white/[0.03]" role="tablist" aria-label="Settings sections">
        @foreach (['business' => 'Business', 'sales' => 'Sales rules', 'leads' => 'Lead rules', 'notifications' => 'Notifications', 'whatsapp' => 'WhatsApp', 'bot' => 'Bot'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['rounded-md px-4 py-1.5 text-theme-sm font-medium transition', 'bg-navy-900 text-white dark:bg-white dark:text-navy-900' => $tab === $key, 'text-gray-600 hover:text-gray-900 dark:text-gray-400' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    {{-- Business --}}
    @if ($tab === 'business')
        <form wire:submit="saveBusiness" class="space-y-6">
            <x-admin.card title="Identity" description="Shown on documents and the website footer.">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-admin.field label="Legal name" for="business.legal_name"><input id="business.legal_name" type="text" wire:model="business.legal_name" class="wx-input"></x-admin.field>
                    <x-admin.field label="Brand name" for="business.brand_name"><input id="business.brand_name" type="text" wire:model="business.brand_name" class="wx-input"></x-admin.field>
                    <x-admin.field label="Furniture brand" for="business.furniture_brand_name"><input id="business.furniture_brand_name" type="text" wire:model="business.furniture_brand_name" class="wx-input"></x-admin.field>
                    <x-admin.field label="Founded" for="business.founded_year"><input id="business.founded_year" type="number" wire:model="business.founded_year" class="wx-input"></x-admin.field>
                    <x-admin.field label="Tagline" for="business.tagline" class="md:col-span-2"><input id="business.tagline" type="text" wire:model="business.tagline" class="wx-input"></x-admin.field>
                    <x-admin.field label="NTN (optional)" for="business.ntn"><input id="business.ntn" type="text" wire:model="business.ntn" class="wx-input"></x-admin.field>
                    <x-admin.field label="Website" for="business.website"><input id="business.website" type="url" wire:model="business.website" class="wx-input"></x-admin.field>
                </div>
            </x-admin.card>

            <x-admin.card title="Contact" description="The WhatsApp number receives website chats and is printed on quotations.">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-admin.field label="Phone" for="business.phone"><input id="business.phone" type="tel" wire:model="business.phone" class="wx-input"></x-admin.field>
                    <x-admin.field label="WhatsApp" for="business.whatsapp"><input id="business.whatsapp" type="tel" wire:model="business.whatsapp" class="wx-input"></x-admin.field>
                    <x-admin.field label="Email" for="business.email"><input id="business.email" type="email" wire:model="business.email" class="wx-input"></x-admin.field>
                    <x-admin.field label="City" for="business.city"><input id="business.city" type="text" wire:model="business.city" class="wx-input"></x-admin.field>
                    <x-admin.field label="Studio address" for="business.address" class="md:col-span-2"><input id="business.address" type="text" wire:model="business.address" class="wx-input"></x-admin.field>
                </div>
            </x-admin.card>

            <x-admin.card title="Working hours" description="Response-time targets only count working hours, so a lead at 11 pm is due the next morning.">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <x-admin.field label="Opens" for="business.opens_at"><input id="business.opens_at" type="time" wire:model="business.opens_at" class="wx-input"></x-admin.field>
                    <x-admin.field label="Closes" for="business.closes_at"><input id="business.closes_at" type="time" wire:model="business.closes_at" class="wx-input"></x-admin.field>
                    <x-admin.field label="Working days" error="business.working_days" class="md:col-span-3">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($days as $number => $label)
                                <label class="cursor-pointer">
                                    <input type="checkbox" wire:model="business.working_days" value="{{ $number }}" class="peer sr-only">
                                    <span class="inline-flex min-w-14 justify-center rounded-lg border border-gray-200 px-3 py-2 text-theme-sm text-gray-600 peer-checked:border-navy-900 peer-checked:bg-navy-900 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-navy-300 dark:border-gray-700 dark:text-gray-300 dark:peer-checked:bg-white dark:peer-checked:text-navy-900">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </x-admin.field>
                </div>
            </x-admin.card>

            <x-admin.card title="Holidays" description="Closed days pause response timers and reminders (AUT-004). Add Eid and other moon-dependent holidays when they are announced.">
                <x-admin.field label="One per line: date and name" for="business.holidays" hint="Example: 2027-03-10 Eid ul Fitr (day 1)">
                    <textarea id="business.holidays" wire:model="business.holidays" rows="6" class="wx-input font-mono text-theme-xs"></textarea>
                </x-admin.field>
            </x-admin.card>



            <x-admin.card title="Social profiles" description="Used in the website footer and structured data.">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach (\App\Livewire\Admin\System\SettingsPage::SOCIAL_NETWORKS as $key => $label)
                        <x-admin.field :label="$label" for="business.social_links.{{ $key }}">
                            <input id="business.social_links.{{ $key }}" type="url" wire:model="business.social_links.{{ $key }}" class="wx-input" placeholder="https://">
                        </x-admin.field>
                    @endforeach
                </div>
            </x-admin.card>

            <div class="flex justify-end"><x-admin.button type="submit" icon="check">Save business settings</x-admin.button></div>
        </form>
    @endif

    {{-- Sales --}}
    {{-- WhatsApp --}}
    @if ($tab === 'whatsapp')
        <form wire:submit="saveWhatsApp" class="space-y-6">
            <x-admin.card title="WhatsApp Cloud API" description="The number the studio writes from. Saved here, it is encrypted in the database; left empty, the server .env is used instead. Nothing saved here is ever shown back.">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-admin.field label="Phone number id" for="whatsapp.phone_number_id" hint="Meta → WhatsApp → API Setup. A long number.">
                        <input id="whatsapp.phone_number_id" type="text" wire:model="whatsapp.phone_number_id" class="wx-input" autocomplete="off" inputmode="numeric">
                    </x-admin.field>
                    <x-admin.field label="WhatsApp Business Account id" for="whatsapp.waba_id" hint="Needed to read and submit templates.">
                        <input id="whatsapp.waba_id" type="text" wire:model="whatsapp.waba_id" class="wx-input" autocomplete="off" inputmode="numeric">
                    </x-admin.field>
                    <x-admin.field label="Access token" for="whatsapp.token" hint="{{ $whatsappHints['token'] }}">
                        <input id="whatsapp.token" type="password" wire:model="whatsapp.token" class="wx-input" autocomplete="new-password" placeholder="{{ $whatsappSaved['token'] !== '' ? '•••••••• (saved, unchanged)' : '' }}">
                    </x-admin.field>
                    <x-admin.field label="App secret" for="whatsapp.app_secret" hint="{{ $whatsappHints['app_secret'] }}">
                        <input id="whatsapp.app_secret" type="password" wire:model="whatsapp.app_secret" class="wx-input" autocomplete="new-password" placeholder="{{ $whatsappSaved['app_secret'] !== '' ? '•••••••• (saved, unchanged)' : '' }}">
                    </x-admin.field>
                    <x-admin.field label="Webhook verify token" for="whatsapp.verify_token" hint="{{ $whatsappHints['verify_token'] }}">
                        <input id="whatsapp.verify_token" type="password" wire:model="whatsapp.verify_token" class="wx-input" autocomplete="new-password" placeholder="{{ $whatsappSaved['verify_token'] !== '' ? '•••••••• (saved, unchanged)' : '' }}">
                    </x-admin.field>
                    <x-admin.field label="Graph API version" for="whatsapp.api_version" hint="Meta retires old versions; keep it current.">
                        <input id="whatsapp.api_version" type="text" wire:model="whatsapp.api_version" class="wx-input" placeholder="v23.0">
                    </x-admin.field>
                    <x-admin.field label="Cost per message (Rs)" for="whatsapp.text_cost" hint="What Meta charges for one free-form message. 0 = count only, no rupee estimate.">
                        <input id="whatsapp.text_cost" type="number" min="0" max="100000" wire:model="whatsapp.text_cost" class="wx-input" inputmode="numeric">
                    </x-admin.field>
                    <x-admin.field label="Cost per template (Rs)" for="whatsapp.template_cost" hint="What Meta charges for one template message. Templates are priced separately.">
                        <input id="whatsapp.template_cost" type="number" min="0" max="100000" wire:model="whatsapp.template_cost" class="wx-input" inputmode="numeric">
                    </x-admin.field>
                    <x-admin.field label="Monthly budget (Rs)" for="whatsapp.monthly_budget" hint="You get one alert the month the estimate crosses this. 0 = no alert.">
                        <input id="whatsapp.monthly_budget" type="number" min="0" max="10000000" wire:model="whatsapp.monthly_budget" class="wx-input" inputmode="numeric">
                    </x-admin.field>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <x-admin.button type="submit" icon="check">Save</x-admin.button>
                    <x-admin.button variant="secondary" icon="trash" wire:click="forgetWhatsApp"
                                    wire:confirm="Remove the saved credentials? The server .env will be used again.">Remove saved credentials</x-admin.button>
                    @if ($whatsappSource === 'env')
                        <span class="text-theme-xs text-gray-500">Using the server .env — nothing is saved here yet.</span>
                    @endif
                </div>
            </x-admin.card>
        </form>

        <x-admin.card title="Webhook" description="Paste these into Meta → WhatsApp → Configuration. Meta calls the URL to deliver messages and delivery receipts.">
            <dl class="grid grid-cols-1 gap-3 text-theme-sm">
                <div>
                    <dt class="text-gray-500">Callback URL</dt>
                    <dd class="break-all font-mono text-theme-xs text-gray-800 dark:text-white/90">{{ route('webhooks.whatsapp') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Verify token</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ $whatsappSaved['verify_token'] !== '' ? 'the one saved above' : 'set it above, then paste the same value into Meta' }}</dd>
                </div>
            </dl>
        </x-admin.card>

        <x-admin.card title="Test connection" description="Credentials that are merely valid have never delivered a message. Give a WhatsApp number and Woodex OS will send one to it.">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <x-admin.field label="Send a test message to" for="whatsapp.probe_number" hint="With country code, e.g. 923001234567. Leave empty to only check the credentials." class="w-full sm:max-w-sm">
                    <input id="whatsapp.probe_number" type="text" wire:model="whatsapp.probe_number" class="wx-input" inputmode="tel" autocomplete="off">
                </x-admin.field>
                <x-admin.button variant="secondary" icon="send" wire:click="testWhatsAppConnection" wire:loading.attr="disabled">Test connection</x-admin.button>
                <x-admin.button variant="secondary" icon="message" :href="route('admin.whatsapp.templates')" wire:navigate>Templates</x-admin.button>
            </div>

            @if ($whatsappStatus !== null)
                <div class="mt-4 rounded-lg border border-gray-200 p-4 text-theme-xs text-gray-600 dark:border-gray-800 dark:text-gray-300" role="status">
                    <p class="@if ($whatsappStatus['ok']) text-success-600 @else text-error-600 @endif font-medium">
                        {{ $whatsappStatus['ok'] ? 'Connected' : 'Not connected' }}
                    </p>
                    @if ($whatsappStatus['name'] || $whatsappStatus['phone'])
                        <p>Number: {{ $whatsappStatus['phone'] }}@if ($whatsappStatus['name']) · {{ $whatsappStatus['name'] }}@endif</p>
                    @endif
                    @if ($whatsappStatus['quality'])
                        <p>Quality rating: {{ \Illuminate\Support\Str::headline($whatsappStatus['quality']) }}</p>
                    @endif
                    @if ($whatsappStatus['probe'] === true)
                        <p>Test message: sent and accepted by Meta.</p>
                    @elseif ($whatsappStatus['probe'] === false)
                        <p>Test message: refused by Meta.</p>
                    @endif
                    @if ($whatsappStatus['error'])
                        <p class="text-error-600">{{ $whatsappStatus['error'] }}</p>
                    @endif
                </div>
            @endif
        </x-admin.card>
    @endif
    @if ($tab === 'sales')
        <form wire:submit="saveSales" class="space-y-6">
            <x-admin.card title="Quotations and invoices">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <x-admin.field label="Quotation valid for (days)" for="sales.quote_validity_days"><input id="sales.quote_validity_days" type="number" min="1" wire:model="sales.quote_validity_days" class="wx-input"></x-admin.field>
                    <x-admin.field label="Invoice due after (days)" for="sales.invoice_due_days"><input id="sales.invoice_due_days" type="number" min="0" wire:model="sales.invoice_due_days" class="wx-input"></x-admin.field>
                    <x-admin.field label="Minimum margin % (optional)" for="sales.min_margin" hint="Quotations below this need approval."><input id="sales.min_margin" type="number" step="0.5" min="0" max="100" wire:model="sales.min_margin" class="wx-input"></x-admin.field>
                    <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="sales.round_totals_to_rupee" class="rounded border-gray-300"> Round totals to whole rupees</label>
                    <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300 md:col-span-2"><input type="checkbox" wire:model="sales.lakh_grouping" class="rounded border-gray-300"> Show amounts in lakh format (12,50,000 instead of 1,250,000)</label>
                    <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300 md:col-span-3"><input type="checkbox" wire:model="sales.quote_follow_ups_enabled" class="rounded border-gray-300"> Chase quotations nobody answered (the team is told to call; the system never writes to a client by itself)</label>
                    <x-admin.field label="Chase after (quiet days)" for="sales.quote_follow_up_days" hint="How long a quotation may sit unanswered before the team is told to call. Three chases, then it is left alone."><input id="sales.quote_follow_up_days" type="number" min="1" max="60" wire:model="sales.quote_follow_up_days" class="wx-input"></x-admin.field>
                    <x-admin.field label="Warn before it lapses (days)" for="sales.quote_expiry_warning_days" hint="Told once per quotation, while the price can still be saved."><input id="sales.quote_expiry_warning_days" type="number" min="1" max="60" wire:model="sales.quote_expiry_warning_days" class="wx-input"></x-admin.field>
                </div>
            </x-admin.card>

            <x-admin.card title="Discount limits" description="Above these, a quotation needs approval from someone with a higher limit.">
                <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                    @foreach (\App\Livewire\Admin\System\SettingsPage::DISCOUNT_ROLES as $role => $label)
                        <x-admin.field :label="$label" for="sales.discount_limits.{{ $role }}">
                            <div class="relative">
                                <input id="sales.discount_limits.{{ $role }}" type="number" step="0.5" min="0" max="100" wire:model="sales.discount_limits.{{ $role }}" class="wx-input pr-8">
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-theme-sm text-gray-400">%</span>
                            </div>
                        </x-admin.field>
                    @endforeach
                </div>
            </x-admin.card>

            <x-admin.card title="Leads">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div class="space-y-4">
                        <x-admin.field label="First response target (working minutes)" for="sales.sla_first_response_minutes" hint="New leads must be called or messaged within this time, or managers are alerted.">
                            <input id="sales.sla_first_response_minutes" type="number" min="5" wire:model="sales.sla_first_response_minutes" class="wx-input">
                        </x-admin.field>
                        <x-admin.field label="Lost reasons (one per line)" for="sales.lost_reasons">
                            <textarea id="sales.lost_reasons" wire:model="sales.lost_reasons" rows="7" class="wx-input h-auto py-2.5"></textarea>
                        </x-admin.field>
                    </div>
                    <div>
                        <span class="wx-label">Budget bands</span>
                        <p class="mb-2 text-theme-xs text-gray-500">Keys are stored on leads, so keep them stable; labels can change any time.</p>
                        <div class="space-y-2">
                            @foreach ($sales['budget_bands'] as $i => $band)
                                <div class="grid grid-cols-[7rem_1fr_2rem] gap-2" wire:key="band-{{ $i }}">
                                    <input type="text" wire:model="sales.budget_bands.{{ $i }}.key" class="wx-input font-mono text-theme-xs" placeholder="3m-7m" aria-label="Key">
                                    <input type="text" wire:model="sales.budget_bands.{{ $i }}.label" class="wx-input" placeholder="Rs 30–70 lakh" aria-label="Label">
                                    <button type="button" wire:click="removeBand({{ $i }})" class="text-gray-400 hover:text-error-600" aria-label="Remove band"><x-icon name="x" class="size-4" /></button>
                                </div>
                            @endforeach
                        </div>
                        @error('sales.budget_bands.*.key') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                        @error('sales.budget_bands.*.label') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="addBand" class="mt-2 inline-flex items-center gap-1 text-theme-sm font-medium text-navy-700 hover:underline dark:text-gray-300"><x-icon name="plus" class="size-4" /> Add band</button>
                    </div>
                </div>
            </x-admin.card>

            <x-admin.card title="Terms and signatures" description="Printed at the end of every quotation.">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-admin.field label="Execution terms" for="sales.execution_terms"><textarea id="sales.execution_terms" wire:model="sales.execution_terms" rows="6" class="wx-input h-auto py-2.5"></textarea></x-admin.field>
                    <x-admin.field label="Design terms" for="sales.design_terms"><textarea id="sales.design_terms" wire:model="sales.design_terms" rows="6" class="wx-input h-auto py-2.5"></textarea></x-admin.field>
                    <div class="md:col-span-2">
                        <span class="wx-label">Signatories</span>
                        <div class="space-y-2">
                            @foreach ($sales['signatories'] as $i => $signatory)
                                <div class="grid grid-cols-[1fr_1fr_2rem] gap-2" wire:key="sig-{{ $i }}">
                                    <input type="text" wire:model="sales.signatories.{{ $i }}.name" class="wx-input" placeholder="Name" aria-label="Name">
                                    <input type="text" wire:model="sales.signatories.{{ $i }}.title" class="wx-input" placeholder="Title" aria-label="Title">
                                    <button type="button" wire:click="removeSignatory({{ $i }})" class="text-gray-400 hover:text-error-600" aria-label="Remove signatory"><x-icon name="x" class="size-4" /></button>
                                </div>
                            @endforeach
                        </div>
                        @error('sales.signatories.*.name') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                        @if (count($sales['signatories']) < 4)
                            <button type="button" wire:click="addSignatory" class="mt-2 inline-flex items-center gap-1 text-theme-sm font-medium text-navy-700 hover:underline dark:text-gray-300"><x-icon name="plus" class="size-4" /> Add signatory</button>
                        @endif
                    </div>
                </div>
            </x-admin.card>

            <div class="flex justify-end"><x-admin.button type="submit" icon="check">Save sales rules</x-admin.button></div>
        </form>
    @endif

    {{-- Lead rules (own component) --}}
    @if ($tab === 'leads')
        <livewire:admin.system.lead-rules-settings />
    @endif

    {{-- Notifications --}}
    @if ($tab === 'notifications')
        <form wire:submit="saveNotifications" class="space-y-6">
            <x-admin.card title="Telegram alerts" description="Each person links their own Telegram under Security & Telegram.">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <label class="flex items-center gap-2 text-theme-sm text-gray-700 md:col-span-3 dark:text-gray-300"><input type="checkbox" wire:model="notifications.telegram_enabled" class="rounded border-gray-300"> Send alerts to Telegram</label>
                    <label class="flex items-center gap-2 text-theme-sm text-gray-700 md:col-span-3 dark:text-gray-300"><input type="checkbox" wire:model="notifications.chat_alert_sound" class="rounded border-gray-300"> Play a sound when a website chat message arrives</label>
                    <x-admin.field label="Quiet hours start" for="notifications.quiet_hours_start" hint="Non-urgent alerts wait until quiet hours end."><input id="notifications.quiet_hours_start" type="time" wire:model="notifications.quiet_hours_start" class="wx-input"></x-admin.field>
                    <x-admin.field label="Quiet hours end" for="notifications.quiet_hours_end"><input id="notifications.quiet_hours_end" type="time" wire:model="notifications.quiet_hours_end" class="wx-input"></x-admin.field>
                    <x-admin.field label="Daily summary at" for="notifications.daily_digest_time"><input id="notifications.daily_digest_time" type="time" wire:model="notifications.daily_digest_time" class="wx-input"></x-admin.field>
                </div>
            </x-admin.card>
            <x-admin.card title="Escalation" description="Who hears about unassigned leads and missed response times.">
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    @foreach ($roles as $key => $description)
                        <label class="flex items-center gap-3 rounded-lg border border-gray-200 px-3 py-2.5 text-theme-sm hover:bg-gray-50 has-[:checked]:border-navy-900 has-[:checked]:bg-navy-50 dark:border-gray-700 dark:hover:bg-white/5 dark:has-[:checked]:bg-white/10">
                            <input type="checkbox" wire:model="notifications.unassigned_lead_roles" value="{{ $key }}" class="rounded border-gray-300">
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ \Illuminate\Support\Str::headline($key) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('notifications.unassigned_lead_roles') <p class="mt-2 text-theme-xs text-error-600">{{ $message }}</p> @enderror
            </x-admin.card>
            <x-admin.card title="Final escalation" description="Alerted when a missed response is still open after the escalation delay (Lead rules tab).">
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    @foreach ($roles as $key => $description)
                        <label class="flex items-center gap-3 rounded-lg border border-gray-200 px-3 py-2.5 text-theme-sm hover:bg-gray-50 has-[:checked]:border-navy-900 has-[:checked]:bg-navy-50 dark:border-gray-700 dark:hover:bg-white/5 dark:has-[:checked]:bg-white/10">
                            <input type="checkbox" wire:model="notifications.sla_final_roles" value="{{ $key }}" class="rounded border-gray-300">
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ \Illuminate\Support\Str::headline($key) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('notifications.sla_final_roles') <p class="mt-2 text-theme-xs text-error-600">{{ $message }}</p> @enderror
            </x-admin.card>
            <div class="flex justify-end"><x-admin.button type="submit" icon="check">Save notification settings</x-admin.button></div>
        </form>
    @endif

    @if ($tab === 'bot')
        @php($botSettings = app(\App\Settings\BotSettings::class))
        @php($botUsageRows = \App\Domain\Bot\Models\BotUsage::query()->whereDate('usage_date', today())->get())
        @php($botKnowledge = \App\Domain\Bot\Models\BotKnowledge::query()->orderByDesc('id')->limit(30)->get())
        <form wire:submit="saveBot">
            <x-admin.card title="Website assistant" description="The chat widget's brain (BOT-002). It ships keyless: with no provider key, every question gets an honest 'a human will reply' and the team is alerted — the machinery works today, the thinking starts the day a key arrives. Guardrails are enforced in code, not asked for politely: no invented prices, no promised dates, no discounts, no other client's data.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-admin.field label="Assistant" for="bot.enabled" hint="Off: the widget stays the rule-based intake and the team answers live threads. On: the bot answers live threads too.">
                        <label class="flex items-center gap-2 pt-2"><input id="bot.enabled" type="checkbox" wire:model="bot.enabled" class="size-4 rounded border-gray-300"> <span class="text-theme-sm">Answer live chats</span></label>
                    </x-admin.field>
                    <x-admin.field label="Only outside studio hours" for="bot.outside_hours_only" hint="BOT-010: during working hours the team answers; the bot covers the evening.">
                        <label class="flex items-center gap-2 pt-2"><input id="bot.outside_hours_only" type="checkbox" wire:model="bot.outside_hours_only" class="size-4 rounded border-gray-300"> <span class="text-theme-sm">Evenings only</span></label>
                    </x-admin.field>
                    <x-admin.field label="Provider" for="bot.provider" hint="'none' is the keyless a-human-will-reply mode.">
                        <select id="bot.provider" wire:model="bot.provider" class="wx-input">
                            <option value="none">None — a human will reply</option>
                            <option value="anthropic">Claude (Anthropic)</option>
                            <option value="openai">OpenAI</option>
                            <option value="gemini">Gemini (Google)</option>
                        </select>
                    </x-admin.field>
                    <x-admin.field label="Model" for="bot.model" hint="Empty = the provider's default (claude-sonnet-4-5 / gpt-4o-mini / gemini-2.0-flash).">
                        <input id="bot.model" type="text" wire:model="bot.model" class="wx-input" placeholder="provider default">
                    </x-admin.field>
                    <x-admin.field label="API key" for="bot.api_key" hint="Encrypted at rest, never shown back. Empty keeps the saved key.">
                        <input id="bot.api_key" type="password" wire:model="bot.api_key" class="wx-input" autocomplete="new-password" placeholder="{{ $botSettings->api_key !== null && $botSettings->api_key !== '' ? '•••••••• (saved, unchanged)' : '' }}">
                    </x-admin.field>
                    <x-admin.field label="Daily token cap" for="bot.daily_token_cap" hint="BOT-008: when the day's tokens are spent, questions get the honest fallback. 0 = no cap.">
                        <input id="bot.daily_token_cap" type="number" min="0" max="10000000" wire:model="bot.daily_token_cap" class="wx-input" inputmode="numeric">
                    </x-admin.field>
                    <x-admin.field label="Bot replies per thread" for="bot.conversation_turn_limit" hint="BOT-008: after this many bot replies on one thread, a human takes over. 0 = no limit.">
                        <input id="bot.conversation_turn_limit" type="number" min="0" max="200" wire:model="bot.conversation_turn_limit" class="wx-input" inputmode="numeric">
                    </x-admin.field>
                    <div class="sm:col-span-2">
                        <x-admin.field label="System prompt" for="bot.system_prompt" hint="Empty = the built-in studio persona with its guardrails (shown under the box). Your own words replace it entirely — keep the rules in them.">
                            <textarea id="bot.system_prompt" wire:model="bot.system_prompt" rows="5" class="wx-input" placeholder="{{ $botSettings->prompt() }}"></textarea>
                        </x-admin.field>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-admin.button type="submit" icon="check">Save</x-admin.button>
                    @if ($botUsageRows->isNotEmpty())
                        <span class="text-theme-xs text-gray-500">Today: {{ $botUsageRows->sum('replies') }} replies · {{ $botUsageRows->sum('tokens_in') + $botUsageRows->sum('tokens_out') }} tokens · {{ $botUsageRows->sum('handoffs') }} hand-offs · {{ $botUsageRows->sum('guardrail_hits') }} guardrail hit(s)</span>
                    @else
                        <span class="text-theme-xs text-gray-500">No bot traffic today.</span>
                    @endif
                </div>
            </x-admin.card>
        </form>

        <x-admin.card class="mt-4" title="Knowledge" description="BOT-003: what the bot may state as fact. Pages are mirrored from the CMS with one click; the studio's own answers live beside them.">
            <div class="flex flex-wrap items-center gap-2">
                <x-admin.button variant="secondary" icon="refresh" wire:click="syncBotKnowledge" wire:confirm.lazy="Re-read every published page into the bot's knowledge?">Sync from published pages</x-admin.button>
                <span class="text-theme-xs text-gray-500">{{ $botKnowledge->where('source', 'cms')->count() }} page entries · {{ $botKnowledge->where('source', 'manual')->count() }} manual</span>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <x-admin.field label="Add your own answer" for="botKnowledgeTitle">
                    <input id="botKnowledgeTitle" type="text" wire:model="botKnowledgeTitle" class="wx-input" placeholder="Question or topic, e.g. 'Do you work in DHA Lahore?'">
                </x-admin.field>
                <x-admin.field label="The answer" for="botKnowledgeBody">
                    <textarea id="botKnowledgeBody" wire:model="botKnowledgeBody" rows="3" class="wx-input" placeholder="Yes — Woodex Interior designs and installs across DHA Lahore…"></textarea>
                </x-admin.field>
            </div>
            <div class="mt-2"><x-admin.button variant="secondary" icon="plus" wire:click="addBotKnowledge">Add entry</x-admin.button></div>
            <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($botKnowledge as $entry)
                    <li class="flex items-start gap-3 py-2.5" wire:key="botk-{{ $entry->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $entry->title }}
                                <x-admin.badge color="{{ $entry->source === 'cms' ? 'gray' : 'navy' }}">{{ $entry->source === 'cms' ? 'page' : 'manual' }}</x-admin.badge>
                            </p>
                            <p class="line-clamp-1 text-theme-xs text-gray-500">{{ $entry->content }}</p>
                        </div>
                        @if ($entry->source === 'manual')
                            <button type="button" wire:click="deleteBotKnowledge({{ $entry->id }})" wire:confirm="Remove this entry?" class="rounded-lg p-1.5 text-gray-400 hover:bg-error-50 hover:text-error-600" title="Remove"><x-icon name="trash" class="size-4" /></button>
                        @endif
                    </li>
                @empty
                    <li class="py-3 text-center text-theme-sm text-gray-500">Nothing yet — sync the pages or add your own answer.</li>
                @endforelse
            </ul>
        </x-admin.card>
    @endif
</div>
