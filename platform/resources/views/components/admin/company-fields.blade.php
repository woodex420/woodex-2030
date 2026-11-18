{{-- Fields for App\Livewire\Forms\CompanyForm, bound as "form.*". --}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <x-admin.field label="Company name" for="form.name" class="sm:col-span-2">
        <input id="form.name" type="text" wire:model="form.name" class="wx-input" placeholder="Bahria Developers (Pvt) Ltd">
    </x-admin.field>
    <x-admin.field label="Type" for="form.type">
        <select id="form.type" wire:model="form.type" class="wx-input">
            @foreach (\App\Domain\Crm\Models\Company::TYPES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </x-admin.field>
    <x-admin.field label="NTN (optional)" for="form.ntn">
        <input id="form.ntn" type="text" wire:model="form.ntn" class="wx-input" placeholder="1234567-8">
    </x-admin.field>
    <x-admin.field label="Phone" for="form.phone">
        <input id="form.phone" type="tel" wire:model="form.phone" class="wx-input" placeholder="042 3578 0000">
    </x-admin.field>
    <x-admin.field label="Email" for="form.email">
        <input id="form.email" type="email" wire:model="form.email" class="wx-input" placeholder="info@company.pk">
    </x-admin.field>
    <x-admin.field label="Website" for="form.website">
        <input id="form.website" type="url" wire:model="form.website" class="wx-input" placeholder="https://">
    </x-admin.field>
    <x-admin.field label="City" for="form.city">
        <input id="form.city" type="text" wire:model="form.city" class="wx-input">
    </x-admin.field>
    <x-admin.field label="Address" for="form.address" class="sm:col-span-2">
        <input id="form.address" type="text" wire:model="form.address" class="wx-input" placeholder="Office 4, Gulberg III, Lahore">
    </x-admin.field>
    <x-admin.field label="Notes" for="form.notes" class="sm:col-span-2">
        <textarea id="form.notes" wire:model="form.notes" rows="3" class="wx-input"></textarea>
    </x-admin.field>
</div>
