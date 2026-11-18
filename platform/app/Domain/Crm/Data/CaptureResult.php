<?php

declare(strict_types=1);

namespace App\Domain\Crm\Data;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;

final readonly class CaptureResult
{
    public function __construct(
        public Lead $lead,
        public Contact $contact,
        public bool $leadCreated,
        public bool $contactCreated,
    ) {}
}
