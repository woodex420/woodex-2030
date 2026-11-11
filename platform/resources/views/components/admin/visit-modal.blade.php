@props(['staff', 'conflicts' => []])
{{-- Site visit booking form for components using App\Livewire\Concerns\BooksSiteVisits. --}}
<x-admin.modal name="showVisit" title="Book a site visit" submit="scheduleVisit"
               description="The visit becomes the lead's next action. The team member gets a Telegram reminder the day before and 2 hours before.">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-admin.field label="Date and time" for="visitAt">
            <input id="visitAt" type="datetime-local" wire:model="visitAt" class="wx-input">
        </x-admin.field>
        <x-admin.field label="Duration" for="visitDuration">
            <select id="visitDuration" wire:model="visitDuration" class="wx-input">
                @foreach ([30 => '30 minutes', 60 => '1 hour', 90 => '1½ hours', 120 => '2 hours', 180 => '3 hours'] as $minutes => $label)
                    <option value="{{ $minutes }}">{{ $label }}</option>
                @endforeach
            </select>
        </x-admin.field>
        <x-admin.field label="Address" for="visitAddress" class="sm:col-span-2">
            <input id="visitAddress" type="text" wire:model="visitAddress" class="wx-input" placeholder="House 12, Street 4, DHA Phase 6, Lahore">
        </x-admin.field>
        <x-admin.field label="Team member" for="visitAssignee" class="sm:col-span-2">
            <select id="visitAssignee" wire:model="visitAssignee" class="wx-input">
                <option value="">Unassigned</option>
                @foreach ($staff as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </select>
        </x-admin.field>
    </div>

    @if ($conflicts !== [])
        <div class="mt-4 rounded-xl border border-warning-200 bg-warning-50 p-3 text-theme-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300" role="alert">
            <p class="flex items-center gap-2 font-medium"><x-icon name="alert" class="size-4" /> Double booking</p>
            <ul class="mt-1 list-disc pl-6">
                @foreach ($conflicts as $conflict)
                    <li>{{ $conflict }}</li>
                @endforeach
            </ul>
            <p class="mt-1">Change the time or team member, or book anyway.</p>
        </div>
    @endif

    <x-slot:footer>
        <x-admin.button variant="secondary" wire:click="cancelVisit">Cancel</x-admin.button>
        <x-admin.button type="submit" icon="calendar-check">{{ $conflicts !== [] ? 'Book anyway' : 'Book visit' }}</x-admin.button>
    </x-slot:footer>
</x-admin.modal>
