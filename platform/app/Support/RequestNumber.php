<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * The number of one request (master list T-062; documents 02 §9 and 04 §2 of the specification).
 *
 * Every answer carries it in the header X-Request-Id, every error page shows it, and every line the application
 * writes to its log while answering carries it too — also a line written later by a job that the request put in
 * the queue. Somebody who reports "I got an error, number 4F7K-9Q2M-T8RB" has named the lines to read.
 *
 * The number is always made here. What a caller sends in X-Request-Id is never taken over: a caller could then
 * choose the number of somebody else's request. A well-formed one is written beside ours, so that a caller's own
 * records can still be matched.
 */
final class RequestNumber
{
    public const HEADER = 'X-Request-Id';

    /** Crockford's alphabet: no I, L, O or U, so that a number read out over the telephone is not misheard. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** What begin() writes for one request and the next request must not inherit (a test, a long-lived worker). */
    private const OF_ONE_REQUEST = ['request_id', 'method', 'route', 'path', 'user_id', 'client_request_id', 'host_request_id'];

    /** Twelve characters in three groups: 60 random bits. */
    public static function make(): string
    {
        $characters = '';

        foreach (unpack('C*', random_bytes(12)) ?: [] as $byte) {
            $characters .= self::ALPHABET[$byte & 31];
        }

        return implode('-', str_split($characters, 4));
    }

    public static function looksLikeOne(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){2}\z/', $value) === 1;
    }

    /** A request starts: a new number, and what is known about the request before the application has run. */
    public static function begin(Request $request): string
    {
        $number = self::make();

        Context::forget(self::OF_ONE_REQUEST);
        Context::add(['request_id' => $number, 'method' => $request->getMethod()]);

        // Who asks is normally learnt later, when the session is read (AppServiceProvider listens for it). A
        // person who is already known now — a test, a worker that keeps running — is written down at once.
        // Nothing here may stop a request: on a site that is not installed yet there may be nothing to ask.
        $known = rescue(static fn () => Auth::hasUser() ? Auth::id() : null, null, false);

        if ($known !== null) {
            Context::add('user_id', $known);
        }

        // The caller's own number, and the host's (Vercel names every request): written beside ours, never
        // instead of it, and only when it is a plain token — a log line is not a place for a caller's free text.
        foreach (['client_request_id' => ['X-Request-Id', 'X-Correlation-Id'], 'host_request_id' => ['X-Vercel-Id']] as $key => $headers) {
            foreach ($headers as $header) {
                $given = $request->headers->get($header);

                if (is_string($given) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,119}\z/', $given) === 1) {
                    Context::add($key, $given);

                    break;
                }
            }
        }

        return $number;
    }

    /** The number of the request being answered. Made now if nothing has begun one (a fault before the application ran). */
    public static function current(): string
    {
        $number = Context::get('request_id');

        if (! self::looksLikeOne($number)) {
            $number = self::make();
            Context::add('request_id', $number);
        }

        return $number;
    }
}
