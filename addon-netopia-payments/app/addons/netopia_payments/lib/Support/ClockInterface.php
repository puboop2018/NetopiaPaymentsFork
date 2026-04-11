<?php

declare(strict_types=1);

namespace Netopia\CsCart\Support;

/**
 * Default system clock. Swap for a fixed clock in tests.
 *
 * Kept as a plain interface (not psr/clock) to avoid an extra dependency;
 * has the same `now()` contract should we adopt PSR-20 later.
 */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
