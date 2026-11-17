<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * One line in the log for a request that was refused or failed (master list T-062).
 *
 * Laravel writes a fault to the log, with its trace. It writes nothing for a refusal — a page that does not
 * exist, a screen a person may not open, a form that expired — so the number on such a page would find no
 * line. This writes one: what was asked, what was answered, how long it took, and who asked.
 *
 * What it never writes: the query string, a value typed into a form, a cookie, an e-mail address, an IP
 * address, or a part of the address that is not a plain number. An address can carry a secret — the token of a
 * password reset, the public address of a client's quotation — so the line has the route's pattern
 * ("admin/reset-password/{token}") and the numbers of the records in it ("lead": 12), never the words.
 *
 * LOG_REQUESTS says how much: "errors" (the default: answers from 400 up), "all" (every request), "off".
 */
final class RequestLog
{
    public static function mode(): string
    {
        $mode = strtolower((string) config('woodex.logging.requests', 'errors'));

        return in_array($mode, ['errors', 'all', 'off'], true) ? $mode : 'errors';
    }

    public static function write(Request $request, Response $response, float $started): void
    {
        $status = $response->getStatusCode();
        $mode = self::mode();

        if ($mode === 'off' || ($mode === 'errors' && $status < 400)) {
            return;
        }

        $line = ['status' => $status, 'ms' => (int) round((microtime(true) - $started) * 1000)];
        $route = $request->route();

        if ($route === null || $route->isFallback) {
            // No route owns this address: it is what somebody typed or a robot tried. Cut, and without its query.
            $line['path'] = mb_substr('/'.ltrim($request->path(), '/'), 0, 120);
        } elseif (($ids = self::ids($route->parameters())) !== []) {
            $line['ids'] = $ids;
        }

        if (($components = self::livewire($request)) !== []) {
            $line['livewire'] = $components;
        }

        // The answer is already made. A log that cannot be written (a full disk, a folder the web server may not
        // write to) must not turn a "not found" into a failure.
        try {
            Log::log($status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'), 'Request answered '.$status, $line);
        } catch (Throwable) {
            // Nothing to do: there is nowhere to say it.
        }
    }

    /**
     * The records an address names, by number.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, int>
     */
    private static function ids(array $parameters): array
    {
        $ids = [];

        foreach ($parameters as $name => $value) {
            if ($value instanceof Model) {
                $value = $value->getKey();
            }

            if (is_int($value) || (is_string($value) && strlen($value) <= 12 && ctype_digit($value))) {
                $ids[(string) $name] = (int) $value;
            }
        }

        return $ids;
    }

    /**
     * Every Livewire call goes to one address; which screen part was called, and which of its actions, is in the
     * body. Names only: never what was typed.
     *
     * @return list<string>
     */
    private static function livewire(Request $request): array
    {
        if (! $request->hasHeader('X-Livewire')) {
            return [];
        }

        $called = [];

        foreach (array_slice((array) $request->input('components', []), 0, 5) as $component) {
            $snapshot = is_array($component) && is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;
            $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;

            if (! is_string($name) || preg_match('/^[A-Za-z0-9._:-]{1,100}\z/', $name) !== 1) {
                continue;
            }

            $methods = [];

            foreach (array_slice((array) ($component['calls'] ?? []), 0, 5) as $call) {
                $method = is_array($call) ? ($call['method'] ?? null) : null;

                if (is_string($method) && preg_match('/^[A-Za-z0-9_$]{1,60}\z/', $method) === 1) {
                    $methods[] = $method;
                }
            }

            $called[] = $name.($methods === [] ? '' : '::'.implode(',', $methods));
        }

        return $called;
    }
}
