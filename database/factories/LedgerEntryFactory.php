<?php

namespace Database\Factories;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => LedgerEntryType::Purchase,
            'amount_nanos' => 5_000_000_000,
            'balance_after_nanos' => 5_000_000_000,
            'reference' => (string) Str::uuid(),
            'created_at' => now(),
        ];
    }
}
