<?php

declare(strict_types=1);

namespace Netopia\CsCart\Dto;

/**
 * Immutable card instrument data. Kept strictly in-memory — never logged.
 */
final readonly class CardData
{
    public function __construct(
        public string $account,
        public int $expMonth,
        public int $expYear,
        public string $secretCode,
    ) {
    }

    /**
     * Create an "empty" card for hosted payment page flow.
     */
    public static function empty(): self
    {
        return new self('', 0, 0, '');
    }

    /**
     * @return array{
     *     type: string,
     *     account: string,
     *     expMonth: int,
     *     expYear: int,
     *     secretCode: string,
     *     token: null
     * }
     */
    public function toInstrument(): array
    {
        return [
            'type'       => 'card',
            'account'    => $this->account,
            'expMonth'   => $this->expMonth,
            'expYear'    => $this->expYear,
            'secretCode' => $this->secretCode,
            'token'      => null,
        ];
    }

    /**
     * Returns a redacted copy of the instrument safe to include in logs.
     *
     * @return array{type: string, account: string, expMonth: string, expYear: string, secretCode: string, token: null}
     */
    public function toRedactedLog(): array
    {
        return [
            'type'       => 'card',
            'account'    => $this->account === '' ? '' : '****' . substr($this->account, -4),
            'expMonth'   => '**',
            'expYear'    => '****',
            'secretCode' => '***',
            'token'      => null,
        ];
    }
}
