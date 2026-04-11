<?php

declare(strict_types=1);

namespace Netopia\Payment2\Tests\CsCart;

use Netopia\CsCart\Status\StatusMapper;
use PHPUnit\Framework\TestCase;

final class StatusMapperTest extends TestCase
{
    public function testMapsSuccessStatusesToProcessed(): void
    {
        $mapper = new StatusMapper();
        $this->assertSame('P', $mapper->map(3));  // Paid
        $this->assertSame('P', $mapper->map(5));  // Confirmed
    }

    public function testMapsFailedStatusesToFailed(): void
    {
        $mapper = new StatusMapper();
        $this->assertSame('F', $mapper->map(11)); // Error
        $this->assertSame('F', $mapper->map(12)); // Declined
        $this->assertSame('F', $mapper->map(13)); // Fraud
        $this->assertSame('F', $mapper->map(23)); // Expired
    }

    public function testMapsCancelledStatusesToInactive(): void
    {
        $mapper = new StatusMapper();
        $this->assertSame('I', $mapper->map(4));  // Canceled
        $this->assertSame('I', $mapper->map(17)); // Reversed
    }

    public function testMapsPendingStatusesToOpen(): void
    {
        $mapper = new StatusMapper();
        $this->assertSame('O', $mapper->map(1));  // New
        $this->assertSame('O', $mapper->map(6));  // Pending
        $this->assertSame('O', $mapper->map(15)); // 3DS
    }

    public function testUnknownStatusDefaultsToOpen(): void
    {
        $mapper = new StatusMapper();
        $this->assertSame('O', $mapper->map(999));
    }

    public function testProcessorParamsOverrideDefault(): void
    {
        $mapper = new StatusMapper();
        $this->assertSame('X', $mapper->map(3, ['status_map_3' => 'X']));
    }

    public function testDefinitionsCoverAllStatuses(): void
    {
        $mapper      = new StatusMapper();
        $definitions = $mapper->definitions();

        $this->assertArrayHasKey(3, $definitions);
        $this->assertSame('P', $definitions[3]['default']);
        $this->assertSame('success', $definitions[3]['group']);
        $this->assertArrayHasKey(11, $definitions);
        $this->assertSame('fail', $definitions[11]['group']);
    }
}
