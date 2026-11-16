<?php

declare(strict_types=1);

namespace App\Http\Controllers\Website;

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use App\Http\Controllers\Controller;
use App\Settings\BusinessSettings;
use App\Support\Phone;
use App\Support\Website\ContactFormResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two addresses the original website's JavaScript already calls (docs/11 §2).
 *
 * The pages are served exactly as the old site made them, so they arrive with their own JavaScript, which posts
 * to `/api/forms.php` and `/api/chat.php`. Rather than change a single character of those pages, these two
 * addresses answer in the shape that JavaScript expects and hand what it sends to Woodex OS: an enquiry becomes a
 * lead in the CRM, on the same pipeline as every other enquiry (CAP-003).
 *
 * Chat answers "switched off" for now: the widget then stays out of the way instead of showing an error. The
 * website chat itself is P4 (CNV-001/008), and it will speak to this same address.
 */
final class LegacySiteBridgeController extends Controller
{
    public function __construct(private readonly BusinessSettings $business) {}

    /** The form keys the pages send, and the lead source each one means. */
    private const array SOURCES = [
        'whatsapp' => LeadSource::WhatsApp,
        'estimator' => LeadSource::Estimator,
        'booking' => LeadSource::Booking,
        'book-a-visit' => LeadSource::Booking,
        'brief' => LeadSource::WebsiteForm,
        'website' => LeadSource::WebsiteForm,
    ];

    /** GET /api/forms.php — the pages ask whether a spam check is wanted before they draw the form. */
    public function config(): JsonResponse
    {
        // No Cloudflare Turnstile on this site: the honeypot and the rate limiter are the spam check.
        return response()->json(['ok' => true, 'turnstile' => '']);
    }

    /** POST /api/forms.php — an enquiry from any form on any of the 142 pages. */
    public function forms(Request $request, CaptureLead $captureLead): Response
    {
        ContactFormResponse::prepare($request);

        $action = trim((string) $request->input('action', ''));

        // A visitor tapping the WhatsApp button: counted by the widget itself, nothing to store.
        if ($action === 'wa_click') {
            return response()->json(['ok' => true]);
        }

        // The booking widget asks first whether it is switched on. Woodex OS has no diaries or staff availability
        // yet (PRD §5.6), so the honest answer is "off" — the page then shows its own "online booking is paused"
        // panel with the WhatsApp number, rather than offering times nobody has confirmed.
        if ($action === 'book_cfg') {
            return response()->json(['ok' => true, 'on' => false, 'types' => [], 'areas' => []]);
        }

        if ($action === 'book_slots') {
            return response()->json([
                'ok' => false,
                'error' => 'Online booking is paused, so no times can be offered yet.',
            ]);
        }

        // A bot fills the hidden field; a person never sees it. Look successful, store nothing.
        if (filled($request->input('_hp')) || filled($request->input('company_hp'))) {
            return ContactFormResponse::fromJson($request, response()->json(['ok' => true, 'id' => 0]));
        }

        // The limiter is counted here rather than on the route, because this address also answers the widget's
        // WhatsApp taps and the booking questions: only writing a lead should cost the studio's enquiry budget.
        if ($this->tooManyEnquiries($request)) {
            return ContactFormResponse::fromJson($request, response()->json([
                'ok' => false,
                // The number comes from Settings → Business: it is the studio's public WhatsApp line, and the
                // one place it may be typed is the settings screen (docs/18 §4).
                'error' => 'Too many enquiries from this connection. Please try again in a minute, or send us a WhatsApp message on '.Phone::display($this->business->phone).'.',
            ], 429));
        }

        RateLimiter::hit('legacy-leads-minute:'.$request->ip(), 60);
        RateLimiter::hit('legacy-leads-day:'.$request->ip(), 86_400);

        // Someone asking for a visit is an enquiry even though we cannot confirm the slot: it is kept as a lead
        // and the page tells them the team will confirm on WhatsApp, which is how the studio has always worked.
        if ($action === 'book_create') {
            return ContactFormResponse::fromJson($request, $this->bookingRequest($request, $captureLead));
        }

        try {
            $data = $this->validated($request);
        } catch (ValidationException $e) {
            return ContactFormResponse::fromJson($request, $this->invalid($e), array_keys($e->errors()));
        }

        $result = $captureLead->handle($this->leadInput($request, $data));

        // `id` is what the original JavaScript shows the visitor; the reference is our own, and worth keeping.
        return ContactFormResponse::fromJson($request, response()->json([
            'ok' => true,
            'id' => $result->lead->id,
            'reference' => $result->lead->ref,
        ], $result->leadCreated ? 201 : 200));
    }

    /** GET|POST /api/chat.php — the live chat of the original pages, not switched on yet. */
    public function chat(): JsonResponse
    {
        return response()->json(['ok' => true, 'on' => false]);
    }

    /** Five enquiries a minute and fifty a day from one address: a person never reaches either. */
    private function tooManyEnquiries(Request $request): bool
    {
        $ip = (string) $request->ip();

        return RateLimiter::tooManyAttempts('legacy-leads-minute:'.$ip, 5)
            || RateLimiter::tooManyAttempts('legacy-leads-day:'.$ip, 50);
    }

    /** POST /api/forms.php with action=book_create — a requested visit, office meeting or video call. */
    private function bookingRequest(Request $request, CaptureLead $captureLead): JsonResponse
    {
        try {
            $data = Validator::make($request->all(), [
                'name' => ['required', 'string', 'min:2', 'max:160'],
                'phone' => ['required', 'string', 'max:40', fn (string $attribute, mixed $value, \Closure $fail) => filled($value) && ! Phone::isValid((string) $value) ? $fail('Please enter a valid phone number, for example 0322 4000768.') : null],
                'email' => ['nullable', 'email:rfc', 'max:160'],
                'type' => ['nullable', 'string', 'max:40'],
                'date' => ['nullable', 'string', 'max:40'],
                'time' => ['nullable', 'string', 'max:20'],
                'city' => ['nullable', 'string', 'max:80'],
                'address' => ['nullable', 'string', 'max:500'],
                'service' => ['nullable', 'string', 'max:190'],
                'note' => ['nullable', 'string', 'max:2000'],
            ])->validate();
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }

        $page = trim((string) $request->input('page', ''));
        $slot = array_filter([
            'type' => $data['type'] ?? null,
            'date' => $data['date'] ?? null,
            'time' => $data['time'] ?? null,
        ]);

        $lines = array_filter([
            filled($data['city'] ?? null) ? 'City: '.$data['city'] : null,
            filled($data['address'] ?? null) ? 'Address: '.$data['address'] : null,
            filled($data['note'] ?? null) ? 'Note: '.$data['note'] : null,
        ]);

        $result = $captureLead->handle(new LeadInput(
            name: (string) $data['name'],
            phone: (string) $data['phone'],
            email: filled($data['email'] ?? null) ? (string) $data['email'] : null,
            source: LeadSource::Booking,
            businessLine: BusinessLine::Interior,
            service: filled($data['service'] ?? null) ? mb_substr((string) $data['service'], 0, 60) : null,
            message: 'Booking request'.($slot === [] ? '' : ' for '.implode(', ', $slot)).($lines === [] ? '.' : ': '.implode('. ', $lines)),
            sourceDetail: 'book-a-visit',
            attribution: $this->attribution($request, $page),
            meta: ['form' => 'booking', 'page' => $page, 'slot' => $slot, 'fields' => $this->extraFields($request)],
        ));

        // `ok: true` takes the page to its "booking request received, we will confirm on WhatsApp" panel — which is
        // exactly what happened: the request is with the studio, the slot is not promised.
        return response()->json([
            'ok' => true,
            'id' => $result->lead->id,
            'reference' => $result->lead->ref,
        ], $result->leadCreated ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && ! Phone::isValid((string) $value)) {
                    $fail('Please enter a valid phone number, for example 0322 4000768.');
                }
            }],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:160'],
            'service' => ['nullable', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:5000'],
            'page' => ['nullable', 'string', 'max:500'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function leadInput(Request $request, array $data): LeadInput
    {
        $form = trim((string) $request->input('form', 'website'));
        $page = trim((string) ($data['page'] ?? ''));
        $consent = $request->boolean('consent') || $request->boolean('agree') || $request->boolean('whatsapp_optin');

        return new LeadInput(
            name: (string) $data['name'],
            phone: filled($data['phone'] ?? null) ? (string) $data['phone'] : null,
            email: filled($data['email'] ?? null) ? (string) $data['email'] : null,
            source: self::SOURCES[$form] ?? LeadSource::WebsiteForm,
            businessLine: BusinessLine::Interior,
            service: filled($data['service'] ?? null) ? mb_substr((string) $data['service'], 0, 60) : null,
            message: filled($data['message'] ?? null) ? (string) $data['message'] : null,
            sourceDetail: $form !== '' ? $form : null,
            attribution: $this->attribution($request, $page),
            consentMarketing: $consent,
            consentWhatsapp: $consent,
            meta: ['form' => $form, 'page' => $page, 'fields' => $this->extraFields($request)],
        );
    }

    /**
     * @return array<string, string>
     */
    private function attribution(Request $request, string $page): array
    {
        return array_filter([
            'landing' => $page,
            'referrer' => $request->headers->get('referer'),
            'device' => $request->userAgent(),
        ]);
    }

    /**
     * Everything the page sent that has no column of its own: nothing a client typed is thrown away.
     *
     * @return array<string, string>
     */
    private function extraFields(Request $request): array
    {
        $known = ['name', 'phone', 'email', 'service', 'message', 'page', 'form', 'action', '_hp', 'company_hp', 'cf-turnstile-response', 'turnstile'];

        if (ContactFormResponse::wantsHtml($request)) {
            $known[] = '_response';
        }

        return collect($request->all())
            ->reject(fn (mixed $value, string $key): bool => in_array($key, $known, true) || ! is_scalar($value) || trim((string) $value) === '')
            ->map(fn (mixed $value): string => mb_substr(trim((string) $value), 0, 500))
            ->all();
    }

    /** The page shows our message next to the WhatsApp number, so it has to read like one sentence. */
    private function invalid(ValidationException $e): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => collect($e->errors())->flatten()->first() ?: 'Please check the form and try again.',
        ], 422);
    }
}
