<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Website\Studio\StudioApi;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The one seam the ported v2.1 admin talks to (docs/audit/16.7).
 *
 * The admin posts `{action, ...}` and reads `{ok, ...}`; every module was written against that. The
 * session cookie is the real authentication — the `X-WX-ADM` header the old admin sent is meta only and
 * is never trusted — and the permission is checked once, here, for every action.
 */
final class WebsiteStudioApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_if($user === null, 401);

        $action = trim((string) $request->input('action', ''));

        // Writes need the same permission the OS screens need; publishing actions check for more inside.
        abort_unless($user->can('website.edit'), 403);

        try {
            $result = (new StudioApi($user))->call($action, $request->except('action'));
        } catch (HttpExceptionInterface $exception) {
            // A permission check inside an action is an HTTP answer, not a message on the screen.
            // (This must come before RuntimeException: Symfony's HttpException extends it.)
            throw $exception;
        } catch (ValidationException $exception) {
            // A guard said why in words meant for the screen ("That address belongs to the system", "the folder
            // could not be created"); show those instead of the generic line, which reads like a crash.
            return response()->json([
                'ok' => false,
                'error' => $exception->validator->errors()->first() ?: 'That change was not accepted.',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'error' => 'The server could not complete that action. Nothing was saved.',
            ]);
        }

        return response()->json(['ok' => true] + $result);
    }
}
