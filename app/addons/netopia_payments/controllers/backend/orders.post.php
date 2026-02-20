<?php
/**
 * NETOPIA Payments — Post-controller hook for orders.
 *
 * Adds the $netopia_payment_link_available flag to the order details template
 * so the "Send payment link" button is shown for NETOPIA orders.
 *
 * @package NetopiaPayments
 */

use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if ($mode === 'details') {
    $order_id = (int) ($_GET['order_id'] ?? $_REQUEST['order_id'] ?? 0);
    if (empty($order_id)) {
        return;
    }

    $order_info = Tygh::$app['view']->getTemplateVars('order_info');
    if (empty($order_info['payment_id'])) {
        return;
    }

    // Check if the order uses the NETOPIA payment processor
    $processor = db_get_row(
        'SELECT pp.processor_script FROM ?:payment_processors pp '
        . 'JOIN ?:payments p ON p.processor_id = pp.processor_id '
        . 'WHERE p.payment_id = ?i',
        $order_info['payment_id']
    );

    if (!empty($processor) && $processor['processor_script'] === 'netopia_payments.php') {
        Tygh::$app['view']->assign('netopia_payment_link_available', true);
    }
}
