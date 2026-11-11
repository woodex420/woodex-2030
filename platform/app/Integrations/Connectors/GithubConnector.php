<?php

declare(strict_types=1);

namespace App\Integrations\Connectors;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * GitHub with a fine-grained or classic personal access token.
 *
 * The token is proved against `GET /user` before it is stored, so a wrong or expired token never becomes a
 * "Connected" card. Read-only by convention: the hub asks for nothing and writes nothing to GitHub.
 */
final class GithubConnector extends Connector
{
    private const string API = 'https://api.github.com';

    public function key(): string
    {
        return 'github';
    }

    public function name(): string
    {
        return 'GitHub';
    }

    public function mode(): string
    {
        return 'token';
    }

    public function icon(): string
    {
        return 'merge';
    }

    public function detail(): string
    {
        return 'Repositories, commits and deploy status for the site.';
    }

    public function missingRequirement(): ?string
    {
        return null;
    }

    public function help(): string
    {
        return 'GitHub → Settings → Developer settings → Personal access tokens. A fine-grained token with read-only repository access is enough — Woodex never writes to GitHub.';
    }

    /** @return list<array{name: string, label: string, type: string, placeholder: string, required: bool}> */
    public function fields(): array
    {
        return [[
            'name' => 'token', 'label' => 'Personal access token', 'type' => 'password',
            'placeholder' => 'github_pat_… or ghp_…', 'required' => true,
        ]];
    }

    /** @param array<string, string> $input */
    public function connect(array $input, User $user): array
    {
        $token = trim((string) ($input['token'] ?? ''));

        if ($token === '' || strlen($token) > 255) {
            throw ValidationException::withMessages(['token' => 'Paste the token GitHub gave you.']);
        }

        $probe = $this->probe(['token' => $token]);

        if (! $probe['ok']) {
            throw ValidationException::withMessages(['token' => (string) $probe['error']]);
        }

        return [
            'credentials' => ['token' => $token],
            'account' => (string) $probe['account'],
            'scopes' => preg_split('/,\s*/', (string) ($probe['meta']['scope'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            'meta' => $probe['meta'],
        ];
    }

    /** @param array<string, mixed> $credentials */
    public function probe(array $credentials): array
    {
        $token = (string) ($credentials['token'] ?? '');
        $empty = ['ok' => false, 'account' => null, 'detail' => '', 'scopes' => null, 'meta' => [], 'error' => 'No GitHub token is stored.'];

        if ($token === '') {
            return $empty;
        }

        $response = Http::withToken($token)->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Woodex-OS'])
            ->timeout(10)->connectTimeout(5)->withoutRedirecting()
            ->get(self::API.'/user');

        if ($response->status() === 401) {
            return [...$empty, 'error' => 'GitHub rejected this token (401). It may be expired or revoked.'];
        }

        if (! $response->successful()) {
            return [...$empty, 'error' => 'GitHub answered '.$response->status().'.'];
        }

        $data = $this->json($response);
        $login = is_string($data['login'] ?? null) ? $data['login'] : '';

        if ($login === '') {
            return [...$empty, 'error' => 'GitHub answered without an account name.'];
        }

        $remaining = $response->header('X-RateLimit-Remaining');
        $repos = is_int($data['public_repos'] ?? null) ? $data['public_repos'] : null;
        $parts = array_filter([
            $repos === null ? null : $repos.' public repositories',
            $remaining === '' ? null : number_format((int) $remaining).' API calls left this hour',
        ]);

        return [
            'ok' => true, 'account' => $login, 'detail' => implode(' · ', $parts),
            'scopes' => preg_split('/,\s*/', (string) $response->header('X-OAuth-Scopes'), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            'meta' => [
                'login' => $login,
                'name' => is_string($data['name'] ?? null) ? $data['name'] : null,
                'repositories' => $repos,
                'rate_limit_remaining' => $remaining === '' ? null : (int) $remaining,
                'scope' => $response->header('X-OAuth-Scopes'),
            ],
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
