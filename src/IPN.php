<?php

declare(strict_types=1);

namespace Netopia\Payment2;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Netopia\Payment2\Exception\VerificationFailedException;

/**
 * IPN (Instant Payment Notification) handler for NETOPIA Payments.
 *
 * Verifies JWT signatures on IPN callbacks and decodes payment status.
 */
class IPN extends Request
{
    public string $activeKey = '';

    /** @var array<string> */
    public array $posSignatureSet = [];

    public string $hashMethod = '';
    public string $alg = '';
    public string $publicKeyStr = '';

    // Error code definitions
    public const E_VERIFICATION_FAILED_GENERAL         = 0x10000101;
    public const E_VERIFICATION_FAILED_SIGNATURE        = 0x10000102;
    public const E_VERIFICATION_FAILED_NBF_IAT          = 0x10000103;
    public const E_VERIFICATION_FAILED_EXPIRED          = 0x10000104;
    public const E_VERIFICATION_FAILED_AUDIENCE         = 0x10000105;
    public const E_VERIFICATION_FAILED_TAINTED_PAYLOAD  = 0x10000106;
    public const E_VERIFICATION_FAILED_PAYLOAD_FORMAT   = 0x10000107;

    public const ERROR_TYPE_NONE      = 0x00;
    public const ERROR_TYPE_TEMPORARY = 0x01;
    public const ERROR_TYPE_PERMANENT = 0x02;

    // Payment status constants
    public const STATUS_NEW                                  = 1;
    public const STATUS_OPENED                               = 2;
    public const STATUS_PAID                                 = 3;
    public const STATUS_CANCELED                             = 4;
    public const STATUS_CONFIRMED                            = 5;
    public const STATUS_PENDING                              = 6;
    public const STATUS_SCHEDULED                            = 7;
    public const STATUS_CREDIT                               = 8;
    public const STATUS_CHARGEBACK_INIT                      = 9;
    public const STATUS_CHARGEBACK_ACCEPT                    = 10;
    public const STATUS_ERROR                                = 11;
    public const STATUS_DECLINED                             = 12;
    public const STATUS_FRAUD                                = 13;
    public const STATUS_PENDING_AUTH                         = 14;
    public const STATUS_3D_AUTH                              = 15;
    public const STATUS_CHARGEBACK_REPRESENTMENT             = 16;
    public const STATUS_REVERSED                             = 17;
    public const STATUS_PENDING_ANY                          = 18;
    public const STATUS_PROGRAMMED_RECURRENT_PAYMENT         = 19;
    public const STATUS_CANCELED_PROGRAMMED_RECURRENT_PAYMENT = 20;
    public const STATUS_TRIAL_PENDING                        = 21;
    public const STATUS_TRIAL                                = 22;
    public const STATUS_EXPIRED                              = 23;

    /**
     * Verify an IPN callback from NETOPIA.
     *
     * @return array{errorType: int, errorCode: int|null, errorMessage: string}
     */
    public function verifyIPN(): array
    {
        $outputData = [
            'errorType'    => self::ERROR_TYPE_NONE,
            'errorCode'    => null,
            'errorMessage' => '',
        ];

        try {
            $verificationToken = $this->extractVerificationToken();
            $jwtParts = $this->parseJwtStructure($verificationToken);
            $publicKey = $this->loadPublicKey();
            $objJwt = $this->decodeAndVerifyJwt($verificationToken, $publicKey, $jwtParts['headerAlg']);
            $this->validateJwtClaims($objJwt);

            $payload = file_get_contents('php://input');
            if ($payload === false) {
                $payload = '';
            }
            $this->verifyPayloadIntegrity($payload, $objJwt);

            $ipnData = $this->decodeIpnPayload($payload);
            $this->processPaymentStatus($ipnData);
        } catch (\Exception $e) {
            $outputData['errorType']   = self::ERROR_TYPE_PERMANENT;
            $outputData['errorCode']   = ($e->getCode() !== 0) ? $e->getCode() : self::E_VERIFICATION_FAILED_GENERAL;
            $outputData['errorMessage'] = $e->getMessage();
        }

        return $outputData;
    }

    /**
     * Extract the Verification-Token from HTTP headers.
     *
     * @throws VerificationFailedException If the token is missing or too large
     */
    private function extractVerificationToken(): string
    {
        $token = $this->getSpecificHeader('Verification-Token');

        if ($token === null || $token === '') {
            throw new VerificationFailedException('Missing Verification-Token header');
        }

        // Prevent DoS via oversized tokens (max 10KB)
        if (strlen($token) > 10240) {
            throw new VerificationFailedException('Verification-Token exceeds maximum allowed length');
        }

        return $token;
    }

    /**
     * Parse JWT structure and validate format.
     *
     * @return array{headerAlg: string}
     * @throws VerificationFailedException If JWT format is invalid
     */
    private function parseJwtStructure(string $token): array
    {
        $tks = explode('.', $token);
        if (count($tks) !== 3) {
            throw new VerificationFailedException('Invalid JWT format: expected 3 parts');
        }

        $headerJson = base64_decode(strtr($tks[0], '-_', '+/'), true);
        if ($headerJson === false) {
            throw new VerificationFailedException('Invalid JWT header encoding');
        }

        $jwtHeader = json_decode($headerJson);
        if ($jwtHeader === null || json_last_error() !== JSON_ERROR_NONE) {
            throw new VerificationFailedException('Invalid JWT header JSON');
        }

        if (!isset($jwtHeader->typ) || $jwtHeader->typ !== 'JWT') {
            throw new VerificationFailedException('Invalid JWT type');
        }

        $headerAlg = $jwtHeader->alg ?? $this->alg;

        return ['headerAlg' => (string) $headerAlg];
    }

    /**
     * Load and validate the public key for signature verification.
     *
     * @return \OpenSSLAsymmetricKey|resource
     * @throws VerificationFailedException If the public key is missing or invalid
     */
    private function loadPublicKey()
    {
        if (empty($this->publicKeyStr)) {
            throw new VerificationFailedException('Public key is not configured');
        }

        $publicKey = openssl_pkey_get_public($this->publicKeyStr);
        if ($publicKey === false) {
            throw new VerificationFailedException('Invalid public key format');
        }

        return $publicKey;
    }

    /**
     * Decode and verify JWT using firebase/php-jwt.
     *
     * @param \OpenSSLAsymmetricKey|resource $publicKey
     * @throws VerificationFailedException If JWT decoding or verification fails
     */
    private function decodeAndVerifyJwt(string $token, $publicKey, string $algorithm): object
    {
        if (empty($this->alg)) {
            throw new VerificationFailedException('JWT algorithm is not configured');
        }

        $jwtAlgorithm = !empty($algorithm) ? $algorithm : $this->alg;

        JWT::$timestamp = time() * 1000;

        try {
            return JWT::decode($token, new Key($publicKey, $jwtAlgorithm));
        } catch (\Exception $e) {
            throw new VerificationFailedException('JWT decode failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate JWT claims (issuer, audience, signature set).
     *
     * @throws VerificationFailedException If any claim fails validation
     */
    private function validateJwtClaims(object $objJwt): void
    {
        // Verify issuer — use hash_equals to prevent timing attacks
        if (!isset($objJwt->iss) || !hash_equals('NETOPIA Payments', (string) $objJwt->iss)) {
            throw new VerificationFailedException('JWT issuer verification failed');
        }

        // Verify audience is present
        if (empty($objJwt->aud)) {
            throw new VerificationFailedException('JWT audience is empty');
        }

        // Normalize audience (may be string or array depending on NETOPIA response)
        $actualAud = is_array($objJwt->aud)
            ? ($objJwt->aud[0] ?? '')
            : (string) $objJwt->aud;

        // Verify audience matches active key — use hash_equals to prevent timing attacks
        if (!hash_equals($this->activeKey, (string) $actualAud)) {
            throw new VerificationFailedException('JWT audience does not match active key');
        }

        if (!in_array($actualAud, $this->posSignatureSet, true)) {
            throw new VerificationFailedException('JWT audience not found in signature set');
        }

        if (empty($this->hashMethod)) {
            throw new VerificationFailedException('Hash method is not configured');
        }
    }

    /**
     * Verify IPN payload integrity using the hash in the JWT subject claim.
     *
     * @throws VerificationFailedException If payload hash doesn't match
     */
    private function verifyPayloadIntegrity(string $payload, object $objJwt): void
    {
        $payloadHash = base64_encode(hash($this->hashMethod, $payload, true));

        // Use hash_equals to prevent timing attacks
        if (!isset($objJwt->sub) || !hash_equals((string) $objJwt->sub, $payloadHash)) {
            throw new VerificationFailedException('Payload integrity check failed');
        }
    }

    /**
     * Decode the raw IPN payload from JSON.
     *
     * @throws VerificationFailedException If JSON is invalid
     */
    private function decodeIpnPayload(string $payload): object
    {
        $ipnData = json_decode($payload, false);

        if ($ipnData === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new VerificationFailedException('Invalid IPN payload JSON');
        }

        /** @var object $ipnData */
        return $ipnData;
    }

    /**
     * Process the payment status from the IPN data.
     *
     * Override this method to implement custom status handling logic.
     */
    protected function processPaymentStatus(object $ipnData): void
    {
        // Status processing is intentionally a no-op in the SDK.
        // Integrators should override this method or handle status
        // in their own callback logic using the IPN status constants.
    }

    /**
     * Get a specific HTTP header value (case-insensitive).
     */
    private function getSpecificHeader(string $headerName): ?string
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers !== false) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp($name, $headerName) === 0) {
                        return $value;
                    }
                }
            }
        }

        $serverKey = 'HTTP_' . str_replace('-', '_', strtoupper($headerName));
        if (isset($_SERVER[$serverKey])) {
            return (string) $_SERVER[$serverKey];
        }

        return null;
    }
}
