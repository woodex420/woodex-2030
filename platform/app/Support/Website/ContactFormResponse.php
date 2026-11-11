<?php

declare(strict_types=1);

namespace App\Support\Website;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Presentation only: native enquiry forms reuse the same capture and spam checks as JSON clients. */
final class ContactFormResponse
{
    public static function wantsHtml(Request $request): bool
    {
        return $request->isMethod('POST')
            && $request->getPathInfo() === '/api/forms.php'
            && strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) === 'application/x-www-form-urlencoded'
            && ! $request->isJson() && ! $request->ajax()
            && in_array('text/html', $request->getAcceptableContentTypes(), true)
            && $request->prefers(['application/json', 'text/html']) === 'text/html'
            && is_string($request->input('form'))
            && preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/', $request->input('form')) === 1
            && $request->input('_response') === 'html'
            && in_array($request->input('action'), [null, '', 'book_create'], true);
    }

    /** Normalize only the native transport's established field aliases, never ordinary API payloads. */
    public static function prepare(Request $request): void
    {
        if (! self::wantsHtml($request)) {
            return;
        }

        if (! $request->has('message')) {
            foreach (['details', 'notes', 'note'] as $alias) {
                if (is_string($request->input($alias))) {
                    $request->merge(['message' => $request->input($alias)]);
                    break;
                }
            }
        }

        if (! $request->has('service')) {
            if ($request->input('form') === 'office-fitout') {
                $request->merge(['service' => 'Office fit-out']);
            } elseif ($request->input('form') === 'fitout-hub' && is_string($request->input('sector'))) {
                $request->merge(['service' => 'Fit-out: '.$request->input('sector')]);
            }
        }
    }

    /** @param list<string> $invalidFields */
    public static function fromJson(Request $request, JsonResponse $response, array $invalidFields = []): Response
    {
        if (! self::wantsHtml($request)) {
            return $response;
        }

        $data = $response->getData(true);
        $saved = ($data['ok'] ?? false) === true && is_int($data['id'] ?? null) && $data['id'] > 0;

        return self::document($response->getStatusCode(), $saved, $invalidFields, $response->headers->all());
    }

    public static function fromException(Request $request, Throwable $exception): ?Response
    {
        if (! self::wantsHtml($request)) {
            return null;
        }

        $status = $exception instanceof ValidationException ? 422 : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);

        return self::document(
            $status,
            false,
            $exception instanceof ValidationException ? array_keys($exception->errors()) : [],
            $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [],
        );
    }

    public static function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, nosnippet');

        return $response;
    }

    /**
     * Never pass client data, raw validator messages, exception text or arbitrary response headers into HTML.
     *
     * @param  list<string>  $invalidFields
     * @param  array<string, string|list<string>>  $headers
     */
    private static function document(int $status, bool $saved, array $invalidFields, array $headers): Response
    {
        [$heading, $message] = match (true) {
            $status === 422 => ['Please check your enquiry', 'Please correct your details and submit again from the Contact page.'],
            $status === 429 => ['Please try again later', 'There have been too many requests from this connection. Please try again later or return to Contact for help.'],
            $status >= 400 => ['We could not confirm your enquiry', 'We could not confirm whether your enquiry was saved. Please return to Contact for help.'],
            $saved => ['Thank you', 'Your enquiry has been saved. You can return to the Contact page if you need further help.'],
            default => ['Thank you', 'You can return to the Contact page for further help.'],
        };
        $labels = ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'service' => 'Project type', 'message' => 'About the space', 'page' => 'Contact page'];
        $fields = array_values(array_intersect_key($labels, array_flip($invalidFields)));
        $safeHeaders = array_intersect_key(array_change_key_case($headers, CASE_LOWER), array_flip(['retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset']));

        return self::protect(response()->view('website.contact-form-result', compact('heading', 'message', 'fields'), $status, $safeHeaders));
    }
}
