<?php

declare(strict_types=1);

namespace Netopia\Payment2\Tests\CsCart;

use Netopia\CsCart\Ipn\IpnVerifier;
use PHPUnit\Framework\TestCase;

final class IpnVerifierTest extends TestCase
{
    /** @return array{private: string, public: string} */
    private function generateRsaKeyPair(): array
    {
        $resource = openssl_pkey_new([
            'digest_alg'       => 'sha512',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        return ['private' => $privateKey, 'public' => $details['key']];
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function makeJwt(string $privateKey, string $posSignature, string $rawPayload, string $alg = 'RS512'): string
    {
        $header = $this->base64url((string) json_encode(['typ' => 'JWT', 'alg' => $alg]));
        $hashAlg = match ($alg) {
            'RS256' => 'sha256',
            'RS384' => 'sha384',
            default => 'sha512',
        };
        $opensslAlg = match ($alg) {
            'RS256' => OPENSSL_ALGO_SHA256,
            'RS384' => OPENSSL_ALGO_SHA384,
            default => OPENSSL_ALGO_SHA512,
        };
        $payload = $this->base64url((string) json_encode([
            'iss' => 'NETOPIA Payments',
            'aud' => $posSignature,
            'sub' => base64_encode(hash($hashAlg, $rawPayload, true)),
        ]));
        $signingInput = $header . '.' . $payload;
        openssl_sign($signingInput, $signature, $privateKey, $opensslAlg);

        return $signingInput . '.' . $this->base64url($signature);
    }

    public function testVerifySuccessfulIpn(): void
    {
        $keys     = $this->generateRsaKeyPair();
        $posSig   = '2ZCT-A1B2-C3D4-E5F6';
        $rawBody  = (string) json_encode(['payment' => ['status' => 3, 'ntpID' => 'NTP-123', 'amount' => 100.5]]);
        $token    = $this->makeJwt($keys['private'], $posSig, $rawBody);

        $verifier = new IpnVerifier();
        $result   = $verifier->verify($keys['public'], $posSig, $rawBody, $token);

        $this->assertTrue($result['verified']);
        $this->assertSame('', $result['error']);
        $this->assertIsArray($result['payload']);
        $this->assertSame(3, $result['payload']['payment']['status']);
    }

    public function testVerifyFailsOnMissingToken(): void
    {
        $verifier = new IpnVerifier();
        $result   = $verifier->verify('pk', 'sig', '{}', null);
        $this->assertFalse($result['verified']);
        $this->assertStringContainsString('Missing Verification-Token', $result['error']);
    }

    public function testVerifyFailsOnOversizedToken(): void
    {
        $verifier = new IpnVerifier();
        $token    = str_repeat('x', IpnVerifier::MAX_JWT_TOKEN_SIZE + 1);
        $result   = $verifier->verify('pk', 'sig', '{}', $token);
        $this->assertFalse($result['verified']);
        $this->assertStringContainsString('maximum allowed size', $result['error']);
    }

    public function testVerifyFailsOnInvalidFormat(): void
    {
        $verifier = new IpnVerifier();
        $result   = $verifier->verify('pk', 'sig', '{}', 'not.a-valid-jwt');
        $this->assertFalse($result['verified']);
    }

    public function testVerifyFailsOnBadAlgorithm(): void
    {
        $header = $this->base64url((string) json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = $this->base64url('{}');
        $token = $header . '.' . $payload . '.' . $this->base64url('sig');

        $verifier = new IpnVerifier();
        $result   = $verifier->verify('pk', 'sig', '{}', $token);
        $this->assertFalse($result['verified']);
        $this->assertStringContainsString('algorithm not allowed', $result['error']);
    }

    public function testVerifyFailsOnAudienceMismatch(): void
    {
        $keys    = $this->generateRsaKeyPair();
        $rawBody = (string) json_encode(['payment' => ['status' => 3]]);
        $token   = $this->makeJwt($keys['private'], 'wrong-sig', $rawBody);

        $verifier = new IpnVerifier();
        $result   = $verifier->verify($keys['public'], 'right-sig', $rawBody, $token);

        $this->assertFalse($result['verified']);
        $this->assertStringContainsString('audience mismatch', $result['error']);
    }

    public function testVerifyFailsOnPayloadTampering(): void
    {
        $keys    = $this->generateRsaKeyPair();
        $posSig  = 'sig';
        $rawBody = (string) json_encode(['payment' => ['status' => 3]]);
        $token   = $this->makeJwt($keys['private'], $posSig, $rawBody);

        $tampered = (string) json_encode(['payment' => ['status' => 5]]);
        $verifier = new IpnVerifier();
        $result   = $verifier->verify($keys['public'], $posSig, $tampered, $token);

        $this->assertFalse($result['verified']);
        $this->assertStringContainsString('integrity', $result['error']);
    }
}
