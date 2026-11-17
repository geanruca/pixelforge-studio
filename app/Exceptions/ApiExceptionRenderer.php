<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Money;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every failure of the public API in one shape, the same one
 * Pixelforge uses, so clients branch on a stable `code`:
 *
 *     {"error": {"type": "...", "code": "...", "message": "...", ...}}
 */
class ApiExceptionRenderer
{
    public static function render(Throwable $exception): ?JsonResponse
    {
        return match (true) {
            $exception instanceof AuthenticationException => self::error(
                401, 'authentication_error', 'invalid_api_token', 'The API token is missing, malformed or revoked.',
            ),
            $exception instanceof ValidationException => self::error(
                422, 'invalid_request_error', 'validation_failed', $exception->getMessage(),
                ['param' => array_key_first($exception->errors()), 'errors' => $exception->errors()],
            ),
            $exception instanceof InsufficientCreditsException => self::error(
                402, 'invalid_request_error', 'insufficient_credits',
                'The available credits do not cover the maximum price of this generation.',
                [
                    'required_usd' => Money::toDecimal($exception->requiredNanos),
                    'available_usd' => Money::toDecimal($exception->availableNanos),
                ],
            ),
            $exception instanceof IdempotencyKeyReusedException => self::error(
                409, 'idempotency_error', 'idempotency_key_reused', $exception->getMessage(),
            ),
            $exception instanceof PixelforgeException => self::error(
                503, 'api_error', 'pricing_unavailable',
                'Pricing is temporarily unavailable, so nothing was created or charged. Try again shortly.',
            ),
            $exception instanceof ModelNotFoundException, $exception instanceof NotFoundHttpException => self::error(
                404, 'invalid_request_error', 'not_found', 'The resource does not exist or belongs to another account.',
            ),
            $exception instanceof ThrottleRequestsException => self::error(
                429, 'rate_limit_error', 'rate_limit_exceeded', 'Too many requests. Wait for the Retry-After seconds.',
                headers: $exception->getHeaders(),
            ),
            $exception instanceof HttpExceptionInterface => self::error(
                $exception->getStatusCode(), 'invalid_request_error', 'request_failed',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'The request could not be handled.',
                headers: $exception->getHeaders(),
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $headers
     */
    private static function error(int $status, string $type, string $code, string $message, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(
            ['error' => ['type' => $type, 'code' => $code, 'message' => $message, ...$extra]],
            $status,
            $headers,
        );
    }
}
