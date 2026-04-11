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

<p>{__("netopia_payment_link_email_greeting", ["[customer_name]" => $customer_name|escape:"html"])}</p>

<p>{__("netopia_payment_link_email_intro", ["[order_id]" => $order_id|escape:"html", "[amount]" => $amount|escape:"html"])}</p>

<p>{__("netopia_payment_link_email_cta")}<br/>
<a href="{$payment_url|escape:"html"}">{$payment_url|escape:"html"}</a></p>

<p>{__("netopia_payment_link_email_secure_note")}</p>

<p>{__("netopia_payment_link_email_disregard")}</p>

<p>{__("netopia_payment_link_email_signoff")}<br/>
{$company_name|escape:"html"}</p>
