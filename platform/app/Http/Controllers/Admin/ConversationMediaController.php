<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Conversations\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A message's media file (CNV-008): the photograph a client sent, the PDF the studio sent back.
 * Files sit on the private `local` disk, so the only way to one is through here — and only for
 * someone who may read the conversation it belongs to. Images, audio, video and PDFs open inline
 * (that is what the thread's <img>/<audio>/<video> tags ask for); everything else downloads.
 */
final class ConversationMediaController
{
    public function __invoke(Request $request, Message $message): Response
    {
        abort_if($message->media_path === null || $message->media_disk === null, 404);
        abort_unless(Storage::disk($message->media_disk)->exists($message->media_path), 404);

        $conversation = $message->conversation;
        abort_if($conversation === null || Gate::denies('view', $conversation), 403);

        $mime = (string) ($message->media_mime ?? 'application/octet-stream');
        $inline = str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/') || $mime === 'application/pdf';

        $name = (string) ($message->media_name ?? basename($message->media_path));
        $disposition = ($inline && ! $request->boolean('download')) ? 'inline' : 'attachment';

        return new StreamedResponse(function () use ($message): void {
            echo Storage::disk($message->media_disk)->get($message->media_path);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.str_replace('"', '', $name).'"',
            // The thread re-requests these on every poll render; a day of private cache is plenty.
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
