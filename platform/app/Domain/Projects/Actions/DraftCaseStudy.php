<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Models\Project;
use App\Domain\Website\Actions\SavePage;
use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turning a finished job into a portfolio page (PRJ-008): the photographs the team shared with the client and the
 * facts of the job become a **draft** page in the CMS, for a person to write up before it is published.
 *
 * It only runs with the client's consent (`case_study_consent`), and it is idempotent: the second press updates
 * the draft it made instead of creating a second page.
 */
final class DraftCaseStudy
{
    public function __construct(private readonly SavePage $save) {}

    public function handle(Project $project, User $by): Page
    {
        $project->loadMissing(['contact', 'updates', 'quote']);

        $slug = '/projects/'.Str::slug($project->code.' '.$project->name).'/';
        $page = $project->case_study_page_id !== null
            ? Page::query()->find($project->case_study_page_id)
            : Page::query()->firstWhere('slug', $slug);

        if ($page === null) {
            $page = new Page(['slug' => $slug]);
            $page->status = PageStatus::Draft;
        }

        $photos = $this->photographs($project, $by);
        $facts = $this->facts($project);

        $page = ($this->save)(
            $page,
            [
                'type' => PageType::Project,
                'slug' => $slug,
                'title' => $project->name,
                'excerpt' => sprintf('%s in %s — a %s project by Woodex.', $project->name, $project->city ?: 'Lahore', $project->business_line->label()),
                'seo' => [
                    'title' => $project->name.' — Woodex Interior',
                    'description' => Str::limit(sprintf('%s in %s. Interior design and execution by Woodex, Lahore.', $project->name, $project->city ?: 'Lahore'), 155),
                    'robots' => 'index,follow',
                    'focus_keyword' => Str::lower($project->name),
                    'og_image_id' => $photos[0]['id'] ?? null,
                    'last_reviewed' => now()->toDateString(),
                ],
                'schema_type' => null,
                'sort' => 0,
            ],
            [
                [
                    'type' => BlockType::Hero->value,
                    'data' => [
                        'eyebrow' => $project->code.' · '.$project->business_line->label(),
                        'heading' => $project->name,
                        'text' => sprintf('A %s project in %s, designed and executed by Woodex.', $project->business_line->label(), $project->city ?: 'Lahore'),
                        'image_id' => $photos[0]['id'] ?? null,
                        'primary_label' => 'Start your project',
                        'primary_url' => '/contact/',
                        'secondary_label' => '',
                        'secondary_url' => '',
                        'facts' => $facts,
                    ],
                    'visible' => true,
                ],
                [
                    'type' => BlockType::Intro->value,
                    'data' => [
                        'eyebrow' => 'The brief',
                        'heading' => 'What was asked for',
                        'body' => $this->story($project),
                        'image_id' => $photos[1]['id'] ?? null,
                        'facts' => [],
                    ],
                    'visible' => true,
                ],
                [
                    'type' => BlockType::Gallery->value,
                    'data' => [
                        'heading' => 'The finished space',
                        'images' => $photos,
                    ],
                    'visible' => $photos !== [],
                ],
                [
                    'type' => BlockType::Specs->value,
                    'data' => [
                        'heading' => 'Project facts',
                        'rows' => $facts,
                    ],
                    'visible' => true,
                ],
            ],
            false, // never publish a case study without a person writing it
            $by,
        );

        $project->forceFill(['case_study_page_id' => $page->id])->save();

        return $page;
    }

    /**
     * The photographs the client was shown, copied into the website's own media library: the site serves its
     * images from the public disk (D-75), the project's files stay private.
     *
     * @return list<array{id: int, caption: string, alt: string}>
     */
    private function photographs(Project $project, User $by): array
    {
        $photos = [];

        foreach ($project->updates->where('client_visible', true) as $update) {
            foreach ($update->getMedia('photos') as $photo) {
                $name = (string) $photo->getCustomProperty('original_name', $photo->name);
                $folder = 'website/projects/'.$project->code;
                $path = $folder.'/'.Str::lower(Str::random(8)).'.'.($photo->extension === 'bin' ? 'jpg' : $photo->extension);

                Storage::disk('public')->put($path, (string) file_get_contents($photo->getPath()));

                $media = Media::query()->create([
                    'disk' => 'public',
                    'path' => $path,
                    'alt' => Str::headline(pathinfo($name, PATHINFO_FILENAME)).' — '.$project->name,
                    'title' => $project->name,
                    'width' => null,
                    'height' => null,
                    'folder' => $folder,
                    'uploaded_by' => $by->id,
                ]);

                $photos[] = ['id' => $media->id, 'caption' => Str::limit($update->body, 120), 'alt' => $media->alt];
            }
        }

        return $photos;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function facts(Project $project): array
    {
        $facts = [
            ['label' => 'Project', 'value' => $project->code],
            ['label' => 'Location', 'value' => trim(($project->area ? $project->area.', ' : '').($project->city ?? '')) ?: 'Lahore'],
            ['label' => 'Scope', 'value' => $project->business_line->label()],
        ];

        if ($project->start_date !== null && $project->completed_at !== null) {
            $facts[] = ['label' => 'Duration', 'value' => $project->start_date->diffInMonths($project->completed_at).' months'];
        }

        if ($project->value_minor > 0) {
            $facts[] = ['label' => 'Investment', 'value' => Money::ofMinor((int) $project->value_minor)->format(true)];
        }

        if ($project->quote !== null) {
            $facts[] = ['label' => 'Space', 'value' => Str::limit($project->quote->title, 40)];
        }

        return $facts;
    }

    /** The client's own diary, joined into a paragraph a person can edit into the story of the job. */
    private function story(Project $project): string
    {
        $lines = $project->updates
            ->where('client_visible', true)
            ->sortBy('created_at')
            ->pluck('body')
            ->filter()
            ->values();

        return $lines->isEmpty()
            ? sprintf('%s in %s. Notes from the site will be added here when the team shares them.', $project->name, $project->city ?: 'Lahore')
            : $lines->implode("\n\n");
    }
}
