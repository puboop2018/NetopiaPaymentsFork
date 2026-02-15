<?php
/**
 * NETOPIA Payments — Admin controller for generating and sending payment links.
 *
 * Routes:
 *   POST dispatch=netopia_payment_link.generate  — Generate a payment link for an order
 *   POST dispatch=netopia_payment_link.send      — Generate + email the link to the customer
 *
 * @package NetopiaPayments
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

require_once Registry::get('config.dir.addons') . 'netopia_payments/func.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return [CONTROLLER_STATUS_NO_PAGE];
}

$order_id = (int) ($_REQUEST['order_id'] ?? 0);

if (empty($order_id)) {
    fn_set_notification('E', __('error'), __('netopia_payment_link_no_order'));
    return [CONTROLLER_STATUS_REDIRECT, fn_url('orders.manage')];
}

// Security: verify the admin has permission to manage this order
$order_info = fn_get_order_info($order_id);
if (empty($order_info)) {
    fn_set_notification('E', __('error'), __('netopia_order_not_found'));
    return [CONTROLLER_STATUS_REDIRECT, fn_url('orders.manage')];
}

if ($mode === 'generate' || $mode === 'send') {

    $result = fn_netopia_generate_payment_link($order_id);

    if (!$result['success']) {
        fn_set_notification('E', __('error'), __('netopia_payment_link_error', ['[error]' => $result['error']]));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('orders.details?order_id=' . $order_id)];
    }

    $payment_url = $result['payment_url'];

    fn_set_notification('N', __('notice'), __('netopia_payment_link_generated', ['[url]' => $payment_url]));

    // If mode is 'send', also email the link to the customer
    if ($mode === 'send') {
        $email_sent = fn_netopia_send_payment_link_email($order_id, $payment_url);

        if ($email_sent) {
            fn_set_notification('N', __('notice'), __('netopia_payment_link_sent', ['[email]' => $order_info['email']]));

            // Record that email was sent
            fn_update_order_payment_info($order_id, [
                'netopia_payment_link_email_sent'    => $order_info['email'],
                'netopia_payment_link_email_sent_at' => date('c'),
            ]);
        } else {
            fn_set_notification('W', __('warning'), __('netopia_payment_link_email_failed'));
        }
    }

    return [CONTROLLER_STATUS_REDIRECT, fn_url('orders.details?order_id=' . $order_id)];
}

return [CONTROLLER_STATUS_NO_PAGE];
