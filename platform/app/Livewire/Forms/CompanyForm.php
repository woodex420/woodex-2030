<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Domain\Crm\Models\Company;
use App\Support\Phone;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Company fields shared by the create (Companies list) and edit (company page) modals.
 */
class CompanyForm extends Form
{
    public string $name = '';

    public string $type = 'company';

    public string $phone = '';

    public string $email = '';

    public string $website = '';

    public string $city = 'Lahore';

    public string $address = '';

    public string $ntn = '';

    public string $notes = '';

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'type' => ['required', Rule::in(array_keys(Company::TYPES))],
            'phone' => ['nullable', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && ! Phone::isValid((string) $value)) {
                    $fail('Enter a valid phone number, e.g. 042 3578 0000 or 0322 4000768.');
                }
            }],
            'email' => ['nullable', 'email', 'max:160'],
            'website' => ['nullable', 'url:http,https', 'max:191'],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'ntn' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function fillFrom(Company $company): void
    {
        $this->name = $company->name;
        $this->type = (string) $company->type;
        $this->phone = Phone::display($company->phone);
        $this->email = (string) $company->email;
        $this->website = (string) $company->website;
        $this->city = (string) $company->city;
        $this->address = (string) $company->address;
        $this->ntn = (string) $company->ntn;
        $this->notes = (string) $company->notes;
    }

    /**
     * @return array<string, string|null>
     */
    public function payload(): array
    {
        $blank = fn (string $value): ?string => trim($value) === '' ? null : trim($value);

        return [
            'name' => trim($this->name),
            'type' => $this->type,
            'phone' => Phone::normalize($this->phone),
            'email' => $blank(mb_strtolower($this->email)),
            'website' => $blank($this->website),
            'city' => $blank($this->city),
            'address' => $blank($this->address),
            'ntn' => $blank($this->ntn),
            'notes' => $blank($this->notes),
        ];
    }
}
