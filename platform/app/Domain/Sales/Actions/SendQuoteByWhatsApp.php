<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Automation\Actions\EnrollLead;
use App\Domain\Automation\Enums\SequenceTrigger;
use App\Domain\Conversations\Actions\SendConversationMessage;
use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Data\WhatsAppDelivery;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteVersion;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\System\Enums\WhatsAppTemplateEvent;
use App\Domain\System\Models\WhatsAppTemplate;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Models\User;
use Throwable;

/**
 * Puts a sent quotation in front of the client on WhatsApp (QUO-008, D-111).
 *
 * The quotation itself is already sent by `SendQuote` — snapshot frozen, PDF stored, link signed. This action only
 * carries the link to the client, because a link always shows the latest revision while a PDF in a chat goes stale
 * the moment the quote is revised.
 *
 * Meta's 24-hour rule decides how it goes: inside the window the message is written as we like; outside it only an
 * approved template may go out, so the link is sent as the template's first variable. Whatever happens is recorded
 * on the quote's timeline, and a failure is returned rather than thrown — the client's quotation does not hang on a
 * network call.
 */
final class SendQuoteByWhatsApp
{
    public function __construct(
        private readonly WhatsAppGateway $whatsapp,
        private readonly SendConversationMessage $send,
        private readonly LogQuoteEvent $log,
    ) {}

    public function handle(Quote $quote, QuoteVersion $version, User $by): WhatsAppDelivery
    {
        $number = $quote->contact?->whatsappNumber();

        if ($number === null || trim($number) === '') {
            return $this->record($quote, $version, $by, WhatsAppDelivery::failed('the client has no phone number for WhatsApp.'));
        }

        if (! $this->whatsapp->isConfigured()) {
            return $this->record($quote, $version, $by, WhatsAppDelivery::failed('WhatsApp is not connected in Settings yet.'));
        }

        $conversation = Conversation::query()->firstOrCreate(
            ['contact_id' => $quote->contact_id, 'channel' => Channel::Whatsapp->value],
            ['external_number' => $number, 'status' => ConversationStatus::Open, 'window_expires_at' => now()->addHours(24), 'unread_count' => 0],
        );

        try {
            $template = $this->quotationTemplate();

            $message = $conversation->isInWindow()
                ? $this->send->handle($conversation, QuoteLinks::whatsappBody($quote), $by)
                : $this->send->template($conversation, $template['name'], [['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => QuoteLinks::portal($quote)],
                ]]], $template['language'], $by);
        } catch (Throwable $exception) {
            return $this->record($quote, $version, $by, WhatsAppDelivery::failed($exception->getMessage()));
        }

        // AUT-001: a quotation that actually went out starts the "nobody answered it" sequences.
        if ($message->status !== MessageStatus::Failed && $quote->lead_id !== null) {
            $lead = Lead::query()->find($quote->lead_id);

            if ($lead !== null) {
                app(EnrollLead::class)->forTrigger(SequenceTrigger::QuotationSent, $lead);
            }
        }

        $delivery = $message->status === MessageStatus::Failed
            ? WhatsAppDelivery::failed($message->error_message ?? 'Meta did not accept the message.')
            : WhatsAppDelivery::sent((string) $message->external_id, $message->type === MessageType::Template);

        return $this->record($quote, $version, $by, $delivery, $message->id);
    }

    /**
     * The approved template Meta holds for quotations: one variable, the link (docs/06 §WhatsApp).
     *
     * The studio maps one on the templates screen (WAP-005), so the wording lives in Meta and not in the code.
     * Without a mapping the configured name is used, and failing that the plain name `quotation`, which is what
     * the studio's first template was called.
     *
     * @return array{name: string, language: string}
     */
    private function quotationTemplate(): array
    {
        $mapped = WhatsAppTemplate::forEvent(WhatsAppTemplateEvent::Quotation);

        if ($mapped !== null && $mapped->name !== '') {
            return ['name' => $mapped->name, 'language' => $mapped->language !== '' ? $mapped->language : 'en'];
        }

        return ['name' => (string) config('services.whatsapp.quotation_template', 'quotation'), 'language' => 'en'];
    }

    private function record(Quote $quote, QuoteVersion $version, User $by, WhatsAppDelivery $delivery, ?int $messageId = null): WhatsAppDelivery
    {
        // Revision 0 is a real revision, so it is kept even though it is falsy.
        $data = ['revision' => (int) $version->revision];

        if ($messageId !== null) {
            $data['message_id'] = $messageId;
        }

        if ($delivery->messageId !== null) {
            $data['external_id'] = $delivery->messageId;
        }

        if ($delivery->error !== null) {
            $data['error'] = $delivery->error;
        }

        if ($delivery->byTemplate) {
            $data['by_template'] = true;
        }

        $this->log->handle(
            $quote,
            $delivery->ok ? 'whatsapp_sent' : 'whatsapp_failed',
            $by,
            $delivery->summary($quote->label()),
            $data,
            ['quote_version_id' => $version->id],
        );

        return $delivery;
    }
}
