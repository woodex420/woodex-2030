<?php

use Symfony\Component\Finder\Finder;

/*
| A notice ("toast") is sent to the browser as an event. Written with names,
|
|     $this->dispatch('toast', message: 'Sending failed.', type: 'error');
|
| it arrives as { message, type }. Written without,
|
|     $this->dispatch('toast', 'Sending failed.', 'error');
|
| it arrives as a list, and the admin showed a green "Saved" for it, whatever it said: a mail that failed to
| send was answered with "Saved" (defect D-038). Nothing fails on the server, and a test that asserts the event
| was dispatched passes either way.
|
| The browser now reads both (resources/js/admin/unsaved.js, toastOf; proved in a browser by the journey "a
| notice written without names says what it says", scripts/os-journeys.mjs). The code is still written one way,
| so that the browser can tell a refusal from a save by its type (T-043).
*/
it('writes every notice with a named message and type', function () {
    $unnamed = [];
    $read = 0;

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $source = $file->getContents();
        preg_match_all('/dispatch\(\s*\'toast\'\s*,\s*([^\n]{0,40})/', $source, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($found as $use) {
            $read++;
            if (! str_starts_with($use[1][0], 'message:')) {
                $unnamed[] = $file->getRelativePathname().':'.(substr_count($source, "\n", 0, $use[0][1]) + 1);
            }
        }
    }

    expect($unnamed)->toBe([])
        ->and($read)->toBeGreaterThan(5);
});
