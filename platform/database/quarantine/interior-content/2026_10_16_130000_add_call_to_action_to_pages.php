<?php

use App\Domain\Website\Actions\AddMissingCallToActions;
use Illuminate\Database\Migrations\Migration;

/**
 * Every page asks for the next step (audit of 16 Oct 2026, docs/10 §3.2).
 *
 * Forty of the 89 published pages - all of the cost guides - ended with an answer and then nothing: no form, no
 * link, no way to carry on. A guide is the page that brings a stranger in from Google; ending it on a full stop
 * throws away the only reason it exists.
 *
 * The work itself lives in AddMissingCallToActions, because a re-import of the old website brings the pages back
 * without their closing and has to be able to do this again.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new AddMissingCallToActions)();
    }

    public function down(): void
    {
        // The blocks these actions write are ordinary sections: the studio removes them in Website -> Pages,
        // or simply re-imports the page.
    }
};
