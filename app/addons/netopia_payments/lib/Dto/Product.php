<?php

declare(strict_types=1);

namespace Netopia\CsCart\Dto;

/**
 * Immutable product line item for a NETOPIA payment request.
 */
final readonly class Product
{
    public function __construct(
        public string $name,
        public string $code,
        public string $category,
        public float $price,
        public int $vat,
    ) {
    }

    /**
     * @return array{name: string, code: string, category: string, price: float, vat: int}
     */
    public function toArray(): array
    {
        return [
            'name'     => $this->name,
            'code'     => $this->code,
            'category' => $this->category,
            'price'    => $this->price,
            'vat'      => $this->vat,
        ];
    }
}
