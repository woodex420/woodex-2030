<?php

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Models\Page;
use Illuminate\Database\Migrations\Migration;

/**
 * The home page's closing "Tell us about your space" was imported as plain text: the words, without the form.
 * A home page that asks for an enquiry should be able to take one, so the closing block becomes an enquiry form
 * block (the same one the city and project pages use), keeping the words the import brought across.
 *
 * Runs once and only when the home page has no form already, so anything the owner arranges in the page editor
 * afterwards is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $home = Page::query()->where('slug', '/')->first();

        if ($home === null || $home->blocks()->where('type', BlockType::Form->value)->exists()) {
            return;
        }

        // The closing text block is the call to action; it becomes the form's heading and intro.
        $closing = $home->blocks()->where('type', BlockType::Text->value)->orderByDesc('position')->first();
        $data = $closing?->data ?? [];

        $home->blocks()->create([
            'type' => BlockType::Form->value,
            'position' => $closing?->position ?? ($home->blocks()->max('position') + 1),
            'visible' => true,
            'data' => [
                'heading' => is_string($data['heading'] ?? null) && $data['heading'] !== ''
                    ? $data['heading']
                    : 'Tell us about your space',
                'text' => is_string($data['body'] ?? null) ? $data['body'] : '',
                'show_contact' => true,
            ],
        ]);

        $closing?->delete();

        // The rendered page is cached against the page's updated_at (PageCache): without this the home page would
        // keep serving the HTML it had before the block moved.
        $home->touch();
    }

    public function down(): void
    {
        $home = Page::query()->where('slug', '/')->first();
        $form = $home?->blocks()->where('type', BlockType::Form->value)->orderByDesc('position')->first();

        if ($home === null || $form === null) {
            return;
        }

        $home->blocks()->create([
            'type' => BlockType::Text->value,
            'position' => $form->position,
            'visible' => true,
            'data' => [
                'heading' => $form->data['heading'] ?? 'Tell us about your space',
                'body' => $form->data['text'] ?? '',
            ],
        ]);

        $form->delete();

        $home->touch();
    }
};
