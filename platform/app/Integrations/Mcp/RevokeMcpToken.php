<?php

declare(strict_types=1);

namespace App\Integrations\Mcp;

use App\Domain\System\Models\IntegrationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RevokeMcpToken
{
    public function handle(User $user, int $id): void
    {
        abort_unless($user->is_active && $user->hasRole('owner') && $user->can('integrations.manage'), 403);
        DB::transaction(function () use ($user, $id): void {
            $token = McpAccessToken::query()->lockForUpdate()->findOrFail($id);
            if ($token->revoked_at !== null) {
                return;
            }
            $token->forceFill(['revoked_at' => now()])->save();
            IntegrationLog::query()->create(['integration' => 'mcp', 'direction' => 'in', 'action' => 'key.revoked',
                'ok' => true, 'request' => ['key_id' => $id, 'user_id' => $user->id], 'created_at' => now()]);
        });
    }
}
