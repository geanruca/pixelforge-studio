<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $requiredNanos,
        public readonly int $availableNanos,
    ) {
        parent::__construct('The balance does not cover this amount.');
    }
}
