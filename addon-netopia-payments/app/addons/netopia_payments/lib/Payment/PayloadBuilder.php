<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

use Netopia\CsCart\Dto\Address;
use Netopia\CsCart\Dto\CardData;
use Netopia\CsCart\Dto\Product;
use Netopia\CsCart\Dto\ThreeDsData;
use Netopia\CsCart\Support\ClockInterface;
use Netopia\CsCart\Support\CountryCodes;

/**
 * Builds JSON payloads for NETOPIA /payment/card/start and /verify-auth endpoints.
 *
 * @phpstan-type ProcessorParams array{
 *     pos_signature: string,
 *     api_key: string,
 *     mode?: string,
 *     currency?: string,
 *     allow_installments?: string,
 *     max_installments?: int|string,
 *     ...<string, mixed>
 * }
 * @phpstan-type OrderInfo array{
 *     order_id: int|string,
 *     total: float|int|string,
 *     email?: string,
 *     secondary_currency?: string,
 *     b_firstname?: string,
 *     b_lastname?: string,
 *     b_phone?: string,
 *     b_address?: string,
 *     b_address_2?: string,
 *     b_city?: string,
 *     b_state?: string,
 *     b_state_descr?: string,
 *     b_zipcode?: string,
 *     b_country?: string,
 *     s_firstname?: string,
 *     s_lastname?: string,
 *     s_phone?: string,
 *     s_address?: string,
 *     s_address_2?: string,
 *     s_city?: string,
 *     s_state?: string,
 *     s_state_descr?: string,
 *     s_zipcode?: string,
 *     s_country?: string,
 *     phone?: string,
 *     payment_info?: array<string, mixed>,
 *     products?: array<int, array<string, mixed>>
 * }
 */
final class PayloadBuilder
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly string $notifyUrl,
        private readonly string $redirectUrl,
        private readonly string $primaryCurrency,
        private readonly string $language = 'RO',
    ) {
    }

    /**
     * Build a full payment start request.
     *
     * Pass $card = null for hosted-page flow (empty instrument).
     *
     * @param ProcessorParams $processorParams
     * @param OrderInfo       $orderInfo
     */
    public function buildStartRequest(
        array $processorParams,
        array $orderInfo,
        ThreeDsData $threeDs,
        int $installments,
        ?CardData $card,
    ): string {
        [$currency, $amount] = $this->resolveCurrency($processorParams, $orderInfo);

        $payload = [
            'config'  => $this->buildConfig(),
            'payment' => [
                'options'    => [
                    'installments' => max(1, $installments),
                    'bonus'        => 0,
                ],
                'instrument' => ($card ?? CardData::empty())->toInstrument(),
                'data'       => $threeDs->toArray(),
            ],
            'order'   => $this->buildOrderSection($processorParams, $orderInfo, $currency, $amount, $installments),
        ];

        return $this->encode($payload);
    }

    /**
     * @param ProcessorParams $processorParams
     * @param OrderInfo       $orderInfo
     * @return array{string, float} [currency, amount]
     */
    public function resolveCurrency(array $processorParams, array $orderInfo): array
    {
        $currency = 'RON';
        if (!empty($processorParams['currency'])) {
            if ($processorParams['currency'] === 'order_currency') {
                $currency = (string) ($orderInfo['secondary_currency'] ?? $this->primaryCurrency);
            } else {
                $currency = (string) $processorParams['currency'];
            }
        }

        $orderCurrency = (string) ($orderInfo['secondary_currency'] ?? $this->primaryCurrency);
        $amount        = (float) $orderInfo['total'];

        if ($currency !== $orderCurrency && function_exists('fn_format_price_by_currency')) {
            $amount = (float) fn_format_price_by_currency($orderInfo['total'], $this->primaryCurrency, $currency);
        }

        return [$currency, $amount];
    }

    /**
     * @return array{emailTemplate: string, notifyUrl: string, redirectUrl: string, language: string}
     */
    private function buildConfig(): array
    {
        return [
            'emailTemplate' => 'confirm',
            'notifyUrl'     => $this->notifyUrl,
            'redirectUrl'   => $this->redirectUrl,
            'language'      => $this->language,
        ];
    }

    /**
     * @param ProcessorParams $processorParams
     * @param OrderInfo       $orderInfo
     * @return array<string, mixed>
     */
    private function buildOrderSection(
        array $processorParams,
        array $orderInfo,
        string $currency,
        float $amount,
        int $installments,
    ): array {
        $billing  = $this->buildBillingAddress($orderInfo);
        $shipping = $this->buildShippingAddress($orderInfo);

        return [
            'ntpID'        => null,
            'posSignature' => (string) $processorParams['pos_signature'],
            'dateTime'     => $this->clock->now()->format('c'),
            'description'  => 'Order #' . $orderInfo['order_id'],
            'orderID'      => (string) $orderInfo['order_id'],
            'amount'       => $amount,
            'currency'     => $currency,
            'billing'      => $billing->toArray(),
            'shipping'     => $shipping->toArray(),
            'products'     => $this->buildProducts($orderInfo, $amount),
            'installments' => $this->buildInstallments($installments),
            'data'         => null,
        ];
    }

    /**
     * @param OrderInfo $orderInfo
     */
    private function buildBillingAddress(array $orderInfo): Address
    {
        return new Address(
            email:      (string) ($orderInfo['email'] ?? ''),
            phone:      (string) ($orderInfo['b_phone'] ?? $orderInfo['phone'] ?? ''),
            firstName:  (string) ($orderInfo['b_firstname'] ?? ''),
            lastName:   (string) ($orderInfo['b_lastname'] ?? ''),
            city:       (string) ($orderInfo['b_city'] ?? ''),
            country:    CountryCodes::toNumeric((string) ($orderInfo['b_country'] ?? 'RO')),
            state:      (string) ($orderInfo['b_state_descr'] ?? $orderInfo['b_state'] ?? ''),
            postalCode: (string) ($orderInfo['b_zipcode'] ?? ''),
            details:    trim(((string) ($orderInfo['b_address'] ?? '')) . ' ' . ((string) ($orderInfo['b_address_2'] ?? ''))),
        );
    }

    /**
     * @param OrderInfo $orderInfo
     */
    private function buildShippingAddress(array $orderInfo): Address
    {
        return new Address(
            email:      (string) ($orderInfo['email'] ?? ''),
            phone:      (string) ($orderInfo['s_phone'] ?? $orderInfo['phone'] ?? ''),
            firstName:  (string) ($orderInfo['s_firstname'] ?? $orderInfo['b_firstname'] ?? ''),
            lastName:   (string) ($orderInfo['s_lastname'] ?? $orderInfo['b_lastname'] ?? ''),
            city:       (string) ($orderInfo['s_city'] ?? $orderInfo['b_city'] ?? ''),
            country:    CountryCodes::toNumeric((string) ($orderInfo['s_country'] ?? $orderInfo['b_country'] ?? 'RO')),
            state:      (string) ($orderInfo['s_state_descr'] ?? $orderInfo['s_state'] ?? $orderInfo['b_state'] ?? ''),
            postalCode: (string) ($orderInfo['s_zipcode'] ?? $orderInfo['b_zipcode'] ?? ''),
            details:    trim(((string) ($orderInfo['s_address'] ?? $orderInfo['b_address'] ?? '')) . ' ' . ((string) ($orderInfo['s_address_2'] ?? $orderInfo['b_address_2'] ?? ''))),
        );
    }

    /**
     * @param OrderInfo $orderInfo
     * @return list<array{name: string, code: string, category: string, price: float, vat: int}>
     */
    private function buildProducts(array $orderInfo, float $amount): array
    {
        $products = [];

        if (!empty($orderInfo['products'])) {
            foreach ($orderInfo['products'] as $raw) {
                $product = new Product(
                    name:     (string) ($raw['product'] ?? 'Product'),
                    code:     (string) ($raw['product_code'] ?? $raw['product_id'] ?? ''),
                    category: 'General',
                    price:    (float) ($raw['price'] ?? 0),
                    vat:      0,
                );
                $products[] = $product->toArray();
            }
        }

        if ($products === []) {
            $products[] = (new Product(
                name:     'Order #' . $orderInfo['order_id'],
                code:     (string) $orderInfo['order_id'],
                category: 'General',
                price:    $amount,
                vat:      0,
            ))->toArray();
        }

        return $products;
    }

    /**
     * @return array{selected: int, available: list<int>}
     */
    private function buildInstallments(int $installments): array
    {
        $selected  = max(1, $installments);
        $available = [0];
        if ($selected > 1) {
            $available = range(2, $selected);
            array_unshift($available, 0);
        }
        return ['selected' => $selected, 'available' => $available];
    }

    /**
     * Build the JSON payload for NETOPIA /payment/card/verify-auth.
     */
    public function buildVerifyAuthRequest(string $authenticationToken, string $ntpId, string $paRes): string
    {
        return $this->encode([
            'authenticationToken' => $authenticationToken,
            'ntpID'               => $ntpId,
            'formData'            => ['paRes' => $paRes],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
