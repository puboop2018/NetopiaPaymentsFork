<?php

declare(strict_types=1);

namespace Netopia\Payment2\Enum;

/**
 * NETOPIA API environment mode.
 */
enum PaymentMode: string
{
    case Live    = 'live';
    case Sandbox = 'sandbox';

    /**
     * Base API URL for this mode.
     */
    public function baseUrl(): string
    {
        return match ($this) {
            self::Live    => 'https://secure.netopia-payments.com/api/',
            self::Sandbox => 'https://secure-sandbox.netopia-payments.com/',
        };
    }

    /**
     * Safe parser: accepts any value and defaults to Sandbox.
     */
    public static function fromMixed(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        return (is_string($value) && strtolower($value) === 'live')
            ? self::Live
            : self::Sandbox;
    }
}
