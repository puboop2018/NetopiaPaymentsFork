<?php

declare(strict_types=1);

namespace Netopia\CsCart\Ipn;

use Netopia\CsCart\Exception\NetopiaAddonException;
use Netopia\Payment2\Enum\JwtAlgorithm;

/**
 * Verifies NETOPIA IPN JWT tokens using RSA public key + SHA* hash.
 *
 * Pure: no CS-Cart dependencies, fully unit-testable.
 */
final class IpnVerifier
{
    public const int MAX_JWT_TOKEN_SIZE = 10240;

    /**
     * @return array{verified: bool, payload: array<string, mixed>|null, error: string}
     */
    public function verify(string $publicKeyPem, string $posSignature, string $rawPostBody, ?string $verificationToken): array
    {
        if ($verificationToken === null || $verificationToken === '') {
            return $this->fail('Missing Verification-Token header');
        }

        if (strlen($verificationToken) > self::MAX_JWT_TOKEN_SIZE) {
            return $this->fail('JWT token exceeds maximum allowed size');
        }

        $parts = explode('.', $verificationToken);
        if (count($parts) !== 3) {
            return $this->fail('Invalid JWT format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        try {
            $header = $this->decodeJsonPart($headerB64);
        } catch (NetopiaAddonException $e) {
            return $this->fail($e->getMessage());
        }

        if (($header['typ'] ?? '') !== 'JWT') {
            return $this->fail('Invalid JWT type');
        }

        try {
            $algorithm = JwtAlgorithm::from((string) ($header['alg'] ?? 'RS512'));
        } catch (\ValueError) {
            return $this->fail('JWT algorithm not allowed: ' . (string) ($header['alg'] ?? ''));
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return $this->fail('Invalid public key');
        }

        $signature    = $this->base64urlDecode($signatureB64);
        $dataToVerify = $headerB64 . '.' . $payloadB64;
        if (openssl_verify($dataToVerify, $signature, $publicKey, $algorithm->opensslAlgorithm()) !== 1) {
            return $this->fail('JWT signature verification failed');
        }

        try {
            $claims = $this->decodeJsonPart($payloadB64);
        } catch (NetopiaAddonException $e) {
            return $this->fail($e->getMessage());
        }

        if (!hash_equals('NETOPIA Payments', (string) ($claims['iss'] ?? ''))) {
            return $this->fail('Invalid JWT issuer');
        }

        $aud = $claims['aud'] ?? '';
        if (is_array($aud)) {
            $aud = (string) ($aud[0] ?? '');
        }

        if (!hash_equals($posSignature, (string) $aud)) {
            return $this->fail('JWT audience mismatch');
        }

        $payloadHash = base64_encode(hash($algorithm->hashAlgorithm(), $rawPostBody, true));
        if (!hash_equals($payloadHash, (string) ($claims['sub'] ?? ''))) {
            return $this->fail('Payload integrity check failed');
        }

        if (!json_validate($rawPostBody)) {
            return $this->fail('Invalid IPN payload JSON');
        }

        /** @var array<string, mixed> $ipnData */
        $ipnData = json_decode($rawPostBody, true, flags: JSON_THROW_ON_ERROR);

        return ['verified' => true, 'payload' => $ipnData, 'error' => ''];
    }

    /**
     * @return array<string, mixed>
     * @throws NetopiaAddonException
     */
    private function decodeJsonPart(string $b64): array
    {
        $json = $this->base64urlDecode($b64);
        if ($json === '' || !json_validate($json)) {
            throw new NetopiaAddonException('Invalid JWT part encoding');
        }
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return $decoded;
    }

    private function base64urlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded !== false ? $decoded : '';
    }

    /**
     * @return array{verified: false, payload: null, error: string}
     */
    private function fail(string $message): array
    {
        return ['verified' => false, 'payload' => null, 'error' => $message];
    }
}
