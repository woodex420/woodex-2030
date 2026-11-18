<?php

declare(strict_types=1);

namespace App\Domain\System\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The first owner account (woodex:install on SSH, the web installer on Hostinger Git deploys).
 */
final class CreateOwnerAccount
{
    /**
     * @return array<string, mixed> validation rules for the owner form fields
     */
    public static function rules(): array
    {
        return [
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:190'],
            'owner_password' => ['required', 'string', 'max:200', Password::default()],
        ];
    }

    /**
     * @param  string  $password  plain text, or a hash made by Hash::make() (the web installer keeps only the hash
     *                            between its two steps)
     *
     * @throws ValidationException
     */
    public function handle(string $name, string $email, string $password): User
    {
        $email = Str::lower(trim($email));

        Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'unique:users,email']],
        )->validate();

        $user = User::create([
            'name' => trim($name),
            'email' => $email,
            'password' => Hash::isHashed($password) ? $password : Hash::make($password),
            'title' => 'Director',
            'is_active' => true,
        ]);
        $user->assignRole('owner');

        return $user;
    }
}
