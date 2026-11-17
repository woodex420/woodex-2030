<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\System\Actions\CreateOwnerAccount;
use App\Domain\Website\Actions\ImportBundledWebsite;
use App\Support\Install\Installer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Throwable;

/**
 * Web installer (D-58). Its routes run without sessions, cookies or CSRF (nothing exists yet): the setup code from the
 * private data folder is the proof of ownership on every POST. Once the installation finishes the code is deleted and
 * every installer URL answers 404.
 */
final class InstallController
{
    private const string NO_PROOF = 'Enter the setup code from the file named above, or leave it empty and use a MySQL database of this hosting account (its user starts with your account name and "_", like u123456789_woodex; host localhost).';

    public function __construct(private readonly Installer $installer) {}

    public function show(Request $request): Response
    {
        if ($this->installer->site()->isConfigured()) {
            abort_unless($this->installer->hasCode(), 404);

            return $this->finishPage(code: '', auto: false);
        }

        try {
            $this->installer->ensureCode();
        } catch (RuntimeException $exception) {
            return $this->form($request, new MessageBag(['setup' => $exception->getMessage()]), 500);
        }

        return $this->form($request);
    }

    public function store(Request $request): Response
    {
        abort_if($this->installer->site()->isConfigured(), 404);

        $client = (string) $request->ip();

        if ($this->installer->tooManyFailures($client)) {
            return $this->form($request, new MessageBag(['setup_code' => 'Too many attempts. Wait 10 minutes, then try again.']), 429);
        }

        // Read before validation (the proof decides which errors to show): anything but a string counts as empty.
        $text = fn (string $key, string $default = ''): string => self::text($request, $key, $default);
        $database = [
            'connection' => $text('connection'),
            'host' => trim($text('db_host', 'localhost')),
            'port' => (int) $text('db_port', '3306'),
            'database' => trim($text('db_database')),
            'username' => trim($text('db_username')),
            'password' => $text('db_password'),
        ];

        // Proof of ownership: the setup code, or else (owners who cannot find the file) a database user of this
        // hosting account on the local MySQL server, confirmed after connecting below (D-59).
        $codeOk = $this->installer->codeMatches($text('setup_code'));
        $byDatabase = ! $codeOk && $this->installer->databaseMayProveOwnership($database);

        $validator = Validator::make($request->all(), [
            'setup_code' => ['nullable', 'string', 'max:64'],
            'app_url' => ['required', 'url:http,https', 'max:190'],
            'connection' => ['required', 'in:mysql,sqlite'],
            'db_host' => ['exclude_unless:connection,mysql', 'required', 'string', 'max:190'],
            'db_port' => ['exclude_unless:connection,mysql', 'required', 'integer', 'between:1,65535'],
            'db_database' => ['exclude_unless:connection,mysql', 'required', 'string', 'max:64'],
            'db_username' => ['exclude_unless:connection,mysql', 'required', 'string', 'max:64'],
            'db_password' => ['nullable', 'string', 'max:190'],
            ...CreateOwnerAccount::rules(),
            'owner_password' => [...CreateOwnerAccount::rules()['owner_password'], 'confirmed'],
        ], [
            'owner_password.confirmed' => 'The two owner passwords do not match.',
        ], [
            'app_url' => 'website address', 'db_host' => 'database host', 'db_port' => 'database port',
            'db_database' => 'database name', 'db_username' => 'database user', 'owner_name' => 'owner name',
            'owner_email' => 'owner email', 'owner_password' => 'owner password',
        ]);

        $validator->after(function ($validator) use ($text, $codeOk, $byDatabase): void {
            if (! $codeOk && ! $byDatabase) {
                $validator->errors()->add('setup_code', trim($text('setup_code')) === ''
                    ? self::NO_PROOF
                    : 'The setup code does not match. Copy it again from the file named above (or leave it empty and use this hosting account\'s MySQL database, host localhost).');
            }

            foreach (['app_url', 'db_host', 'db_database', 'db_username', 'db_password', 'owner_name'] as $field) {
                if (preg_match('/[\r\n\0]/', $text($field)) === 1) {
                    $validator->errors()->add($field, 'Line breaks are not allowed here.');
                }
            }
        });

        if ($validator->fails()) {
            if ($validator->errors()->has('setup_code')) {
                $this->installer->recordFailure($client);
            }

            return $this->form($request, $validator->errors(), 422);
        }

        $check = $this->installer->checkDatabase($database);

        if (! $check['ok']) {
            if ($byDatabase) {
                $this->installer->recordFailure($client);
            }

            return $this->form($request, new MessageBag(['database' => $check['message']]), 422);
        }

        if ($byDatabase && ! $this->installer->databaseProvesOwnership($database, $check['user'] ?? null)) {
            $this->installer->recordFailure($client);

            return $this->form($request, new MessageBag(['setup_code' => self::NO_PROOF]), 422);
        }

        try {
            $this->installer->configure([
                'app_url' => (string) $request->input('app_url'),
                ...$database,
                'owner_name' => trim((string) $request->input('owner_name')),
                'owner_email' => trim((string) $request->input('owner_email')),
                'owner_password' => (string) $request->input('owner_password'),
                'enforce_2fa' => $request->boolean('enforce_2fa'),
            ]);
        } catch (RuntimeException $exception) {
            return $this->form($request, new MessageBag(['setup' => $exception->getMessage()]), 500);
        }

        // Step 2 checks the code again; an owner who proved ownership with the database gets it here.
        return $this->finishPage(code: $codeOk ? $text('setup_code') : (string) $this->installer->currentCode(), auto: true);
    }

    public function finish(Request $request, CreateOwnerAccount $createOwner, ImportBundledWebsite $importWebsite): Response
    {
        abort_unless($this->installer->site()->isConfigured() && $this->installer->hasCode(), 404);

        $code = self::text($request, 'setup_code');

        if (! $this->installer->codeMatches($code)) {
            return $this->finishPage(code: '', auto: false, error: 'The setup code does not match. Copy it again from the file in the data folder.', status: 422);
        }

        try {
            $result = $this->installer->finish($createOwner, $importWebsite);
        } catch (Throwable $exception) {
            report($exception);

            return $this->finishPage(code: $code, auto: false, error: $exception->getMessage(), status: 500);
        }

        return response()->view('install.done', [
            'owner' => $result['owner'],
            'log' => $result['log'],
            'cron' => $this->installer->cronCommand(),
            'envFile' => $this->installer->site()->displayPath($this->installer->directory().'/.env'),
            'classic' => $this->installer->isClassic(),
        ]);
    }

    private function form(Request $request, ?MessageBag $errors = null, int $status = 200): Response
    {
        $bag = new ViewErrorBag;

        if ($errors !== null) {
            $bag->put('default', $errors);
        }

        return response()->view('install.form', [
            'errors' => $bag,
            'requirements' => $this->installer->requirements(),
            'codeFile' => $this->installer->site()->displayPath($this->installer->codePath()),
            'siteCodeFile' => $this->installer->siteCodeDisplay(),
            'databaseProof' => $this->installer->site()->account() !== null,
            'classic' => $this->installer->isClassic(),
            'input' => [
                'app_url' => self::text($request, 'app_url', $request->getSchemeAndHttpHost()),
                'connection' => self::text($request, 'connection', 'mysql'),
                'db_host' => self::text($request, 'db_host', 'localhost'),
                'db_port' => self::text($request, 'db_port', '3306'),
                'db_database' => self::text($request, 'db_database'),
                'db_username' => self::text($request, 'db_username'),
                'owner_name' => self::text($request, 'owner_name'),
                'owner_email' => self::text($request, 'owner_email'),
                'enforce_2fa' => $request->isMethod('post') ? $request->boolean('enforce_2fa') : true,
            ],
        ], $status);
    }

    /** A text field as typed; anything else (arrays from a crafted request, null) counts as the default. */
    private static function text(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_string($value) ? $value : ($value === null ? $default : '');
    }

    private function finishPage(string $code, bool $auto, ?string $error = null, int $status = 200): Response
    {
        return response()->view('install.finish', [
            'code' => $code,
            'auto' => $auto && $error === null,
            'error' => $error,
            'codeFile' => $this->installer->site()->displayPath($this->installer->codePath()),
            'siteCodeFile' => $this->installer->siteCodeDisplay(),
        ], $status);
    }
}
