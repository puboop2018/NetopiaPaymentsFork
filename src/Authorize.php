<?php

declare(strict_types=1);

namespace Netopia\Payment2;

use Netopia\Payment2\Exception\InvalidParameterException;

class Authorize extends Start
{
    public string $paReq = '';
    public string $bankUrl = '';

    /**
     * Validate that all required 3D Secure authorization parameters are set.
     *
     * @throws InvalidParameterException If any required parameter is missing
     */
    public function validateParam(): void
    {
        $required = [
            'apiKey'  => $this->apiKey,
            'paReq'   => $this->paReq,
            'backUrl' => $this->backUrl,
            'bankUrl' => $this->bankUrl,
        ];

        foreach ($required as $name => $value) {
            if (empty($value)) {
                throw new InvalidParameterException($name . ' is required for authorization.');
            }
        }
    }
}
