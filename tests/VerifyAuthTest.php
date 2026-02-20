<?php

declare(strict_types=1);

namespace Netopia\Payment2\Tests;

use Netopia\Payment2\Exception\InvalidParameterException;
use Netopia\Payment2\VerifyAuth;
use PHPUnit\Framework\TestCase;

class VerifyAuthTest extends TestCase
{
    public function testSetVerifyAuthThrowsOnEmptyToken(): void
    {
        $verifyAuth = new VerifyAuth();
        $verifyAuth->ntpID = 'ntp-123';

        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('authenticationToken is required');
        $verifyAuth->setVerifyAuth();
    }

    public function testSetVerifyAuthThrowsOnEmptyNtpId(): void
    {
        $verifyAuth = new VerifyAuth();
        $verifyAuth->authenticationToken = 'token-123';

        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('ntpID is required');
        $verifyAuth->setVerifyAuth();
    }

    public function testSetVerifyAuthReturnsValidJson(): void
    {
        $verifyAuth = new VerifyAuth();
        $verifyAuth->authenticationToken = 'token-abc';
        $verifyAuth->ntpID = 'ntp-123';
        $verifyAuth->postData = ['paRes' => 'response-data'];

        $json = $verifyAuth->setVerifyAuth();
        $decoded = json_decode($json, true);

        $this->assertSame('token-abc', $decoded['authenticationToken']);
        $this->assertSame('ntp-123', $decoded['ntpID']);
        $this->assertSame(['paRes' => 'response-data'], $decoded['formData']);
    }
}
