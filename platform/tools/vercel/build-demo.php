<?php

declare(strict_types=1);

/**
 * Builds the sample-data workspace of the Vercel demo (docs/woodex-os/13-VERCEL-DEPLOYMENT.md).
 *
 * The vercel-php builder runs "composer run vercel" after "composer install --no-dev --no-scripts"; that script is
 * this file. It does nothing unless the Vercel project sets WOODEX_DEMO=1. In a demo it creates
 * database/woodex-synthetic-vercel-demo.sqlite: tables, reference data, four staff accounts (all on the reserved
 * domain woodex.test) and records that are all named SYNTHETIC. The file travels with the function and is copied
 * to /tmp when an instance starts (api/index.php).
 *
 * A failure here fails the deployment, so a demo that cannot be signed in to is never published.
 * It never reads a DB_* setting or the project's APP_KEY: the commands below are given a SQLite file, a key made
 * here for this demo alone (database/woodex-synthetic-vercel-demo.sqlite.key), and nothing else.
 */
$base = dirname(__DIR__, 2);
$say = static function (string $line): void {
    fwrite(STDOUT, '[demo] '.$line.PHP_EOL);
};
$fail = static function (string $line): never {
    fwrite(STDERR, '[demo] FAILED: '.$line.PHP_EOL);
    exit(1);
};

if (getenv('WOODEX_DEMO') !== '1') {
    $say('WOODEX_DEMO is not 1: this deployment is not a demo, nothing to build.');
    exit(0);
}

// A key for this demo alone, made here and shipped with the function. The project's APP_KEY is never used: a link
// signed in the demo would otherwise also be accepted by the real admin. It is never printed.
$key = 'base64:'.base64_encode(random_bytes(32));
$passwords = [];
foreach (['WX_PW_OWNER', 'WX_PW_MANAGER', 'WX_PW_SALES', 'WX_PW_ACCOUNTS'] as $name) {
    $passwords[$name] = (string) getenv($name);
    if (strlen($passwords[$name]) < 16) {
        $fail($name.' must be set in the project settings (16 characters or more).');
    }
}

$database = $base.'/database/woodex-synthetic-vercel-demo.sqlite';
@unlink($database);
@unlink($database.'.key');
if (! touch($database) || file_put_contents($database.'.key', $key.PHP_EOL) === false) {
    $fail('cannot create the database file and its key.');
}
foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/private', 'app/public'] as $folder) {
    if (! is_dir($base.'/storage/'.$folder) && ! mkdir($base.'/storage/'.$folder, 0775, true)) {
        $fail('cannot create storage/'.$folder.'.');
    }
}

// Everything a child process may know. No DB_HOST, DB_USERNAME or DB_PASSWORD is passed on.
$environment = [
    'PATH' => (string) getenv('PATH'),
    'LD_LIBRARY_PATH' => (string) getenv('LD_LIBRARY_PATH'),
    'PHP_INI_SCAN_DIR' => (string) getenv('PHP_INI_SCAN_DIR'),
    'PHP_INI_EXTENSION_DIR' => (string) getenv('PHP_INI_EXTENSION_DIR'),
    'HOME' => (string) getenv('HOME'),
    'APP_NAME' => 'WOODEX OS',
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'false',
    'APP_KEY' => $key,
    'APP_URL' => 'http://localhost',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database,
    'MAIL_MAILER' => 'log',
    'LOG_CHANNEL' => 'stderr',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'BROADCAST_CONNECTION' => 'log',
    ...$passwords,
];
$environment = array_filter($environment, static fn (string $value): bool => $value !== '');

$run = static function (string $what, array $arguments) use ($base, $environment, $say, $fail): void {
    $started = microtime(true);
    $process = proc_open([PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base, $environment);
    if (! is_resource($process)) {
        $fail($what.': could not start PHP.');
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        fwrite(STDERR, substr((string) $output, -3000).PHP_EOL.substr((string) $errors, -3000).PHP_EOL);
        $fail($what.' ended with code '.$code.'.');
    }
    $say(sprintf('%s: done in %.1fs', $what, microtime(true) - $started));
};

$run('tables', ['artisan', 'migrate', '--force', '--no-interaction']);
$run('reference data', ['artisan', 'db:seed', '--class=ProductionSeeder', '--force', '--no-interaction']);
$run('staff accounts', ['tools/preview/synthetic_fixtures.php', 'staff']);
$run('sample records', ['tools/preview/synthetic_fixtures.php', 'data']);

$pdo = new PDO('sqlite:'.$database);
$staff = (int) $pdo->query("select count(*) from users where email like '%@woodex.test'")->fetchColumn();
$others = (int) $pdo->query("select count(*) from users where email not like '%@woodex.test'")->fetchColumn();
$pending = (int) $pdo->query('select count(*) from migrations')->fetchColumn();
$pdo = null;
if ($staff !== 4 || $others !== 0 || $pending === 0 || ! is_file($database.'.key')) {
    $fail("unexpected content: {$staff} demo accounts, {$others} other accounts, {$pending} migrations.");
}
$say(sprintf('workspace ready: %d KB, %d migrations, %d demo accounts.', (int) round(filesize($database) / 1024), $pending, $staff));
