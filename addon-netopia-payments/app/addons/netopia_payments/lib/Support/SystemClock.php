<?php

declare(strict_types=1);

namespace Netopia\CsCart\Support;

/**
 * Wall-clock implementation of ClockInterface.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    public function iso8601(): string
    {
        return $this->now()->format('c');
    }
}
