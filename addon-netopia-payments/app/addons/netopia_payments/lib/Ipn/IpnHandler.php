<?php

declare(strict_types=1);

namespace Netopia\CsCart\Ipn;

use Netopia\CsCart\Key\KeyStorage;
use Netopia\CsCart\Status\StatusMapper;
use Netopia\Payment2\Enum\PaymentMode;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates IPN callback handling.
 *
 * Reads the raw POST body, resolves the public key, verifies the JWT, and
 * updates CS-Cart order state through closures supplied by the bootstrap layer.
 * This keeps CS-Cart globals out of the class while preserving the hook contract.
 *
 * Closures are typed to match CS-Cart signatures but the handler itself is pure:
 * all state changes flow through the injected callables.
 *
 * @phpstan-type OrderLookup          \Closure(int): (array<string, mixed>|null)
 * @phpstan-type ProcessorDataLookup  \Closure(int): (array<string, mixed>|null)
 * @phpstan-type PaymentInfoUpdater   \Closure(int, array<string, mixed>): void
 * @phpstan-type PaymentFinalizer     \Closure(int, array<string, mixed>): void
 * @phpstan-type Responder            \Closure(int, int, string): void
 */
final class IpnHandler
{
    /**
     * @param OrderLookup         $orderLookup
     * @param ProcessorDataLookup $processorDataLookup
     * @param PaymentInfoUpdater  $paymentInfoUpdater
     * @param PaymentFinalizer    $paymentFinalizer
     * @param Responder           $responder
     */
    public function __construct(
        private readonly IpnVerifier $verifier,
        private readonly KeyStorage $keyStorage,
        private readonly StatusMapper $statusMapper,
        private readonly \Closure $orderLookup,
        private readonly \Closure $processorDataLookup,
        private readonly \Closure $paymentInfoUpdater,
        private readonly \Closure $paymentFinalizer,
        private readonly \Closure $responder,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Entry point. Reads raw POST body, resolves the public key, verifies the
     * JWT, and updates the CS-Cart order.
     */
    public function handle(string $rawPostBody, ?string $verificationToken): void
    {
        if ($rawPostBody === '' || !json_validate($rawPostBody)) {
            $this->respond(2, 1, 'Empty or invalid IPN payload');
            return;
        }

        /** @var array<string, mixed> $ipnRaw */
        $ipnRaw   = json_decode($rawPostBody, true, flags: JSON_THROW_ON_ERROR);
        $orderRaw = $ipnRaw['order'] ?? [];
        $orderId  = is_array($orderRaw) ? (int) ($orderRaw['orderID'] ?? 0) : 0;

        if ($orderId <= 0) {
            $this->respond(2, 2, 'Missing order ID in IPN');
            return;
        }

        $orderInfo = ($this->orderLookup)($orderId);
        if (!is_array($orderInfo) || $orderInfo === []) {
            $this->respond(2, 3, 'Order not found');
            return;
        }

        $paymentId     = (int) ($orderInfo['payment_id'] ?? 0);
        $processorData = ($this->processorDataLookup)($paymentId);
        $params        = is_array($processorData) ? ($processorData['processor_params'] ?? null) : null;
        if (!is_array($params) || $params === []) {
            $this->respond(2, 4, 'Processor not configured');
            return;
        }

        $mode      = PaymentMode::fromMixed($params['mode'] ?? null);
        $publicKey = $this->keyStorage->load($params, 'public_key', $paymentId, $mode);
        if ($publicKey === '') {
            $this->respond(2, 5, 'Public key not configured for ' . $mode->value . ' mode');
            return;
        }

        $result = $this->verifier->verify(
            publicKeyPem:        $publicKey,
            posSignature:        (string) ($params['pos_signature'] ?? ''),
            rawPostBody:         $rawPostBody,
            verificationToken:   $verificationToken,
        );

        if (!$result['verified']) {
            $this->logger->warning('NETOPIA IPN verification failed', [
                'order_id' => $orderId,
                'error'    => $result['error'],
            ]);
            $this->respond(2, 6, 'IPN verification failed: ' . $result['error']);
            return;
        }

        $payload    = $result['payload'] ?? [];
        $payment    = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $ntpStatus  = (int) ($payment['status'] ?? 0);
        $ntpId      = (string) ($payment['ntpID'] ?? '');
        $amount     = (float) ($payment['amount'] ?? 0);
        $csStatus   = $this->statusMapper->map($ntpStatus, $params);

        // Idempotency: skip if already in a matching final state
        $currentStatus = (string) ($orderInfo['status'] ?? '');
        if ($currentStatus === $csStatus && in_array($currentStatus, ['P', 'F', 'I'], true)) {
            $this->respond(1, 0, 'OK (already processed)');
            return;
        }

        $paymentData = is_array($payment['data'] ?? null) ? $payment['data'] : [];
        $errorBlock  = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        ($this->paymentInfoUpdater)($orderId, [
            'transaction_id'        => $ntpId,
            'netopia_ntp_id'        => $ntpId,
            'netopia_status'        => $ntpStatus,
            'netopia_amount'        => $amount,
            'netopia_error_code'    => (string) ($paymentData['errorCode'] ?? $errorBlock['code'] ?? ''),
            'netopia_error_message' => (string) ($paymentData['errorMessage'] ?? $errorBlock['message'] ?? ''),
        ]);

        ($this->paymentFinalizer)($orderId, [
            'order_status'   => $csStatus,
            'reason_text'    => 'IPN: status=' . $ntpStatus . ', ntpID=' . $ntpId,
            'transaction_id' => $ntpId,
        ]);

        $this->respond(1, 0, 'OK');
    }

    private function respond(int $errorType, int $errorCode, string $message): void
    {
        ($this->responder)($errorType, $errorCode, $message);
    }
}
