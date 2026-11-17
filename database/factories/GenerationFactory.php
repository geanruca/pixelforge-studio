<?php

namespace Database\Factories;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * A generation that was recorded locally and not yet sent to Pixelforge.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Queued,
            'prompt' => fake()->sentence(),
            'size' => GenerationSize::Square,
            'quality' => GenerationQuality::Medium,
            'reference_images' => [],
            'estimated_price_nanos' => 68_875_000,
            'reserved_nanos' => 86_375_000,
        ];
    }

    /**
     * Accepted by Pixelforge and waiting in its queue.
     */
    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'provider_id' => 'gen_'.Str::lower(Str::random(24)),
            'submitted_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->submitted()->state(fn (): array => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function succeeded(int $providerCostNanos = 50_000_000): static
    {
        return $this->submitted()->state(fn (): array => [
            'status' => GenerationStatus::Succeeded,
            'outcome_confirmed' => true,
            'provider_cost_nanos' => $providerCostNanos,
            'price_nanos' => intdiv($providerCostNanos * 5, 4),
            'image_url' => 'https://pixelforge.test/images/'.Str::random(12).'.png',
            'image_url_expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);
    }

    public function failed(string $errorCode = 'generation_failed'): static
    {
        return $this->submitted()->state(fn (): array => [
            'status' => GenerationStatus::Failed,
            'outcome_confirmed' => true,
            'error_code' => $errorCode,
            'completed_at' => now(),
        ]);
    }
}
