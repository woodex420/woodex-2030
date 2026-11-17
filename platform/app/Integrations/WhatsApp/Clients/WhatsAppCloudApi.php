<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp\Clients;

use App\Domain\System\Models\IntegrationLog;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\SendResult;
use App\Integrations\WhatsApp\WhatsAppCredentials;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp Cloud API (Graph) client, WAP-001…WAP-005.
 *
 * Credentials come from the dashboard or the server .env (WhatsAppCredentials, docs/06 §4) and are never shown
 * in the dashboard: the Integrations card only says whether they are set. Every call is written to the
 * integration log, so a message that never arrived can be explained from the admin.
 */
final class WhatsAppCloudApi implements WhatsAppGateway
{
    /** Meta never returns more than a few hundred templates; five pages is far past any studio's list. */
    private const MAX_TEMPLATE_PAGES = 5;

    public function __construct(
        private readonly WhatsAppCredentials $credentials,
    ) {}

    public function isConfigured(): bool
    {
        return $this->credentials->isConfigured();
    }

    public function sendText(string $to, string $body): SendResult
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    public function sendTemplate(string $to, string $template, string $language = 'en', array $components = []): SendResult
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ]);
    }

    public function sendMedia(string $to, string $type, string $mediaId, ?string $caption = null, ?string $filename = null): SendResult
    {
        $object = ['id' => $mediaId];

        if ($caption !== null && $caption !== '' && in_array($type, ['image', 'document', 'video'], true)) {
            $object['caption'] = $caption;
        }

        if ($type === 'document' && $filename !== null && $filename !== '') {
            // WhatsApp shows this name on the client's bubble; without it Meta invents one.
            $object['filename'] = $filename;
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $type,
            $type => $object,
        ]);
    }

    public function uploadMedia(string $content, string $mime, string $filename): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $started = microtime(true);
        $status = null;
        $mediaId = null;
        $error = null;

        try {
            $response = Http::timeout(30)->connectTimeout(5)->acceptJson()
                ->withToken((string) $this->credentials->token())
                ->attach('file', $content, $filename, ['Content-Type' => $mime])
                ->post($this->url('media'), ['messaging_product' => 'whatsapp']);

            $status = $response->status();
            $id = $response->json('id');
            $mediaId = $response->successful() && is_string($id) && $id !== '' ? $id : null;
            $error = $mediaId !== null ? null : (is_string($response->json('error.message')) ? (string) $response->json('error.message') : 'Meta did not take the file.');
        } catch (Throwable $exception) {
            report($exception);
            $error = $exception->getMessage();
        }

        IntegrationLog::create([
            'integration' => 'whatsapp',
            'direction' => 'out',
            'action' => 'media.upload',
            'status_code' => $status,
            'ok' => $mediaId !== null,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            // Never the token, never the file itself: only what kind of thing it was.
            'request' => ['mime' => $mime, 'filename' => $filename, 'bytes' => strlen($content)],
            'response' => $mediaId !== null ? ['media_id' => $mediaId] : null,
            'error' => $error,
            'created_at' => now(),
        ]);

        return $mediaId;
    }

    public function media(string $mediaId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::timeout(8)->connectTimeout(4)->acceptJson()
                ->withToken((string) $this->credentials->token())
                ->get($this->url($mediaId));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $url = $response->json('url');

        return is_string($url) && $url !== ''
            ? [
                'url' => $url,
                'mime_type' => (string) ($response->json('mime_type') ?? ''),
                'sha256' => (string) ($response->json('sha256') ?? ''),
                'file_size' => (int) ($response->json('file_size') ?? 0),
            ]
            : null;
    }

    public function accountInfo(): array
    {
        $empty = ['ok' => false, 'display_phone_number' => null, 'verified_name' => null, 'quality_rating' => null, 'error' => 'WhatsApp is not connected yet.'];

        if (! $this->isConfigured()) {
            return $empty;
        }

        $response = $this->call('account', $this->url('', ['fields' => 'display_phone_number,verified_name,quality_rating']));

        if (! $response['ok']) {
            return [...$empty, 'error' => $response['error']];
        }

        return [
            'ok' => true,
            'display_phone_number' => $response['json']['display_phone_number'] ?? null,
            'verified_name' => $response['json']['verified_name'] ?? null,
            'quality_rating' => $response['json']['quality_rating'] ?? null,
            'error' => null,
        ];
    }

    public function templates(): array
    {
        if (! $this->credentials->canManageTemplates()) {
            return [];
        }

        $fields = 'id,name,language,category,status,components,rejected_reason';
        $path = $this->wabaUrl('message_templates', ['fields' => $fields, 'limit' => 100]);
        $templates = [];

        for ($page = 0; $page < self::MAX_TEMPLATE_PAGES; $page++) {
            $response = $this->call('templates', $path);

            if (! $response['ok']) {
                break;
            }

            foreach ((array) ($response['json']['data'] ?? []) as $template) {
                if (is_array($template)) {
                    $templates[] = $this->normaliseTemplate($template);
                }
            }

            $next = $response['json']['paging']['next'] ?? null;
            $after = $response['json']['paging']['cursors']['after'] ?? null;

            if (! is_string($next) || $next === '' || ! is_string($after) || $after === '') {
                break;
            }

            // Meta's next link carries our fields and limit already; asking again with them is harmless.
            $path = $this->wabaUrl('message_templates', ['fields' => $fields, 'limit' => 100, 'after' => $after]);
        }

        return $templates;
    }

    public function submitTemplate(string $name, string $language, string $category, string $body, array $exampleValues = []): array
    {
        if (! $this->credentials->canManageTemplates()) {
            return ['ok' => false, 'id' => null, 'status' => null, 'error' => 'Add the WhatsApp Business Account id before submitting templates.'];
        }

        $components = [
            [
                'type' => 'BODY',
                'text' => $body,
                // Meta will not review a template whose variables it has never seen filled in.
                'example' => ['body_text' => [$exampleValues === [] ? [''] : array_values($exampleValues)]],
            ],
        ];

        $response = $this->call('template.create', $this->wabaUrl('message_templates'), [
            'name' => $name,
            'language' => $language,
            'category' => $category,
            'components' => $components,
        ]);

        return [
            'ok' => $response['ok'],
            'id' => is_string($response['json']['id'] ?? null) ? (string) $response['json']['id'] : null,
            'status' => is_string($response['json']['status'] ?? null) ? (string) $response['json']['status'] : null,
            'error' => $response['ok'] ? null : $response['error'],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function send(array $payload): SendResult
    {
        if (! $this->isConfigured()) {
            return SendResult::failed('WhatsApp is not configured in the server .env.');
        }

        $started = microtime(true);
        $status = null;
        $result = SendResult::failed('Unknown error.');

        try {
            $response = Http::timeout(8)->connectTimeout(4)->acceptJson()
                ->withToken((string) $this->credentials->token())
                ->post($this->url('messages'), $payload);

            $status = $response->status();
            $messageId = $response->json('messages.0.id');
            $error = $response->json('error.message');

            $result = $response->successful() && is_string($messageId)
                ? SendResult::sent($messageId)
                : SendResult::failed(is_string($error) ? $error : 'Meta refused the message.', $status);
        } catch (Throwable $exception) {
            report($exception);
            $result = SendResult::failed($exception->getMessage());
        }

        IntegrationLog::create([
            'integration' => 'whatsapp',
            'direction' => 'out',
            'action' => is_string($payload['type'] ?? null) ? (string) $payload['type'] : 'message',
            'status_code' => $status,
            'ok' => $result->ok,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            // Never log the token or the message body (it is client conversation, D-47): only its length.
            'request' => ['to' => $payload['to'] ?? null, 'length' => isset($payload['text']['body']) ? mb_strlen($payload['text']['body']) : null],
            'response' => $result->messageId !== null ? ['message_id' => $result->messageId] : null,
            'error' => $result->ok ? null : $result->error,
            'created_at' => now(),
        ]);

        return $result;
    }

    /**
     * One Graph call, its outcome logged like any other integration call.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{ok: bool, json: array<string, mixed>, error: ?string}
     */
    private function call(string $action, string $url, ?array $payload = null): array
    {
        $started = microtime(true);
        $status = null;
        $json = [];

        try {
            $request = Http::timeout(10)->connectTimeout(5)->acceptJson()->withToken((string) $this->credentials->token());
            $response = $payload === null ? $request->get($url) : $request->post($url, $payload);

            $status = $response->status();
            $json = is_array($response->json()) ? $response->json() : [];
            $ok = $response->successful();
            $error = $ok ? null : (is_string($json['error']['message'] ?? null) ? (string) $json['error']['message'] : 'Meta answered '.$status.'.');
        } catch (Throwable $exception) {
            report($exception);
            $ok = false;
            $error = $exception->getMessage();
        }

        IntegrationLog::create([
            'integration' => 'whatsapp',
            'direction' => 'out',
            'action' => $action,
            'status_code' => $status,
            'ok' => $ok,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'request' => ['url' => $this->loggableUrl($url)],
            'response' => $ok ? null : $json['error'] ?? null,
            'error' => $error,
            'created_at' => now(),
        ]);

        return ['ok' => $ok, 'json' => $json, 'error' => $error];
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array{id: string, name: string, language: string, category: string, status: string, components: list<array<string, mixed>>, rejected_reason: ?string}
     */
    private function normaliseTemplate(array $template): array
    {
        $components = [];

        foreach ((array) ($template['components'] ?? []) as $component) {
            if (is_array($component)) {
                $components[] = $component;
            }
        }

        return [
            'id' => (string) ($template['id'] ?? ''),
            'name' => (string) ($template['name'] ?? ''),
            'language' => (string) ($template['language'] ?? 'en'),
            'category' => (string) ($template['category'] ?? ''),
            'status' => (string) ($template['status'] ?? ''),
            'components' => $components,
            'rejected_reason' => is_string($template['rejected_reason'] ?? null) ? (string) $template['rejected_reason'] : null,
        ];
    }

    /**
     * https://graph.facebook.com/{version}/{phone-number-id}/{path}
     *
     * @param  array<string, mixed>  $query
     */
    private function url(string $path, array $query = []): string
    {
        return $this->build((string) $this->credentials->phoneNumberId(), $path, $query);
    }

    /**
     * https://graph.facebook.com/{version}/{waba-id}/{path} — templates belong to the account, not the number.
     *
     * @param  array<string, mixed>  $query
     */
    private function wabaUrl(string $path, array $query = []): string
    {
        return $this->build((string) $this->credentials->wabaId(), $path, $query);
    }

    /** @param  array<string, mixed>  $query */
    private function build(string $owner, string $path, array $query): string
    {
        $url = sprintf(
            '%s/%s/%s%s',
            rtrim($this->credentials->apiBase(), '/'),
            trim($this->credentials->apiVersion(), '/'),
            $owner,
            $path === '' ? '' : '/'.ltrim($path, '/'),
        );

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /** The number id and the token are the only sensitive parts of these URLs; the log keeps neither. */
    private function loggableUrl(string $url): string
    {
        $id = (string) $this->credentials->phoneNumberId();

        return $id === '' ? $url : str_replace($id, '{phone-number-id}', $url);
    }
}
