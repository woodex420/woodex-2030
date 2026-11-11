<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domain\Conversations\Actions\QueueWhatsAppEvent;
use App\Domain\System\Enums\WebhookStatus;
use App\Domain\System\Models\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Integrations\WhatsApp\WhatsAppCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp Cloud API webhook (WAP-002): Meta's `GET` handshake, then every inbound message and status.
 *
 * Prove the signature, persist every item, then acknowledge without business/provider work on the
 * production database queue. The durable inbox, row-locked worker and cron recovery make retries safe.
 */
final class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly QueueWhatsAppEvent $queue,
        private readonly WhatsAppCredentials $credentials,
    ) {}

    /** Meta's handshake: it asks for the token we configured, and we echo its challenge back. */
    public function verify(Request $request): Response|JsonResponse
    {
        $expected = $this->credentials->verifyToken();
        $mode = (string) $request->query('hub_mode');
        $token = (string) $request->query('hub_verify_token');
        $challenge = (string) $request->query('hub_challenge');

        if ($expected === '' || $mode !== 'subscribe' || ! hash_equals($expected, $token)) {
            return response()->json(['error' => 'Verification failed.'], 403);
        }

        return response($challenge, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $secret = $this->credentials->appSecret();
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '' || ! $this->signatureMatches($secret, $signature, $request->getContent())) {
            Log::warning('WhatsApp webhook: signature did not match.', ['ip' => $request->ip()]);

            abort(403);
        }

        $ids = [];
        foreach ((array) ($request->json('entry') ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = is_array($change) ? ($change['value'] ?? null) : null;
                if (! is_array($value)) {
                    continue;
                }
                foreach ($this->events($value) as $event) {
                    // Persist the entire batch before queueing any business work. Unique provider/id also
                    // handles concurrent duplicate deliveries; retain the original trusted payload on replay.
                    $stored = WebhookEvent::query()->firstOrCreate([
                        'provider' => 'whatsapp', 'external_id' => $event['id'],
                    ], [
                        'type' => $event['type'], 'payload' => $event['payload'],
                        'signature_valid' => true, 'status' => WebhookStatus::Received,
                    ]);
                    $ids[$stored->id] = $stored->id;
                }
            }
        }

        foreach ($ids as $id) {
            try {
                $this->queue->handle($id);
            } catch (Throwable $exception) {
                // The durable row is already committed. Cron will retry an expired queue lease even if
                // the queue broker/insert is unavailable here. Do not log a payload or exception message.
                Log::warning('WhatsApp event enqueue failed.', ['event_id' => $id, 'exception' => $exception::class]);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Normalize each batch item into its own replayable payload, keeping metadata and matching contact names.
     *
     * @param  array<string, mixed>  $value
     * @return list<array{id: string, type: string, payload: array<string, mixed>}>
     */
    private function events(array $value): array
    {
        $events = [];
        foreach (['messages' => 'message', 'statuses' => 'status'] as $key => $type) {
            foreach ((array) ($value[$key] ?? []) as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '') {
                    continue;
                }
                if ($type === 'status' && ! is_string($item['status'] ?? null)) {
                    continue;
                }
                $id = $item['id'].($type === 'status' ? ':'.$item['status'] : '');
                if (strlen($id) > 191) {
                    continue;
                }
                $normalized = $value;
                unset($normalized['messages'], $normalized['statuses']);
                $normalized[$key] = [$item];
                if ($type === 'message') {
                    $normalized['contacts'] = array_values(array_filter((array) ($value['contacts'] ?? []),
                        fn (mixed $contact): bool => is_array($contact) && ($contact['wa_id'] ?? null) === ($item['from'] ?? null)));
                } else {
                    unset($normalized['contacts']);
                }
                $events[] = ['id' => $id, 'type' => $type, 'payload' => $normalized];
            }
        }

        return $events;
    }

    private function signatureMatches(string $secret, string $signature, string $body): bool
    {
        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature);
    }
}
