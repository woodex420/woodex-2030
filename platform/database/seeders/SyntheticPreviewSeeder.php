<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Support\SyntheticPreview;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/** Explicit disposable-preview command only. Never called by DatabaseSeeder or production upgrades. */
final class SyntheticPreviewSeeder extends Seeder
{
    public function run(): void
    {
        $credentials = SyntheticPreview::credentials();
        if ($credentials === null
            || $credentials['email'] !== 'owner@woodex.test'
            || ! in_array(config('mail.default'), ['array', 'log'], true)
            || ! in_array(config('queue.default'), ['database', 'null'], true)) {
            throw new RuntimeException('Synthetic accounts require an opted-in local/testing isolated SQLite database, non-sending mail and a queue without a worker.');
        }
        if (User::query()->where('email', 'not like', '%@woodex.test')->exists()) {
            throw new RuntimeException('Refusing to seed a database containing non-preview staff accounts.');
        }
        DB::transaction(function () use ($credentials): void {
            foreach ([
                [$credentials['email'], 'Preview Owner', 'owner'],
                ['manager@woodex.test', 'Preview Sales Manager', 'sales_manager'],
                ['accounts@woodex.test', 'Preview Accountant', 'accountant'],
            ] as [$email, $name, $role]) {
                $user = User::query()->updateOrCreate(['email' => $email], [
                    'name' => $name,
                    'password' => Hash::make($credentials['password']),
                    'is_active' => true,
                ]);
                $user->syncRoles([$role]);
            }
        });
        $this->command?->info('Three synthetic staff accounts prepared. Credentials are available only on the opted-in preview login.');
    }
}
