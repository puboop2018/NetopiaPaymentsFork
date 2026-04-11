<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

/**
 * Sends the NETOPIA payment-link email to the customer.
 *
 * Mailing is delegated to an injected closure so this class has no direct
 * dependency on CS-Cart's mailer service.
 */
final class PaymentLinkEmailSender
{
    /**
     * @param \Closure(array<string, mixed> $config): bool $mailSender
     *     Receives the mailer config array and returns success.
     * @param \Closure(string $code, array<string, string|int> $args): string $translator
     *     Translation callback (typically __()).
     */
    public function __construct(
        private readonly \Closure $mailSender,
        private readonly \Closure $translator,
        private readonly string $companyName,
        private readonly string $primaryCurrency,
    ) {
    }

    /**
     * @param array<string, mixed> $orderInfo
     */
    public function send(array $orderInfo, string $paymentUrl, string $formattedAmount): bool
    {
        $email = (string) ($orderInfo['email'] ?? '');
        if ($email === '') {
            return false;
        }

        $orderId      = (int) ($orderInfo['order_id'] ?? 0);
        $customerName = trim(((string) ($orderInfo['b_firstname'] ?? '')) . ' ' . ((string) ($orderInfo['b_lastname'] ?? '')));
        if ($customerName === '') {
            $customerName = $email;
        }
        $currency = (string) ($orderInfo['secondary_currency'] ?? $this->primaryCurrency);
        $subject  = ($this->translator)('netopia_payment_link_email_subject', ['[order_id]' => $orderId]);

        $sent = ($this->mailSender)([
            'to'            => $email,
            'from'          => 'default_company_orders_department',
            'data'          => [
                'customer_name' => $customerName,
                'order_id'      => $orderId,
                'amount'        => $formattedAmount . ' ' . $currency,
                'payment_url'   => $paymentUrl,
                'company_name'  => $this->companyName,
                'order_info'    => $orderInfo,
            ],
            'template_code' => 'netopia_payment_link',
            'tpl'           => 'addons/netopia_payments/payment_link_email.tpl',
            'subject'       => $subject,
        ]);

        if ($sent) {
            return true;
        }

        $body = ($this->translator)('netopia_payment_link_email_body', [
            '[customer_name]' => $customerName,
            '[order_id]'      => $orderId,
            '[amount]'        => $formattedAmount . ' ' . $currency,
            '[payment_url]'   => $paymentUrl,
            '[company_name]'  => $this->companyName,
        ]);

        return (bool) ($this->mailSender)([
            'to'      => $email,
            'from'    => 'default_company_orders_department',
            'data'    => [],
            'subject' => $subject,
            'body'    => $body,
        ]);
    }
}
