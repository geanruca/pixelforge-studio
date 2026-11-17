<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class IdempotencyKeyReusedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used with different parameters.');
    }
}
