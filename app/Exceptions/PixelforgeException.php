<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Any failed call to the Pixelforge API, classified by what we may do next.
 */
class PixelforgeException extends RuntimeException
{
    /**
     * The only answers that close a generation for good: Pixelforge's own
     * codes for "this request creates nothing and never will". It is an
     * allowlist on purpose. An error code we do not know is treated as
     * temporary, because wrongly closing a generation that was in fact
     * accepted means an image nobody is charged for.
     */
    private const array DEFINITIVE_CODES = [
        'content_policy_violation',
        'validation_failed',
        'daily_limit_exceeded',
        'invalid_api_key',
        'idempotency_key_reused',
    ];

    public const string CONNECTION_FAILED = 'connection_failed';

    public const string UNEXPECTED_RESPONSE = 'unexpected_response';

    public const string CIRCUIT_OPEN = 'circuit_open';

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

    /**
     * No call was made: the circuit breaker is giving the provider time to recover.
     */
    public static function circuitOpen(int $retryAfterSeconds): static
    {
        return new static(
            self::CIRCUIT_OPEN,
            'The image service is recovering; calls are paused for a moment.',
            retryAfterSeconds: max(1, $retryAfterSeconds),
        );
    }

    /**
     * Whether this failure says the provider itself is unwell (no answer, an
     * answer we cannot read, any 5xx including `overloaded`) rather than
     * healthy and refusing this particular request. Only these trip the
     * circuit breaker.
     */
    public function indicatesUnhealthyProvider(): bool
    {
        return $this->errorCode === self::CONNECTION_FAILED
            || $this->errorCode === self::UNEXPECTED_RESPONSE
            || ($this->httpStatus !== null && $this->httpStatus >= 500);
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
            && in_array($this->errorCode, self::DEFINITIVE_CODES, true);
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
