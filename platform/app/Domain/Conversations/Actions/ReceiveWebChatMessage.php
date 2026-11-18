<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Actions;

use App\Domain\Bot\Actions\ReplyWithBot;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Actions\LogLeadActivity;
use App\Domain\Crm\Actions\ManageTask;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Jobs\AskForPhoneNumber;
use App\Settings\BusinessSettings;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One visitor message through the widget's rule-based intake (P8.5): asked_name → asked_number →
 * asked_service → live (legacy encoded contact states are still readable). A valid contact immediately creates/links the lead via CaptureLead (the single
 * entry point, CAP-003) — that fires LeadCaptured, which alerts the team exactly like any other
 * enquiry. Service completion enriches that same lead. From there the thread is a normal web_chat conversation the team answers from the inbox.
 *
 * Two consent rules hold: typing a number into a chat box is not WhatsApp consent (consent_whatsapp
 * stays false), and when the visitor gives an email instead of a number we ask for the number
 * inside the chat itself and open the same follow-up task the email flow uses — the task is the
 * exactly-once anchor, so the client is never asked twice.
 */
final class ReceiveWebChatMessage
{
    private const STATE_ASKED_NAME = 'asked_name';

    private const STATE_ASKED_NUMBER = 'asked_number';

    private const STATE_LIVE = 'live';

    public function __construct(
        private readonly StartWebChat $startWebChat,
        private readonly CaptureLead $captureLead,
        private readonly ManageTask $manageTask,
        private readonly LogLeadActivity $logActivity,
        private readonly BusinessSettings $business,
    ) {}

    public function handle(Conversation $conversation, string $body): Message
    {
        $body = trim($body);
        $body = $body === '' ? '👍' : mb_substr($body, 0, 2000);
        $runBot = false;

        // Serialize intake transitions: two requests with stale models must not capture twice.
        $message = DB::transaction(function () use ($conversation, $body, &$runBot): Message {
            $thread = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($thread->status === ConversationStatus::Closed) {
                $thread->update(['status' => ConversationStatus::Open, 'closed_at' => null]);
            }
            $message = $thread->messages()->create([
                'direction' => MessageDirection::In,
                'type' => MessageType::Text,
                'body' => $body,
                'status' => MessageStatus::Delivered,
                'delivered_at' => now(),
            ]);
            $thread->touchLastMessage(MessageDirection::In, $body);

            // A real staff reply hands the thread to humans. Internal notes do not stop intake.
            $human = $thread->messages()->whereNotNull('user_id')
                ->where('direction', MessageDirection::Out->value)
                ->where('type', '!=', MessageType::Note->value)->exists();
            if ($human) {
                $thread->update(['widget_state' => self::STATE_LIVE]);

                return $message;
            }

            $state = (string) ($thread->widget_state ?? self::STATE_LIVE);
            if ($state === self::STATE_ASKED_NAME) {
                $this->handleName($thread, $body);
            } elseif ($state === 'asked_service' || str_starts_with($state, 'asked_service|')) {
                $this->handleService($thread, $state, $body);
            } elseif ($state === self::STATE_ASKED_NUMBER) {
                $this->handleNumber($thread, $body);
            } else {
                $runBot = true;
            }

            return $message;
        });

        $conversation->refresh();
        // Provider work is outside the intake transaction. A failed bot cannot lose the message.
        if ($runBot) {
            try {
                app(ReplyWithBot::class)->handle($conversation);
            } catch (Throwable $botFailure) {
                report($botFailure);
            }
        }

        return $message;
    }

    /** The name becomes the conversation subject and later the lead's name. */
    private function handleName(Conversation $conversation, string $body): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $body) ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            $this->startWebChat->bot($conversation, 'Sorry, I did not catch that — what name should the team use for you?');

            return;
        }

        $conversation->update([
            'subject' => $name,
            'widget_state' => self::STATE_ASKED_NUMBER,
        ]);

        $this->startWebChat->bot($conversation, "Nice to meet you, {$name}! What's the best WhatsApp number to reach you on?");
    }

    /** A phone number (preferred) or an email address, both validated before we move on. */
    private function handleNumber(Conversation $conversation, string $body): void
    {
        $candidate = trim(str_replace(['tel:', ' ', '-', '(', ')'], '', $body));

        if (Phone::isValid($candidate) || Phone::isValid('+'.ltrim($candidate, '+'))) {
            $contact = (string) Phone::normalize($candidate);
            $this->captureContact($conversation, $contact);
            $conversation->update(['widget_state' => 'asked_service']);
            $this->startWebChat->bot($conversation, 'Your enquiry is saved. And what do you need done — for example a wardrobe, a full home interior, an office fit-out, or a kitchen?');

            return;
        }

        $email = filter_var(trim($body), FILTER_VALIDATE_EMAIL);
        if ($email !== false) {
            $contact = 'email:'.mb_strtolower($email);
            $this->captureContact($conversation, $contact);
            $conversation->update(['widget_state' => 'asked_service']);
            $this->startWebChat->bot($conversation, "Your enquiry is saved — we'll use {$email}. What do you need done — a wardrobe, a full home interior, an office fit-out, a kitchen?");

            return;
        }

        $this->startWebChat->bot($conversation, "Hmm, that doesn't look like a number. Please reply with your WhatsApp number (like 0322 4000768) — or an email address if you prefer.");
    }

    /** Capture at identification, not after the optional qualification question. */
    private function captureContact(Conversation $conversation, string $contact): void
    {
        if ($conversation->lead_id !== null) {
            return;
        }

        $isEmail = str_starts_with($contact, 'email:');
        $result = $this->captureLead->handle(new LeadInput(
            name: (string) ($conversation->subject ?? 'Website visitor'),
            phone: $isEmail ? null : $contact,
            email: $isEmail ? substr($contact, strlen('email:')) : null,
            source: LeadSource::WebsiteChat,
            message: 'Web chat enquiry — service not provided yet.',
            sourceDetail: 'Chat widget',
        ));
        $lead = $result->lead;
        $conversation->update(['contact_id' => $lead->contact_id, 'lead_id' => $lead->id]);

        // Anchor before the after-response email job runs. Supplying email does not consent to outreach.
        if ($isEmail && ! $lead->tasks()->where('title', AskForPhoneNumber::TASK_TITLE)->exists()) {
            $this->manageTask->create(AskForPhoneNumber::TASK_TITLE, null, null, null, $lead);
            $this->logActivity->handle($lead, ActivityType::System, null,
                'Website chat: email provided, no phone. Continue in this thread; ask for a number only if the visitor prefers WhatsApp.');
        }
    }

    /** Enrich the same enquiry; never fire LeadCaptured a second time for qualification. */
    private function handleService(Conversation $conversation, string $state, string $body): void
    {
        $saved = $conversation->contact;
        $contact = $state === 'asked_service'
            ? ($saved->phone ?? ($saved?->email ? 'email:'.$saved->email : ''))
            : substr($state, strlen('asked_service|'));
        if ($contact === '') {
            $conversation->update(['widget_state' => self::STATE_ASKED_NUMBER]);
            $this->startWebChat->bot($conversation, 'Please share a valid phone number or email so the team can save your enquiry.');

            return;
        }
        $isEmail = str_starts_with($contact, 'email:');
        // A retried identification request is not a service answer.
        if (($saved?->email !== null && mb_strtolower(trim($body)) === $saved->email)
            || ($isEmail && mb_strtolower(trim($body)) === substr($contact, 6))
            || (! $isEmail && Phone::normalize($body) === $contact)) {
            $this->startWebChat->bot($conversation, 'Your enquiry is saved. What service do you need? You can also wait here for our team.');

            return;
        }

        $this->captureContact($conversation, $contact); // also resumes pre-upgrade partial intake
        $lead = $conversation->lead()->lockForUpdate()->firstOrFail();
        $service = mb_substr(trim($body), 0, 190);
        // Do not overwrite an existing open lead's established service when capture merged it.
        if (blank($lead->service)) {
            $lead->service = $service;
        }
        if ($lead->message === 'Web chat enquiry — service not provided yet.') {
            $lead->message = 'Web chat enquiry: '.$service;
        }
        $lead->last_activity_at = now();
        $lead->save();
        // Visitor text is not a staff note: never interpret public @names as notification commands.
        $lead->activities()->create([
            'contact_id' => $lead->contact_id,
            'type' => ActivityType::System,
            'direction' => 'in',
            'body' => 'Website chat service provided: '.$service,
            'occurred_at' => now(),
        ]);
        $lead->contact()->update(['last_activity_at' => now()]);
        $conversation->update(['widget_state' => self::STATE_LIVE]);
        $name = (string) ($conversation->subject ?? 'Website visitor');

        $person = $this->business->brand_name.' team';
        $closing = "Thank you, {$name} — that's everything I need. I've passed your enquiry to the {$person}; they'll reply right here. You can also leave this page open and check back later.";

        if ($isEmail) {
            $closing .= ' And if you would like to continue on WhatsApp, just send your number here.';
        }

        $this->startWebChat->bot($conversation, $closing);
    }
}
