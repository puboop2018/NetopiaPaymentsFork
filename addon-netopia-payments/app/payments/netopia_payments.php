<?php

declare(strict_types=1);

/**
 * NETOPIA Payments processor for CS-Cart 4.19.x
 *
 * Thin controller: delegates IPN/3DS callbacks to fn_netopia_handle_* wrappers
 * (which in turn delegate to Netopia\CsCart classes) and drives the initial
 * checkout flow using PayloadBuilder + ApiClient + ErrorCode/PaymentStatus enums.
 *
 * @package NetopiaPayments
 */

use Netopia\CsCart\Bootstrap;
use Netopia\CsCart\Dto\CardData;
use Netopia\CsCart\Session\ThreeDsSessionStore;
use Netopia\CsCart\Support\Sanitizer;
use Netopia\Payment2\Enum\ErrorCode;
use Netopia\Payment2\Enum\PaymentMode;
use Netopia\Payment2\Enum\PaymentStatus;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

// ---------------------------------------------------------------------------
// CALLBACK HANDLING (IPN & 3DS Return)
// ---------------------------------------------------------------------------
if (defined('PAYMENT_NOTIFICATION')) {
    if ($mode === 'notify') {
        fn_netopia_handle_ipn();
    } elseif ($mode === 'return') {
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

if (empty($params['pos_signature']) || empty($params['api_key'])) {
    $pp_response = [
        'order_status' => 'F',
        'reason_text'  => 'NETOPIA Payments is not configured. POS Signature and API Key are required.',
    ];
    return;
}

$paymentMode = PaymentMode::fromMixed($params['mode'] ?? null);

$installments = 1;
if (!empty($params['allow_installments']) && $params['allow_installments'] === 'Y') {
    $maxInstallments = (int) ($params['max_installments'] ?? 1);
    $selected        = (int) ($_POST['netopia_installments'] ?? 1);
    $installments    = ($selected > 1 && $selected <= $maxInstallments) ? $selected : 1;
}

$bootstrap = Bootstrap::instance();
$threeDs   = $bootstrap->threeDsFactory->fromRequest($_SERVER, $_POST);

$paymentInfo = is_array($order_info['payment_info'] ?? null) ? $order_info['payment_info'] : [];
$card = new CardData(
    account:    (string) ($paymentInfo['card_number']  ?? ''),
    expMonth:   (int)    ($paymentInfo['expiry_month'] ?? 0),
    expYear:    (int)    ($paymentInfo['expiry_year']  ?? 0),
    secretCode: (string) ($paymentInfo['cvv2']         ?? ''),
);

try {
    $jsonRequest = $bootstrap->payloadBuilder->buildStartRequest(
        processorParams: $params,
        orderInfo:       $order_info,
        threeDs:         $threeDs,
        installments:    $installments,
        card:            $card,
    );
} catch (\Throwable $e) {
    $bootstrap->logger->error('NETOPIA payload build failed', ['error' => $e->getMessage()]);
    $pp_response = [
        'order_status' => 'F',
        'reason_text'  => 'NETOPIA: failed to build payment request.',
    ];
    return;
}

$apiClient = $bootstrap->apiClientFor((string) $params['api_key'], $paymentMode);

try {
    $response = $apiClient->post('payment/card/start', $jsonRequest);
} catch (\Throwable $e) {
    $bootstrap->logger->error('NETOPIA API request failed', ['error' => $e->getMessage()]);
    $pp_response = [
        'order_status' => 'F',
        'reason_text'  => 'NETOPIA API error: ' . $e->getMessage(),
    ];
    return;
}

// Development logging with redacted card data
if (defined('DEVELOPMENT') && DEVELOPMENT) {
    $logData = $response->data ?? [];
    if (isset($logData['payment']['instrument'])) {
        $logData['payment']['instrument'] = $card->toRedactedLog();
    }
    $bootstrap->logger->debug('NETOPIA start response', ['response' => $logData]);
}

if (!$response->isSuccess() || $response->data === null) {
    $pp_response = [
        'order_status' => 'F',
        'reason_text'  => 'NETOPIA API error: ' . $response->message,
    ];
    return;
}

$errorBlock     = $response->errorBlock();
$paymentData    = $response->paymentData();
$customerAction = $response->customerAction();

$errorCode = (string) ($errorBlock['code']   ?? '');
$ntpStatus = (int)    ($paymentData['status'] ?? 0);
$ntpId     = (string) ($paymentData['ntpID']  ?? '');
$status    = PaymentStatus::tryFrom($ntpStatus);

/** @var \ArrayAccess<string, mixed> $sessionContainer */
$sessionContainer  = Tygh::$app['session'];
$threeDsSession    = new ThreeDsSessionStore($sessionContainer);

$paymentInfoUpdate = [
    'netopia_ntp_id' => $ntpId,
    'transaction_id' => $ntpId,
];

// Dispatch based on (errorCode, status) combination
$flow = match (true) {
    ErrorCode::isThreeDsRequired($errorCode) && $ntpStatus === 15 => 'three_ds',
    ErrorCode::isHostedPage($errorCode)                          => 'hosted_page',
    ErrorCode::isApproved($errorCode) && $status?->isSuccessful() === true => 'approved',
    default                                                      => 'failed',
};

switch ($flow) {
    case 'three_ds':
        $authToken = (string) ($customerAction['authenticationToken'] ?? '');
        $paReq     = (string) ($customerAction['formData']['paReq']  ?? '');
        $bankUrl   = (string) ($customerAction['url']                 ?? '');

        if ($bankUrl === '' || $paReq === '' || !Sanitizer::isSafeHttpsUrl($bankUrl)) {
            $pp_response = [
                'order_status' => 'F',
                'reason_text'  => 'NETOPIA: 3D Secure data incomplete or invalid bank URL.',
            ];
            return;
        }

        $paymentInfoUpdate['netopia_auth_token'] = $authToken;
        fn_update_order_payment_info($order_id, $paymentInfoUpdate);

        $threeDsSession->rememberOrder((int) $order_id, (int) $order_info['payment_id']);

        fn_change_order_status($order_id, 'O', '', false);

        $returnUrl = fn_url('payment_notification.return?payment=netopia_payments', AREA, 'current');
        fn_create_payment_form($bankUrl, [
            'paReq'   => $paReq,
            'MD'      => '',
            'TermUrl' => $returnUrl,
        ], 'NETOPIA 3D Secure', false);
        exit;

    case 'hosted_page':
        $paymentUrl = (string) ($paymentData['paymentURL'] ?? '');
        if (!Sanitizer::isSafeHttpsUrl($paymentUrl)) {
            $pp_response = [
                'order_status' => 'F',
                'reason_text'  => 'NETOPIA: hosted page URL missing or insecure.',
            ];
            return;
        }

        $paymentInfoUpdate['netopia_payment_link']    = $paymentUrl;
        $paymentInfoUpdate['netopia_payment_link_at'] = $bootstrap->clock->now()->format(\DateTimeInterface::ATOM);
        fn_update_order_payment_info($order_id, $paymentInfoUpdate);

        $threeDsSession->rememberOrder((int) $order_id, (int) $order_info['payment_id']);

        fn_change_order_status($order_id, 'O', '', false);
        fn_redirect($paymentUrl, true);
        exit;

    case 'approved':
        fn_update_order_payment_info($order_id, $paymentInfoUpdate);
        $pp_response = [
            'order_status'   => $bootstrap->statusMapper->map($ntpStatus, $params),
            'reason_text'    => 'Payment approved. NTP ID: ' . $ntpId,
            'transaction_id' => $ntpId,
        ];
        break;

    case 'failed':
    default:
        $errorMsg = (string) ($errorBlock['message'] ?? 'Payment was not approved');
        fn_update_order_payment_info($order_id, $paymentInfoUpdate);
        $pp_response = [
            'order_status'   => $bootstrap->statusMapper->map($ntpStatus, $params),
            'reason_text'    => 'NETOPIA: ' . $errorMsg . ' (code: ' . $errorCode . ', status: ' . $ntpStatus . ')',
            'transaction_id' => $ntpId,
        ];
        break;
}
