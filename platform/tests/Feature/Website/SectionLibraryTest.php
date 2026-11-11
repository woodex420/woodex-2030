<?php

declare(strict_types=1);

use App\Domain\Website\Actions\SaveHtmlFile;
use App\Domain\Website\Builder\BlockLibrary;
use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Builder\GlobalSection;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/*
| The section library (docs/audit/21 §5.2). The builder shipped the "My sections" panel against a server that
| answered an empty list and refused every write — a visible promise it could not keep. These tests hold the
| server: save, list, update, delete with a usage warning, import with cleaning, and the one thing a saved
| section cannot do on its own — put a global section's new version onto every page that uses it.
*/

beforeEach(function () {
    $this->site = sys_get_temp_dir().'/woodex-library-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->site.'/public/about');
    File::ensureDirectoryExists($this->site.'/public/insights/a-post');
    File::ensureDirectoryExists($this->site.'/storage');

    $this->app->usePublicPath($this->site.'/public');
    $this->app->useStoragePath($this->site.'/storage');

    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo(['website.edit', 'website.publish']);

    $this->library = new BlockLibrary(new SaveHtmlFile, $this->editor);
});

afterEach(function () {
    File::deleteDirectory($this->site);
});

/** A page document with `$inner` inside <main>. */
function libraryPage(string $title, string $inner): string
{
    return '<!DOCTYPE html><html lang="en"><head><title>'.$title.'</title></head><body><main>'
        .$inner.'</main></body></html>';
}

function libraryApi(User $user, array $payload): TestResponse
{
    return test()->actingAs($user)->postJson(route('admin.website.builder.api'), $payload);
}

it('saves a section and lists it back in the order it was saved', function () {
    $first = $this->library->save([
        'name' => 'Opening band', 'cat' => 'Hero', 'tags' => 'dark, lahore', 'kind' => 'section',
        'html' => '<section class="wx-block"><h1>Lahore</h1></section>',
    ]);
    $second = $this->library->save([
        'name' => 'Enquiry button', 'kind' => 'element', 'html' => '<a class="btn" href="/contact/">Enquire</a>',
    ]);

    expect($first['ok'])->toBeTrue()
        ->and($first['block']['id'])->toBe(1)
        ->and($first['block']['tags'])->toBe(['dark', 'lahore'])
        ->and($first['block']['cat'])->toBe('Hero')
        ->and($first['block']['global'])->toBeFalse()
        ->and($second['block']['id'])->toBe(2)
        ->and($second['block']['cat'])->toBe('Custom')
        ->and($second['block']['kind'])->toBe('element');

    $blocks = $this->library->all();

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0]['name'])->toBe('Opening band')
        // The newest is last: that is where the builder looks for the section it has just saved.
        ->and($blocks[array_key_last($blocks)]['name'])->toBe('Enquiry button');
});

it('refuses a section that carries code', function (string $html, string $reason) {
    expect(fn () => $this->library->save(['name' => 'Bad', 'html' => $html]))
        ->toThrow(ValidationException::class);

    expect($this->library->all())->toBe([]);
})->with([
    'script' => ['<section><script>alert(1)</script></section>', 'a script'],
    'inline handler' => ['<section><a href="/x" onclick="steal()">x</a></section>', 'handler'],
    'javascript: link' => ['<section><a href="javascript:alert(1)">x</a></section>', 'javascript'],
    'iframe' => ['<section><iframe src="https://example.com"></iframe></section>', 'frame'],
]);

it('refuses a section that is not one element', function (string $html) {
    expect(fn () => $this->library->save(['name' => 'Two', 'html' => $html]))
        ->toThrow(ValidationException::class);
})->with([
    'two sections' => ['<section>One</section><section>Two</section>'],
    'stray text' => ['<section>One</section> and some text'],
]);

it('updates a section in place when it is given its id', function () {
    $saved = $this->library->save(['name' => 'Old name', 'cat' => 'CTA', 'html' => '<section>A</section>']);
    $updated = $this->library->save([
        'id' => $saved['block']['id'], 'name' => 'New name', 'cat' => 'Hero',
        'html' => '<section>B</section>',
    ]);

    expect($updated['block']['id'])->toBe($saved['block']['id'])
        ->and($updated['block']['name'])->toBe('New name')
        ->and($updated['block']['cat'])->toBe('Hero')
        ->and($updated['block']['html'])->toBe('<section>B</section>')
        ->and($updated['block']['created_at'])->toBe($saved['block']['created_at'])
        ->and($this->library->all())->toHaveCount(1);
});

it('names the pages a global section is on, and refuses to delete it without force', function () {
    $block = $this->library->save([
        'name' => 'Studio band', 'global' => true, 'html' => '<section class="studio"><h2>Studio</h2></section>',
    ])['block'];

    $copy = GlobalSection::attribute($block['html'], $block['id']);
    File::put($this->site.'/public/about/index.html', libraryPage('About', $copy));
    File::put($this->site.'/public/insights/a-post/index.html', libraryPage('Post', $copy));
    File::put($this->site.'/public/index.html', libraryPage('Home', '<p>No section here.</p>'));

    expect($this->library->usage())->toBe([$block['id'] => ['about/index.html', 'insights/a-post/index.html']]);

    $refused = $this->library->delete($block['id'], false);

    expect($refused['ok'])->toBeFalse()
        ->and($refused['used'])->toBeTrue()
        ->and($refused['error'])->toContain('2 page(s)')
        ->and($this->library->all())->toHaveCount(1);
});

it('forces the delete, keeps the copies, and never reuses the id', function () {
    $block = $this->library->save(['name' => 'Studio band', 'global' => true, 'html' => '<section class="studio">x</section>'])['block'];
    File::put($this->site.'/public/about/index.html', libraryPage('About', GlobalSection::attribute($block['html'], $block['id'])));

    expect($this->library->delete($block['id'], true)['ok'])->toBeTrue();
    expect($this->library->all())->toBe([]);

    // The page keeps its copy — it simply stops being linked. A new section must not inherit the old marker.
    expect(BuilderFile::read('about/index.html'))->toContain('data-wx-global="1"');

    $new = $this->library->save(['name' => 'Something else', 'html' => '<section>y</section>'])['block'];

    expect($new['id'])->toBe(2);
});

it('puts the library version of a global section onto every page that uses it', function () {
    $block = $this->library->save([
        'name' => 'Studio band', 'global' => true, 'html' => '<section class="studio"><h2>Old heading</h2></section>',
    ])['block'];

    $copy = GlobalSection::attribute($block['html'], $block['id']);
    File::put($this->site.'/public/about/index.html', libraryPage('About', '<p>Intro</p>'.$copy));
    File::put($this->site.'/public/insights/a-post/index.html', libraryPage('Post', $copy));
    File::put($this->site.'/public/index.html', libraryPage('Home', '<p>Home has no copy.</p>'));

    $this->library->save([
        'id' => $block['id'], 'name' => 'Studio band', 'global' => true,
        'html' => '<section class="studio"><h2>New heading</h2></section>',
    ]);

    $result = $this->library->sync($block['id']);

    expect($result['changed'])->toBe(2)
        ->and($result['pages'])->toBe(['about/index.html', 'insights/a-post/index.html'])
        ->and($result['duplicates'])->toBe([]);

    // The marker is inside the opening tag, the section is the new one, and the rest of the page is untouched.
    $about = BuilderFile::read('about/index.html');
    expect($about)->toContain('<section class="studio" data-wx-global="1">')
        ->and($about)->toContain('New heading')
        ->and($about)->not->toContain('Old heading')
        ->and($about)->toContain('<p>Intro</p>')
        ->and(BuilderFile::read('index.html'))->not->toContain('data-wx-global');

    // The page was snapshotted before it was rewritten, so the old version is still there.
    expect(BuilderFile::versions('about/index.html'))->not->toBeEmpty();
});

it('leaves a page alone when it already carries the current version', function () {
    $block = $this->library->save(['name' => 'Band', 'global' => true, 'html' => '<section class="b"><h2>Same</h2></section>'])['block'];

    // The page still carries the older copy, so the first sync is a change and the second is nothing to do.
    File::put($this->site.'/public/about/index.html', libraryPage('About', GlobalSection::attribute('<section class="b"><h2>Older</h2></section>', $block['id'])));

    expect($this->library->sync($block['id'])['changed'])->toBe(1)
        ->and($this->library->sync($block['id'])['changed'])->toBe(0)
        ->and(BuilderFile::read('about/index.html'))->toContain('Same');
});

it('refuses to sync a section that is not global', function () {
    $block = $this->library->save(['name' => 'Plain', 'html' => '<section>x</section>'])['block'];

    expect(fn () => $this->library->sync($block['id']))->toThrow(ValidationException::class);
});

it('cleans an imported library file and counts what it skipped', function () {
    $result = $this->library->import([
        ['name' => 'Unsafe', 'cat' => 'Hero', 'html' => '<section onclick="steal()"><script>alert(1)</script><h2>Still here</h2><a href="javascript:go()">link</a></section>'],
        ['name' => 'Empty', 'html' => '   '],
        ['name' => 'Two roots', 'html' => '<section>a</section><section>b</section>'],
    ]);

    expect($result['added'])->toBe(1)
        ->and($result['skipped'])->toBe(2);

    $saved = $this->library->all()[0];

    expect($saved['html'])->toContain('Still here')
        ->and($saved['html'])->not->toContain('<script')
        ->and($saved['html'])->not->toContain('onclick')
        ->and($saved['html'])->not->toContain('javascript:');
});

it('refuses to read a damaged library file instead of replacing it with an empty one', function () {
    $path = $this->site.'/storage/app/private/section-library.json';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '{"blocks": [');

    expect(fn () => $this->library->all())->toThrow(RuntimeException::class);
    expect(File::get($path))->toBe('{"blocks": [');

    // The endpoint answers the panel in its own shape — `{ok:false, error}` — instead of an HTML error page,
    // and the file is still untouched afterwards.
    libraryApi($this->editor, ['action' => 'blocks_list'])
        ->assertStatus(500)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'has not been changed'));

    expect(File::get($path))->toBe('{"blocks": [');
});

it('answers the builder endpoint with the library, not a placeholder', function () {
    $saved = libraryApi($this->editor, [
        'action' => 'blocks_save', 'name' => 'Saved from the panel', 'cat' => 'CTA',
        'tags' => 'panel, saved', 'global' => false, 'kind' => 'section',
        'html' => '<section class="panel"><h2>From the panel</h2></section>',
    ])->assertOk()->assertJsonPath('ok', true);

    $id = $saved->json('block.id');

    expect($saved->json('blocks'))->toHaveCount(1)
        ->and($saved->json('blocks.0.tags'))->toBe(['panel', 'saved']);

    libraryApi($this->editor, ['action' => 'blocks_list'])
        ->assertOk()
        ->assertJsonPath('blocks.0.name', 'Saved from the panel');

    libraryApi($this->editor, ['action' => 'blocks_usage'])->assertOk()->assertJsonPath('usage', []);

    libraryApi($this->editor, ['action' => 'blocks_delete', 'id' => $id])->assertOk()->assertJsonPath('ok', true);

    libraryApi($this->editor, ['action' => 'blocks_list'])->assertOk()->assertJsonPath('blocks', []);
});

it('marks a global section inside its opening tag, not after it', function () {
    $html = '<section class="studio" id="studio"><h2>Hi</h2></section>';

    expect(GlobalSection::attribute($html, 7))->toBe('<section class="studio" id="studio" data-wx-global="7"><h2>Hi</h2></section>');

    // Saving a copy that already carries a marker re-links it once, not twice.
    expect(GlobalSection::count(GlobalSection::attribute(GlobalSection::attribute($html, 7), 7), 7))->toBe(1);
});

it('finds the matching close tag of a nested section and ignores > inside an attribute', function () {
    $html = '<main><section data-wx-global="3"><div><section><p>inner</p></section></div><img alt="a > b"></section><p>after</p></main>';

    $range = GlobalSection::range($html, 3);

    expect($range)->not->toBeNull()
        ->and(str_starts_with(substr($html, $range['start']), '<section data-wx-global="3">'))->toBeTrue()
        ->and(substr($html, $range['end']))->toBe('<p>after</p></main>');

    $replaced = GlobalSection::replace($html, 3, '<section data-wx-global="3">new</section>');

    expect($replaced['count'])->toBe(1)
        ->and($replaced['html'])->toBe('<main><section data-wx-global="3">new</section><p>after</p></main>');
});
