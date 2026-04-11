<?php

declare(strict_types=1);

namespace Netopia\CsCart\Support;

/**
 * Input sanitization / validation helpers.
 */
final class Sanitizer
{
    /** Maximum length for 3DS browser fingerprint string values. */
    public const int MAX_3DS_FIELD_LENGTH = 512;

    /**
     * Strip control characters and truncate a 3DS fingerprint field.
     */
    public static function threeDsField(string $value): string
    {
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        return substr($cleaned, 0, self::MAX_3DS_FIELD_LENGTH);
    }

    /**
     * Return a valid IP address or loopback on failure.
     */
    public static function ipAddress(string $ip): string
    {
        $filtered = filter_var($ip, FILTER_VALIDATE_IP);
        return $filtered !== false ? $filtered : '127.0.0.1';
    }

    /**
     * Validate that a URL returned by NETOPIA is safe to redirect/form-post to.
     */
    public static function isSafeHttpsUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        return strtolower($parsed['scheme']) === 'https';
    }
}
