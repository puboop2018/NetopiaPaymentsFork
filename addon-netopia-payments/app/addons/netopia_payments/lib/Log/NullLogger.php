<?php

declare(strict_types=1);

namespace Netopia\CsCart\Log;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * No-op logger for tests / CLI contexts where CS-Cart isn't bootstrapped.
 */
final class NullLogger extends AbstractLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        // intentional no-op
    }
}
