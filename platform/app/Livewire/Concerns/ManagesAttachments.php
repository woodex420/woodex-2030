<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use Illuminate\Http\UploadedFile;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Upload / delete files on the record returned by attachable() (GLB-006). Files save as soon as they
 * are picked; each upload is noted on the timeline.
 */
trait ManagesAttachments
{
    use WithFileUploads;

    /** @var array<int, UploadedFile> */
    public array $uploads = [];

    abstract protected function attachable(): Lead|Contact;

    public function updatedUploads(): void
    {
        $record = $this->attachable();
        $this->authorize('update', $record);

        $this->validate([
            'uploads' => ['array', 'max:10'],
            'uploads.*' => ['file', 'max:20480', 'extensions:'.implode(',', Lead::ATTACHMENT_EXTENSIONS)],
        ], [
            'uploads.*.max' => 'Each file can be up to 20 MB.',
            'uploads.*.extensions' => 'Allowed: photos, PDF, DWG/DXF drawings, Excel, Word and PowerPoint files.',
        ]);

        $names = [];
        foreach ($this->uploads as $upload) {
            $media = $record->addMedia($upload->getRealPath())
                ->usingFileName($upload->hashName())
                ->usingName(pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME))
                ->withCustomProperties(['uploaded_by' => $this->user()->id, 'original_name' => $upload->getClientOriginalName()])
                ->toMediaCollection('files');
            $names[] = $media->getCustomProperty('original_name');
        }

        $this->uploads = [];

        Activity::create([
            'contact_id' => $record instanceof Lead ? $record->contact_id : $record->id,
            'lead_id' => $record instanceof Lead ? $record->id : null,
            'user_id' => $this->user()->id,
            'type' => ActivityType::Note,
            'body' => (count($names) === 1 ? 'File added: ' : count($names).' files added: ').implode(', ', $names),
            'data' => ['files' => $names],
            'occurred_at' => now(),
        ]);

        $this->toast(count($names) === 1 ? 'File uploaded.' : count($names).' files uploaded.');
    }

    public function deleteAttachment(int $mediaId): void
    {
        $record = $this->attachable();
        $this->authorize('update', $record);

        $media = $record->media()->where('collection_name', 'files')->findOrFail($mediaId);
        assert($media instanceof Media);
        $name = (string) $media->getCustomProperty('original_name', $media->file_name);
        $media->delete();

        $this->toast("{$name} deleted.");
    }
}
