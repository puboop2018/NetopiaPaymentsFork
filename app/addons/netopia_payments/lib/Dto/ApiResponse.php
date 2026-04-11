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
}
