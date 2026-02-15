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

if (!defined('BOOTSTRAP')) { die('Access denied'); }

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
    $selected = (int) ($_REQUEST['netopia_installments'] ?? 1);
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

if ($error_code === '100' && $ntp_status === 15) {
    // -------------------------------------------------------------------
    // 3D Secure Authentication Required
    // -------------------------------------------------------------------
    $auth_token = (string) ($customer_action['authenticationToken'] ?? '');
    $pa_req     = (string) ($customer_action['formData']['paReq'] ?? '');
    $bank_url   = (string) ($customer_action['url'] ?? '');

    if (empty($bank_url) || empty($pa_req)) {
        $pp_response = [
            'order_status' => 'F',
            'reason_text'  => 'NETOPIA: 3D Secure data incomplete.',
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

} elseif (($error_code === '0' || $error_code === '00') && ($ntp_status === 3 || $ntp_status === 5)) {
    // -------------------------------------------------------------------
    // Payment Approved directly (no 3DS needed)
    // -------------------------------------------------------------------
    fn_update_order_payment_info($order_id, $payment_info_update);

    $pp_response = [
        'order_status' => 'P',
        'reason_text'  => 'Payment approved. NTP ID: ' . $ntp_id,
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
        'order_status' => 'F',
        'reason_text'  => 'NETOPIA: ' . $error_msg . ' (code: ' . $error_code . ', status: ' . $ntp_status . ')',
        'transaction_id' => $ntp_id,
    ];
}

// =======================================================================
// CALLBACK FUNCTIONS
// =======================================================================

/**
 * Handle IPN (Instant Payment Notification) from NETOPIA.
 */
function fn_netopia_handle_ipn(): void
{
    // Read raw POST body once — passed to verify to avoid double-reading php://input
    $raw_post = file_get_contents('php://input');
    $ipn_raw  = json_decode($raw_post, true);

    if (empty($ipn_raw)) {
        fn_netopia_ipn_response(2, 1, 'Empty IPN payload');
        return;
    }

    $order_id = (string) ($ipn_raw['order']['orderID'] ?? '');

    if (empty($order_id)) {
        fn_netopia_ipn_response(2, 2, 'Missing order ID in IPN');
        return;
    }

    // Get order and processor data
    $order_info = fn_get_order_info((int) $order_id);
    if (empty($order_info)) {
        fn_netopia_ipn_response(2, 3, 'Order not found: ' . $order_id);
        return;
    }

    $processor_data = fn_get_payment_method_data($order_info['payment_id']);
    if (empty($processor_data['processor_params'])) {
        fn_netopia_ipn_response(2, 4, 'Processor not configured');
        return;
    }

    $params = $processor_data['processor_params'];
    $mode = (!empty($params['mode']) && $params['mode'] === 'live') ? 'live' : 'sandbox';
    $public_key = fn_netopia_load_key($params, 'public_key', (int) $order_info['payment_id'], $mode);

    if (empty($public_key)) {
        fn_netopia_ipn_response(2, 5, 'Public key not configured for ' . $mode . ' mode');
        return;
    }

    // Verify the IPN JWT signature (pass raw body to avoid double-read)
    $result = fn_netopia_verify_ipn($public_key, $params['pos_signature'], $raw_post);

    if (!$result['verified']) {
        fn_netopia_ipn_response(2, 6, 'IPN verification failed: ' . $result['error']);
        return;
    }

    $ipn_data = $result['payload'];
    $ntp_status = (int) ($ipn_data['payment']['status'] ?? 0);
    $ntp_id     = (string) ($ipn_data['payment']['ntpID'] ?? '');
    $amount     = (float) ($ipn_data['payment']['amount'] ?? 0);

    // Idempotency: skip if order is already in a final state matching this IPN
    $cs_status = fn_netopia_map_order_status($ntp_status);
    $current_status = $order_info['status'] ?? '';
    if ($current_status === $cs_status && in_array($current_status, ['P', 'F', 'I'], true)) {
        fn_netopia_ipn_response(1, 0, 'OK (already processed)');
        return;
    }

    // Update order payment info
    $payment_info = [
        'transaction_id'        => $ntp_id,
        'netopia_ntp_id'        => $ntp_id,
        'netopia_status'        => $ntp_status,
        'netopia_amount'        => $amount,
        'netopia_error_code'    => (string) ($ipn_data['payment']['data']['errorCode'] ?? ($ipn_data['error']['code'] ?? '')),
        'netopia_error_message' => (string) ($ipn_data['payment']['data']['errorMessage'] ?? ($ipn_data['error']['message'] ?? '')),
    ];

    fn_update_order_payment_info((int) $order_id, $payment_info);

    // Update order status
    $pp_response = [
        'order_status'   => $cs_status,
        'reason_text'    => 'IPN: status=' . $ntp_status . ', ntpID=' . $ntp_id,
        'transaction_id' => $ntp_id,
    ];

    fn_finish_payment((int) $order_id, $pp_response);

    // Send success response to NETOPIA
    fn_netopia_ipn_response(1, 0, 'OK');
}

/**
 * Handle customer return from 3D Secure bank authentication.
 */
function fn_netopia_handle_3ds_return(): void
{
    $order_id   = (int) (Tygh::$app['session']['netopia_order_id'] ?? 0);
    $payment_id = (int) (Tygh::$app['session']['netopia_payment_id'] ?? 0);

    if (empty($order_id)) {
        fn_set_notification('E', __('error'), __('netopia_3ds_session_expired'));
        fn_redirect(fn_url('checkout.checkout'));
        return;
    }

    $order_info = fn_get_order_info($order_id);
    if (empty($order_info)) {
        fn_set_notification('E', __('error'), __('netopia_order_not_found'));
        fn_redirect(fn_url('checkout.checkout'));
        return;
    }

    $processor_data = fn_get_payment_method_data($order_info['payment_id']);
    $params = $processor_data['processor_params'] ?? [];

    $pa_res = $_POST['paRes'] ?? $_REQUEST['paRes'] ?? '';

    if (empty($pa_res)) {
        // No paRes — customer may have cancelled 3DS. Check payment status via API.
        $pp_response = [
            'order_status' => 'F',
            'reason_text'  => '3D Secure authentication was not completed.',
        ];
        fn_finish_payment($order_id, $pp_response);
        fn_order_placement_routines('route', $order_id);
        return;
    }

    // Retrieve stored authentication data
    $payment_info = $order_info['payment_info'] ?? [];
    $auth_token   = $payment_info['netopia_auth_token'] ?? '';
    $ntp_id       = $payment_info['netopia_ntp_id'] ?? '';

    if (empty($auth_token) || empty($ntp_id)) {
        $pp_response = [
            'order_status' => 'F',
            'reason_text'  => '3D Secure session data missing.',
        ];
        fn_finish_payment($order_id, $pp_response);
        fn_order_placement_routines('route', $order_id);
        return;
    }

    $is_live = !empty($params['mode']) && $params['mode'] === 'live';

    // Build and send VerifyAuth request
    $verify_json = fn_netopia_build_verify_auth_request($auth_token, $ntp_id, $pa_res);
    $response    = fn_netopia_api_request('payment/card/verify-auth', $verify_json, $params['api_key'], $is_live);

    $data = $response['data'] ?? [];

    // Handle nested data structure
    $verify_data = $data['data'] ?? $data;
    $error_code  = (string) ($verify_data['error']['code'] ?? '');
    $payment     = $verify_data['payment'] ?? [];
    $ntp_status  = (int) ($payment['status'] ?? 0);
    $verify_ntp  = (string) ($payment['ntpID'] ?? $ntp_id);

    $payment_info_update = [
        'transaction_id'          => $verify_ntp,
        'netopia_ntp_id'          => $verify_ntp,
        'netopia_verify_status'   => $ntp_status,
        'netopia_verify_error'    => $error_code,
    ];
    fn_update_order_payment_info($order_id, $payment_info_update);

    if (($error_code === '0' || $error_code === '00') && ($ntp_status === 3 || $ntp_status === 5)) {
        // 3DS verification successful, payment approved
        $pp_response = [
            'order_status'   => 'P',
            'reason_text'    => 'Payment approved after 3D Secure. NTP ID: ' . $verify_ntp,
            'transaction_id' => $verify_ntp,
        ];
    } else {
        // 3DS verification failed or payment not approved
        $error_msg = $verify_data['error']['message'] ?? 'Verification failed';
        $pp_response = [
            'order_status'   => 'F',
            'reason_text'    => 'NETOPIA 3DS: ' . $error_msg . ' (code: ' . $error_code . ', status: ' . $ntp_status . ')',
            'transaction_id' => $verify_ntp,
        ];
    }

    fn_finish_payment($order_id, $pp_response);

    // Clean up session
    unset(Tygh::$app['session']['netopia_order_id']);
    unset(Tygh::$app['session']['netopia_payment_id']);

    fn_order_placement_routines('route', $order_id);
}

/**
 * Send a JSON response back to NETOPIA IPN.
 */
function fn_netopia_ipn_response(int $error_type, int $error_code, string $message): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'errorType'    => $error_type,
        'errorCode'    => $error_code,
        'errorMessage' => $message,
    ]);
    exit;
}
