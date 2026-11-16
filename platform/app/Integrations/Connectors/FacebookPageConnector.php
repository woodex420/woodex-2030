<?php

declare(strict_types=1);

namespace App\Integrations\Connectors;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * A Facebook Page with its Page access token, proved against the Graph API before it is stored —
 * v2.1's connector shape (Page ID + write-only token).
 *
 * The token proves who the Page is. Publishing itself still waits on Meta app approval (the Social
 * planner has no Graph transport yet), so this connector claims identity, never delivery.
 */
final class FacebookPageConnector extends Connector
{
    private const string API = 'https://graph.facebook.com/v23.0';

    public function key(): string
    {
        return 'facebook_page';
    }

    public function name(): string
    {
        return 'Facebook Page';
    }

    public function mode(): string
    {
        return 'token';
    }

    public function icon(): string
    {
        return 'megaphone';
    }

    public function detail(): string
    {
        return 'Your Facebook Page, proved live against Meta.';
    }

    public function missingRequirement(): ?string
    {
        return null;
    }

    public function help(): string
    {
        return 'In Meta Business Suite → Settings → Business assets, copy the Page ID. Create a Page access token at developers.facebook.com → your app → Tools → Graph API Explorer. Posting itself still needs Meta app approval — this connection proves the credential today.';
    }

    /** @return list<array{name: string, label: string, type: string, placeholder: string, required: bool}> */
    public function fields(): array
    {
        return [
            ['name' => 'page_id', 'label' => 'Page ID', 'type' => 'text', 'placeholder' => '123456789012345', 'required' => true],
            ['name' => 'token', 'label' => 'Page access token', 'type' => 'password', 'placeholder' => 'EAAB…', 'required' => true],
        ];
    }

    /** @param array<string, string> $input */
    public function connect(array $input, User $user): array
    {
        $pageId = trim((string) ($input['page_id'] ?? ''));
        $token = trim((string) ($input['token'] ?? ''));

        if (preg_match('/^[0-9]{1,30}$/', $pageId) !== 1) {
            throw ValidationException::withMessages(['page_id' => 'Paste the Page ID from Meta Business Suite.']);
        }

        if ($token === '' || strlen($token) > 2048) {
            throw ValidationException::withMessages(['token' => 'Paste the Page access token Meta gave you.']);
        }

        $probe = $this->probe(['page_id' => $pageId, 'token' => $token]);

        if (! $probe['ok']) {
            throw ValidationException::withMessages(['token' => (string) $probe['error']]);
        }

        return [
            'credentials' => ['page_id' => $pageId, 'token' => $token],
            'account' => (string) $probe['account'],
            'scopes' => [],
            'meta' => $probe['meta'],
        ];
    }

    /** @param array<string, mixed> $credentials */
    public function probe(array $credentials): array
    {
        $pageId = (string) ($credentials['page_id'] ?? '');
        $token = (string) ($credentials['token'] ?? '');
        $empty = ['ok' => false, 'account' => null, 'detail' => '', 'scopes' => null, 'meta' => [], 'error' => 'No Facebook Page credential is stored.'];

        if ($pageId === '' || $token === '') {
            return $empty;
        }

        $response = Http::withToken($token)->acceptJson()
            ->withHeaders(['User-Agent' => 'Woodex-OS'])
            ->timeout(10)->connectTimeout(5)->withoutRedirecting()
            ->get(self::API.'/me', ['fields' => 'id,name,fan_count']);

        $data = $this->json($response);
        $message = is_string($data['error']['message'] ?? null) ? $data['error']['message'] : null;

        if (! $response->successful()) {
            return [...$empty, 'error' => 'Meta rejected this Page token'.($message !== null ? ': '.$message : ' ('.$response->status().').')];
        }

        if ((string) ($data['id'] ?? '') !== $pageId) {
            return [...$empty, 'error' => 'This token belongs to a different account, not the Page ID you entered. Use that Page access token.'];
        }

        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';

        if ($name === '') {
            return [...$empty, 'error' => 'Meta answered without a Page name.'];
        }

        $followers = is_int($data['fan_count'] ?? null) ? $data['fan_count'] : null;

        return [
            'ok' => true,
            'account' => $name,
            'detail' => $followers === null ? '' : number_format($followers).' followers',
            'scopes' => [],
            'meta' => ['page_id' => $pageId, 'name' => $name, 'followers' => $followers],
            'error' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}
