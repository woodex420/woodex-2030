<?php

it('brands the sign-in screen for Furniture without preloading the Interior photo', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('WOODEX OS')
        ->assertSee('Woodex Furniture')
        ->assertDontSee('Woodex Interior')
        ->assertDontSee('svc-interior-design.webp');
});

it('ships styles without references to excluded Interior assets', function () {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $css = file_get_contents(public_path('build/'.$manifest['resources/css/admin.css']['file']));

    expect($css)->not->toContain('Nohemi', 'svc-interior-design.webp');
    expect(file_exists(resource_path('fonts/Inter.woff2')))->toBeTrue();
});
