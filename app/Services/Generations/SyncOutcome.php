<?php

declare(strict_types=1);

namespace App\Services\Generations;

enum SyncOutcome
{
    /** The snapshot moved the generation forward. */
    case Applied;

    /** The snapshot was not newer than what we already had; nothing changed. */
    case Stale;
}
