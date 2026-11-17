@extends('layouts.setup')

@section('title', 'Install')
@section('step', 'Step 1 of 2 · Settings')
@section('heading', 'Install Woodex OS')
@section('subheading', 'The code is on the server. Connect a database and create the owner account: this takes about a minute and needs no SSH.')

@section('content')
    @php($allOk = collect($requirements)->every(fn ($check) => $check['ok']))

    <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 class="text-theme-sm font-semibold text-gray-900 dark:text-white">Server check</h2>
        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($requirements as $check)
                <li class="flex items-start gap-3 py-2.5 text-theme-sm">
                    <span @class(['mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white', 'bg-success-500' => $check['ok'], 'bg-error-500' => ! $check['ok']])>{{ $check['ok'] ? '✓' : '!' }}</span>
                    <span>
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $check['label'] }}</span>
                        <span class="block text-theme-xs text-gray-500">{{ $check['detail'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
        @unless ($allOk)
            <p class="mt-3 rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700">Fix the items marked "!" in hPanel, then reload this page. You can still try to install.</p>
        @endunless
    </section>

    <form method="POST" action="{{ url('/install') }}" class="mt-6 space-y-6" autocomplete="off">
        <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-theme-sm font-semibold text-gray-900 dark:text-white">1. Setup code</h2>
            <p class="mt-1 text-theme-xs text-gray-500">Proves that you own this hosting account.</p>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-theme-xs text-gray-600 dark:text-gray-400">
                <li>Open <strong>hPanel → Websites → this site → File Manager</strong>.</li>
                @if ($siteCodeFile)
                    <li>Open the file
                        <code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px] font-semibold text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ $siteCodeFile }}</code>
                        (in the first folder File Manager shows) and copy the code.</li>
                    <li class="text-gray-500">The same code is kept outside the website in <code>{{ $codeFile }}</code> (File Manager → <em>Access all files of hosting</em>).</li>
                @else
                    <li>Choose <strong>Access all files of hosting</strong>, open
                        <code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px] font-semibold text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ $codeFile }}</code>
                        and copy the code.</li>
                @endif
            </ol>
            <p class="mt-2 text-theme-xs text-gray-500">Reloading this page keeps the same code. The file cannot be opened from the web and is deleted when the installation finishes.</p>
            <label for="setup_code" class="wx-label mt-4">Setup code</label>
            <input id="setup_code" name="setup_code" @unless ($databaseProof) required @endunless maxlength="64" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX"
                   class="wx-input h-11 font-mono uppercase tracking-wider">
            @if ($databaseProof)
                <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-theme-xs text-gray-600 dark:bg-white/5 dark:text-gray-400">
                    <strong>Cannot find the file?</strong> Leave the code empty and use a MySQL database of this hosting account
                    in step 3 (host <code>localhost</code>, the user starts with your account name, like <code>u123456789_woodex</code>):
                    only the account owner can create one in hPanel, so it proves ownership too.
                </p>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-theme-sm font-semibold text-gray-900 dark:text-white">2. Website address</h2>
            <label for="app_url" class="wx-label mt-4">Address of this site</label>
            <input id="app_url" name="app_url" type="url" required value="{{ $input['app_url'] }}" class="wx-input h-11">
            <p class="mt-1.5 text-theme-xs text-gray-500">The admin will open at this address + <code>/admin</code>. Use https once the SSL certificate is active.</p>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-theme-sm font-semibold text-gray-900 dark:text-white">3. Database</h2>
            <p class="mt-1 text-theme-xs text-gray-500">Create a new, empty MySQL database and user in <strong>hPanel → Databases → Management</strong>, then enter the same details here.</p>

            <div class="mt-4 flex flex-wrap gap-3">
                <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-theme-sm dark:border-gray-700">
                    <input type="radio" name="connection" value="mysql" @checked($input['connection'] === 'mysql') class="size-4"> MySQL (recommended)
                </label>
                <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-theme-sm dark:border-gray-700">
                    <input type="radio" name="connection" value="sqlite" @checked($input['connection'] === 'sqlite') class="size-4"> SQLite file (quick test only)
                </label>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label for="db_host" class="wx-label">Host</label>
                    <input id="db_host" name="db_host" value="{{ $input['db_host'] }}" class="wx-input h-11">
                </div>
                <div>
                    <label for="db_port" class="wx-label">Port</label>
                    <input id="db_port" name="db_port" inputmode="numeric" value="{{ $input['db_port'] }}" class="wx-input h-11">
                </div>
                <div>
                    <label for="db_database" class="wx-label">Database name</label>
                    <input id="db_database" name="db_database" value="{{ $input['db_database'] }}" placeholder="u123456789_woodex" class="wx-input h-11">
                </div>
                <div>
                    <label for="db_username" class="wx-label">Database user</label>
                    <input id="db_username" name="db_username" value="{{ $input['db_username'] }}" placeholder="u123456789_woodex" class="wx-input h-11">
                </div>
                <div>
                    <label for="db_password" class="wx-label">Database password</label>
                    <input id="db_password" name="db_password" type="password" autocomplete="new-password" class="wx-input h-11">
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-theme-sm font-semibold text-gray-900 dark:text-white">4. Owner account</h2>
            <p class="mt-1 text-theme-xs text-gray-500">The first sign-in. More staff are invited later from Users.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="owner_name" class="wx-label">Name</label>
                    <input id="owner_name" name="owner_name" required value="{{ $input['owner_name'] }}" autocomplete="name" class="wx-input h-11">
                </div>
                <div>
                    <label for="owner_email" class="wx-label">Email</label>
                    <input id="owner_email" name="owner_email" type="email" required value="{{ $input['owner_email'] }}" autocomplete="email" class="wx-input h-11">
                </div>
                <div>
                    <label for="owner_password" class="wx-label">Password</label>
                    <input id="owner_password" name="owner_password" type="password" required minlength="10" autocomplete="new-password" class="wx-input h-11">
                    <p class="mt-1.5 text-theme-xs text-gray-500">At least 10 characters with letters and numbers.</p>
                </div>
                <div>
                    <label for="owner_password_confirmation" class="wx-label">Password again</label>
                    <input id="owner_password_confirmation" name="owner_password_confirmation" type="password" required minlength="10" autocomplete="new-password" class="wx-input h-11">
                </div>
            </div>
            <label class="mt-4 flex items-start gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" name="enforce_2fa" value="1" @checked($input['enforce_2fa']) class="mt-0.5 size-4">
                <span>Require two-factor sign-in (authenticator app) for admin and accountant. Owners can enable 2FA voluntarily. <span class="text-gray-500">Recommended: the admin holds client data.</span></span>
            </label>
        </section>

        <button type="submit" data-busy-text="Checking the database…" class="flex h-12 w-full items-center justify-center rounded-lg bg-navy-900 text-theme-sm font-medium text-white hover:bg-navy-800">
            Install Woodex OS
        </button>
        <p class="text-center text-theme-xs text-gray-500">
            Settings are saved in the private data folder{{ $classic ? ' (platform/, see docs/06 §2)' : '' }}, never in the website files.
        </p>
    </form>
@endsection
