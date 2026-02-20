<?php

declare(strict_types=1);

namespace Netopia\Payment2;

use Netopia\Payment2\Exception\InvalidApiKeyException;

class Start extends BaseHttpClient
{
    public string $posSignature = '';
    public string $notifyUrl = '';
    public string $redirectUrl = '';
    public string $apiKey = '';
    public bool $isLive = false;
    public string $backUrl = '';

    /**
     * Send a payment start request to NETOPIA.
     *
     * @throws InvalidApiKeyException If API key is not set
     */
    protected function sendRequest(string $jsonStr): string
    {
        if (empty($this->apiKey)) {
            throw new InvalidApiKeyException('API key is required for payment requests.');
        }

        return $this->sendHttpRequest('payment/card/start', $jsonStr, 'POST');
    }
}
