<?php

declare(strict_types=1);

/**
 * NETOPIA Payments helper functions for CS-Cart.
 *
 * This file is intentionally thin: each fn_netopia_* function is a wrapper
 * around a service in app/addons/netopia_payments/lib/. Business logic lives
 * in the PSR-4 Netopia\CsCart namespace — see lib/Bootstrap.php for the
 * object-graph composition root.
 *
 * @package NetopiaPayments
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

use Netopia\CsCart\Bootstrap;
use Netopia\CsCart\Exception\KeyStorageException;
use Netopia\CsCart\Session\ThreeDsSessionStore;
use Netopia\CsCart\Support\CountryCodes;
use Netopia\CsCart\Support\Sanitizer;
use Netopia\Payment2\Enum\PaymentMode;
use Tygh\Tygh;

// ---------------------------------------------------------------------------
// Key management
// ---------------------------------------------------------------------------

/**
 * Returns the directory path where NETOPIA key files are stored.
 */
function fn_netopia_get_keys_dir(int $payment_id): string
{
    return Bootstrap::instance()->keyStorage->dirFor($payment_id);
}

/**
 * Load a NETOPIA key from file or from processor_params textarea fallback.
 *
 * @param array<string, mixed> $processor_params
 */
function fn_netopia_load_key(array $processor_params, string $key_type, int $payment_id, string $mode = ''): string
{
    $paymentMode = $mode !== ''
        ? PaymentMode::fromMixed($mode)
        : PaymentMode::fromMixed($processor_params['mode'] ?? null);

    return Bootstrap::instance()->keyStorage->load($processor_params, $key_type, $payment_id, $paymentMode);
}

/**
 * Read a key file with path traversal protection.
 */
function fn_netopia_read_key_file(string $keys_dir, string $filename): string
{
    return Bootstrap::instance()->keyStorage->readFile($keys_dir, $filename);
}

// ---------------------------------------------------------------------------
// Key upload hook
// ---------------------------------------------------------------------------

/**
 * Hook: handle file uploads when a NETOPIA payment method is saved.
 *
 * @param array<string, mixed> $payment_data
 */
function fn_netopia_payments_update_payment_post(array $payment_data, int $payment_id, string $lang_code = ''): void
{
    if (empty($payment_data['processor_id'])) {
        return;
    }

    $processor_info = db_get_row('SELECT * FROM ?:payment_processors WHERE processor_id = ?i', $payment_data['processor_id']);
    if (empty($processor_info) || $processor_info['processor_script'] !== 'netopia_payments.php') {
        return;
    }

    $keyStorage = Bootstrap::instance()->keyStorage;
    $keys_dir   = $keyStorage->dirFor($payment_id);
    $params     = fn_netopia_load_processor_params($payment_id);
    $updated    = false;

    $key_slots = [
        'sandbox_public_key'  => 'netopia_sandbox_public_key_file',
        'sandbox_private_key' => 'netopia_sandbox_private_key_file',
        'live_public_key'     => 'netopia_live_public_key_file',
        'live_private_key'    => 'netopia_live_private_key_file',
    ];

    foreach ($key_slots as $param_key => $file_input_name) {
        $upload = $_FILES[$file_input_name] ?? null;
        if (!is_array($upload) || empty($upload['name']) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        try {
            $keyStorage->secureDir($keys_dir);

            $file_field = $param_key . '_file';
            if (!empty($params[$file_field])) {
                $keyStorage->delete($keys_dir, (string) $params[$file_field]);
            }

            $stored = $keyStorage->storeUpload($upload, $keys_dir);

            $content = file_get_contents($upload['tmp_name']);
            $params[$file_field] = $stored;
            $params[$param_key]  = $content !== false ? trim($content) : '';
            $updated             = true;

            fn_set_notification('N', __('notice'), __('netopia_key_uploaded', ['[key]' => $param_key]));
        } catch (KeyStorageException $e) {
            fn_set_notification('W', __('warning'), $e->getMessage());
        }
    }

    foreach (array_keys($key_slots) as $param_key) {
        if (empty($_POST['delete_netopia_' . $param_key])) {
            continue;
        }
        $file_field = $param_key . '_file';
        if (!empty($params[$file_field])) {
            if (!$keyStorage->delete($keys_dir, (string) $params[$file_field])) {
                fn_set_notification('W', __('warning'), __('netopia_key_delete_failed'));
            }
            unset($params[$file_field]);
            $params[$param_key] = '';
            $updated            = true;
        }
    }

    if ($updated) {
        db_query('UPDATE ?:payments SET processor_params = ?s WHERE payment_id = ?i', serialize($params), $payment_id);
    }
}

/**
 * Load processor params from the database for a given payment ID.
 *
 * @return array<string, mixed>
 */
function fn_netopia_load_processor_params(int $payment_id): array
{
    $payment_row = db_get_row('SELECT processor_params FROM ?:payments WHERE payment_id = ?i', $payment_id);
    if (!empty($payment_row['processor_params'])) {
        $params = unserialize($payment_row['processor_params'], ['allowed_classes' => false]);
        if (is_array($params)) {
            return $params;
        }
    }

    return [];
}

/**
 * Write security files (.htaccess, index.html) to prevent direct web access.
 */
function fn_netopia_secure_keys_dir(string $dir): void
{
    Bootstrap::instance()->keyStorage->secureDir($dir);
}

// ---------------------------------------------------------------------------
// Country code mapping
// ---------------------------------------------------------------------------

/**
 * ISO 3166-1 alpha-2 to numeric country code.
 */
function fn_netopia_get_country_numeric_code(string $alpha2): int
{
    return CountryCodes::toNumeric($alpha2);
}

// ---------------------------------------------------------------------------
// API communication
// ---------------------------------------------------------------------------

/**
 * Send an HTTP request to the NETOPIA API.
 *
 * @return array{status: int, code: int, message: string, data: array<string, mixed>|null}
 */
function fn_netopia_api_request(string $endpoint, string $json_body, string $api_key, bool $is_live = false): array
{
    $mode   = $is_live ? PaymentMode::Live : PaymentMode::Sandbox;
    $client = Bootstrap::instance()->apiClientFor($api_key, $mode);

    try {
        $response = $client->post($endpoint, $json_body);
    } catch (\Netopia\CsCart\Exception\ApiException $e) {
        return ['status' => 0, 'code' => 0, 'message' => $e->getMessage(), 'data' => null];
    }

    return [
        'status'  => $response->status,
        'code'    => $response->code,
        'message' => $response->message,
        'data'    => $response->data,
    ];
}

// ---------------------------------------------------------------------------
// 3D Secure browser fingerprint helpers
// ---------------------------------------------------------------------------

/**
 * Collect 3D Secure browser fingerprint data.
 *
 * @return array<string, string>
 */
function fn_netopia_get_3ds_data(): array
{
    return Bootstrap::instance()->threeDsFactory->fromRequest($_SERVER, $_POST)->toArray();
}

/** Sanitize a 3DS browser fingerprint field value. */
function fn_netopia_sanitize_3ds_field(string $value): string
{
    return Sanitizer::threeDsField($value);
}

/** Sanitize and validate an IP address string. */
function fn_netopia_sanitize_ip(string $ip): string
{
    return Sanitizer::ipAddress($ip);
}

/** Validate that a URL returned by NETOPIA is a safe HTTPS URL. */
function fn_netopia_validate_url(string $url): bool
{
    return Sanitizer::isSafeHttpsUrl($url);
}

// ---------------------------------------------------------------------------
// Callback handlers (IPN & 3DS return)
// ---------------------------------------------------------------------------

/**
 * Handle IPN (Instant Payment Notification) from NETOPIA.
 */
function fn_netopia_handle_ipn(): void
{
    $raw_post = (string) (file_get_contents('php://input') ?: '');
    $token    = fn_netopia_extract_verification_token();

    $handler = Bootstrap::instance()->ipnHandler(
        orderLookup:         static fn (int $orderId): ?array => fn_get_order_info($orderId) ?: null,
        processorDataLookup: static fn (int $paymentId): ?array => fn_get_payment_method_data($paymentId) ?: null,
        paymentInfoUpdater:  static function (int $orderId, array $info): void {
            fn_update_order_payment_info($orderId, $info);
        },
        paymentFinalizer:    static function (int $orderId, array $response): void {
            fn_finish_payment($orderId, $response);
        },
        responder:           static function (int $errorType, int $errorCode, string $message): void {
            fn_netopia_ipn_response($errorType, $errorCode, $message);
        },
    );

    $handler->handle($raw_post, $token);
}

/**
 * Handle customer return from 3D Secure bank authentication.
 */
function fn_netopia_handle_3ds_return(): void
{
    /** @var \ArrayAccess<string, mixed> $sessionContainer */
    $sessionContainer = Tygh::$app['session'];
    $session          = new ThreeDsSessionStore($sessionContainer);

    $handler = Bootstrap::instance()->threeDsReturnHandler(
        session:             $session,
        orderLookup:         static fn (int $orderId): ?array => fn_get_order_info($orderId) ?: null,
        processorDataLookup: static fn (int $paymentId): ?array => fn_get_payment_method_data($paymentId) ?: null,
        paymentInfoUpdater:  static function (int $orderId, array $info): void {
            fn_update_order_payment_info($orderId, $info);
        },
        paymentFinalizer:    static function (int $orderId, array $response): void {
            fn_finish_payment($orderId, $response);
        },
        placementRouter:     static function (int $orderId): void {
            fn_order_placement_routines('route', $orderId);
        },
        checkoutRedirect:    static function (string $reason): void {
            fn_set_notification('E', __('error'), __($reason));
            fn_redirect(fn_url('checkout.checkout'));
        },
    );

    $handler->handle($_POST);
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

/**
 * Extract the Verification-Token from HTTP headers.
 */
function fn_netopia_extract_verification_token(): ?string
{
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Verification-Token') === 0) {
                return (string) $value;
            }
        }
    }

    return isset($_SERVER['HTTP_VERIFICATION_TOKEN']) ? (string) $_SERVER['HTTP_VERIFICATION_TOKEN'] : null;
}

// ---------------------------------------------------------------------------
// Status mapping
// ---------------------------------------------------------------------------

/**
 * Return NETOPIA status definitions: code, label, and default CS-Cart mapping.
 *
 * @param mixed $dummy Unused (required for Smarty modifier compatibility)
 * @return array<int, array{label: string, default: string, group: string}>
 */
function fn_netopia_get_status_definitions($dummy = null): array
{
    return Bootstrap::instance()->statusMapper->definitions();
}

/**
 * Map NETOPIA payment status code to CS-Cart order status.
 *
 * @param array<string, mixed> $processor_params
 */
function fn_netopia_map_order_status(int $netopia_status, array $processor_params = []): string
{
    return Bootstrap::instance()->statusMapper->map($netopia_status, $processor_params);
}

// ---------------------------------------------------------------------------
// Payment link
// ---------------------------------------------------------------------------

/**
 * Generate a NETOPIA payment link for an order.
 *
 * @return array{success: bool, payment_url: string, error: string}
 */
function fn_netopia_generate_payment_link(int $order_id): array
{
    $order_info = fn_get_order_info($order_id);
    if (empty($order_info)) {
        return ['success' => false, 'payment_url' => '', 'error' => 'Order not found.'];
    }

    $processor_data = fn_get_payment_method_data($order_info['payment_id']);
    if (empty($processor_data['processor_params'])) {
        return ['success' => false, 'payment_url' => '', 'error' => 'Payment processor not configured.'];
    }

    $service = Bootstrap::instance()->paymentLinkService(
        paymentInfoUpdater: static function (int $orderId, array $info): void {
            fn_update_order_payment_info($orderId, $info);
        },
        orderStatusChanger: static function (int $orderId, string $status): void {
            fn_change_order_status($orderId, $status, '', false);
        },
    );

    return $service->generate($order_info, $processor_data['processor_params'], $_SERVER);
}

/**
 * Send a payment link email to the customer for an order.
 */
function fn_netopia_send_payment_link_email(int $order_id, string $payment_url): bool
{
    $order_info = fn_get_order_info($order_id);
    if (empty($order_info)) {
        return false;
    }

    $sender = Bootstrap::instance()->paymentLinkEmailSender(
        mailSender: static function (array $config): bool {
            $area = \defined('AREA') ? AREA : 'A';
            return (bool) Tygh::$app['mailer']->send($config, $area);
        },
        translator: static fn (string $code, array $args): string => (string) __($code, $args),
    );

    $currency        = (string) ($order_info['secondary_currency'] ?? CART_PRIMARY_CURRENCY);
    $formattedAmount = (string) fn_format_price($order_info['total'], $currency);

    return $sender->send($order_info, $payment_url, $formattedAmount);
}
