<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Domain\Conversations\Actions\ReceiveWebChatMessage;
use App\Domain\Conversations\Actions\StartWebChat;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Http\Controllers\Controller;
use App\Settings\BusinessSettings;
use App\Settings\SiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The web chat widget's three stateless endpoints (P8.5): open a thread, send a message, poll for
 * replies. The token in the visitor's localStorage is the only credential — resuming a thread is
 * just looking the token up. Everything is on by the owner's switch (Website Settings, A0): with
 * the widget off, the endpoints answer 404 like any other page that does not exist.
 */
final class ChatWidgetController extends Controller
{
    public function start(Request $request, SiteSettings $site, StartWebChat $startWebChat): JsonResponse
    {
        abort_unless($site->chat_widget_enabled, 404);

        $token = (string) $request->query('token', '');
        $conversation = $startWebChat->handle($token !== '' ? $token : null);

        return response()->json([
            'ok' => true,
            'token' => $conversation->widget_token,
            'messages' => $this->present($conversation->messages()->orderBy('id')->get()),
        ]);
    }

    public function send(Request $request, SiteSettings $site, StartWebChat $startWebChat, ReceiveWebChatMessage $receive): JsonResponse
    {
        abort_unless($site->chat_widget_enabled, 404);

        $data = $request->validate([
            'token' => ['required', 'string', 'max:48'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $conversation = $this->conversation($data['token'], $startWebChat);
        $receive->handle($conversation, (string) $data['body']);

        return response()->json([
            'ok' => true,
            'messages' => $this->present($conversation->messages()->orderBy('id')->get()),
        ]);
    }

    public function messages(Request $request, SiteSettings $site, StartWebChat $startWebChat): JsonResponse
    {
        abort_unless($site->chat_widget_enabled, 404);

        $data = $request->validate([
            'token' => ['required', 'string', 'max:48'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $conversation = $this->conversation($data['token'], $startWebChat);
        $after = (int) ($data['after'] ?? 0);

        $fresh = $conversation->messages()->orderBy('id')->get();
        $new = $fresh->filter(fn (Message $message): bool => $message->id > $after)->values();

        // The visitor has seen every staff reply in this response — mark it read for them.
        $conversation->messages()
            ->where('direction', MessageDirection::Out->value)
            ->whereNull('read_at')
            ->update(['delivered_at' => now(), 'read_at' => now()]);

        return response()->json([
            'ok' => true,
            'messages' => $this->present($new),
        ]);
    }

    /**
     * The widget script: GET /chat/widget.js — brand-coloured, self-contained, cacheable.
     * With the widget off it answers with a no-op comment rather than a 404, so a page holding a
     * cached copy of the script (or the link itself) degrades silently instead of erroring; the
     * data endpoints above stay firmly 404.
     */
    public function script(SiteSettings $site, BusinessSettings $business): Response
    {
        if (! $site->chat_widget_enabled) {
            return response('/* Woodex web chat is switched off. */', 200, [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => 'public, max-age=300',
            ]);
        }

        return response()
            ->view('chat.widget', [
                'brand' => $business->brand_name,
                'startUrl' => route('api.public.chat.start'),
                'sendUrl' => route('api.public.chat.send'),
                'messagesUrl' => route('api.public.chat.messages'),
            ])
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * @param  iterable<int, Message>  $messages
     * @return list<array{id: int, who: string, body: string, at: ?string}>
     */
    private function present(iterable $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            $out[] = [
                'id' => $message->id,
                'who' => $message->direction === MessageDirection::In
                    ? 'visitor'
                    : (isset($message->meta['bot']) ? 'bot' : 'staff'),
                'body' => $message->body,
                'at' => optional($message->sent_at ?? $message->delivered_at)->toIso8601String(),
            ];
        }

        return $out;
    }

    /** Token → conversation; an unknown token simply starts a fresh thread (no leak, no error). */
    private function conversation(string $token, StartWebChat $startWebChat): Conversation
    {
        return $startWebChat->handle($token);
    }
}
