<?php

declare(strict_types=1);

namespace Netopia\Payment2\Tests;

use Netopia\Payment2\Authorize;
use Netopia\Payment2\Exception\InvalidParameterException;
use PHPUnit\Framework\TestCase;

class AuthorizeTest extends TestCase
{
    public function testValidateParamThrowsOnMissingApiKey(): void
    {
        $auth = new Authorize();
        $auth->paReq = 'test';
        $auth->backUrl = 'https://example.com';
        $auth->bankUrl = 'https://bank.example.com';

        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('apiKey is required');
        $auth->validateParam();
    }

    public function testValidateParamThrowsOnMissingBankUrl(): void
    {
        $auth = new Authorize();
        $auth->apiKey = 'key';
        $auth->paReq = 'test';
        $auth->backUrl = 'https://example.com';

        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('bankUrl is required');
        $auth->validateParam();
    }

    public function testValidateParamPassesWithAllFields(): void
    {
        $auth = new Authorize();
        $auth->apiKey = 'key';
        $auth->paReq = 'test';
        $auth->backUrl = 'https://example.com';
        $auth->bankUrl = 'https://bank.example.com';

        $auth->validateParam();
        $this->assertTrue(true);
    }
}
