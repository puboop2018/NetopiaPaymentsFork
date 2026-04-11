<?php

declare(strict_types=1);

namespace Netopia\Payment2;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Netopia\Payment2\Enum\JwtAlgorithm;
use Netopia\Payment2\Enum\PaymentStatus;
use Netopia\Payment2\Exception\KeyLoadException;
use Netopia\Payment2\Exception\VerificationFailedException;

/**
 * IPN (Instant Payment Notification) handler for NETOPIA Payments.
 *
 * Verifies JWT signatures on IPN callbacks and decodes payment status.
 *
 * Note: This class extends BaseHttpClient (not Request) because IPN verification
 * does not need payment request building capabilities. This follows the
 * Interface Segregation Principle.
 */
class IPN extends BaseHttpClient
{
    /** Maximum JWT token size (10 KB) — prevents DoS. */
    public const int MAX_JWT_TOKEN_SIZE = 10240;

    public const int E_VERIFICATION_FAILED_GENERAL = 0x10000101;

    public const int ERROR_TYPE_NONE      = 0x00;
    public const int ERROR_TYPE_TEMPORARY = 0x01;
    public const int ERROR_TYPE_PERMANENT = 0x02;

    // Legacy payment status constants — use Enum\PaymentStatus instead.
    public const int STATUS_NEW                                   = 1;
    public const int STATUS_OPENED                                = 2;
    public const int STATUS_PAID                                  = 3;
    public const int STATUS_CANCELED                              = 4;
    public const int STATUS_CONFIRMED                             = 5;
    public const int STATUS_PENDING                               = 6;
    public const int STATUS_SCHEDULED                             = 7;
    public const int STATUS_CREDIT                                = 8;
    public const int STATUS_CHARGEBACK_INIT                       = 9;
    public const int STATUS_CHARGEBACK_ACCEPT                     = 10;
    public const int STATUS_ERROR                                 = 11;
    public const int STATUS_DECLINED                              = 12;
    public const int STATUS_FRAUD                                 = 13;
    public const int STATUS_PENDING_AUTH                          = 14;
    public const int STATUS_3D_AUTH                               = 15;
    public const int STATUS_CHARGEBACK_REPRESENTMENT              = 16;
    public const int STATUS_REVERSED                              = 17;
    public const int STATUS_PENDING_ANY                           = 18;
    public const int STATUS_PROGRAMMED_RECURRENT_PAYMENT          = 19;
    public const int STATUS_CANCELED_PROGRAMMED_RECURRENT_PAYMENT = 20;
    public const int STATUS_TRIAL_PENDING                         = 21;
    public const int STATUS_TRIAL                                 = 22;
    public const int STATUS_EXPIRED                               = 23;

    public string $activeKey = '';

    /** @var array<string> */
    public array $posSignatureSet = [];

    public string $hashMethod = '';
    public string $alg = '';
    public string $publicKeyStr = '';

    /**
     * Verify an IPN callback from NETOPIA.
     *
     * @param string|null $rawPayload Raw POST body. If null, reads from php://input.
     *                                Pass this explicitly to avoid double-reading php://input
     *                                and to make the method testable outside HTTP context.
     * @return array{errorType: int, errorCode: int|null, errorMessage: string}
     */
    public function verifyIPN(?string $rawPayload = null): array
    {
        $outputData = [
            'errorType'    => self::ERROR_TYPE_NONE,
            'errorCode'    => null,
            'errorMessage' => '',
        ];

        try {
            $verificationToken = $this->extractVerificationToken();
            $jwtParts          = $this->parseJwtStructure($verificationToken);
            $publicKey         = $this->loadPublicKey();
            $objJwt            = $this->decodeAndVerifyJwt($verificationToken, $publicKey, $jwtParts['headerAlg']);
            $this->validateJwtClaims($objJwt);

            $rawPayload ??= $this->readStdin();
            $this->verifyPayloadIntegrity($rawPayload, $objJwt);

            $ipnData = $this->decodeIpnPayload($rawPayload);
            $this->processPaymentStatus($ipnData);
        } catch (\Throwable $e) {
            $outputData['errorType']    = self::ERROR_TYPE_PERMANENT;
            $outputData['errorCode']    = ($e->getCode() !== 0) ? $e->getCode() : self::E_VERIFICATION_FAILED_GENERAL;
            $outputData['errorMessage'] = $e->getMessage();
        }

        return $outputData;
    }

    /**
     * Read and validate the raw IPN body from php://input.
     */
    private function readStdin(): string
    {
        $raw = file_get_contents('php://input');
        return $raw === false ? '' : $raw;
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

        if (strlen($token) > self::MAX_JWT_TOKEN_SIZE) {
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

        if (!json_validate($headerJson)) {
            throw new VerificationFailedException('Invalid JWT header JSON');
        }

        /** @var object $jwtHeader */
        $jwtHeader = json_decode($headerJson, false, flags: JSON_THROW_ON_ERROR);

        if (!isset($jwtHeader->typ) || $jwtHeader->typ !== 'JWT') {
            throw new VerificationFailedException('Invalid JWT type');
        }

        $headerAlg = $jwtHeader->alg ?? $this->alg;

        return ['headerAlg' => (string) $headerAlg];
    }

    /**
     * Load and validate the public key for signature verification.
     *
     * @throws KeyLoadException If the public key is missing or invalid
     */
    private function loadPublicKey(): \OpenSSLAsymmetricKey
    {
        if ($this->publicKeyStr === '') {
            throw new KeyLoadException('Public key is not configured');
        }

        $publicKey = openssl_pkey_get_public($this->publicKeyStr);
        if ($publicKey === false) {
            throw new KeyLoadException('Invalid public key format');
        }

        return $publicKey;
    }

    /**
     * Decode and verify JWT using firebase/php-jwt.
     *
     * @throws VerificationFailedException If JWT decoding or verification fails
     */
    private function decodeAndVerifyJwt(string $token, \OpenSSLAsymmetricKey $publicKey, string $algorithm): object
    {
        if ($this->alg === '') {
            throw new VerificationFailedException('JWT algorithm is not configured');
        }

        $rawAlg = $algorithm !== '' ? $algorithm : $this->alg;

        try {
            $jwtAlgorithm = JwtAlgorithm::from($rawAlg);
        } catch (\ValueError) {
            throw new VerificationFailedException('JWT algorithm not allowed: ' . $rawAlg);
        }

        JWT::$timestamp = time() * 1000;

        try {
            return JWT::decode($token, new Key($publicKey, $jwtAlgorithm->value));
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
        if (!isset($objJwt->iss) || !hash_equals('NETOPIA Payments', (string) $objJwt->iss)) {
            throw new VerificationFailedException('JWT issuer verification failed');
        }

        if (empty($objJwt->aud)) {
            throw new VerificationFailedException('JWT audience is empty');
        }

        $actualAud = is_array($objJwt->aud)
            ? ($objJwt->aud[0] ?? '')
            : (string) $objJwt->aud;

        if (!hash_equals($this->activeKey, (string) $actualAud)) {
            throw new VerificationFailedException('JWT audience does not match active key');
        }

        if (!in_array($actualAud, $this->posSignatureSet, true)) {
            throw new VerificationFailedException('JWT audience not found in signature set');
        }

        if ($this->hashMethod === '') {
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
        if (!json_validate($payload)) {
            throw new VerificationFailedException('Invalid IPN payload JSON');
        }

        $ipnData = json_decode($payload, false, flags: JSON_THROW_ON_ERROR);

        if (!is_object($ipnData)) {
            throw new VerificationFailedException('IPN payload must be a JSON object');
        }

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
     * Helper: extract PaymentStatus enum from a decoded IPN payload.
     *
     * Returns null if the status is missing or unknown.
     */
    public static function extractStatus(object $ipnData): ?PaymentStatus
    {
        $status = $ipnData->payment->status ?? null;
        if (!is_int($status) && !(is_string($status) && ctype_digit($status))) {
            return null;
        }
        return PaymentStatus::tryFrom((int) $status);
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
