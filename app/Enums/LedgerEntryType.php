<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerEntryType: string
{
    /** Credits bought by the user. */
    case Purchase = 'purchase';

    /** Worst-case price set aside when a generation is submitted. */
    case Hold = 'hold';

    /** The hold given back once the generation reaches a final state. */
    case HoldRelease = 'hold_release';

    /** The actual price of a succeeded generation. */
    case Charge = 'charge';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Credits purchased',
            self::Hold => 'Reserved for generation',
            self::HoldRelease => 'Reservation released',
            self::Charge => 'Generation charged',
        };
    }
}
