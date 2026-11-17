<?php

declare(strict_types=1);

namespace App\Enums;

enum WebhookOutcome: string
{
    /** The event moved the generation forward. */
    case Applied = 'applied';

    /** The event was older than what we already knew (out of order). */
    case Stale = 'stale';

    /** The event needed no action (ping, unknown type, unknown generation). */
    case Ignored = 'ignored';
}
