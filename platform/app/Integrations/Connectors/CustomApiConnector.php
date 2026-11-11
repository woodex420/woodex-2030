<?php

declare(strict_types=1);

namespace App\Integrations\Connectors;

use App\Integrations\Support\PublicUrlGuard;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Any REST service that answers a GET with a bearer token — Notion, Slack (bot token + `auth.test`), a client's
 * own API, an internal webhook that exposes a health route.
 *
 * "Any connector" in the honest sense: the owner supplies a URL and a token, Woodex makes the real request and
 * stores the measured result. It is read-only; the hub has no generic write path into a third party.
 */
final class CustomApiConnector extends Connector
{
    public function key(): string
    {
        return 'custom_api';
    }

    public function name(): string
    {
        return 'Custom API';
    }

    public function mode(): string
    {
        return 'token';
    }

    public function icon(): string
    {
        return 'link';
    }

    public function detail(): string
    {
        return 'Any REST service: a URL and a bearer token, checked live.';
    }

    public function missingRequirement(): ?string
    {
        return null;
    }

    /** @return list<array{name: string, label: string, type: string, placeholder: string, required: bool}> */
    public function fields(): array
    {
        return [
            ['name' => 'label', 'label' => 'Service name', 'type' => 'text', 'placeholder' => 'Notion, client API…', 'required' => true],
            ['name' => 'base_url', 'label' => 'Health URL (GET)', 'type' => 'url', 'placeholder' => 'https://api.example.com/health', 'required' => true],
            ['name' => 'token', 'label' => 'Bearer token', 'type' => 'password', 'placeholder' => 'secret_… · xoxb-…', 'required' => true],
        ];
    }

    /** @param array<string, string> $input */
    public function connect(array $input, User $user): array
    {
        $url = trim((string) ($input['base_url'] ?? ''));
        $token = trim((string) ($input['token'] ?? ''));
        $label = trim((string) ($input['label'] ?? ''));

        $errors = [];

        if ($label === '' || mb_strlen($label) > 60) {
            $errors['label'] = 'Give the service a name of 60 characters or fewer.';
        }

        if (! $this->safeUrl($url)) {
            $errors['base_url'] = 'Use an https URL on a public host. Local and private addresses are refused.';
        }

        if ($token === '' || strlen($token) > 2048) {
            $errors['token'] = 'Paste the token the service gave you.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $probe = $this->probe(['base_url' => $url, 'token' => $token, 'label' => $label]);

        if (! $probe['ok']) {
            throw ValidationException::withMessages(['base_url' => (string) $probe['error']]);
        }

        return [
            'credentials' => ['base_url' => $url, 'token' => $token, 'label' => $label],
            'account' => (string) $probe['account'],
            'scopes' => [],
            'meta' => $probe['meta'],
        ];
    }

    /** @param array<string, mixed> $credentials */
    public function probe(array $credentials): array
    {
        $url = (string) ($credentials['base_url'] ?? '');
        $token = (string) ($credentials['token'] ?? '');
        $empty = ['ok' => false, 'account' => null, 'detail' => '', 'scopes' => null, 'meta' => [], 'error' => 'This connection has no URL or token.'];

        if (! $this->safeUrl($url) || $token === '') {
            return [...$empty, 'error' => 'The stored URL or token is not usable. Remove the connection and add it again.'];
        }

        $started = microtime(true);

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(10)->connectTimeout(5)
                ->withoutRedirecting()->withOptions(PublicUrlGuard::requestOptions($url))->get($url);
        } catch (\Throwable $exception) {
            return [...$empty, 'error' => 'Could not reach the service or its destination was refused by the public-address guard.'];
        }

        $ms = (int) round((microtime(true) - $started) * 1000);

        if (! $response->successful()) {
            return [...$empty, 'error' => 'The service answered '.$response->status().'.'.($response->status() === 401 ? ' The token was refused.' : '')];
        }

        $body = $response->json();

        if (is_array($body) && ($body['ok'] ?? null) === false) {
            $reason = is_string($body['error'] ?? null) ? $body['error'] : 'the service reported a failure';

            return [...$empty, 'error' => 'The service answered 200 but reported failure: '.$reason];
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        return [
            'ok' => true,
            'account' => $host,
            'detail' => 'HTTP '.$response->status().' in '.$ms.' ms',
            'scopes' => [],
            'meta' => ['url' => $url, 'status' => $response->status(), 'latency_ms' => $ms,
                'label' => (string) ($credentials['label'] ?? '')],
            'error' => null,
        ];
    }

    /** @param array<string, mixed> $credentials */
    public function disconnect(array $credentials): void
    {
        // Nothing to revoke at a generic provider; deleting the row removes the only copy of the token.
    }

    /** Only public https hosts: a connector must never become an SSRF probe into the private network. */
    private function safeUrl(string $url): bool
    {
        return PublicUrlGuard::isSafe($url);
    }
}
