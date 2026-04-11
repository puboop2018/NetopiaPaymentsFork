<?php

declare(strict_types=1);

namespace Netopia\Payment2\Enum;

/**
 * NETOPIA API / IPN error codes used for flow control.
 *
 * Only codes that drive addon logic are enumerated here; NETOPIA returns
 * many more error codes, all handled as "other failure".
 */
enum ErrorCode: string
{
    case Approved        = '0';
    case ApprovedAlt     = '00';
    case ThreeDsRequired = '100';
    case HostedPage      = '101';

    /**
     * Returns true if the given raw error code string indicates approval.
     */
    public static function isApproved(string $code): bool
    {
        return $code === self::Approved->value || $code === self::ApprovedAlt->value;
    }

    public static function isThreeDsRequired(string $code): bool
    {
        return $code === self::ThreeDsRequired->value;
    }

    public static function isHostedPage(string $code): bool
    {
        return $code === self::HostedPage->value;
    }
}
