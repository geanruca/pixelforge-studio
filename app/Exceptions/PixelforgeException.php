<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Any failed call to the Pixelforge API, classified by what we may do next.
 */
class PixelforgeException extends RuntimeException
{
    /** Rejections the docs promise are temporary and create nothing. */
    private const array RETRYABLE_CODES = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'];

    public const string CONNECTION_FAILED = 'connection_failed';

    public const string UNEXPECTED_RESPONSE = 'unexpected_response';

    final public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    /**
     * We never got an answer (timeout, DNS, reset). The request may or may not
     * have been accepted, which is why every create carries an idempotency key.
     */
    public static function connectionFailed(string $message): static
    {
        return new static(self::CONNECTION_FAILED, $message);
    }

    public static function unexpected(string $message, ?int $httpStatus = null): static
    {
        return new static(self::UNEXPECTED_RESPONSE, $message, $httpStatus);
    }

    /**
     * Whether Pixelforge answered, with one of its own error codes, that this
     * request creates nothing and never will (bad parameters, content policy,
     * daily limit, bad key). Only then may the generation be closed for good.
     */
    public function isDefinitiveRejection(): bool
    {
        return $this->httpStatus !== null
            && $this->httpStatus >= 400
            && $this->httpStatus < 500
            && $this->errorCode !== self::UNEXPECTED_RESPONSE
            && ! in_array($this->errorCode, self::RETRYABLE_CODES, true);
    }

    /**
     * Everything else is worth another attempt: temporary limits, overloads,
     * timeouts, 5xx and answers we could not read. Those last ones are
     * ambiguous (the generation may exist), so they are never treated as final.
     */
    public function isRetryable(): bool
    {
        return ! $this->isDefinitiveRejection();
    }
}
