<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestLog;
use App\Support\RequestNumber;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request its number before anything else runs, and sends it back with the answer (T-062).
 *
 * It is the outermost middleware (bootstrap/app.php): a refusal or a fault anywhere further in is turned into
 * an answer on the way back out, and gets the number like every other answer.
 */
final class AssignRequestNumber
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $number = RequestNumber::begin($request);

        $response = $next($request);

        $response->headers->set(RequestNumber::HEADER, $number);
        RequestLog::write($request, $response, $started);

        return $response;
    }
}
