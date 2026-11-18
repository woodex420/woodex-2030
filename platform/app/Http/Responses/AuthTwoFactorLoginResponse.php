<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A sign-in that was finished with a code from the phone goes where every other sign-in goes
 * ({@see AuthLoginResponse::destination()}): without this it always opened the dashboard, whatever the person chose.
 */
final class AuthTwoFactorLoginResponse implements TwoFactorLoginResponse
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        return $request->wantsJson() ? new JsonResponse('', 204) : redirect()->to(AuthLoginResponse::destination($request));
    }
}
