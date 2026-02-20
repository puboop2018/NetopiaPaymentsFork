<?php

declare(strict_types=1);

namespace Netopia\Payment2;

use Netopia\Payment2\Exception\InvalidApiKeyException;
use Netopia\Payment2\Exception\InvalidParameterException;

class Status extends Start
{
    public string $ntpID = '';
    public string $orderID = '';

    /**
     * Validate that all required status query parameters are set.
     *
     * @throws InvalidParameterException If any required parameter is missing
     */
    public function validateParam(): void
    {
        $required = [
            'apiKey'       => $this->apiKey,
            'posSignature' => $this->posSignature,
            'ntpID'        => $this->ntpID,
            'orderID'      => $this->orderID,
        ];

        foreach ($required as $name => $value) {
            if (empty($value)) {
                throw new InvalidParameterException($name . ' is required for status query.');
            }
        }
    }

    /**
     * Build the status query request payload.
     */
    public function setStatus(): string
    {
        $payload = [
            'posID'   => $this->posSignature,
            'ntpID'   => $this->ntpID,
            'orderID' => $this->orderID,
        ];

        return (string) json_encode($payload);
    }

    /**
     * Send the status query request to NETOPIA.
     *
     * @throws InvalidApiKeyException If API key is not set
     */
    public function getStatus(string $jsonStr): string
    {
        if (empty($this->apiKey)) {
            throw new InvalidApiKeyException('API key is required for status queries.');
        }

        return $this->sendHttpRequest('operation/status', $jsonStr, 'POST');
    }
}
