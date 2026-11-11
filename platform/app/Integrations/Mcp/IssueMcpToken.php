<?php

declare(strict_types=1);

namespace App\Integrations\Mcp;

use App\Domain\System\Models\IntegrationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class IssueMcpToken
{
    public const ABILITIES = ['status.read', 'site.read', 'pages.read', 'leads.read', 'quotes.read'];

    /** @param list<string> $abilities */
    public function handle(User $user, string $password, string $name, array $abilities, int $days): string
    {
        abort_unless($user->is_active && $user->hasRole('owner') && $user->can('integrations.manage'), 403);
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['bridgePassword' => 'The password is incorrect.']);
        }
        validator(['name' => $name, 'abilities' => $abilities, 'days' => $days], [
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::in(self::ABILITIES)],
            'days' => ['required', 'integer', 'min:1', 'max:90'],
        ])->validate();

        $plain = 'wx_mcp_'.bin2hex(random_bytes(32));
        DB::transaction(function () use ($user, $name, $abilities, $days, $plain): void {
            $token = McpAccessToken::query()->create([
                'user_id' => $user->id, 'name' => $name, 'abilities' => array_values($abilities),
                'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDays($days),
            ]);

            IntegrationLog::query()->create(['integration' => 'mcp', 'direction' => 'in', 'action' => 'key.created',
                'ok' => true, 'request' => ['key_id' => $token->id, 'user_id' => $user->id], 'created_at' => now()]);
        });

        return $plain;
    }
}
