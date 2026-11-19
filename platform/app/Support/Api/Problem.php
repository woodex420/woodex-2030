<?php

declare(strict_types=1);

namespace App\Support\Api;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A refusal that names its own code and its own sentence (master list T-063).
 *
 * Under /api/v1 it is answered as {"error": {"code": …, "message": …, "details": …}} by ApiErrors. Anywhere else
 * it is an ordinary HTTP error with the same status and sentence, so that code shared with a screen can throw it.
 */
final class Problem extends HttpException
{
    /**
     * @param  array<string, list<string>>  $details
     * @param  array<string, mixed>  $headers
     */
    public function __construct(int $status, public readonly string $problem, string $message, public readonly array $details = [], array $headers = [])
    {
        parent::__construct($status, $message, null, $headers);
    }
}
