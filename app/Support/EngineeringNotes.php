<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The engineering decisions behind this application, as data: the problem
 * each one answers, what was decided, where it lives and which test proves
 * it. The public /engineering page renders it, and
 * Tests\Feature\EngineeringNotesTest fails if a file or a test named here
 * stops existing, so this page cannot quietly go stale.
 */
final class EngineeringNotes
{
    /**
     * @return list<array{
     *     area: string,
     *     summary: string,
     *     notes: list<array{title: string, problem: string, decision: string, files: list<string>, tests: list<string>}>
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'area' => 'Money',
                'summary' => 'Every generation is recorded at exactly what it cost, once.',
                'notes' => [
                    [
                        'title' => 'Integer nanodollars, never floats',
                        'problem' => 'The provider bills fractions of a cent with nine decimals. In floating point 0.1 + 0.2 is not 0.3, and those errors add up across thousands of generations.',
                        'decision' => 'Amounts are integers of nanodollars (1 USD = 1,000,000,000). Rates become whole nanodollars per token, so a cost is an integer sum. A rate or amount that would need rounding is refused loudly rather than rounded silently.',
                        'files' => ['app/Support/Money.php', 'app/Services/Pricing/Rates.php'],
                        'tests' => ['tests/Unit/MoneyTest.php', 'tests/Unit/Pricing/PricingTableTest.php'],
                    ],
                    [
                        'title' => 'Sub-cent prices are charged exactly',
                        'problem' => 'An image costs between one and ten cents. Rounding each to a cent would either give work away or multiply its price.',
                        'decision' => 'Charges keep full precision; the interface shows four decimals with the exact figure on hover. The only rounding is up to a whole nanodollar when the 25% markup does not divide evenly, so the margin is never below 25%.',
                        'files' => ['app/Services/Pricing/Markup.php', 'resources/js/lib/money.ts'],
                        'tests' => ['tests/Unit/Pricing/PricingTableTest.php'],
                    ],
                    [
                        'title' => 'Reserve the worst case, charge the actual price',
                        'problem' => 'The real price is only known when the image is done, yet users must not spend credits they do not have, even with several images in flight.',
                        'decision' => 'The estimate is a low / expected / high range. Submitting places a hold for the highest price; finishing releases the hold and charges the actual price in the same transaction. A failed generation releases all of it.',
                        'files' => ['app/Services/Pricing/PricingTable.php', 'app/Services/Credits/CreditLedger.php', 'app/Services/Generations/GenerationCreator.php'],
                        'tests' => ['tests/Feature/Credits/CreditLedgerTest.php', 'tests/Feature/Generations/GenerationPagesTest.php'],
                    ],
                    [
                        'title' => 'Three independent guards against charging twice',
                        'problem' => 'Webhooks arrive twice and out of order; polling and webhooks race each other. Any one of them charging again loses the user\'s trust.',
                        'decision' => 'Webhook event ids are claimed in a unique column in the same transaction as their effect. The state machine refuses anything that does not move a generation forward. And the ledger has a unique index per generation and entry type, so even buggy code cannot write a second charge.',
                        'files' => ['app/Http/Controllers/PixelforgeWebhookController.php', 'app/Services/Generations/GenerationSynchronizer.php', 'database/migrations/2026_11_16_233630_create_ledger_entries_table.php'],
                        'tests' => ['tests/Feature/Webhooks/PixelforgeWebhookTest.php', 'tests/Feature/Generations/GenerationSynchronizerTest.php'],
                    ],
                    [
                        'title' => 'An append-only ledger that always adds up',
                        'problem' => 'A balance that is only a number cannot explain itself or be audited.',
                        'decision' => 'One class is the only door to a balance. Every movement locks the user row, appends an entry and updates the balance in one transaction. The sum of a user\'s entries always equals the balance, and every money test asserts it.',
                        'files' => ['app/Services/Credits/CreditLedger.php', 'app/Models/LedgerEntry.php'],
                        'tests' => ['tests/Feature/Credits/CreditLedgerTest.php'],
                    ],
                ],
            ],
            [
                'area' => 'Resilience',
                'summary' => 'The provider rate limits, overloads, times out and fails after accepting. None of that reaches the user as an error or a wrong charge.',
                'notes' => [
                    [
                        'title' => 'Dependency injection behind a provider contract',
                        'problem' => 'Code that calls a vendor client directly cannot be protected, swapped or tested without touching every caller.',
                        'decision' => 'The application depends on an ImageProvider contract. The container decides what stands behind it, so a circuit breaker, a second provider or a test double is one binding, and no caller changes.',
                        'files' => ['app/Contracts/ImageProvider.php', 'app/Providers/AppServiceProvider.php', 'app/Services/Pixelforge/PixelforgeClient.php'],
                        'tests' => ['tests/Feature/Resilience/ResilientImageProviderTest.php'],
                    ],
                    [
                        'title' => 'Circuit breaker with a fallback seam',
                        'problem' => 'Retrying against a provider that is down wastes workers and rate limit, and slows its recovery.',
                        'decision' => 'After five consecutive failures that point at an unwell provider (timeouts, 5xx) calls pause for 30 seconds and are then probed. A healthy provider saying no (validation, limits) never opens the circuit. A fallback provider can be bound for creating generations; none is, since there is one provider. Meanwhile each caller degrades on its own: estimates use the last pricing seen, the report shows our numbers as unchecked, submissions wait.',
                        'files' => ['app/Services/Resilience/CircuitBreaker.php', 'app/Services/Resilience/ResilientImageProvider.php', 'app/Services/Pricing/PricingRepository.php'],
                        'tests' => ['tests/Feature/Resilience/ResilientImageProviderTest.php'],
                    ],
                    [
                        'title' => 'Idempotency at every hop',
                        'problem' => 'Anything that moves money or triggers fulfilment will be retried: by a browser, an API client, a queue worker or the provider.',
                        'decision' => 'Five layers, each tested. Our call to the provider carries an idempotency key, so a retried timeout cannot create a second image. API clients and AI agents can send their own key. Buying credits carries a per-click reference. Webhook events are deduplicated by id. And settling a generation is idempotent by construction.',
                        'files' => ['app/Services/Pixelforge/PixelforgeClient.php', 'app/Services/Generations/GenerationCreator.php', 'app/Http/Requests/PurchaseCreditsRequest.php', 'app/Http/Controllers/PixelforgeWebhookController.php'],
                        'tests' => ['tests/Feature/Api/GenerationApiTest.php', 'tests/Feature/Credits/CreditPagesTest.php', 'tests/Feature/Webhooks/PixelforgeWebhookTest.php', 'tests/Feature/Generations/SubmitGenerationTest.php'],
                    ],
                    [
                        'title' => 'Back off, respect Retry-After, and know when to stop',
                        'problem' => 'Found on the live API: an overloaded request was being retried every two seconds for fifteen minutes, spending half the rate limit on a call that could not succeed.',
                        'decision' => 'Overloads, rate limits and timeouts back off exponentially, never sooner than Retry-After, and stop after six attempts with the hold refunded. Waiting for a free slot is not a failure and does not count.',
                        'files' => ['app/Jobs/SubmitGeneration.php', 'app/Exceptions/PixelforgeException.php'],
                        'tests' => ['tests/Feature/Generations/SubmitGenerationTest.php'],
                    ],
                    [
                        'title' => 'One state machine for webhooks, responses and polling',
                        'problem' => 'Three sources describe the same generation and arrive in any order. Three handlers would eventually disagree.',
                        'decision' => 'All three are parsed into one snapshot and applied by one class that locks the row and only moves the status forward. Ordering stops mattering by construction.',
                        'files' => ['app/Services/Generations/GenerationSynchronizer.php', 'app/Services/Pixelforge/ProviderGeneration.php'],
                        'tests' => ['tests/Feature/Generations/GenerationSynchronizerTest.php'],
                    ],
                    [
                        'title' => '"We gave up" is not "it failed"',
                        'problem' => 'A request that timed out may have been accepted. Closing it as failed means an image nobody is charged for.',
                        'decision' => 'Only the provider\'s own definitive error codes close a generation for good, as an allowlist. Giving up is recorded as unconfirmed; once the provider has accepted a generation it cannot be closed locally at all, and polling keeps asking until the provider confirms how it ended.',
                        'files' => ['app/Exceptions/PixelforgeException.php', 'app/Services/Generations/GenerationSynchronizer.php'],
                        'tests' => ['tests/Feature/Generations/SubmitGenerationTest.php', 'tests/Feature/Console/ReconcileGenerationsTest.php'],
                    ],
                    [
                        'title' => 'A poller and a sweeper behind the webhooks',
                        'problem' => 'All six webhook attempts can fail while we are down, and a queue job can be lost. Either would leave credits on hold forever.',
                        'decision' => 'Every minute a command polls whatever the provider has not confirmed as finished, closes and refunds submissions whose job disappeared, and retries missing image copies. A generation that keeps failing to sync goes to the back of the line so it cannot starve the rest.',
                        'files' => ['app/Console/Commands/ReconcileGenerations.php', 'routes/console.php'],
                        'tests' => ['tests/Feature/Console/ReconcileGenerationsTest.php'],
                    ],
                    [
                        'title' => 'Signed webhooks, verified on the raw body',
                        'problem' => 'An unauthenticated endpoint that settles money is an open door.',
                        'decision' => 'HMAC-SHA256 over the raw body, compared in constant time, with a five minute replay window, and closed by default when no secret is configured.',
                        'files' => ['app/Services/Pixelforge/WebhookSignature.php'],
                        'tests' => ['tests/Feature/Webhooks/PixelforgeWebhookTest.php'],
                    ],
                ],
            ],
            [
                'area' => 'Fairness and throughput',
                'summary' => 'The provider runs three images at a time for the whole account. That scarce capacity is shared deliberately.',
                'notes' => [
                    [
                        'title' => 'Round-robin between users',
                        'problem' => 'First come, first served lets one user who submits twenty images make everyone else wait behind all twenty.',
                        'decision' => 'The line for a slot is ordered round-robin by user: everybody\'s first waiting image goes before anybody\'s second, and images a user already has running count as turns taken. The position shown to each user comes from the same order.',
                        'files' => ['app/Services/Generations/SlotAllocator.php'],
                        'tests' => ['tests/Feature/Generations/SlotAllocatorTest.php'],
                    ],
                    [
                        'title' => 'Workers that do not trip over each other',
                        'problem' => 'Several workers checking "is a slot free?" at the same instant all see yes, and the losers burn a request on a 429.',
                        'decision' => 'Checking for a slot and taking it happen under one lock. A claim expires if its worker dies. Submissions, which users wait on, run on their own queue ahead of image downloads, which can be slow.',
                        'files' => ['app/Services/Generations/SlotAllocator.php', 'app/Jobs/SubmitGeneration.php', 'app/Jobs/StoreGenerationImage.php'],
                        'tests' => ['tests/Feature/Generations/SlotAllocatorTest.php'],
                    ],
                    [
                        'title' => 'Rate limits on both sides',
                        'problem' => 'The provider allows 60 requests a minute; our own endpoints can be hammered too.',
                        'decision' => 'Pricing is cached so typing an estimate never spends a provider request. Our API and MCP server allow 60 requests a minute per account and answer 429 with Retry-After; submitting and buying are throttled per user.',
                        'files' => ['app/Services/Pricing/PricingRepository.php', 'app/Providers/AppServiceProvider.php', 'routes/api.php'],
                        'tests' => ['tests/Feature/Api/GenerationApiTest.php', 'tests/Feature/Generations/GenerationPagesTest.php'],
                    ],
                ],
            ],
            [
                'area' => 'Product and interface',
                'summary' => 'Costs, status and errors are always clear, and waiting is never a blank spinner.',
                'notes' => [
                    [
                        'title' => 'Waiting that tells you what is happening',
                        'problem' => 'A spinner for up to a minute and a half says nothing about whether it is working or what it will cost.',
                        'decision' => 'While an image generates, its card narrates each step as it happens: credits reserved, place in line, automatic retries, time running, what will be charged. When it finishes, the image fades in and a notice says what was charged and what returned to the balance.',
                        'files' => ['resources/js/components/LiveGenerationProgress.vue', 'resources/js/pages/generations/Index.vue'],
                        'tests' => ['tests/Feature/Generations/LiveGenerationTest.php'],
                    ],
                    [
                        'title' => 'Ask for what is missing while the user waits',
                        'problem' => 'Asking for every optional detail up front slows the one thing the user came to do.',
                        'decision' => 'The form asks only for what a price needs. The optional name of the image is asked for during the wait, saved optimistically and editable afterwards. Running out of credits is solved in place, without losing the prompt.',
                        'files' => ['resources/js/components/GenerationTitle.vue', 'resources/js/pages/generations/Create.vue'],
                        'tests' => ['tests/Feature/Generations/LiveGenerationTest.php'],
                    ],
                    [
                        'title' => 'The price is visible before, during and after',
                        'problem' => 'People distrust charges they did not see coming.',
                        'decision' => 'The estimate updates as the user types, with its range. History shows estimated next to charged, and failures say in plain words that nothing was charged.',
                        'files' => ['resources/js/pages/generations/Create.vue', 'app/Models/Generation.php'],
                        'tests' => ['tests/Feature/Generations/GenerationPagesTest.php'],
                    ],
                    [
                        'title' => 'Images that outlive the provider\'s links',
                        'problem' => 'The provider\'s image links expire after 24 hours, which would leave history full of broken images.',
                        'decision' => 'Finished images are copied to our own storage and served through an authorised route. If a copy never lands, the card says so instead of showing a broken image.',
                        'files' => ['app/Jobs/StoreGenerationImage.php', 'app/Http/Controllers/GenerationController.php'],
                        'tests' => ['tests/Feature/Console/ReconcileGenerationsTest.php'],
                    ],
                ],
            ],
            [
                'area' => 'Operating it',
                'summary' => 'Built so that someone else can merge it, run it and trust its numbers.',
                'notes' => [
                    [
                        'title' => 'A report reconciled to the nanodollar',
                        'problem' => 'Recorded cost and billed cost drift apart quietly unless someone compares them.',
                        'decision' => 'The operator report shows spend, provider cost and profit per day and per user, and checks each day against the provider\'s own usage report. Days are UTC days of completion, the day the provider bills on.',
                        'files' => ['app/Services/Reports/SpendReport.php'],
                        'tests' => ['tests/Feature/Admin/ReportTest.php'],
                    ],
                    [
                        'title' => 'An API and an MCP server over the same services',
                        'problem' => 'A second interface usually means a second copy of the rules.',
                        'decision' => 'The REST API and the MCP server for AI agents call the same services as the web app. The API contract is an OpenAPI file, and a test fails if a route, a field or a tool name drifts from the documentation.',
                        'files' => ['app/Http/Controllers/Api/V1/GenerationController.php', 'app/Mcp/Servers/PixelforgeStudioServer.php', 'docs-site/api-reference/openapi.yaml'],
                        'tests' => ['tests/Feature/Api/OpenApiContractTest.php', 'tests/Feature/Mcp/PixelforgeStudioServerTest.php'],
                    ],
                    [
                        'title' => 'Access that cannot be claimed by signing up',
                        'problem' => 'Sign-up is open and emails are not verified, so an operator list by email could be claimed by whoever registered first.',
                        'decision' => 'Operator access is a flag granted from the server. API tokens are minted behind password confirmation, stored only as a hash, shown once and expire after 90 days.',
                        'files' => ['app/Console/Commands/GrantOperatorAccess.php', 'app/Http/Controllers/Settings/ApiTokenController.php'],
                        'tests' => ['tests/Feature/Admin/ReportTest.php', 'tests/Feature/Settings/ApiTokenTest.php'],
                    ],
                    [
                        'title' => 'Reviewed by someone who did not write it',
                        'problem' => 'The author of a money path is the worst person to find its gaps.',
                        'decision' => 'An independent review of the money paths found a hold that could be stuck by a lost job and an accepted generation that could drop out of polling. Both were fixed with a test before release. Static analysis runs at PHPStan level 9 with no baseline, and the suite also passes on PostgreSQL.',
                        'files' => ['phpstan.neon', 'docs/REQUIREMENTS.md'],
                        'tests' => ['tests/Feature/Console/ReconcileGenerationsTest.php'],
                    ],
                ],
            ],
        ];
    }
}
