<?php

namespace Tests\Support;

use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Generations\GenerationCreator;
use App\Support\Money;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Builders for the payloads Pixelforge sends, shaped exactly like its docs.
 */
trait InteractsWithPixelforge
{
    protected const string WEBHOOK_SECRET = 'whsec_test_secret';

    protected function configurePixelforge(): void
    {
        config([
            'pixelforge.base_url' => 'https://pixelforge.test/v1',
            'pixelforge.api_key' => 'pf_test_key',
            'pixelforge.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    /**
     * The real body of `GET /v1/pricing`.
     *
     * @return array<string, mixed>
     */
    protected function pricingPayload(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => $this->ratesPayload(),
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function ratesPayload(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }

    /**
     * A usage whose provider cost is exactly $0.0565 (56,500,000 nanodollars)
     * and whose price with a 25% markup is exactly $0.070625.
     *
     * @return array<string, int>
     */
    protected function usagePayload(): array
    {
        return [
            'input_text_tokens' => 100,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 960,
            'total_tokens' => 2160,
        ];
    }

    protected const int USAGE_COST_NANOS = 56_500_000;

    protected const int USAGE_PRICE_NANOS = 70_625_000;

    /**
     * A Pixelforge generation object for the given local generation.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function providerGeneration(Generation $generation, string $status, array $overrides = []): array
    {
        $final = in_array($status, ['succeeded', 'failed'], true);

        return [
            'id' => $generation->provider_id ?? 'gen_'.Str::lower(Str::random(24)),
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_id' => (string) $generation->id],
            'image_url' => $status === 'succeeded' ? 'https://pixelforge.test/images/signed.png' : null,
            'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601String() : null,
            'usage' => $status === 'succeeded' ? $this->usagePayload() : null,
            'rates' => $this->ratesPayload(),
            'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'] : null,
            'created_at' => now()->subSeconds(20)->toIso8601String(),
            'started_at' => $status === 'queued' ? null : now()->subSeconds(10)->toIso8601String(),
            'completed_at' => $final ? now()->toIso8601String() : null,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    protected function webhookEvent(string $type, array $object, ?string $eventId = null): array
    {
        return [
            'id' => $eventId ?? 'evt_'.Str::lower(Str::random(24)),
            'object' => 'event',
            'type' => $type,
            'created_at' => now()->toIso8601String(),
            'data' => ['object' => $object],
        ];
    }

    /**
     * Deliver an event the way Pixelforge does: raw JSON body, signed.
     *
     * @param  array<string, mixed>  $event
     */
    protected function deliverWebhook(array $event, ?string $secret = null, ?int $timestamp = null, ?string $body = null): TestResponse
    {
        $body ??= (string) json_encode($event);
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret ?? self::WEBHOOK_SECRET);

        return $this->call('POST', route('webhooks.pixelforge'), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
        ], content: $body);
    }

    /**
     * A user holding `$dollars` of purchased credits.
     */
    protected function userWithCredits(int $dollars = 5): User
    {
        $user = User::factory()->create();

        app(CreditLedger::class)->purchase($user, Money::fromDollars($dollars), (string) Str::uuid());

        return $user;
    }

    /**
     * A generation of `$user` with its worst-case price already on hold,
     * exactly as {@see GenerationCreator} leaves it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function heldGeneration(User $user, array $attributes = [], bool $submitted = true): Generation
    {
        $factory = Generation::factory()->for($user);

        $generation = ($submitted ? $factory->submitted() : $factory)->create($attributes);

        app(CreditLedger::class)->hold($generation);

        return $generation;
    }

    /**
     * The invariant behind every money test: the ledger adds up to the balance.
     */
    protected function assertLedgerMatchesBalance(User $user): void
    {
        $this->assertSame(
            (int) $user->ledgerEntries()->sum('amount_nanos'),
            $user->refresh()->balance_nanos,
            'The sum of the ledger entries must equal the balance.',
        );
    }

    protected function ledgerCount(Generation $generation, LedgerEntryType $type): int
    {
        return $generation->ledgerEntries()->where('type', $type)->count();
    }
}
