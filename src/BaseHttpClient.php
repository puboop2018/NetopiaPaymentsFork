<?php

declare(strict_types=1);

namespace Netopia\Payment2;

use Netopia\Payment2\Enum\PaymentMode;
use Netopia\Payment2\Exception\HttpException;
use Netopia\Payment2\Exception\InvalidApiKeyException;

class BaseHttpClient
{
    public const int TIMEOUT_SECONDS = 30;

    /** @var array<int, string> */
    private const array HTTP_MESSAGES = [
        200 => 'Request successful',
        400 => 'Bad Request',
        401 => 'Authorization required',
        404 => 'Endpoint not found',
    ];

    protected string $apiKey = '';
    protected bool $isLive = false;

    protected function getBaseUrl(): string
    {
        return $this->resolveMode()->baseUrl();
    }

    private function resolveMode(): PaymentMode
    {
        return $this->isLive ? PaymentMode::Live : PaymentMode::Sandbox;
    }

    /**
     * Send an HTTP request to the NETOPIA API.
     *
     * @param string $endpoint API endpoint path
     * @param string $payload  JSON-encoded request body
     * @param string $method   HTTP method (default POST)
     * @return string JSON-encoded response
     *
     * @throws InvalidApiKeyException If apiKey is empty
     * @throws HttpException          If the cURL request fails
     */
    protected function sendHttpRequest(string $endpoint, string $payload, string $method = 'POST'): string
    {
        if ($this->apiKey === '') {
            throw new InvalidApiKeyException('API key must not be empty.');
        }

        $url = rtrim($this->getBaseUrl(), '/') . '/' . ltrim($endpoint, '/');

        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException('Failed to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $payload,
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
            return (string) json_encode([
                'status'  => 0,
                'code'    => 0,
                'message' => 'Connection error occurred',
                'data'    => null,
            ], JSON_FORCE_OBJECT);
        }

        $response = $this->handleResponse($httpCode, (string) $result);

        return (string) json_encode($response, JSON_FORCE_OBJECT);
    }

    /**
     * Parse the HTTP response and return a normalized response array.
     *
     * @return array{status: int, code: int, message: string, data: mixed}
     */
    protected function handleResponse(int $httpCode, string $result): array
    {
        $responseData = null;
        if (json_validate($result)) {
            $responseData = json_decode($result, false);
        }

        $message = self::HTTP_MESSAGES[$httpCode] ?? 'Unexpected error occurred';
        $status  = ($httpCode === 200) ? 1 : 0;

        return [
            'status'  => $status,
            'code'    => $httpCode,
            'message' => $message,
            'data'    => $responseData,
        ];
    }
}
