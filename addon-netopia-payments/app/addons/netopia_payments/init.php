<?php

declare(strict_types=1);

/**
 * NETOPIA Payments addon initialization.
 *
 * @package NetopiaPayments
 * @author  NETOPIA Payments
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

// Register the stand-alone PSR-4 autoloader for Netopia\CsCart\*,
// Netopia\Payment2\* and Psr\Log\* classes bundled under lib/.
require_once __DIR__ . '/autoload.php';

fn_register_hooks(
    'update_payment_post'
);
