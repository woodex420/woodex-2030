<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use App\Domain\Sales\Data\ApprovalAssessment;
use RuntimeException;

/**
 * A quotation action that the quote's state or the rules do not allow (send, approve, accept…). The message is
 * written for the person who clicked.
 */
final class QuoteActionDenied extends RuntimeException
{
    public ?ApprovalAssessment $assessment = null;

    public static function needsApproval(ApprovalAssessment $assessment): self
    {
        $exception = new self('Approval is needed before sending: '.implode(' ', $assessment->reasons));
        $exception->assessment = $assessment;

        return $exception;
    }
}
