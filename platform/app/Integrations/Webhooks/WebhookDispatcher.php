<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

/**
 * Fans a business moment out to every active endpoint subscribed to it. One delivery row per
 * (endpoint, occurrence) — the job then owns the attempts; this class only decides who hears it.
 */
final class WebhookDispatcher
{
    /** @param array<string, mixed> $data */
    public function dispatch(string $event, array $data): void
    {
        $payload = ['event' => $event, 'occurred_at' => now()->toIso8601String(), 'data' => $data];

        OutboundWebhook::query()->active()->get()
            ->filter(fn (OutboundWebhook $webhook): bool => $webhook->subscribesTo($event))
            ->each(fn (OutboundWebhook $webhook) => $this->enqueue($webhook, $event, $payload));
    }

    /** A test delivery the owner can watch arrive. Paused endpoints never receive requests. */
    public function ping(OutboundWebhook $webhook): WebhookDelivery
    {
        return $this->enqueue($webhook, WebhookEvents::TEST, [
            'event' => WebhookEvents::TEST,
            'occurred_at' => now()->toIso8601String(),
            'data' => ['message' => 'Woodex OS webhook test — if your app received this, the connection works.', 'endpoint' => $webhook->name],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function enqueue(OutboundWebhook $webhook, string $event, array $payload): WebhookDelivery
    {
        $delivery = $webhook->deliveries()->create([
            'event' => $event,
            'payload' => $payload,
            'status' => 'pending',
        ]);

        DeliverWebhook::dispatch($delivery->id)->onConnection('database')->onQueue('webhooks')->afterCommit();

        return $delivery;
    }
}
