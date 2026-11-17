<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Models\DocumentDesign;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\FurnitureDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveDocumentDesign
{
    /** @param array<string, mixed> $values */
    public function handle(User $by, string $line, array $values, int $expectedRevision, ?UploadedFile $logo = null, bool $removeLogo = false): DocumentDesign
    {
        $by = User::query()->findOrFail($by->id);
        abort_unless($by->is_active, 403);
        Gate::forUser($by)->authorize('settings.manage');
        foreach (['brand_name', 'header_text', 'footer_text', 'accent_color'] as $field) {
            if (is_string($values[$field] ?? null)) {
                $values[$field] = trim($values[$field]);
            }
        }
        $data = Validator::make(array_merge($values, ['business_line' => $line, 'logo' => $logo]), [
            'business_line' => ['required', Rule::in(['interior', 'furniture'])],
            'brand_name' => ['required', 'string', 'max:35'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'header_text' => ['present', 'string', 'max:100'],
            'footer_text' => ['present', 'string', 'max:120'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:96', 'dimensions:min_width=8,min_height=8,max_width=600,max_height=240'],
            'furniture_style' => ['sometimes', 'array'],
            'furniture_style.header_align' => ['sometimes', Rule::in(['left', 'center', 'right'])],
            'furniture_style.footer_align' => ['sometimes', Rule::in(['left', 'center', 'right'])],
            'furniture_style.secondary_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'furniture_style.quotation_title' => ['sometimes', 'required', 'string', 'max:60'],
            'furniture_style.invoice_title' => ['sometimes', 'required', 'string', 'max:60'],
            'furniture_style.use_default_logo' => ['sometimes', Rule::in(['yes', 'no'])],
            'pdf_theme' => ['sometimes', Rule::in(array_keys(DocumentStyles::THEMES))],
            'quote_blocks' => ['sometimes', 'array', 'max:20'],
            'quote_blocks.*.key' => ['required', 'string', Rule::in(array_keys(DocumentStyles::QUOTE_BLOCKS))],
            'quote_blocks.*.visible' => ['required', 'boolean'],
            'invoice_blocks' => ['sometimes', 'array', 'max:20'],
            'invoice_blocks.*.key' => ['required', 'string', Rule::in(array_keys(DocumentStyles::INVOICE_BLOCKS))],
            'invoice_blocks.*.visible' => ['required', 'boolean'],
        ])->validate();
        if (array_key_exists('furniture_style', $data)) {
            $data['furniture_style'] = FurnitureDocument::style($data['furniture_style']);
        }
        unset($data['logo'], $data['business_line']);
        if (array_key_exists('quote_blocks', $data)) {
            $data['quote_blocks'] = DocumentStyles::blocks('quote', $data['quote_blocks'], true);
        }
        if (array_key_exists('invoice_blocks', $data)) {
            $data['invoice_blocks'] = DocumentStyles::blocks('invoice', $data['invoice_blocks']);
        }
        if ($logo !== null) {
            $bytes = $logo->get();
            $data['logo_data_uri'] = 'data:'.$logo->getMimeType().';base64,'.base64_encode($bytes);
            $data['logo_sha256'] = hash('sha256', $bytes);
        } elseif ($removeLogo) {
            $data['logo_data_uri'] = null;
            $data['logo_sha256'] = null;
        }

        return DB::transaction(function () use ($by, $line, $data, $expectedRevision): DocumentDesign {
            $design = DocumentDesign::query()->where('business_line', $line)->lockForUpdate()->firstOrFail();
            abort_unless($design->revision === $expectedRevision, 409, 'This design changed. Reload before saving.');
            $design->fill($data);
            $design->revision++;
            $design->save();
            activity('sales-library')->causedBy($by)->performedOn($design)->withProperties(['revision' => $design->revision, 'logo_sha256' => $design->logo_sha256])->log('Document design saved for future copies');

            return $design->refresh();
        });
    }
}
