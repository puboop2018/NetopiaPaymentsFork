<?php

declare(strict_types=1);

namespace Netopia\Payment2;

class Request extends Start
{
    public string $authenticationToken = '';
    public string $ntpID = '';
    public string $jsonRequest = '';

    /**
     * Build config section of the payment request.
     *
     * @param array<string, mixed> $configData
     * @return array<string, string>
     */
    public function setConfig(array $configData): array
    {
        return [
            'emailTemplate' => (string) ($configData['emailTemplate'] ?? 'confirm'),
            'notifyUrl'     => (string) ($configData['notifyUrl'] ?? ''),
            'redirectUrl'   => (string) ($configData['redirectUrl'] ?? ''),
            'language'      => (string) ($configData['language'] ?? 'RO'),
        ];
    }

    /**
     * Build payment section with card instrument data.
     *
     * @param array<string, mixed> $cardData
     * @param string               $threeDSecureData JSON-encoded 3DS browser data
     * @return array<string, mixed>
     */
    public function setPayment(array $cardData, string $threeDSecureData, string $clientIp = ''): array
    {
        $decoded = json_decode($threeDSecureData);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $decoded = new \stdClass();
        }
        $decoded->IP_ADDRESS = $clientIp !== '' ? $clientIp : '127.0.0.1';

        return [
            'options' => [
                'installments' => 1,
                'bonus'        => 0,
            ],
            'instrument' => [
                'type'       => 'card',
                'account'    => (string) ($cardData['account'] ?? ''),
                'expMonth'   => (int) ($cardData['expMonth'] ?? 0),
                'expYear'    => (int) ($cardData['expYear'] ?? 0),
                'secretCode' => (string) ($cardData['secretCode'] ?? ''),
                'token'      => null,
            ],
            'data' => $decoded,
        ];
    }

    /**
     * Build payment section with empty instrument fields for hosted payment page flow.
     * This triggers error code 101, returning a paymentURL for the customer.
     *
     * @param mixed $threeDSecureData JSON string or object of 3DS browser data
     * @return array<string, mixed>
     */
    public function setPaymentForLink($threeDSecureData = null, string $clientIp = ''): array
    {
        if (is_string($threeDSecureData)) {
            $threeDSecureData = json_decode($threeDSecureData);
        }
        if (is_object($threeDSecureData)) {
            $threeDSecureData->IP_ADDRESS = $clientIp !== '' ? $clientIp : '127.0.0.1';
        }

        return [
            'options' => [
                'installments' => 1,
                'bonus'        => 0,
            ],
            'instrument' => [
                'type'       => 'card',
                'account'    => '',
                'expMonth'   => 0,
                'expYear'    => 0,
                'secretCode' => '',
                'token'      => null,
            ],
            'data' => $threeDSecureData,
        ];
    }

    /**
     * Build a payment link request (hosted payment page).
     *
     * @param array<string, mixed> $configData
     * @param object               $orderData
     * @param mixed                $threeDSecureData
     */
    public function setPaymentLinkRequest(array $configData, object $orderData, $threeDSecureData = null, string $clientIp = ''): string
    {
        $startArr = [
            'config'  => $this->setConfig($configData),
            'payment' => $this->setPaymentForLink($threeDSecureData, $clientIp),
            'order'   => $this->setOrder($orderData),
        ];

        return (string) json_encode($startArr);
    }

    /**
     * Build the order section of the payment request.
     *
     * @param object $orderData Order data object with billing/shipping properties
     * @return array<string, mixed>
     */
    public function setOrder(object $orderData): array
    {
        return [
            'ntpID'        => '',
            'posSignature' => (string) $this->posSignature,
            'dateTime'     => date('c'),
            'description'  => (string) ($orderData->description ?? ''),
            'orderID'      => (string) ($orderData->orderID ?? ''),
            'amount'       => (float) ($orderData->amount ?? 0),
            'currency'     => (string) ($orderData->currency ?? ''),
            'billing'      => [
                'email'      => (string) ($orderData->billing->email ?? ''),
                'phone'      => (string) ($orderData->billing->phone ?? ''),
                'firstName'  => (string) ($orderData->billing->firstName ?? ''),
                'lastName'   => (string) ($orderData->billing->lastName ?? ''),
                'city'       => (string) ($orderData->billing->city ?? ''),
                'country'    => (int) ($orderData->billing->country ?? 0),
                'state'      => (string) ($orderData->billing->state ?? ''),
                'postalCode' => (string) ($orderData->billing->postalCode ?? ''),
                'details'    => (string) ($orderData->billing->details ?? ''),
            ],
            'shipping' => [
                'email'      => (string) ($orderData->shipping->email ?? ''),
                'phone'      => (string) ($orderData->shipping->phone ?? ''),
                'firstName'  => (string) ($orderData->shipping->firstName ?? ''),
                'lastName'   => (string) ($orderData->shipping->lastName ?? ''),
                'city'       => (string) ($orderData->shipping->city ?? ''),
                'country'    => (int) ($orderData->shipping->country ?? 0),
                'state'      => (string) ($orderData->shipping->state ?? ''),
                'postalCode' => (string) ($orderData->shipping->postalCode ?? ''),
                'details'    => (string) ($orderData->shipping->details ?? ''),
            ],
            'products'     => $orderData->products ?? [],
            'installments' => [
                'selected'  => 1,
                'available' => [0],
            ],
            'data' => null,
        ];
    }

    /**
     * Build the full payment start request.
     *
     * @param array<string, mixed> $configData
     * @param array<string, mixed> $cardData
     * @param object               $orderData
     * @param string|null          $threeDSecureData JSON-encoded 3DS data
     * @return string JSON-encoded request body
     */
    public function setRequest(array $configData, array $cardData, object $orderData, ?string $threeDSecureData = null, string $clientIp = ''): string
    {
        $startArr = [
            'config'  => $this->setConfig($configData),
            'payment' => $this->setPayment($cardData, $threeDSecureData ?? '{}', $clientIp),
            'order'   => $this->setOrder($orderData),
        ];

        return (string) json_encode($startArr);
    }

    /**
     * Execute the payment start request.
     */
    public function startPayment(): string
    {
        return $this->sendRequest($this->jsonRequest);
    }
}
