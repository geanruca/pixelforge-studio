<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pixelforge API
    |--------------------------------------------------------------------------
    |
    | One API key per environment: every key has its own webhook URL, signing
    | secret and usage report, so sharing a key would mix up two environments.
    |
    */

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    /** Seconds a signed webhook timestamp may differ from our clock before it is rejected as a replay. */
    'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

    'timeout' => (int) env('PIXELFORGE_TIMEOUT', 15),

    'connect_timeout' => (int) env('PIXELFORGE_CONNECT_TIMEOUT', 5),

    /** Generations the account may have queued or processing at once. */
    'max_concurrent_generations' => (int) env('PIXELFORGE_MAX_CONCURRENT', 3),

    /** Minutes we keep retrying a submission (limits, overloads) before giving up. */
    'submission_window_minutes' => (int) env('PIXELFORGE_SUBMISSION_WINDOW', 15),

    /** Seconds the pricing table is cached before it is fetched again. */
    'pricing_cache_seconds' => (int) env('PIXELFORGE_PRICING_CACHE', 600),

    /** Seconds an in-flight generation may go without news before we poll it. */
    'reconcile_after_seconds' => (int) env('PIXELFORGE_RECONCILE_AFTER', 45),

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Users pay the provider cost plus this markup, in basis points
    | (2500 = 25%). Integer basis points keep the money math exact.
    |
    */

    'markup_basis_points' => (int) env('PIXELFORGE_MARKUP_BASIS_POINTS', 2500),

    /** Credit packs the fake "Buy credits" button offers, in whole US dollars. */
    'credit_packs_usd' => [5, 10, 25],

    /** Disk where finished images are kept, since provider URLs expire after 24 hours. */
    'image_disk' => env('PIXELFORGE_IMAGE_DISK', 'local'),

    /** Comma-separated emails allowed to open the operator report. */
    'admin_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('ADMIN_EMAILS', '')),
    ))),

];
