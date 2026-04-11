<?php

declare(strict_types=1);

namespace Netopia\CsCart\Http;

use Netopia\CsCart\Dto\ApiResponse;
use Netopia\CsCart\Exception\ApiException;
use Netopia\Payment2\Enum\PaymentMode;
use Psr\Log\LoggerInterface;

/**
 * NETOPIA API client for addon-level calls.
 *
 * Uses cURL directly (kept separate from SDK BaseHttpClient so that
 * CS-Cart addon error handling returns normalized ApiResponse objects
 * instead of throwing — CS-Cart hooks expect flow continuation).
 */
final class ApiClient
{
    public const int TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly string $apiKey,
        private readonly PaymentMode $mode,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @throws ApiException on missing API key
     */
    public function post(string $endpoint, string $jsonBody): ApiResponse
    {
        if ($this->apiKey === '') {
            throw new ApiException('API key is not configured.');
        }

        $url = rtrim($this->mode->baseUrl(), '/') . '/' . ltrim($endpoint, '/');

        $ch = curl_init($url);
        if ($ch === false) {
            return ApiResponse::failure('Failed to initialize HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $result   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($result === false || $error !== '') {
            $this->logger?->warning('NETOPIA API connection error', ['endpoint' => $endpoint, 'error' => $error]);
            return ApiResponse::failure('Connection error occurred.');
        }

        $body = (string) $result;
        if (!json_validate($body)) {
            $this->logger?->warning('NETOPIA API returned invalid JSON', ['endpoint' => $endpoint, 'http_code' => $httpCode]);
            return new ApiResponse(0, $httpCode, 'Invalid JSON response from NETOPIA.', null);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        return ApiResponse::fromHttp($httpCode, $data);
    }
}
