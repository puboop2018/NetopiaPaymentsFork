<?php

declare(strict_types=1);

namespace Netopia\CsCart\Dto;

/**
 * Immutable normalized NETOPIA API response.
 */
final readonly class ApiResponse
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public int $status,
        public int $code,
        public string $message,
        public ?array $data,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === 1 && $this->data !== null;
    }

    public static function failure(string $message, int $httpCode = 0): self
    {
        return new self(0, $httpCode, $message, null);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromHttp(int $httpCode, ?array $data): self
    {
        return new self(
            status:  $httpCode === 200 ? 1 : 0,
            code:    $httpCode,
            message: $httpCode === 200 ? 'OK' : ('HTTP ' . $httpCode),
            data:    $data,
        );
    }

    /**
     * Look up a block inside the response envelope, tolerating both the bare
     * `{key: {...}}` layout and the nested `{data: {key: {...}}}` layout.
     *
     * @return array<string, mixed>
     */
    public function envelopeBlock(string $key): array
    {
        if ($this->data === null) {
            return [];
        }

        $direct = $this->data[$key] ?? null;
        if (is_array($direct)) {
            return $direct;
        }

        $nested = $this->data['data'] ?? null;
        if (is_array($nested) && isset($nested[$key]) && is_array($nested[$key])) {
            return $nested[$key];
        }

        return [];
    }

    /** @return array<string, mixed> */
    public function errorBlock(): array
    {
        return $this->envelopeBlock('error');
    }

    /** @return array<string, mixed> */
    public function paymentData(): array
    {
        return $this->envelopeBlock('payment');
    }

    /** @return array<string, mixed> */
    public function customerAction(): array
    {
        return $this->envelopeBlock('customerAction');
    }
}
