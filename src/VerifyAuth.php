<?php

declare(strict_types=1);

namespace Netopia\Payment2;

use Netopia\Payment2\Exception\InvalidParameterException;

class VerifyAuth extends Request
{
    /** @var array<string, mixed> */
    public array $postData = [];

    /**
     * Build the verify-auth request payload.
     *
     * @throws InvalidParameterException If required fields are missing
     */
    public function setVerifyAuth(): string
    {
        if (empty($this->authenticationToken)) {
            throw new InvalidParameterException('authenticationToken is required for verify-auth.');
        }
        if (empty($this->ntpID)) {
            throw new InvalidParameterException('ntpID is required for verify-auth.');
        }

        $payload = [
            'authenticationToken' => $this->authenticationToken,
            'ntpID'               => $this->ntpID,
            'formData'            => $this->postData,
        ];

        $json = json_encode($payload);
        if ($json === false) {
            throw new InvalidParameterException('Failed to encode verify-auth payload as JSON.');
        }

        return $json;
    }

    /**
     * Send the verify-auth request to NETOPIA.
     */
    public function sendRequestVerifyAuth(string $jsonStr): string
    {
        return $this->sendHttpRequest('payment/card/verify-auth', $jsonStr, 'POST');
    }
}
