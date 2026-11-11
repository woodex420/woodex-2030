@use('App\Support\Phone')
<div>
    <x-admin.page-header title="Users & roles" description="Invite your team by email. Each person sets their own password and turns on two-factor sign-in."
                         :breadcrumbs="['System' => null, 'Users & roles' => null]">
        <x-slot:actions>
            <x-admin.button icon="user-plus" wire:click="openInvite">Invite user</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Person</th>
                        <th class="px-5 py-3 font-medium">Role</th>
                        <th class="px-5 py-3 font-medium">Security</th>
                        <th class="px-5 py-3 font-medium">Last sign-in</th>
                        <th class="px-5 py-3 font-medium"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($members as $member)
                        @php $role = $member->getRoleNames()->first(); @endphp
                        <tr wire:key="user-{{ $member->id }}" @class(['opacity-60' => ! $member->is_active])>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-navy-900 text-theme-xs font-semibold text-white">{{ $member->initials() }}</span>
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $member->name }} @if ($member->is(auth()->user()))<span class="text-theme-xs font-normal text-gray-400">(you)</span>@endif</p>
                                        <p class="truncate text-theme-xs text-gray-500">{{ $member->email }}{{ $member->title ? ' · '.$member->title : '' }}{{ $member->phone ? ' · '.Phone::display($member->phone) : '' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <x-admin.badge :color="$role === 'owner' ? 'wood' : 'navy'" :dot="false">{{ $roleLabels[$role] ?? 'No role' }}</x-admin.badge>
                                @unless ($member->is_active)
                                    <x-admin.badge color="error" class="ml-1">Deactivated</x-admin.badge>
                                @endunless
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <x-admin.badge :color="$member->hasTwoFactorEnabled() ? 'success' : 'warning'" :dot="false">{{ $member->hasTwoFactorEnabled() ? '2FA on' : '2FA off' }}</x-admin.badge>
                                    @if ($member->telegramLink?->is_active)
                                        <x-admin.badge color="info" :dot="false">Telegram</x-admin.badge>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap text-theme-xs text-gray-500">{{ $member->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-1">
                                    <x-admin.button size="sm" variant="ghost" icon="pencil" wire:click="openEdit({{ $member->id }})">Edit</x-admin.button>
                                    @if ($member->is_active && $member->last_login_at === null)
                                        <x-admin.button size="sm" variant="ghost" icon="send" wire:click="resendInvite({{ $member->id }})">Resend invite</x-admin.button>
                                    @endif
                                    @unless ($member->is(auth()->user()))
                                        @if ($member->is_active)
                                            <x-admin.button size="sm" variant="ghost" icon="user-x" class="text-error-600" wire:click="toggleActive({{ $member->id }})" wire:confirm="Deactivate {{ $member->name }}? They will be signed out immediately.">Deactivate</x-admin.button>
                                        @else
                                            <x-admin.button size="sm" variant="ghost" icon="user-check" wire:click="toggleActive({{ $member->id }})">Reactivate</x-admin.button>
                                        @endif
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-admin.card>

    <x-admin.card title="What each role can do" class="mt-6">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-theme-sm md:grid-cols-2">
            @foreach ($roles as $key => $description)
                <div class="flex gap-3">
                    <dt class="w-32 shrink-0 font-medium text-gray-800 dark:text-white/90">{{ $roleLabels[$key] ?? $key }}</dt>
                    <dd class="text-gray-600 dark:text-gray-400">{{ $description }}</dd>
                </div>
            @endforeach
        </dl>
    </x-admin.card>

    @if ($showForm)
        <x-admin.modal name="showForm" :title="$editingId ? 'Edit user' : 'Invite a team member'" :description="$editingId ? null : 'They get an email with a link to set their password.'" submit="save">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-admin.field label="Full name" for="name" class="sm:col-span-2">
                    <input id="name" type="text" wire:model="name" class="wx-input" autofocus>
                </x-admin.field>
                <x-admin.field label="Email" for="email" class="sm:col-span-2">
                    <input id="email" type="email" wire:model="email" class="wx-input" @disabled($editingId)>
                </x-admin.field>
                <x-admin.field label="Mobile" for="phone">
                    <input id="phone" type="tel" wire:model="phone" class="wx-input" placeholder="0300 1234567">
                </x-admin.field>
                <x-admin.field label="Job title" for="title">
                    <input id="title" type="text" wire:model="title" class="wx-input" placeholder="Sales Executive">
                </x-admin.field>
                <x-admin.field label="Role" for="role" class="sm:col-span-2">
                    <div class="space-y-2">
                        @foreach ($roles as $key => $description)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 px-3 py-2.5 hover:bg-gray-50 has-[:checked]:border-navy-900 has-[:checked]:bg-navy-50 dark:border-gray-700 dark:hover:bg-white/5 dark:has-[:checked]:bg-white/10">
                                <input type="radio" wire:model="role" value="{{ $key }}" class="mt-0.5">
                                <span>
                                    <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $roleLabels[$key] ?? $key }}</span>
                                    <span class="block text-theme-xs text-gray-500">{{ $description }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </x-admin.field>
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showForm', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" :icon="$editingId ? 'check' : 'send'">{{ $editingId ? 'Save' : 'Send invitation' }}</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
