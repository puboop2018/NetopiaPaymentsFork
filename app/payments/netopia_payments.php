<?php
/**
 * NETOPIA Payments processor for CS-Cart 4.19.x
 *
 * Handles the complete payment flow:
 * - Initial payment (card start)
 * - 3D Secure authentication redirect and verification
 * - IPN (Instant Payment Notification) callback
 *
 * @package NetopiaPayments
 */

use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

// Include addon functions
require_once Registry::get('config.dir.addons') . 'netopia_payments/func.php';

// ---------------------------------------------------------------------------
// CALLBACK HANDLING (IPN & 3DS Return)
// ---------------------------------------------------------------------------
if (defined('PAYMENT_NOTIFICATION')) {

    if ($mode === 'notify') {
        // ---------------------------------------------------------------
        // IPN Callback from NETOPIA
        // ---------------------------------------------------------------
        fn_netopia_handle_ipn();
    } elseif ($mode === 'return') {
        // ---------------------------------------------------------------
        // Customer return from 3D Secure bank authentication
        // ---------------------------------------------------------------
        fn_netopia_handle_3ds_return();
    }

    exit;
}

// ---------------------------------------------------------------------------
// INITIAL PAYMENT PROCESSING (Checkout)
// ---------------------------------------------------------------------------
if (empty($processor_data) || empty($order_info)) {
    fn_set_notification('E', __('error'), __('netopia_configuration_error'));
    $pp_response = [
        'order_status' => 'F',
        'reason_text'  => 'Missing processor or order data.',
    ];
    return;
}

$params = $processor_data['processor_params'];

// Validate required configuration
if (empty($params['pos_signature']) || empty($params['api_key'])) {
    $pp_response = [
        'order_status' => 'F',
        'reason_text'  => 'NETOPIA Payments is not configured. POS Signature and API Key are required.',
    ];
    return;
}

$is_live = !empty($params['mode']) && $params['mode'] === 'live';

// Determine installments
$installments = 1;
if (!empty($params['allow_installments']) && $params['allow_installments'] === 'Y') {
    $max_installments = (int) ($params['max_installments'] ?? 1);
    // Check if customer selected installments (from checkout form)
    $selected = (int) ($_POST['netopia_installments'] ?? 1);
    $installments = ($selected > 1 && $selected <= $max_installments) ? $selected : 1;
}

// Collect 3D Secure browser fingerprint data
$three_ds_data = fn_netopia_get_3ds_data();

// Build the payment start request
$json_request = fn_netopia_build_start_request($params, $order_info, $three_ds_data, $installments);

// Send to NETOPIA Start API
$response = fn_netopia_api_request('payment/card/start', $json_request, $params['api_key'], $is_live);

// Log for debugging (only in development mode) — mask card data in logs
if (defined('DEVELOPMENT') && DEVELOPMENT) {
    $log_data = $response;
    unset($log_data['data']['payment']['instrument']);
    fn_log_event('general', 'runtime', [
        'message' => 'NETOPIA start response: ' . json_encode($log_data),
    ]);
}

if ($response['status'] !== 1 || empty($response['data'])) {
    $error_message = $response['data']['message'] ?? $response['message'] ?? 'Connection error';
    $pp_response = [
        'order_status' => 'F',
        'reason_text'  => 'NETOPIA API error: ' . $error_message,
    ];
    return;
}

$data = $response['data'];
$error_code    = (string) ($data['error']['code'] ?? $data['data']['error']['code'] ?? '');
$payment_data  = $data['payment'] ?? $data['data']['payment'] ?? [];
$customer_action = $data['customerAction'] ?? $data['data']['customerAction'] ?? [];
$ntp_status    = (int) ($payment_data['status'] ?? 0);
$ntp_id        = (string) ($payment_data['ntpID'] ?? '');

// Store NETOPIA transaction data in order payment info
$payment_info_update = [
    'netopia_ntp_id' => $ntp_id,
    'transaction_id' => $ntp_id,
];

// -----------------------------------------------------------------------
// Handle response based on error code and status
// -----------------------------------------------------------------------

if ($error_code === NETOPIA_ERROR_CODE_3DS && $ntp_status === NETOPIA_STATUS_3DS_REQUIRED) {
    // -------------------------------------------------------------------
    // 3D Secure Authentication Required
    // -------------------------------------------------------------------
    $auth_token = (string) ($customer_action['authenticationToken'] ?? '');
    $pa_req     = (string) ($customer_action['formData']['paReq'] ?? '');
    $bank_url   = (string) ($customer_action['url'] ?? '');

    if (empty($bank_url) || empty($pa_req) || !fn_netopia_validate_url($bank_url)) {
        $pp_response = [
            'order_status' => 'F',
            'reason_text'  => 'NETOPIA: 3D Secure data incomplete or invalid bank URL.',
        ];
        return;
    }

    // Store authentication data for later verification
    $payment_info_update['netopia_auth_token'] = $auth_token;
    $payment_info_update['netopia_ntp_id']     = $ntp_id;
    fn_update_order_payment_info($order_id, $payment_info_update);

    // Store order_id in session for the return callback
    Tygh::$app['session']['netopia_order_id'] = $order_id;
    Tygh::$app['session']['netopia_payment_id'] = $order_info['payment_id'];

    // Set order to "Open" while awaiting 3DS
    fn_change_order_status($order_id, 'O', '', false);

    // Build return URL for after 3DS bank authentication
    $return_url = fn_url('payment_notification.return?payment=netopia_payments', AREA, 'current');

    // Redirect customer to bank 3DS page
    $form_data = [
        'paReq'   => $pa_req,
        'MD'      => '',
        'TermUrl' => $return_url,
    ];

    fn_create_payment_form($bank_url, $form_data, 'NETOPIA 3D Secure', false);
    exit;

} elseif ($error_code === NETOPIA_ERROR_CODE_HOSTED_PAGE && !empty($payment_data['paymentURL']) && fn_netopia_validate_url((string) $payment_data['paymentURL'])) {
    // -------------------------------------------------------------------
    // Hosted Payment Page — redirect customer to NETOPIA payment page
    // -------------------------------------------------------------------
    $payment_url = (string) $payment_data['paymentURL'];

    $payment_info_update['netopia_payment_link']    = $payment_url;
    $payment_info_update['netopia_payment_link_at'] = date('c');
    fn_update_order_payment_info($order_id, $payment_info_update);

    // Store order_id in session for the return callback
    Tygh::$app['session']['netopia_order_id'] = $order_id;
    Tygh::$app['session']['netopia_payment_id'] = $order_info['payment_id'];

    // Set order to "Open" while awaiting payment
    fn_change_order_status($order_id, 'O', '', false);

    // Redirect customer to NETOPIA hosted payment page
    fn_redirect($payment_url, true);
    exit;

} elseif (($error_code === '0' || $error_code === '00') && ($ntp_status === NETOPIA_STATUS_PAID || $ntp_status === NETOPIA_STATUS_CONFIRMED)) {
    // -------------------------------------------------------------------
    // Payment Approved directly (no 3DS needed)
    // -------------------------------------------------------------------
    fn_update_order_payment_info($order_id, $payment_info_update);

    $pp_response = [
        'order_status'   => fn_netopia_map_order_status($ntp_status, $params),
        'reason_text'    => 'Payment approved. NTP ID: ' . $ntp_id,
        'transaction_id' => $ntp_id,
    ];

} else {
    // -------------------------------------------------------------------
    // Payment Failed / Declined / Error
    // -------------------------------------------------------------------
    $error_msg = $data['error']['message']
        ?? $data['data']['error']['message']
        ?? 'Payment was not approved';

    fn_update_order_payment_info($order_id, $payment_info_update);

    $pp_response = [
        'order_status'   => fn_netopia_map_order_status($ntp_status, $params),
        'reason_text'    => 'NETOPIA: ' . $error_msg . ' (code: ' . $error_code . ', status: ' . $ntp_status . ')',
        'transaction_id' => $ntp_id,
    ];
}

