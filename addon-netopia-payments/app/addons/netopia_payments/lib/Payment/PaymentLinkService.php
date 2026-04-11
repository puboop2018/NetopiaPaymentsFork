<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

use Netopia\CsCart\Http\ApiClient;
use Netopia\CsCart\Support\Sanitizer;
use Netopia\CsCart\ThreeDs\ThreeDsDataFactory;
use Netopia\Payment2\Enum\ErrorCode;
use Netopia\Payment2\Enum\PaymentMode;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Generates NETOPIA hosted payment links for existing orders.
 *
 * @phpstan-type LinkResult array{success: bool, payment_url: string, error: string}
 */
final class PaymentLinkService
{
    /**
     * @param \Closure(string $apiKey, PaymentMode $mode): ApiClient $apiClientFactory
     * @param \Closure(int $orderId, array<string, mixed> $info): void $paymentInfoUpdater
     * @param \Closure(int $orderId, string $status): void $orderStatusChanger
     */
    public function __construct(
        private readonly PayloadBuilder $payloadBuilder,
        private readonly ThreeDsDataFactory $threeDsFactory,
        private readonly \Closure $apiClientFactory,
        private readonly \Closure $paymentInfoUpdater,
        private readonly \Closure $orderStatusChanger,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Generate a payment link for an already-placed order.
     *
     * @param array<string, mixed> $orderInfo       CS-Cart order data
     * @param array<string, mixed> $processorParams Payment processor params
     * @param array<string, mixed> $server          Typically $_SERVER (for 3DS fingerprint)
     * @return LinkResult
     */
    public function generate(array $orderInfo, array $processorParams, array $server): array
    {
        if (empty($processorParams['pos_signature']) || empty($processorParams['api_key'])) {
            return $this->failure('NETOPIA POS Signature or API Key missing.');
        }

        $orderId = (int) ($orderInfo['order_id'] ?? 0);
        if ($orderId <= 0) {
            return $this->failure('Invalid order ID.');
        }

        $mode         = PaymentMode::fromMixed($processorParams['mode'] ?? null);
        $installments = $this->resolveInstallments($processorParams);
        $threeDs      = $this->threeDsFactory->forServerContext($server);

        try {
            $json      = $this->payloadBuilder->buildStartRequest($processorParams, $orderInfo, $threeDs, $installments, null);
            $apiClient = ($this->apiClientFactory)((string) $processorParams['api_key'], $mode);
            $response  = $apiClient->post('payment/card/start', $json);
        } catch (\Throwable $e) {
            $this->logger->warning('NETOPIA payment link request failed', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            return $this->failure('NETOPIA API error: ' . $e->getMessage());
        }

        if (!$response->isSuccess() || $response->data === null) {
            return $this->failure('NETOPIA API error: ' . $response->message);
        }

        $data       = $response->data;
        $inner      = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $errorBlock = is_array($inner['error'] ?? null) ? $inner['error'] : [];
        $payment    = is_array($inner['payment'] ?? null) ? $inner['payment'] : [];
        $errorCode  = (string) ($errorBlock['code'] ?? '');
        $paymentUrl = (string) ($payment['paymentURL'] ?? '');
        $ntpId      = (string) ($payment['ntpID'] ?? '');

        if (ErrorCode::isHostedPage($errorCode) && Sanitizer::isSafeHttpsUrl($paymentUrl)) {
            ($this->paymentInfoUpdater)($orderId, [
                'netopia_ntp_id'          => $ntpId,
                'netopia_payment_link'    => $paymentUrl,
                'netopia_payment_link_at' => date('c'),
                'transaction_id'          => $ntpId,
            ]);
            ($this->orderStatusChanger)($orderId, 'O');

            return ['success' => true, 'payment_url' => $paymentUrl, 'error' => ''];
        }

        $errorMsg = (string) ($errorBlock['message'] ?? 'Unexpected response (code: ' . $errorCode . ')');
        return $this->failure($errorMsg);
    }

    /**
     * @param array<string, mixed> $processorParams
     */
    private function resolveInstallments(array $processorParams): int
    {
        if (($processorParams['allow_installments'] ?? '') !== 'Y') {
            return 1;
        }
        return max(1, (int) ($processorParams['max_installments'] ?? 1));
    }

    /**
     * @return LinkResult
     */
    private function failure(string $message): array
    {
        return ['success' => false, 'payment_url' => '', 'error' => $message];
    }
}
