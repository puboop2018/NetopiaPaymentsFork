<?php

declare(strict_types=1);

namespace Netopia\Payment2\Tests;

use Netopia\Payment2\Exception\InvalidParameterException;
use Netopia\Payment2\Status;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase
{
    public function testValidateParamThrowsOnEmptyApiKey(): void
    {
        $status = new Status();
        $status->posSignature = 'test';
        $status->ntpID = 'test';
        $status->orderID = 'test';

        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('apiKey is required');
        $status->validateParam();
    }

    public function testValidateParamThrowsOnEmptyPosSignature(): void
    {
        $status = new Status();
        $status->apiKey = 'test-key';
        $status->ntpID = 'test';
        $status->orderID = 'test';

        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('posSignature is required');
        $status->validateParam();
    }

    public function testValidateParamPassesWithAllFields(): void
    {
        $status = new Status();
        $status->apiKey = 'test-key';
        $status->posSignature = 'test-sig';
        $status->ntpID = 'ntp-123';
        $status->orderID = 'order-456';

        // Should not throw
        $status->validateParam();
        $this->assertTrue(true);
    }

    public function testSetStatusReturnsValidJson(): void
    {
        $status = new Status();
        $status->posSignature = 'POS-SIG';
        $status->ntpID = 'NTP-123';
        $status->orderID = 'ORD-456';

        $json = $status->setStatus();
        $decoded = json_decode($json, true);

        $this->assertSame('POS-SIG', $decoded['posID']);
        $this->assertSame('NTP-123', $decoded['ntpID']);
        $this->assertSame('ORD-456', $decoded['orderID']);
    }
}
