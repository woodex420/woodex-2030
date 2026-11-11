<?php

declare(strict_types=1);

namespace App\Domain\Projects\Enums;

use App\Support\Concerns\EnumHelpers;

enum ProjectStage: string
{
    use EnumHelpers;

    case Planning = 'planning';
    case Design = 'design';
    case Procurement = 'procurement';
    case Execution = 'execution';
    case Finishing = 'finishing';
    case Handover = 'handover';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::Design => 'Design',
            self::Procurement => 'Procurement',
            self::Execution => 'Execution',
            self::Finishing => 'Finishing',
            self::Handover => 'Handover',
            self::Completed => 'Completed',
        };
    }

    /**
     * TailAdmin badge colour for this stage.
     *
     * @return 'navy'|'info'|'warning'|'wood'|'success'
     */
    public function color(): string
    {
        return match ($this) {
            self::Planning => 'navy',
            self::Design => 'info',
            self::Procurement => 'warning',
            self::Execution => 'wood',
            self::Finishing => 'warning',
            self::Handover => 'success',
            self::Completed => 'success',
        };
    }

    /** What this stage means for the client, in one line (the "template per stage" of PRJ-002). */
    public function clientLine(): string
    {
        return match ($this) {
            self::Planning => 'Your project is with our planning team: drawings, measurements and the schedule are being put together.',
            self::Design => 'Our designers are working on the drawings and the 3D views of your space.',
            self::Procurement => 'We are ordering the materials and the fittings your project needs.',
            self::Execution => 'Work on site is under way.',
            self::Finishing => 'The work is in its finishing stage: polishing, fixtures, paint and the final details.',
            self::Handover => 'Your space is ready. We are preparing the handover.',
            self::Completed => 'Your project is complete. Thank you for trusting Woodex with it.',
        };
    }
}
