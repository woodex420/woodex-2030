<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use App\Integrations\Google\Contracts\GoogleGateway;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleClient implements GoogleGateway
{
    public function exchange(string $code, string $verifier): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier,
            'redirect_uri' => config('services.google.redirect_uri')]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function profile(string $accessToken): array
    {
        return $this->data(Http::acceptJson()->withToken($accessToken)->timeout(10)->connectTimeout(5)
            ->withoutRedirecting()->get('https://openidconnect.googleapis.com/v1/userinfo'));
    }

    public function revoke(string $token): bool
    {
        return Http::asForm()->timeout(10)->connectTimeout(5)->withoutRedirecting()
            ->post('https://oauth2.googleapis.com/revoke', ['token' => $token])->successful();
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function token(array $fields): array
    {
        return $this->data(Http::asForm()->acceptJson()->timeout(10)->connectTimeout(5)->withoutRedirecting()
            ->post('https://oauth2.googleapis.com/token', $fields + [
                'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            ]));
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        // Never throw an HTTP exception containing a token-bearing provider body or request.
        $data = $response->json();
        if (! $response->successful() || ! is_array($data)) {
            throw new RuntimeException('Google request failed.');
        }

        return $data;
    }
}
