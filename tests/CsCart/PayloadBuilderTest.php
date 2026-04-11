<?php

declare(strict_types=1);

namespace Netopia\Payment2\Tests\CsCart;

use Netopia\CsCart\Dto\CardData;
use Netopia\CsCart\Dto\ThreeDsData;
use Netopia\CsCart\Payment\PayloadBuilder;
use Netopia\CsCart\Support\ClockInterface;
use PHPUnit\Framework\TestCase;

final class PayloadBuilderTest extends TestCase
{
    private function clock(): ClockInterface
    {
        return new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-04-11T12:00:00+00:00');
            }
        };
    }

    private function threeDs(): ThreeDsData
    {
        return new ThreeDsData(
            browserUserAgent:    'Mozilla/5.0',
            os:                  'Linux',
            osVersion:           '6.0',
            mobile:              'false',
            screenPoint:         'false',
            screenPrint:         '1920x1080',
            browserColorDepth:   '24',
            browserScreenHeight: '1080',
            browserScreenWidth:  '1920',
            browserPlugins:      '',
            browserJavaEnabled:  'false',
            browserLanguage:     'en-US',
            browserTz:           'Europe/Bucharest',
            browserTzOffset:     '0',
            ipAddress:           '127.0.0.1',
        );
    }

    public function testBuildStartRequestHostedPage(): void
    {
        $builder = new PayloadBuilder(
            clock:           $this->clock(),
            notifyUrl:       'https://example.com/notify',
            redirectUrl:     'https://example.com/return',
            primaryCurrency: 'RON',
        );

        $json = $builder->buildStartRequest(
            processorParams: ['pos_signature' => 'SIG-1', 'api_key' => 'KEY'],
            orderInfo:       [
                'order_id'    => 42,
                'total'       => 199.99,
                'email'       => 'test@example.com',
                'b_firstname' => 'Ion',
                'b_lastname'  => 'Popescu',
                'b_country'   => 'RO',
            ],
            threeDs:      $this->threeDs(),
            installments: 1,
            card:         null,
        );

        $decoded = json_decode($json, true);
        $this->assertSame('https://example.com/notify', $decoded['config']['notifyUrl']);
        $this->assertSame('SIG-1', $decoded['order']['posSignature']);
        $this->assertSame('42', $decoded['order']['orderID']);
        $this->assertSame(199.99, $decoded['order']['amount']);
        $this->assertSame('', $decoded['payment']['instrument']['account']);
        $this->assertSame(642, $decoded['order']['billing']['country']);
    }

    public function testBuildStartRequestWithCard(): void
    {
        $builder = new PayloadBuilder(
            clock:           $this->clock(),
            notifyUrl:       'https://example.com/notify',
            redirectUrl:     'https://example.com/return',
            primaryCurrency: 'RON',
        );

        $json = $builder->buildStartRequest(
            processorParams: ['pos_signature' => 'SIG-1'],
            orderInfo:       ['order_id' => 7, 'total' => 10],
            threeDs:         $this->threeDs(),
            installments:    2,
            card:            new CardData('4111111111111111', 12, 2030, '123'),
        );

        $decoded = json_decode($json, true);
        $this->assertSame('4111111111111111', $decoded['payment']['instrument']['account']);
        $this->assertSame(12, $decoded['payment']['instrument']['expMonth']);
        $this->assertSame(2030, $decoded['payment']['instrument']['expYear']);
        $this->assertSame(2, $decoded['payment']['options']['installments']);
        $this->assertSame([0, 2], $decoded['order']['installments']['available']);
    }

    public function testBuildVerifyAuthRequest(): void
    {
        $builder = new PayloadBuilder(
            clock:           $this->clock(),
            notifyUrl:       '',
            redirectUrl:     '',
            primaryCurrency: 'RON',
        );

        $json    = $builder->buildVerifyAuthRequest('auth-token', 'NTP-1', 'pa-res-value');
        $decoded = json_decode($json, true);

        $this->assertSame('auth-token', $decoded['authenticationToken']);
        $this->assertSame('NTP-1', $decoded['ntpID']);
        $this->assertSame('pa-res-value', $decoded['formData']['paRes']);
    }
}
