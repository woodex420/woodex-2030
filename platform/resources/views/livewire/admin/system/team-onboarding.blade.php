@php
    // Short words: the list has to show its one button on a laptop screen without being pushed sideways.
    $links = [
        'used' => ['label' => 'Set', 'color' => 'success', 'more' => null],
        'waiting' => ['label' => 'Link sent', 'color' => 'info', 'more' => 'not used yet'],
        'expired' => ['label' => 'Link ran out', 'color' => 'warning', 'more' => null],
        'none' => ['label' => 'No link', 'color' => 'warning', 'more' => 'send one'],
    ];
    $twoFactor = [
        'on' => ['label' => 'On', 'color' => 'success'],
        'missing' => ['label' => 'Required, off', 'color' => 'error'],
        'optional' => ['label' => 'Not required', 'color' => 'gray'],
    ];
@endphp
<div>
    <x-admin.page-header title="Team onboarding"
                         description="How far each member is on the way into the workspace: invited, password set, signed in, two-factor sign-in."
                         :breadcrumbs="['System' => null, 'Team onboarding' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="users" :href="route('admin.users.index')" wire:navigate>Invite a member</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" data-onboarding-summary>
        @foreach (['members' => 'Active members', 'through' => 'Through', 'not_signed_in' => 'Have not signed in', 'two_factor_missing' => 'Two-factor missing'] as $key => $label)
            <div class="wx-surface wx-card rounded-2xl border border-gray-200 px-4 py-3 dark:border-gray-800">
                <p class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase">{{ $label }}</p>
                <p class="mt-1 text-2xl leading-8 font-bold tracking-tight tabular-nums text-gray-900 dark:text-white" data-onboarding-count="{{ $key }}">{{ $counts[$key] }}</p>
            </div>
        @endforeach
    </div>

    <x-admin.card class="mb-6" title="How a member joins">
        <ol class="list-decimal space-y-1.5 pl-5 text-theme-sm text-gray-700 dark:text-gray-200">
            <li>You invite them on <a href="{{ route('admin.users.index') }}" wire:navigate class="font-medium text-brand-600 underline-offset-2 hover:underline dark:text-brand-300">Access &amp; team</a>, with a role.</li>
            <li>They get an email with a link to set their own password. The link works for {{ $linkMinutes }} minutes; after that, send it again from this screen.</li>
            <li>Their first sign-in opens a welcome: protect the sign-in, choose how the workspace looks and where it opens, see what they can open.</li>
            <li>
                @if ($twoFactorEnforced && $twoFactorRoles !== [])
                    Two-factor sign-in is required for: {{ implode(', ', $twoFactorRoles) }}. A member with such a role reaches no other screen until it is on.
                @else
                    Two-factor sign-in is not required for any role in this installation. Each member can switch it on for their own account.
                @endif
            </li>
        </ol>
    </x-admin.card>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-200 text-theme-xs text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Member</th>
                        <th scope="col" class="px-4 py-3 font-medium">Password</th>
                        <th scope="col" class="px-4 py-3 font-medium">Two-factor</th>
                        <th scope="col" class="px-4 py-3 font-medium">Stands</th>
                        <th scope="col" class="px-4 py-3 font-medium"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    @foreach ($members as $member)
                        <tr wire:key="member-{{ $member['id'] }}" data-member="{{ $member['email'] }}" data-through="{{ $member['through'] ? 'yes' : 'no' }}">
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-900 dark:text-white">{{ $member['name'] }}</p>
                                <p class="text-theme-xs text-gray-500">{{ $member['email'] }}</p>
                                <p class="text-theme-xs text-gray-500">{{ $member['role'] !== '' ? $member['role'] : 'no role' }} · invited {{ $member['invited']?->format('j M Y') ?? 'before records were kept' }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <x-admin.badge :color="$links[$member['link']]['color']">{{ $links[$member['link']]['label'] }}</x-admin.badge>
                                @if ($links[$member['link']]['more'] || $member['link_sent'])
                                    <p class="mt-1 text-theme-xs whitespace-nowrap text-gray-500">{{ implode(' · ', array_filter([$links[$member['link']]['more'], $member['link_sent'] ? 'sent '.$member['link_sent']->diffForHumans() : null])) }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3"><x-admin.badge :color="$twoFactor[$member['two_factor']]['color']">{{ $twoFactor[$member['two_factor']]['label'] }}</x-admin.badge></td>
                            <td class="px-4 py-3">
                                @if ($member['through'])
                                    <x-admin.badge color="success">Through</x-admin.badge>
                                @else
                                    <x-admin.badge color="warning">{{ $member['signed_in'] ? 'Two-factor missing' : 'Waiting to sign in' }}</x-admin.badge>
                                @endif
                                <p class="mt-1 text-theme-xs whitespace-nowrap text-gray-500">{{ $member['signed_in'] ? 'last sign-in '.$member['signed_in']->diffForHumans() : 'has not signed in' }}</p>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @unless ($member['signed_in'])
                                    <x-admin.button size="sm" variant="secondary" wire:click="sendAgain({{ $member['id'] }})" aria-label="Send the link again to {{ $member['name'] }}">Send link again</x-admin.button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-admin.card>
</div>
