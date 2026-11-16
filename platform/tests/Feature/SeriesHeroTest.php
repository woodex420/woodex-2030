<?php

use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\SeriesContent;
use App\Domain\Commerce\Services\SeriesHero;
use App\Domain\Website\Models\Media;
use App\Livewire\Admin\Commerce\SeriesEditor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->editor = $this->userWithRole('admin');
    $this->actingAs($this->editor);
    $this->series = Series::firstOrFail();
    $this->file = UploadedFile::fake()->image('real-fixture.png', 40, 30);
    $this->bytes = file_get_contents($this->file->getRealPath());
    $this->media = Media::create(['title' => 'Hero fixture', 'alt' => 'Real fixture alt', 'disk' => 'public', 'path' => 'website/hero.png']);
    Storage::disk('public')->put($this->media->path, $this->bytes);
});

it('stages a private library copy without saving and retains historical bytes when removing a later hero', function () {
    $component = Livewire::test(SeriesEditor::class, ['series' => $this->series])->call('selectHero', $this->media->id)->assertSet('form.hero_alt', 'Real fixture alt');
    expect($this->series->fresh()->draft_revision_id)->toBeNull();
    $component->set('form.layout', 'split')->set('form.bottom_text', '<script>unsafe</script>')->call('addFaq')->set('form.faqs.0', ['question' => 'Real question?', 'answer' => 'Real answer'])->call('saveDraft')->assertHasNoErrors();
    $id = $this->series->fresh()->draft_revision_id;
    $content = json_decode(DB::table('series_content_revisions')->where('id', $id)->value('content'), true);
    Storage::disk('local')->assertExists($content['hero_path']);
    Storage::disk('public')->assertExists($this->media->path);
    $this->get(route('admin.commerce.series-content.preview', $id))->assertOk()->assertSee('hero split')->assertSee('Real question?')->assertDontSee('<script>', false);
    $this->get(route('admin.commerce.series-content.hero', $id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $component->set('confirmPublish', true)->call('publish')->assertHasNoErrors()->call('removeHero')->call('saveDraft')->assertHasNoErrors();
    expect($this->series->fresh()->published_revision_id)->toBe($id);
    Storage::disk('local')->assertExists($content['hero_path']);
    $this->actingAs($this->userWithRole('sales'))->get(route('admin.commerce.series-content.hero', $id))->assertOk();
    $this->get(route('admin.commerce.series-content.hero', $this->series->fresh()->draft_revision_id))->assertForbidden();
});

it('requires alt text and explicit staging and rejects forged paths and unsafe uploads', function () {
    Livewire::test(SeriesEditor::class, ['series' => $this->series])->set('heroUpload', $this->file)->call('saveDraft')->assertHasErrors('hero')->call('uploadHero')->set('form.hero_alt', '')->call('saveDraft')->assertHasErrors('hero_alt');
    Livewire::test(SeriesEditor::class, ['series' => $this->series])->set('heroUpload', UploadedFile::fake()->createWithContent('fake.png', '<svg onload="alert(1)"/>'))->call('uploadHero')->assertHasErrors('hero');
    $service = app(SeriesContent::class);
    $id = $service->save($this->series, array_replace($service->defaults($this->series), ['hero_path' => '../../secret']), 0, $this->editor);
    expect(json_decode(DB::table('series_content_revisions')->where('id', $id)->value('content'), true)['hero_path'])->toBeNull();
    expect(fn () => app(SeriesHero::class)->store('not an image'))->toThrow(ValidationException::class);
});

it('blocks view-only and revoked website-library permissions while keeping native upload permission separate', function () {
    $this->editor->roles->each(fn ($role) => $role->revokePermissionTo('website.edit'));
    $this->actingAs($this->editor->fresh());
    Livewire::test(SeriesEditor::class, ['series' => $this->series])->call('selectHero', $this->media->id)->assertForbidden();
    Livewire::test(SeriesEditor::class, ['series' => $this->series])->set('heroUpload', $this->file)->call('uploadHero')->assertHasNoErrors();
    $this->actingAs($this->userWithRole('sales'));
    Livewire::test(SeriesEditor::class, ['series' => $this->series])->assertForbidden();
});

it('rejects tampered or missing hero bytes and invalid layout or nested FAQ fields', function () {
    $hero = app(SeriesHero::class)->store($this->bytes);
    $service = app(SeriesContent::class);
    $id = $service->save($this->series, array_replace($service->defaults($this->series), ['hero_alt' => 'Fixture']), 0, $this->editor, $hero);
    Storage::disk('local')->put($hero, 'tampered');
    $this->get(route('admin.commerce.series-content.hero', $id))->assertNotFound();
    foreach ([['layout' => 'iframe'], ['faqs' => [['question' => '', 'answer' => 'A']]]] as $invalid) {
        expect(fn () => $service->validate(array_replace($service->defaults($this->series), $invalid)))->toThrow(ValidationException::class);
    }
});
