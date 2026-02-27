{**
 * NETOPIA Payments — Payment link email template.
 *
 * Variables:
 *   $customer_name - Customer full name
 *   $order_id      - CS-Cart order ID
 *   $amount        - Formatted amount with currency
 *   $payment_url   - The NETOPIA hosted payment page URL
 *   $company_name  - Store company name
 *}

<p>Dear {$customer_name},</p>

<p>Your payment for Order <strong>#{$order_id}</strong> ({$amount}) is pending.</p>

<p>Please complete your payment using the secure link below:<br/>
<a href="{$payment_url}">{$payment_url}</a></p>

<p>This link will take you to a secure NETOPIA payment page where you can enter your card details.</p>

<p>If you have already completed this payment, please disregard this email.</p>

<p>Thank you,<br/>
{$company_name}</p>
