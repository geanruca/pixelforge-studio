<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

/**
 * Verifies the `Pixelforge-Signature` header: `t=<unix time>,v1=<hex hmac>`,
 * where the HMAC-SHA256 covers `<t>.<raw body>` with the key's signing secret.
 */
class WebhookSignature
{
    /**
     * @param  string  $payload  the raw request body, exactly as received (never the re-encoded JSON)
     */
    public function isValid(string $payload, ?string $header): bool
    {
        $secret = config('pixelforge.webhook_secret');

        if (! is_string($secret) || $secret === '' || $header === null) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        // An old timestamp means a captured request being replayed.
        if (abs(now()->getTimestamp() - (int) $timestamp) > config()->integer('pixelforge.webhook_tolerance')) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
