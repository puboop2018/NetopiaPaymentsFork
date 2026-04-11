<?php

declare(strict_types=1);

namespace Netopia\CsCart\Dto;

/**
 * Immutable billing or shipping address in NETOPIA API format.
 */
final readonly class Address
{
    public function __construct(
        public string $email,
        public string $phone,
        public string $firstName,
        public string $lastName,
        public string $city,
        public int $country,
        public string $state,
        public string $postalCode,
        public string $details,
    ) {
    }

    /**
     * Convert to the associative array shape expected by NETOPIA API.
     *
     * @return array{
     *     email: string,
     *     phone: string,
     *     firstName: string,
     *     lastName: string,
     *     city: string,
     *     country: int,
     *     state: string,
     *     postalCode: string,
     *     details: string
     * }
     */
    public function toArray(): array
    {
        return [
            'email'      => $this->email,
            'phone'      => $this->phone,
            'firstName'  => $this->firstName,
            'lastName'   => $this->lastName,
            'city'       => $this->city,
            'country'    => $this->country,
            'state'      => $this->state,
            'postalCode' => $this->postalCode,
            'details'    => $this->details,
        ];
    }
}
