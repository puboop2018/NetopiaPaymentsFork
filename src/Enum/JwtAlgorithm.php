<?php

declare(strict_types=1);

namespace Netopia\Payment2\Enum;

/**
 * Allowed JWT signing algorithms for NETOPIA IPN verification.
 *
 * RSA-only whitelist to prevent algorithm substitution attacks.
 * HS* and 'none' are intentionally excluded.
 */
enum JwtAlgorithm: string
{
    case RS256 = 'RS256';
    case RS384 = 'RS384';
    case RS512 = 'RS512';

    /**
     * OpenSSL algorithm constant for verification.
     */
    public function opensslAlgorithm(): int
    {
        return match ($this) {
            self::RS256 => OPENSSL_ALGO_SHA256,
            self::RS384 => OPENSSL_ALGO_SHA384,
            self::RS512 => OPENSSL_ALGO_SHA512,
        };
    }

    /**
     * Hash function name for payload integrity verification.
     */
    public function hashAlgorithm(): string
    {
        return match ($this) {
            self::RS256 => 'sha256',
            self::RS384 => 'sha384',
            self::RS512 => 'sha512',
        };
    }

    /**
     * Safely parse an algorithm string. Throws on unknown algorithms.
     */
    public static function fromString(string $alg): self
    {
        return self::from($alg);
    }
}
