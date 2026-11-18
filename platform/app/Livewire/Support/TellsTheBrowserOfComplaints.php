<?php

declare(strict_types=1);

namespace App\Livewire\Support;

use Livewire\Component;
use Livewire\Mechanisms\HandleComponents\ComponentContext;

use function Livewire\on;
use function Livewire\store;

/**
 * Tells the browser that an answer saved nothing: something the person sent was refused, or held back.
 *
 * The browser keeps a mark on a form whose text is not saved yet, and takes the mark away when a save succeeds
 * (resources/js/admin/unsaved.js, master list T-043). A save that was refused must leave the mark. Livewire sends
 * the list of complaints to the browser only for fields that are properties of the screen; a complaint under any
 * other name ("publish", "templateId") is drawn into the page and not sent. So the fact is said plainly, as one
 * word in the answer: wxInvalid.
 *
 * A screen that holds a save back without a complaint (a warning to read first) says so itself:
 * InteractsWithAdmin::nothingSavedYet().
 *
 * This listens to Livewire's "dehydrate" and is not a Livewire\ComponentHook, on purpose. Livewire wires up
 * component hooks when its own provider boots, which is before the application's: a hook registered from
 * AppServiceProvider is never called in a real request. In a test it is, from the second test of a run on,
 * because the list of hooks outlives the application; so every test passed while no browser was ever told
 * anything. The journey in the browser found it (18 November 2026).
 */
final class TellsTheBrowserOfComplaints
{
    private const string HELD_BACK = 'wxNothingSaved';

    /** Once, when the application starts. */
    public static function listen(): void
    {
        on('dehydrate', function (mixed $screen, ComponentContext $context): void {
            if (! $screen instanceof Component) {
                return;
            }

            if ($screen->getErrorBag()->isNotEmpty() || store($screen)->get(self::HELD_BACK, false) === true) {
                $context->addEffect('wxInvalid', true);
            }
        });
    }

    public static function nothingSaved(Component $screen): void
    {
        store($screen)->set(self::HELD_BACK, true);
    }
}
