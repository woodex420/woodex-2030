<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Actions\CreateOwnerAccount;
use App\Domain\Website\Actions\ImportBundledWebsite;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * First-run setup over SSH: seeds reference data and creates the owner account (docs/06 §3).
 * Non-interactive mode reads OWNER_NAME / OWNER_EMAIL / OWNER_PASSWORD from .env.
 * Hostinger Git deploys without SSH use the web installer at /install instead (docs/06 §2).
 */
final class InstallCommand extends Command
{
    protected $signature = 'woodex:install {--force : Run the reference data seeder again}';

    protected $description = 'Seed Woodex OS reference data and create the owner account.';

    public function handle(CreateOwnerAccount $createOwner, ImportBundledWebsite $importWebsite): int
    {
        $this->components->info('Woodex OS setup');

        // Reference data only adds what is missing: rates, templates and stages edited by the owner are kept.
        $this->call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);

        if (User::role('owner')->exists() && ! $this->option('force')) {
            $this->components->info('An owner account already exists. Nothing else to do.');
            $this->importWebsite($importWebsite);

            return self::SUCCESS;
        }

        $name = (string) (config('woodex.owner.name') ?: ($this->input->isInteractive() ? text('Owner name', default: 'Imtiaz Ahmad', required: true) : ''));
        $email = (string) (config('woodex.owner.email') ?: ($this->input->isInteractive() ? text('Owner email', required: true) : ''));
        $secret = (string) (config('woodex.owner.password') ?: ($this->input->isInteractive() ? password('Owner password (min 10 characters, letters and numbers)', required: true) : ''));

        $passwordCheck = Validator::make(['password' => $secret], ['password' => ['required', Password::default()]]);

        try {
            $passwordCheck->validate();
            $createOwner->handle($name, $email, $secret);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->components->info("Owner account created for {$email}. Turn on two-factor authentication after the first login.");
        $this->importWebsite($importWebsite);

        return self::SUCCESS;
    }

    /**
     * The pages of the website come with the code (docs/09 §13). The migration imports them on a database that
     * already has an owner; here they land on a brand-new one, written in the new owner's name.
     */
    private function importWebsite(ImportBundledWebsite $importWebsite): void
    {
        $output = $importWebsite();

        if ($output === null) {
            return;
        }

        $this->line($output === '' ? 'The pages of the website are up to date.' : $output);
    }
}
