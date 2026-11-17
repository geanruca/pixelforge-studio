<?php

namespace Tests\Feature\Credits;

use App\Enums\LedgerEntryType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

class CreditLedgerTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    private CreditLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(CreditLedger::class);
    }

    public function test_a_purchase_adds_credits_and_records_an_entry(): void
    {
        $user = User::factory()->create();

        $entry = $this->ledger->purchase($user, Money::fromDollars(5), 'ref-1');

        $this->assertNotNull($entry);
        $this->assertSame(5_000_000_000, $user->balance_nanos);
        $this->assertSame(5_000_000_000, $entry->balance_after_nanos);
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_repeating_a_purchase_reference_credits_it_once(): void
    {
        $user = User::factory()->create();

        $this->ledger->purchase($user, Money::fromDollars(5), 'ref-1');
        $repeat = $this->ledger->purchase($user, Money::fromDollars(5), 'ref-1');

        $this->assertNull($repeat);
        $this->assertSame(5_000_000_000, $user->refresh()->balance_nanos);
        $this->assertSame(1, $user->ledgerEntries()->count());
    }

    public function test_a_hold_takes_the_worst_case_price_off_the_balance(): void
    {
        $user = $this->userWithCredits(1);
        $generation = Generation::factory()->for($user)->create(['reserved_nanos' => 86_375_000]);

        $this->ledger->hold($generation);

        $this->assertSame(1_000_000_000 - 86_375_000, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_a_hold_is_refused_when_the_balance_does_not_cover_it(): void
    {
        $user = User::factory()->create();
        $this->ledger->purchase($user, 86_374_999, 'ref-1');
        $generation = Generation::factory()->for($user)->create(['reserved_nanos' => 86_375_000]);

        try {
            $this->ledger->hold($generation);
            $this->fail('The hold should have been refused.');
        } catch (InsufficientCreditsException $exception) {
            $this->assertSame(86_375_000, $exception->requiredNanos);
            $this->assertSame(86_374_999, $exception->availableNanos);
        }

        $this->assertSame(86_374_999, $user->refresh()->balance_nanos);
        $this->assertSame(0, $this->ledgerCount($generation, LedgerEntryType::Hold));
    }

    public function test_a_hold_for_exactly_the_whole_balance_is_allowed(): void
    {
        $user = User::factory()->create();
        $this->ledger->purchase($user, 86_375_000, 'ref-1');
        $generation = Generation::factory()->for($user)->create(['reserved_nanos' => 86_375_000]);

        $this->ledger->hold($generation);

        $this->assertSame(0, $user->refresh()->balance_nanos);
    }

    public function test_settling_releases_the_hold_and_charges_only_the_actual_price(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $charged = $this->ledger->settle($generation, self::USAGE_PRICE_NANOS);

        $this->assertTrue($charged);
        $this->assertSame(1_000_000_000 - self::USAGE_PRICE_NANOS, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_settling_twice_charges_once(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->ledger->settle($generation, self::USAGE_PRICE_NANOS);
        $again = $this->ledger->settle($generation, self::USAGE_PRICE_NANOS);

        $this->assertFalse($again);
        $this->assertSame(1, $this->ledgerCount($generation, LedgerEntryType::Charge));
        $this->assertSame(1, $this->ledgerCount($generation, LedgerEntryType::HoldRelease));
        $this->assertSame(1_000_000_000 - self::USAGE_PRICE_NANOS, $user->refresh()->balance_nanos);
    }

    public function test_releasing_gives_the_whole_hold_back_and_only_once(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->ledger->release($generation);
        $this->ledger->release($generation);

        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
        $this->assertSame(1, $this->ledgerCount($generation, LedgerEntryType::HoldRelease));
        $this->assertSame(0, $this->ledgerCount($generation, LedgerEntryType::Charge));
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_the_database_itself_refuses_a_second_charge_for_a_generation(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);
        $this->ledger->settle($generation, self::USAGE_PRICE_NANOS);

        $this->expectException(UniqueConstraintViolationException::class);

        // Bypassing the ledger on purpose: even buggy code cannot double charge.
        LedgerEntry::query()->create([
            'user_id' => $user->id,
            'generation_id' => $generation->id,
            'type' => LedgerEntryType::Charge,
            'amount_nanos' => -self::USAGE_PRICE_NANOS,
            'balance_after_nanos' => 0,
            'created_at' => now(),
        ]);
    }

    public function test_many_generations_keep_the_ledger_and_the_balance_in_step(): void
    {
        $user = $this->userWithCredits(2);

        $succeeded = $this->heldGeneration($user);
        $failed = $this->heldGeneration($user);
        $pending = $this->heldGeneration($user);

        $this->ledger->settle($succeeded, self::USAGE_PRICE_NANOS);
        $this->ledger->release($failed);

        $this->assertSame(
            2_000_000_000 - self::USAGE_PRICE_NANOS - $pending->reserved_nanos,
            $user->refresh()->balance_nanos,
        );
        $this->assertLedgerMatchesBalance($user);
    }
}
