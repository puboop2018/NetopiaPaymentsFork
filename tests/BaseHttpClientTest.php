<?php

declare(strict_types=1);

namespace Netopia\Payment2\Tests;

use Netopia\Payment2\BaseHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the BaseHttpClient class.
 *
 * Uses a TestableHttpClient subclass to access protected methods.
 */
class BaseHttpClientTest extends TestCase
{
    public function testHandleResponseReturnsSuccessForHttp200(): void
    {
        $client = new TestableHttpClient();

        $result = $client->publicHandleResponse(200, '{"status":"ok"}');

        $this->assertSame(1, $result['status']);
        $this->assertSame(200, $result['code']);
        $this->assertSame('Request successful', $result['message']);
        $this->assertNotNull($result['data']);
    }

    public function testHandleResponseReturnsErrorForHttp400(): void
    {
        $client = new TestableHttpClient();

        $result = $client->publicHandleResponse(400, '{"error":"bad"}');

        $this->assertSame(0, $result['status']);
        $this->assertSame(400, $result['code']);
        $this->assertSame('Bad Request', $result['message']);
    }

    public function testHandleResponseReturnsErrorForHttp401(): void
    {
        $client = new TestableHttpClient();

        $result = $client->publicHandleResponse(401, '{}');

        $this->assertSame(0, $result['status']);
        $this->assertSame('Authorization required', $result['message']);
    }

    public function testHandleResponseHandlesInvalidJson(): void
    {
        $client = new TestableHttpClient();

        $result = $client->publicHandleResponse(200, 'not-json');

        $this->assertSame(1, $result['status']);
        $this->assertNull($result['data']);
    }

    public function testHandleResponseHandlesUnknownHttpCode(): void
    {
        $client = new TestableHttpClient();

        $result = $client->publicHandleResponse(500, '{}');

        $this->assertSame(0, $result['status']);
        $this->assertSame('Unexpected error occurred', $result['message']);
    }
}

/**
 * Testable subclass that exposes protected methods for testing.
 */
class TestableHttpClient extends BaseHttpClient
{
    /**
     * @return array{status: int, code: int, message: string, data: mixed}
     */
    public function publicHandleResponse(int $httpCode, string $result): array
    {
        return $this->handleResponse($httpCode, $result);
    }
}
