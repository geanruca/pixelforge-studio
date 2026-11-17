<?php

declare(strict_types=1);

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Position in the lifecycle. A generation only ever moves to a higher
     * rank, which is what makes out-of-order webhooks harmless.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Processing => 1,
            self::Succeeded, self::Failed => 2,
        };
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Queued, self::Processing];
    }
}
