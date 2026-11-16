<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce configured staff TOTP enrolment; owners may enrol voluntarily (D-20, SYS-003).
 */
final class EnsureTwoFactorIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Page loads only: staff required to enrol can reach only the Security page, and the
        // Livewire requests made from that page must keep working while 2FA is being set up.
        if (
            $user !== null
            && $request->isMethod('GET')
            && ! $user->hasTwoFactorEnabled()
            && $user->requiresTwoFactorAuthentication()
            && ! $request->routeIs('admin.profile.security')
        ) {
            return redirect()->route('admin.profile.security')
                ->with('warning', 'Please turn on two-factor authentication to continue.');
        }

        return $next($request);
    }
}
