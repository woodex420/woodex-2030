<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\Connectors\ConnectorManager;
use App\Integrations\Connectors\OAuthState;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The browser half of an OAuth connector: start at the provider, come back to the signed state, store only after
 * the provider has proved the token. Nothing about the company account is read before the owner consents.
 */
final class ConnectorOAuthController extends Controller
{
    public function start(string $provider, ConnectorManager $manager): RedirectResponse
    {
        $this->authorizeOwner();

        try {
            return redirect()->away($manager->authorizeUrl($provider, $this->user()));
        } catch (ValidationException $exception) {
            return $this->back($exception->getMessage());
        }
    }

    public function callback(Request $request, string $provider, ConnectorManager $manager): RedirectResponse
    {
        $this->authorizeOwner();

        $state = OAuthState::pull((string) $request->query('state'));

        if ($state === null || $state['provider'] !== $provider || $state['user_id'] !== (int) auth()->id()) {
            return $this->back('This authorization link is invalid or has expired. Start the connection again.');
        }

        if (is_string($request->query('error')) && $request->query('error') !== '') {
            return $this->back('Google did not grant access: '.str((string) $request->query('error'))->limit(80));
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->back('Google returned no authorization code. Start the connection again.');
        }

        try {
            $connection = $manager->completeAuthorization($provider, $code, $this->user());
        } catch (ValidationException $exception) {
            return $this->back((string) collect($exception->errors())->flatten()->first());
        } catch (Throwable $exception) {
            report($exception);

            return $this->back('The connection could not be completed. Nothing was stored.');
        }

        return redirect()->route('admin.integrations')
            ->with('connector_status', 'Connected '.$connection->account.' — the provider proved the token.');
    }

    private function authorizeOwner(): void
    {
        abort_unless(auth()->check() && auth()->user()->hasRole('owner') && auth()->user()->can('integrations.manage'), 403);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('admin.integrations')->with('connector_error', $message);
    }
}
