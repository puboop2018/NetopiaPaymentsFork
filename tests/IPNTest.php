<?php

declare(strict_types=1);

namespace Netopia\Payment2\Tests;

use Netopia\Payment2\IPN;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the IPN class.
 *
 * Uses a TestableIPN subclass to test methods without relying on
 * HTTP headers or $_SERVER superglobals.
 */
class IPNTest extends TestCase
{
    private function generateRsaKeyPair(): array
    {
        $config = [
            'digest_alg' => 'sha512',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $keyResource = openssl_pkey_new($config);
        openssl_pkey_export($keyResource, $privateKeyPem);
        $publicKeyDetails = openssl_pkey_get_details($keyResource);
        $publicKeyPem = $publicKeyDetails['key'];

        return ['private' => $privateKeyPem, 'public' => $publicKeyPem];
    }

    public function testVerifyIPNFailsWithoutVerificationToken(): void
    {
        $ipn = new TestableIPN();
        $ipn->publicKeyStr = 'test';
        $ipn->alg = 'RS512';
        $ipn->activeKey = 'test';
        $ipn->posSignatureSet = ['test'];
        $ipn->hashMethod = 'sha512';
        $ipn->setMockHeader(null);

        $result = $ipn->verifyIPN('{}');

        $this->assertSame(IPN::ERROR_TYPE_PERMANENT, $result['errorType']);
        $this->assertStringContainsString('Missing Verification-Token', $result['errorMessage']);
    }

    public function testVerifyIPNFailsWithInvalidJwtFormat(): void
    {
        $ipn = new TestableIPN();
        $ipn->publicKeyStr = 'test';
        $ipn->alg = 'RS512';
        $ipn->activeKey = 'test';
        $ipn->posSignatureSet = ['test'];
        $ipn->hashMethod = 'sha512';
        $ipn->setMockHeader('not-a-jwt');

        $result = $ipn->verifyIPN('{}');

        $this->assertSame(IPN::ERROR_TYPE_PERMANENT, $result['errorType']);
        $this->assertStringContainsString('Invalid JWT format', $result['errorMessage']);
    }

    public function testVerifyIPNFailsWithOversizedToken(): void
    {
        $ipn = new TestableIPN();
        $ipn->publicKeyStr = 'test';
        $ipn->alg = 'RS512';
        $ipn->activeKey = 'test';
        $ipn->posSignatureSet = ['test'];
        $ipn->hashMethod = 'sha512';
        // Token over 10KB
        $ipn->setMockHeader(str_repeat('a', 10241));

        $result = $ipn->verifyIPN('{}');

        $this->assertSame(IPN::ERROR_TYPE_PERMANENT, $result['errorType']);
        $this->assertStringContainsString('exceeds maximum', $result['errorMessage']);
    }

    public function testVerifyIPNFailsWithMissingPublicKey(): void
    {
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'RS512']));
        $payload = base64_encode(json_encode(['test' => true]));
        $signature = base64_encode('fake-sig');
        $token = $header . '.' . $payload . '.' . $signature;

        $ipn = new TestableIPN();
        $ipn->publicKeyStr = '';
        $ipn->alg = 'RS512';
        $ipn->activeKey = 'test';
        $ipn->posSignatureSet = ['test'];
        $ipn->hashMethod = 'sha512';
        $ipn->setMockHeader($token);

        $result = $ipn->verifyIPN('{}');

        $this->assertSame(IPN::ERROR_TYPE_PERMANENT, $result['errorType']);
        $this->assertStringContainsString('Public key is not configured', $result['errorMessage']);
    }

    public function testVerifyIPNFailsWithDisallowedAlgorithm(): void
    {
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode(['test' => true]));
        $signature = base64_encode('fake-sig');
        $token = $header . '.' . $payload . '.' . $signature;

        $keys = $this->generateRsaKeyPair();

        $ipn = new TestableIPN();
        $ipn->publicKeyStr = $keys['public'];
        $ipn->alg = 'HS256';
        $ipn->activeKey = 'test';
        $ipn->posSignatureSet = ['test'];
        $ipn->hashMethod = 'sha512';
        $ipn->setMockHeader($token);

        $result = $ipn->verifyIPN('{}');

        $this->assertSame(IPN::ERROR_TYPE_PERMANENT, $result['errorType']);
        $this->assertStringContainsString('algorithm not allowed', $result['errorMessage']);
    }

    public function testVerifyIPNFailsWithEmptyAlgorithmConfig(): void
    {
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'RS512']));
        $payload = base64_encode(json_encode(['test' => true]));
        $signature = base64_encode('fake-sig');
        $token = $header . '.' . $payload . '.' . $signature;

        $keys = $this->generateRsaKeyPair();

        $ipn = new TestableIPN();
        $ipn->publicKeyStr = $keys['public'];
        $ipn->alg = '';
        $ipn->activeKey = 'test';
        $ipn->posSignatureSet = ['test'];
        $ipn->hashMethod = 'sha512';
        $ipn->setMockHeader($token);

        $result = $ipn->verifyIPN('{}');

        $this->assertSame(IPN::ERROR_TYPE_PERMANENT, $result['errorType']);
        $this->assertStringContainsString('algorithm is not configured', $result['errorMessage']);
    }

    public function testVerifyIPNSuccessReturnsNoError(): void
    {
        $result = $this->createValidIpnResult();

        $this->assertSame(IPN::ERROR_TYPE_NONE, $result['errorType']);
        $this->assertNull($result['errorCode']);
        $this->assertSame('', $result['errorMessage']);
    }

    public function testStatusConstantsHaveExpectedValues(): void
    {
        $this->assertSame(3, IPN::STATUS_PAID);
        $this->assertSame(5, IPN::STATUS_CONFIRMED);
        $this->assertSame(15, IPN::STATUS_3D_AUTH);
        $this->assertSame(12, IPN::STATUS_DECLINED);
        $this->assertSame(13, IPN::STATUS_FRAUD);
    }

    public function testErrorTypeConstantsExist(): void
    {
        $this->assertSame(0x00, IPN::ERROR_TYPE_NONE);
        $this->assertSame(0x01, IPN::ERROR_TYPE_TEMPORARY);
        $this->assertSame(0x02, IPN::ERROR_TYPE_PERMANENT);
    }

    /**
     * Helper: create a valid IPN verification using real RSA keys.
     */
    private function createValidIpnResult(): array
    {
        $keys = $this->generateRsaKeyPair();
        $posSignature = 'TEST-POS-SIG';
        $ipnPayload = json_encode(['payment' => ['status' => 3, 'ntpID' => 'NTP-123']]);

        // Build a valid JWT
        $payloadHash = base64_encode(hash('sha512', $ipnPayload, true));
        $jwtHeader = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'RS512']));
        $jwtPayload = base64_encode(json_encode([
            'iss' => 'NETOPIA Payments',
            'aud' => $posSignature,
            'sub' => $payloadHash,
            'iat' => time() * 1000,
        ]));

        $dataToSign = $jwtHeader . '.' . $jwtPayload;
        openssl_sign($dataToSign, $signature, $keys['private'], OPENSSL_ALGO_SHA512);
        $jwtSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        // Use standard base64url for header and payload too
        $jwtHeader = rtrim(strtr($jwtHeader, '+/', '-_'), '=');
        $jwtPayload = rtrim(strtr($jwtPayload, '+/', '-_'), '=');

        $token = $jwtHeader . '.' . $jwtPayload . '.' . $jwtSignature;

        $ipn = new TestableIPN();
        $ipn->publicKeyStr = $keys['public'];
        $ipn->alg = 'RS512';
        $ipn->activeKey = $posSignature;
        $ipn->posSignatureSet = [$posSignature];
        $ipn->hashMethod = 'sha512';
        $ipn->setMockHeader($token);

        return $ipn->verifyIPN($ipnPayload);
    }
}

/**
 * Testable IPN subclass that overrides header retrieval for testing.
 */
class TestableIPN extends IPN
{
    private ?string $mockHeader = null;

    public function setMockHeader(?string $value): void
    {
        $this->mockHeader = $value;
    }

    /**
     * Override private method via reflection-free approach:
     * We override extractVerificationToken by making the parent's
     * getSpecificHeader return our mock value.
     *
     * Since getSpecificHeader is private and we can't override it,
     * we set $_SERVER to simulate the header.
     */
    public function verifyIPN(?string $rawPayload = null): array
    {
        // Simulate HTTP header via $_SERVER
        if ($this->mockHeader !== null) {
            $_SERVER['HTTP_VERIFICATION_TOKEN'] = $this->mockHeader;
        } else {
            unset($_SERVER['HTTP_VERIFICATION_TOKEN']);
        }

        $result = parent::verifyIPN($rawPayload);

        // Clean up
        unset($_SERVER['HTTP_VERIFICATION_TOKEN']);

        return $result;
    }
}
