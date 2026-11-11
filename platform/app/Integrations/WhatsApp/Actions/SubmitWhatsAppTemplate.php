<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp\Actions;

use App\Domain\System\Enums\WhatsAppTemplateStatus;
use App\Domain\System\Models\WhatsAppTemplate;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;

/**
 * Sends a new template to Meta for approval, and records it here as in review (WAP-005).
 *
 * Nothing can be sent with a template until Meta approves it — usually within a day, sometimes longer — so the
 * row is created with the status Meta will answer with, and the studio watches it move on the templates screen.
 *
 * Meta rejects a template for ordinary reasons we can catch before spending the round trip: a name that is not
 * lowercase, a body without variables it was promised, or variables without examples of what they will hold.
 */
final class SubmitWhatsAppTemplate
{
    public function __construct(
        private readonly WhatsAppGateway $whatsapp,
    ) {}

    /**
     * @param  list<string>  $exampleValues  One example per {{1}}…{{n}}, in order
     * @return array{ok: bool, template: ?WhatsAppTemplate, error: ?string}
     */
    public function handle(string $name, string $language, string $category, string $body, array $exampleValues = []): array
    {
        $name = strtolower(trim($name));
        $body = trim($body);
        $language = strtolower(trim($language)) ?: 'en';
        $category = strtoupper(trim($category)) ?: 'UTILITY';

        if ($name === '' || preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
            return $this->fail('The template name may only hold lowercase letters, numbers and underscores, e.g. quotation_ready.');
        }

        if ($body === '') {
            return $this->fail('Write the message the client will receive.');
        }

        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
        $variables = array_values(array_unique(array_map('intval', $matches[1] ?? [])));
        sort($variables);

        $examples = array_values(array_filter(array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $exampleValues)));

        if (count($examples) !== count($variables)) {
            return $this->fail(count($variables) === 0
                ? 'This template has no variables, so no examples are needed.'
                : 'Give one example for each of the '.count($variables).' variable(s) in the message, so Meta can see what they hold.');
        }

        if ($variables !== [] && $variables !== range(1, count($variables))) {
            return $this->fail('Number the variables in order: {{1}}, then {{2}}, and so on.');
        }

        if (WhatsAppTemplate::query()->where('name', $name)->where('language', $language)->exists()) {
            return $this->fail("A template called {$name} already exists for {$language}. Sync first, or choose another name.");
        }

        $result = $this->whatsapp->submitTemplate($name, $language, $category, $body, $examples);

        if (! $result['ok']) {
            return $this->fail($result['error'] ?? 'Meta refused the template.');
        }

        $template = WhatsAppTemplate::query()->create([
            'meta_id' => $result['id'],
            'name' => $name,
            'language' => $language,
            'category' => $category,
            'status' => WhatsAppTemplateStatus::fromMeta($result['status'] ?? 'PENDING')->value,
            'components' => [['type' => 'BODY', 'text' => $body, 'example' => ['body_text' => [$examples]]]],
            'body' => $body,
            'synced_at' => now(),
        ]);

        return ['ok' => true, 'template' => $template, 'error' => null];
    }

    /**
     * @return array{ok: bool, template: ?WhatsAppTemplate, error: ?string}
     */
    private function fail(string $error): array
    {
        return ['ok' => false, 'template' => null, 'error' => $error];
    }
}
