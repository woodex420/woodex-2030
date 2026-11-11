<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bridge;

use App\Domain\System\Models\IntegrationLog;
use App\Http\Controllers\Controller;
use App\Integrations\Mcp\McpAccessToken;
use App\Integrations\Mcp\McpTools;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The remote MCP endpoint: JSON-RPC 2.0 over HTTPS, authenticated by the same `wx_mcp_` bearer key.
 *
 * This is what makes the bridge usable by any MCP client without a local Node adapter: paste
 * `https://<site>/api/mcp` and the key. Read-only tools, the key's abilities, and the issuing user's live
 * permissions all still apply.
 */
final class McpHttpController extends Controller
{
    public function __invoke(Request $request, McpTools $tools): JsonResponse
    {
        /** @var McpAccessToken $token */
        $token = $request->attributes->get('mcp_token');
        /** @var User $user */
        $user = $request->user();

        $payload = $request->json()->all();
        $method = is_string($payload['method'] ?? null) ? $payload['method'] : '';
        $id = $payload['id'] ?? null;

        // Notifications carry no id and expect no body (Streamable HTTP: 202 Accepted).
        if ($method === '' || str_starts_with($method, 'notifications/')) {
            return response()->json(null, 202);
        }

        return match ($method) {
            'initialize' => $this->ok($id, [
                'protocolVersion' => '2025-06-18',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'woodex', 'version' => '2.0.0'],
                'instructions' => 'Woodex website bridge. Read-only: pages, site health, SEO worklist, leads and quotes. Call tools/list for the tools this key allows.',
            ]),
            'ping' => $this->ok($id, (object) []),
            'tools/list' => $this->ok($id, ['tools' => $this->toolsFor($token)]),
            'tools/call' => $this->callTool($payload, $id, $token, $user, $tools),
            default => $this->error($id, -32601, 'Method not found: '.$method),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callTool(array $payload, mixed $id, McpAccessToken $token, User $user, McpTools $tools): JsonResponse
    {
        $params = $payload['params'] ?? [];
        $name = is_array($params) && is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params) && is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (! isset(McpTools::TOOLS[$name])) {
            return $this->error($id, -32602, 'Unknown tool: '.$name);
        }

        $ability = McpTools::TOOLS[$name]['ability'];

        if (! in_array($ability, $token->abilities, true)) {
            return $this->error($id, -32003, 'This key does not allow '.$ability.'. Issue a new key with that scope.');
        }

        $started = microtime(true);

        try {
            $data = $tools->call($name, $arguments, $user, $token->abilities);
            $this->log($name, $token, true, null, $started);
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors())->flatten()->first();
            $this->log($name, $token, false, $message, $started);

            return $this->ok($id, ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true]);
        } catch (AuthorizationException) {
            $this->log($name, $token, false, 'Forbidden', $started);

            return $this->ok($id, ['content' => [['type' => 'text', 'text' => 'The user behind this key may not read that record.']], 'isError' => true]);
        } catch (Throwable $exception) {
            report($exception);
            $this->log($name, $token, false, 'Internal error', $started);

            return $this->error($id, -32603, 'The tool failed before it could answer.');
        }

        return $this->ok($id, [
            'content' => [['type' => 'text', 'text' => (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
            'structuredContent' => $data,
            'isError' => false,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function toolsFor(McpAccessToken $token): array
    {
        return array_values(array_filter(McpTools::definitions(),
            static fn (array $tool): bool => in_array(McpTools::TOOLS[$tool['name']]['ability'], $token->abilities, true)));
    }

    /** @param array<string, mixed> $result */
    private function ok(mixed $id, array|object $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    private function log(string $tool, McpAccessToken $token, bool $ok, ?string $error, float $started): void
    {
        IntegrationLog::query()->create([
            'integration' => 'mcp', 'direction' => 'in', 'action' => 'rpc.'.$tool, 'ok' => $ok,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'status_code' => $ok ? 200 : 400,
            'request' => ['key_id' => $token->id, 'transport' => 'http'], 'error' => $error, 'created_at' => now(),
        ]);
    }
}
