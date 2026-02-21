<?php

declare(strict_types=1);

namespace Netopia\Payment2\Tests;

use Netopia\Payment2\Request;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Request class.
 */
class RequestTest extends TestCase
{
    public function testSetConfigReturnsExpectedStructure(): void
    {
        $request = new Request();

        $result = $request->setConfig([
            'emailTemplate' => 'confirm',
            'notifyUrl'     => 'https://example.com/ipn',
            'redirectUrl'   => 'https://example.com/return',
            'language'      => 'EN',
        ]);

        $this->assertSame('confirm', $result['emailTemplate']);
        $this->assertSame('https://example.com/ipn', $result['notifyUrl']);
        $this->assertSame('https://example.com/return', $result['redirectUrl']);
        $this->assertSame('EN', $result['language']);
    }

    public function testSetConfigUsesDefaults(): void
    {
        $request = new Request();

        $result = $request->setConfig([]);

        $this->assertSame('confirm', $result['emailTemplate']);
        $this->assertSame('', $result['notifyUrl']);
        $this->assertSame('', $result['redirectUrl']);
        $this->assertSame('RO', $result['language']);
    }

    public function testSetPaymentUsesExplicitIpAddress(): void
    {
        $request = new Request();

        $cardData = [
            'account'    => '4111111111111111',
            'expMonth'   => 12,
            'expYear'    => 2027,
            'secretCode' => '123',
        ];

        $threeDsData = json_encode(['BROWSER_USER_AGENT' => 'Test']);
        $result = $request->setPayment($cardData, $threeDsData, '192.168.1.1');

        $this->assertSame('192.168.1.1', $result['data']->IP_ADDRESS);
        $this->assertSame('card', $result['instrument']['type']);
        $this->assertSame('4111111111111111', $result['instrument']['account']);
        $this->assertSame(12, $result['instrument']['expMonth']);
        $this->assertSame(2027, $result['instrument']['expYear']);
    }

    public function testSetPaymentDefaultsIpWhenEmpty(): void
    {
        $request = new Request();

        $result = $request->setPayment([], '{}');

        $this->assertSame('127.0.0.1', $result['data']->IP_ADDRESS);
    }

    public function testSetPaymentHandlesInvalid3dsJson(): void
    {
        $request = new Request();

        $result = $request->setPayment([], 'not-json', '10.0.0.1');

        $this->assertSame('10.0.0.1', $result['data']->IP_ADDRESS);
        $this->assertSame('card', $result['instrument']['type']);
    }

    public function testSetPaymentForLinkUsesExplicitIp(): void
    {
        $request = new Request();

        $result = $request->setPaymentForLink('{"test": true}', '10.20.30.40');

        $this->assertSame('10.20.30.40', $result['data']->IP_ADDRESS);
        $this->assertSame('', $result['instrument']['account']);
        $this->assertSame(0, $result['instrument']['expMonth']);
    }

    public function testSetPaymentForLinkDefaultsIp(): void
    {
        $request = new Request();

        $result = $request->setPaymentForLink('{"test": true}');

        $this->assertSame('127.0.0.1', $result['data']->IP_ADDRESS);
    }

    public function testSetPaymentForLinkHandlesNullInput(): void
    {
        $request = new Request();

        $result = $request->setPaymentForLink(null, '10.0.0.1');

        $this->assertIsObject($result['data']);
        $this->assertSame('10.0.0.1', $result['data']->IP_ADDRESS);
    }

    public function testSetPaymentForLinkHandlesInvalidJson(): void
    {
        $request = new Request();

        $result = $request->setPaymentForLink('not-valid-json', '10.0.0.1');

        $this->assertIsObject($result['data']);
        $this->assertSame('10.0.0.1', $result['data']->IP_ADDRESS);
    }

    public function testSetOrderReturnsExpectedStructure(): void
    {
        $request = new Request();
        $request->posSignature = 'TEST-POS';

        $orderData = (object) [
            'description' => 'Test order',
            'orderID'     => 'ORD-123',
            'amount'      => 99.50,
            'currency'    => 'RON',
            'billing'     => (object) [
                'email'      => 'test@example.com',
                'phone'      => '0712345678',
                'firstName'  => 'John',
                'lastName'   => 'Doe',
                'city'       => 'Bucharest',
                'country'    => 642,
                'state'      => 'Bucharest',
                'postalCode' => '010101',
                'details'    => 'Str. Test 1',
            ],
            'shipping'    => (object) [
                'email'  => 'test@example.com',
                'phone'  => '',
                'firstName' => 'John',
                'lastName'  => 'Doe',
                'city'      => 'Bucharest',
                'country'   => 642,
                'state'     => 'Bucharest',
                'postalCode' => '010101',
                'details'   => 'Str. Test 1',
            ],
            'products' => [],
        ];

        $result = $request->setOrder($orderData);

        $this->assertSame('TEST-POS', $result['posSignature']);
        $this->assertSame('Test order', $result['description']);
        $this->assertSame('ORD-123', $result['orderID']);
        $this->assertSame(99.50, $result['amount']);
        $this->assertSame('test@example.com', $result['billing']['email']);
        $this->assertSame('John', $result['billing']['firstName']);
    }

    public function testSetRequestReturnsValidJson(): void
    {
        $request = new Request();
        $request->posSignature = 'TEST-POS';

        $configData = [
            'notifyUrl'   => 'https://example.com/ipn',
            'redirectUrl' => 'https://example.com/return',
        ];

        $cardData = [
            'account'    => '4111111111111111',
            'expMonth'   => 12,
            'expYear'    => 2027,
            'secretCode' => '123',
        ];

        $orderData = (object) [
            'description' => 'Order #1',
            'orderID'     => '1',
            'amount'      => 100.0,
            'currency'    => 'RON',
            'billing'     => (object) ['email' => 'test@test.com'],
            'shipping'    => (object) ['email' => 'test@test.com'],
            'products'    => [],
        ];

        $json = $request->setRequest($configData, $cardData, $orderData, '{}', '1.2.3.4');
        $decoded = json_decode($json, true);

        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('config', $decoded);
        $this->assertArrayHasKey('payment', $decoded);
        $this->assertArrayHasKey('order', $decoded);
        $this->assertSame('1.2.3.4', $decoded['payment']['data']['IP_ADDRESS']);
    }

    public function testSetPaymentLinkRequestReturnsValidJson(): void
    {
        $request = new Request();
        $request->posSignature = 'TEST-POS';

        $configData = [
            'notifyUrl'   => 'https://example.com/ipn',
            'redirectUrl' => 'https://example.com/return',
        ];

        $orderData = (object) [
            'description' => 'Order #2',
            'orderID'     => '2',
            'amount'      => 50.0,
            'currency'    => 'RON',
            'billing'     => (object) ['email' => 'test@test.com'],
            'shipping'    => (object) ['email' => 'test@test.com'],
            'products'    => [],
        ];

        $json = $request->setPaymentLinkRequest($configData, $orderData, null, '5.6.7.8');
        $decoded = json_decode($json, true);

        $this->assertNotNull($decoded);
        $this->assertSame('', $decoded['payment']['instrument']['account']);
        $this->assertSame(0, $decoded['payment']['instrument']['expMonth']);
    }
}
