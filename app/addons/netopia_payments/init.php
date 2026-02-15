<?php
/**
 * NETOPIA Payments addon initialization.
 *
 * @package NetopiaPayments
 * @author  NETOPIA Payments
 */

if (!defined('BOOTSTRAP')) { die('Access denied'); }

fn_register_hooks(
    'update_payment_post'
);
