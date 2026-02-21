<?php
/**
 * NETOPIA Payments helper functions for CS-Cart.
 *
 * Implements NETOPIA v2 API communication, JWT verification for IPN,
 * and country code conversion utilities.
 *
 * @package NetopiaPayments
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

use Tygh\Registry;
use Tygh\Tygh;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/** Maximum allowed key file size in bytes (64 KB). */
const NETOPIA_MAX_KEY_FILE_SIZE = 65536;

/** File permissions for uploaded key files (owner read+write, group read). */
const NETOPIA_KEY_FILE_PERMISSIONS = 0640;

/** cURL request timeout in seconds. */
const NETOPIA_API_TIMEOUT = 30;

/** NETOPIA error code: 3D Secure authentication required. */
const NETOPIA_ERROR_CODE_3DS = '100';

/** NETOPIA error code: redirect to hosted payment page. */
const NETOPIA_ERROR_CODE_HOSTED_PAGE = '101';

/** NETOPIA status: 3D Secure authentication required. */
const NETOPIA_STATUS_3DS_REQUIRED = 15;

/** NETOPIA status: payment captured. */
const NETOPIA_STATUS_PAID = 3;

/** NETOPIA status: payment confirmed (after IPN). */
const NETOPIA_STATUS_CONFIRMED = 5;

/** Default country code (Romania, ISO 3166-1 numeric). */
const NETOPIA_DEFAULT_COUNTRY_CODE = 642;

/** Maximum JWT token size in bytes (10 KB) — prevents DoS. */
const NETOPIA_MAX_JWT_TOKEN_SIZE = 10240;

/** Maximum allowed length for 3DS browser fingerprint string values. */
const NETOPIA_MAX_3DS_FIELD_LENGTH = 512;

// ---------------------------------------------------------------------------
// Key management
// ---------------------------------------------------------------------------

/**
 * Returns the directory path where NETOPIA key files are stored for a given payment method.
 *
 * @param int $payment_id CS-Cart payment method ID
 * @return string Absolute path to the keys directory
 */
function fn_netopia_get_keys_dir(int $payment_id): string
{
    return Registry::get('config.dir.addons') . 'netopia_payments/keys/' . $payment_id . '/';
}

/**
 * Load a NETOPIA key from file or from processor_params textarea fallback.
 *
 * Checks for an uploaded key file first. If not found, falls back to the
 * content stored in the processor_params textarea.
 *
 * @param array  $processor_params Payment processor params
 * @param string $key_type         'public_key' or 'private_key'
 * @param int    $payment_id       CS-Cart payment method ID
 * @param string $mode             'live' or 'sandbox' (auto-detected if empty)
 * @return string PEM key content, or empty string if not available
 */
function fn_netopia_load_key(array $processor_params, string $key_type, int $payment_id, string $mode = ''): string
{
    if (empty($mode)) {
        $mode = (!empty($processor_params['mode']) && $processor_params['mode'] === 'live') ? 'live' : 'sandbox';
    }
    $env_key  = $mode . '_' . $key_type;
    $env_file = $env_key . '_file';

    $keys_dir = fn_netopia_get_keys_dir($payment_id);

    // Priority 1: environment-specific uploaded file
    $content = fn_netopia_read_key_file($keys_dir, $processor_params[$env_file] ?? '');
    if ($content !== '') {
        return $content;
    }

    // Priority 2: environment-specific textarea content
    if (!empty($processor_params[$env_key])) {
        return trim($processor_params[$env_key]);
    }

    // Priority 3: legacy non-prefixed uploaded file (backward compat)
    $legacy_file = $key_type . '_file';
    $content = fn_netopia_read_key_file($keys_dir, $processor_params[$legacy_file] ?? '');
    if ($content !== '') {
        return $content;
    }

    // Priority 4: legacy non-prefixed textarea
    return trim($processor_params[$key_type] ?? '');
}

/**
 * Read a key file from the keys directory with path traversal protection.
 *
 * @param string $keys_dir Directory containing key files
 * @param string $filename Filename to read (basename-protected)
 * @return string File content, or empty string if not available
 */
function fn_netopia_read_key_file(string $keys_dir, string $filename): string
{
    if (empty($filename)) {
        return '';
    }

    // Prevent path traversal by using only the basename
    $safe_name = basename($filename);
    $file_path = $keys_dir . $safe_name;

    if (file_exists($file_path) && is_readable($file_path)) {
        $content = file_get_contents($file_path);
        if ($content !== false) {
            return trim($content);
        }
    }

    return '';
}

// ---------------------------------------------------------------------------
// Key upload hook
// ---------------------------------------------------------------------------

/**
 * Hook: handle file uploads when a NETOPIA payment method is saved.
 *
 * Processes uploaded public_key and private_key files, validates them,
 * stores them securely in the addon's keys directory, and updates
 * the processor_params with the stored file names.
 *
 * @param array  $payment_data Payment method data being saved
 * @param int    $payment_id   Payment method ID
 * @param string $lang_code    Language code
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

    $keys_dir = fn_netopia_get_keys_dir($payment_id);
    $updated = false;
    $params = fn_netopia_load_processor_params($payment_id);

    $key_slots = [
        'sandbox_public_key'  => 'netopia_sandbox_public_key_file',
        'sandbox_private_key' => 'netopia_sandbox_private_key_file',
        'live_public_key'     => 'netopia_live_public_key_file',
        'live_private_key'    => 'netopia_live_private_key_file',
    ];

    // Handle file uploads
    foreach ($key_slots as $param_key => $file_input_name) {
        $result = fn_netopia_process_key_upload($file_input_name, $param_key, $keys_dir, $params);
        if ($result !== null) {
            $params = $result;
            $updated = true;
        }
    }

    // Handle deletion requests
    foreach (array_keys($key_slots) as $param_key) {
        if (!empty($_POST['delete_netopia_' . $param_key])) {
            $file_field = $param_key . '_file';
            if (!empty($params[$file_field])) {
                $file_to_delete = $keys_dir . basename($params[$file_field]);
                if (file_exists($file_to_delete) && !unlink($file_to_delete)) {
                    fn_set_notification('W', __('warning'), __('netopia_key_delete_failed'));
                }
                unset($params[$file_field]);
                $params[$param_key] = '';
                $updated = true;
            }
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
 * Process a single key file upload: validate, store, and update params.
 *
 * @return array<string, mixed>|null Updated params array on success, null if no upload
 */
function fn_netopia_process_key_upload(string $file_input_name, string $param_key, string $keys_dir, array $params): ?array
{
    if (empty($_FILES[$file_input_name]['name']) || $_FILES[$file_input_name]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $upload = $_FILES[$file_input_name];

    // Validate file extension
    $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['pem', 'key', 'cer', 'crt', 'pub', 'txt'];
    if (!in_array($ext, $allowed_extensions, true)) {
        fn_set_notification('W', __('warning'), __('netopia_key_invalid_extension', ['[ext]' => $ext]));
        return null;
    }

    // Validate file size
    if ($upload['size'] > NETOPIA_MAX_KEY_FILE_SIZE) {
        fn_set_notification('W', __('warning'), __('netopia_key_too_large'));
        return null;
    }

    // Read and validate content
    $content = file_get_contents($upload['tmp_name']);
    if ($content === false || empty(trim($content))) {
        fn_set_notification('W', __('warning'), __('netopia_key_empty'));
        return null;
    }

    // Sanitize filename (whitelist approach, prevent path traversal)
    $safe_name = basename($upload['name']);
    $safe_name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $safe_name);
    if (!preg_match('/^[a-zA-Z0-9._\-]+\.(pem|key|cer|crt|pub|txt)$/', $safe_name)) {
        fn_set_notification('W', __('warning'), __('netopia_key_invalid_extension', ['[ext]' => $ext]));
        return null;
    }

    // Create directory if needed
    if (!is_dir($keys_dir)) {
        fn_mkdir($keys_dir);
        if (!is_dir($keys_dir)) {
            fn_set_notification('W', __('warning'), __('netopia_key_upload_failed'));
            return null;
        }
    }

    fn_netopia_secure_keys_dir($keys_dir);

    // Remove old file for this key slot
    $file_field = $param_key . '_file';
    if (!empty($params[$file_field])) {
        $old_file = $keys_dir . basename($params[$file_field]);
        if (file_exists($old_file)) {
            unlink($old_file);
        }
    }

    $dest_path = $keys_dir . $safe_name;

    if (move_uploaded_file($upload['tmp_name'], $dest_path)) {
        if (!chmod($dest_path, NETOPIA_KEY_FILE_PERMISSIONS)) {
            fn_set_notification('W', __('warning'), 'File uploaded but permissions could not be set.');
        }
        $params[$file_field] = $safe_name;
        $params[$param_key] = trim($content);

        fn_set_notification('N', __('notice'), __('netopia_key_uploaded', ['[key]' => $param_key]));
        return $params;
    }

    fn_set_notification('W', __('warning'), __('netopia_key_upload_failed'));
    return null;
}

/**
 * Write security files (.htaccess, index.html) to prevent direct web access to keys.
 */
function fn_netopia_secure_keys_dir(string $dir): void
{
    $htaccess = $dir . '.htaccess';
    if (!file_exists($htaccess)) {
        $written = file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n");
        if ($written === false) {
            fn_log_event('general', 'runtime', ['message' => 'NETOPIA: Failed to create .htaccess in keys directory']);
        }
    }

    $index = $dir . 'index.html';
    if (!file_exists($index)) {
        file_put_contents($index, '');
    }
}

// ---------------------------------------------------------------------------
// Country code mapping
// ---------------------------------------------------------------------------

/**
 * ISO 3166-1 alpha-2 to numeric country code mapping.
 * NETOPIA API requires numeric country codes.
 *
 * @param string $alpha2 Two-letter country code (e.g. 'RO')
 * @return int Numeric ISO 3166-1 country code (defaults to Romania 642)
 */
function fn_netopia_get_country_numeric_code(string $alpha2): int
{
    static $map = [
        'AF' => 4, 'AL' => 8, 'DZ' => 12, 'AS' => 16, 'AD' => 20, 'AO' => 24,
        'AG' => 28, 'AR' => 32, 'AM' => 51, 'AU' => 36, 'AT' => 40, 'AZ' => 31,
        'BS' => 44, 'BH' => 48, 'BD' => 50, 'BB' => 52, 'BY' => 112, 'BE' => 56,
        'BZ' => 84, 'BJ' => 204, 'BT' => 64, 'BO' => 68, 'BA' => 70, 'BW' => 72,
        'BR' => 76, 'BN' => 96, 'BG' => 100, 'BF' => 854, 'BI' => 108, 'KH' => 116,
        'CM' => 120, 'CA' => 124, 'CV' => 132, 'CF' => 140, 'TD' => 148, 'CL' => 152,
        'CN' => 156, 'CO' => 170, 'KM' => 174, 'CG' => 178, 'CD' => 180, 'CR' => 188,
        'CI' => 384, 'HR' => 191, 'CU' => 192, 'CY' => 196, 'CZ' => 203, 'DK' => 208,
        'DJ' => 262, 'DM' => 212, 'DO' => 214, 'EC' => 218, 'EG' => 818, 'SV' => 222,
        'GQ' => 226, 'ER' => 232, 'EE' => 233, 'ET' => 231, 'FJ' => 242, 'FI' => 246,
        'FR' => 250, 'GA' => 266, 'GM' => 270, 'GE' => 268, 'DE' => 276, 'GH' => 288,
        'GR' => 300, 'GD' => 308, 'GT' => 320, 'GN' => 324, 'GW' => 624, 'GY' => 328,
        'HT' => 332, 'HN' => 340, 'HK' => 344, 'HU' => 348, 'IS' => 352, 'IN' => 356,
        'ID' => 360, 'IR' => 364, 'IQ' => 368, 'IE' => 372, 'IL' => 376, 'IT' => 380,
        'JM' => 388, 'JP' => 392, 'JO' => 400, 'KZ' => 398, 'KE' => 404, 'KI' => 296,
        'KP' => 408, 'KR' => 410, 'KW' => 414, 'KG' => 417, 'LA' => 418, 'LV' => 428,
        'LB' => 422, 'LS' => 426, 'LR' => 430, 'LY' => 434, 'LI' => 438, 'LT' => 440,
        'LU' => 442, 'MO' => 446, 'MK' => 807, 'MG' => 450, 'MW' => 454, 'MY' => 458,
        'MV' => 462, 'ML' => 466, 'MT' => 470, 'MH' => 584, 'MR' => 478, 'MU' => 480,
        'MX' => 484, 'FM' => 583, 'MD' => 498, 'MC' => 492, 'MN' => 496, 'ME' => 499,
        'MA' => 504, 'MZ' => 508, 'MM' => 104, 'NA' => 516, 'NR' => 520, 'NP' => 524,
        'NL' => 528, 'NZ' => 554, 'NI' => 558, 'NE' => 562, 'NG' => 566, 'NO' => 578,
        'OM' => 512, 'PK' => 586, 'PW' => 585, 'PS' => 275, 'PA' => 591, 'PG' => 598,
        'PY' => 600, 'PE' => 604, 'PH' => 608, 'PL' => 616, 'PT' => 620, 'QA' => 634,
        'RO' => 642, 'RU' => 643, 'RW' => 646, 'KN' => 659, 'LC' => 662, 'VC' => 670,
        'WS' => 882, 'SM' => 674, 'ST' => 678, 'SA' => 682, 'SN' => 686, 'RS' => 688,
        'SC' => 690, 'SL' => 694, 'SG' => 702, 'SK' => 703, 'SI' => 705, 'SB' => 90,
        'SO' => 706, 'ZA' => 710, 'ES' => 724, 'LK' => 144, 'SD' => 729, 'SR' => 740,
        'SZ' => 748, 'SE' => 752, 'CH' => 756, 'SY' => 760, 'TW' => 158, 'TJ' => 762,
        'TZ' => 834, 'TH' => 764, 'TL' => 626, 'TG' => 768, 'TO' => 776, 'TT' => 780,
        'TN' => 788, 'TR' => 792, 'TM' => 795, 'TV' => 798, 'UG' => 800, 'UA' => 804,
        'AE' => 784, 'GB' => 826, 'US' => 840, 'UY' => 858, 'UZ' => 860, 'VU' => 548,
        'VE' => 862, 'VN' => 704, 'YE' => 887, 'ZM' => 894, 'ZW' => 716,
    ];

    return $map[strtoupper($alpha2)] ?? NETOPIA_DEFAULT_COUNTRY_CODE;
}

// ---------------------------------------------------------------------------
// API communication
// ---------------------------------------------------------------------------

/**
 * Send an HTTP request to the NETOPIA API.
 *
 * @param string $endpoint  API endpoint path (e.g. 'payment/card/start')
 * @param string $json_body JSON-encoded request body
 * @param string $api_key   Merchant API key
 * @param bool   $is_live   True for production, false for sandbox
 * @return array{status: int, code: int, message: string, data: mixed}
 */
function fn_netopia_api_request(string $endpoint, string $json_body, string $api_key, bool $is_live = false): array
{
    if (empty($api_key)) {
        return [
            'status'  => 0,
            'code'    => 0,
            'message' => 'API key is not configured.',
            'data'    => null,
        ];
    }

    $base_url = $is_live
        ? 'https://secure.netopia-payments.com/api/'
        : 'https://secure-sandbox.netopia-payments.com/';

    $url = rtrim($base_url, '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'status'  => 0,
            'code'    => 0,
            'message' => 'Failed to initialize HTTP client.',
            'data'    => null,
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $json_body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => NETOPIA_API_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $api_key,
            'Content-Type: application/json',
        ],
    ]);

    $result    = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error     = curl_error($ch);
    curl_close($ch);

    if ($result === false || $error !== '') {
        return [
            'status'  => 0,
            'code'    => 0,
            'message' => 'Connection error occurred.',
            'data'    => null,
        ];
    }

    $data = json_decode($result, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return [
            'status'  => 0,
            'code'    => $http_code,
            'message' => 'Invalid JSON response from NETOPIA.',
            'data'    => null,
        ];
    }

    return [
        'status'  => ($http_code === 200) ? 1 : 0,
        'code'    => $http_code,
        'message' => ($http_code === 200) ? 'OK' : ('HTTP ' . $http_code),
        'data'    => $data,
    ];
}

// ---------------------------------------------------------------------------
// Payment request building — shared helpers to eliminate duplication
// ---------------------------------------------------------------------------

/**
 * Determine the payment currency and convert the order amount.
 *
 * @return array{currency: string, amount: float}
 */
function fn_netopia_resolve_currency_and_amount(array $processor_params, array $order_info): array
{
    $currency = 'RON';
    if (!empty($processor_params['currency'])) {
        if ($processor_params['currency'] === 'order_currency') {
            $currency = $order_info['secondary_currency'] ?? CART_PRIMARY_CURRENCY;
        } else {
            $currency = $processor_params['currency'];
        }
    }

    $order_currency = $order_info['secondary_currency'] ?? CART_PRIMARY_CURRENCY;
    $amount = (float) $order_info['total'];
    if ($currency !== $order_currency) {
        $amount = fn_format_price_by_currency($order_info['total'], CART_PRIMARY_CURRENCY, $currency);
    }

    return ['currency' => $currency, 'amount' => $amount];
}

/**
 * Build the product list from order info.
 *
 * @return array<int, array<string, mixed>>
 */
function fn_netopia_build_product_list(array $order_info, float $amount): array
{
    $products = [];
    if (!empty($order_info['products'])) {
        foreach ($order_info['products'] as $product) {
            $products[] = [
                'name'     => (string) ($product['product'] ?? 'Product'),
                'code'     => (string) ($product['product_code'] ?? $product['product_id'] ?? ''),
                'category' => 'General',
                'price'    => (float) ($product['price'] ?? 0),
                'vat'      => 0,
            ];
        }
    }
    if (empty($products)) {
        $products[] = [
            'name'     => 'Order #' . $order_info['order_id'],
            'code'     => (string) $order_info['order_id'],
            'category' => 'General',
            'price'    => $amount,
            'vat'      => 0,
        ];
    }

    return $products;
}

/**
 * Build installments configuration.
 *
 * @return array{selected: int, available: array<int>}
 */
function fn_netopia_build_installments(int $installments): array
{
    $selected = max(1, $installments);
    $available = [0];
    if ($selected > 1) {
        $available = range(2, $selected);
        array_unshift($available, 0);
    }

    return ['selected' => $selected, 'available' => $available];
}

/**
 * Build the common order section of the payment request payload.
 *
 * @return array<string, mixed>
 */
function fn_netopia_build_order_section(
    array $processor_params,
    array $order_info,
    string $currency,
    float $amount,
    int $installments
): array {
    $billing_country = fn_netopia_get_country_numeric_code($order_info['b_country'] ?? 'RO');
    $shipping_country = fn_netopia_get_country_numeric_code($order_info['s_country'] ?? $order_info['b_country'] ?? 'RO');

    return [
        'ntpID'        => null,
        'posSignature' => (string) $processor_params['pos_signature'],
        'dateTime'     => date('c'),
        'description'  => 'Order #' . $order_info['order_id'],
        'orderID'      => (string) $order_info['order_id'],
        'amount'       => $amount,
        'currency'     => $currency,
        'billing'      => [
            'email'      => (string) ($order_info['email'] ?? ''),
            'phone'      => (string) ($order_info['b_phone'] ?? $order_info['phone'] ?? ''),
            'firstName'  => (string) ($order_info['b_firstname'] ?? ''),
            'lastName'   => (string) ($order_info['b_lastname'] ?? ''),
            'city'       => (string) ($order_info['b_city'] ?? ''),
            'country'    => $billing_country,
            'state'      => (string) ($order_info['b_state_descr'] ?? $order_info['b_state'] ?? ''),
            'postalCode' => (string) ($order_info['b_zipcode'] ?? ''),
            'details'    => (string) ($order_info['b_address'] ?? '') . ' ' . ($order_info['b_address_2'] ?? ''),
        ],
        'shipping' => [
            'email'      => (string) ($order_info['email'] ?? ''),
            'phone'      => (string) ($order_info['s_phone'] ?? $order_info['phone'] ?? ''),
            'firstName'  => (string) ($order_info['s_firstname'] ?? $order_info['b_firstname'] ?? ''),
            'lastName'   => (string) ($order_info['s_lastname'] ?? $order_info['b_lastname'] ?? ''),
            'city'       => (string) ($order_info['s_city'] ?? $order_info['b_city'] ?? ''),
            'country'    => $shipping_country,
            'state'      => (string) ($order_info['s_state_descr'] ?? $order_info['s_state'] ?? $order_info['b_state'] ?? ''),
            'postalCode' => (string) ($order_info['s_zipcode'] ?? $order_info['b_zipcode'] ?? ''),
            'details'    => (string) ($order_info['s_address'] ?? $order_info['b_address'] ?? '') . ' ' . ($order_info['s_address_2'] ?? ''),
        ],
        'products'     => fn_netopia_build_product_list($order_info, $amount),
        'installments' => fn_netopia_build_installments($installments),
        'data'         => null,
    ];
}

// ---------------------------------------------------------------------------
// Payment request builders
// ---------------------------------------------------------------------------

/**
 * Build the JSON payload for NETOPIA Start payment request.
 *
 * @param array $processor_params Processor configuration from admin
 * @param array $order_info       CS-Cart order information
 * @param array $three_ds_data    3D Secure browser fingerprint data
 * @param int   $installments     Number of installments (1 = no installments)
 * @return string JSON-encoded request body
 */
function fn_netopia_build_start_request(array $processor_params, array $order_info, array $three_ds_data, int $installments = 1): string
{
    $notify_url  = fn_url('payment_notification.notify?payment=netopia_payments', AREA, 'current');
    $redirect_url = fn_url('payment_notification.return?payment=netopia_payments', AREA, 'current');
    $resolved = fn_netopia_resolve_currency_and_amount($processor_params, $order_info);

    $payload = [
        'config' => [
            'emailTemplate' => 'confirm',
            'notifyUrl'     => $notify_url,
            'redirectUrl'   => $redirect_url,
            'language'      => 'RO',
        ],
        'payment' => [
            'options' => [
                'installments' => max(1, $installments),
                'bonus'        => 0,
            ],
            'instrument' => [
                'type'       => 'card',
                'account'    => (string) ($order_info['payment_info']['card_number'] ?? ''),
                'expMonth'   => (int) ($order_info['payment_info']['expiry_month'] ?? 0),
                'expYear'    => (int) ($order_info['payment_info']['expiry_year'] ?? 0),
                'secretCode' => (string) ($order_info['payment_info']['cvv2'] ?? ''),
                'token'      => null,
            ],
            'data' => $three_ds_data,
        ],
        'order' => fn_netopia_build_order_section(
            $processor_params, $order_info,
            $resolved['currency'], $resolved['amount'],
            $installments
        ),
    ];

    return (string) json_encode($payload);
}

/**
 * Collect 3D Secure browser fingerprint data from server-side variables and POST data.
 *
 * Values are sanitized and length-limited to prevent abuse.
 *
 * @return array<string, string>
 */
function fn_netopia_get_3ds_data(): array
{
    return [
        'BROWSER_USER_AGENT'    => fn_netopia_sanitize_3ds_field($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'),
        'OS'                    => php_uname('s'),
        'OS_VERSION'            => php_uname('r'),
        'MOBILE'                => (isset($_POST['netopia_mobile']) && $_POST['netopia_mobile'] === 'true') ? 'true' : 'false',
        'SCREEN_POINT'          => fn_netopia_sanitize_3ds_field($_POST['netopia_screen_point'] ?? 'false'),
        'SCREEN_PRINT'          => fn_netopia_sanitize_3ds_field($_POST['netopia_screen_print'] ?? 'Current Resolution: 1920x1080'),
        'BROWSER_COLOR_DEPTH'   => fn_netopia_sanitize_3ds_field($_POST['netopia_color_depth'] ?? '24'),
        'BROWSER_SCREEN_HEIGHT' => fn_netopia_sanitize_3ds_field($_POST['netopia_screen_height'] ?? '1080'),
        'BROWSER_SCREEN_WIDTH'  => fn_netopia_sanitize_3ds_field($_POST['netopia_screen_width'] ?? '1920'),
        'BROWSER_PLUGINS'       => fn_netopia_sanitize_3ds_field($_POST['netopia_plugins'] ?? ''),
        'BROWSER_JAVA_ENABLED'  => fn_netopia_sanitize_3ds_field($_POST['netopia_java_enabled'] ?? 'false'),
        'BROWSER_LANGUAGE'      => fn_netopia_sanitize_3ds_field($_POST['netopia_language'] ?? ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en-US')),
        'BROWSER_TZ'            => fn_netopia_sanitize_3ds_field($_POST['netopia_tz'] ?? 'Europe/Bucharest'),
        'BROWSER_TZ_OFFSET'     => fn_netopia_sanitize_3ds_field($_POST['netopia_tz_offset'] ?? '0'),
        'IP_ADDRESS'            => fn_netopia_sanitize_ip($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
    ];
}

/**
 * Sanitize a 3DS browser fingerprint field value.
 */
function fn_netopia_sanitize_3ds_field(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
    return substr($value, 0, NETOPIA_MAX_3DS_FIELD_LENGTH);
}

/**
 * Sanitize and validate an IP address string.
 */
function fn_netopia_sanitize_ip(string $ip): string
{
    $filtered = filter_var($ip, FILTER_VALIDATE_IP);
    return $filtered !== false ? $filtered : '127.0.0.1';
}

// ---------------------------------------------------------------------------
// Callback handlers (IPN & 3DS return)
// ---------------------------------------------------------------------------

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
        fn_netopia_ipn_response(2, 3, 'Order not found');
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
        fn_log_event('general', 'runtime', [
            'message' => 'NETOPIA IPN verification failed for order ' . $order_id . ': ' . $result['error'],
        ]);
        fn_netopia_ipn_response(2, 6, 'IPN verification failed: ' . $result['error']);
        return;
    }

    $ipn_data = $result['payload'];
    $ntp_status = (int) ($ipn_data['payment']['status'] ?? 0);
    $ntp_id     = (string) ($ipn_data['payment']['ntpID'] ?? '');
    $amount     = (float) ($ipn_data['payment']['amount'] ?? 0);

    // Idempotency: skip if order is already in a final state matching this IPN
    $cs_status = fn_netopia_map_order_status($ntp_status, $params);
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

    $pa_res = $_POST['paRes'] ?? '';

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

    if (($error_code === '0' || $error_code === '00') && ($ntp_status === NETOPIA_STATUS_PAID || $ntp_status === NETOPIA_STATUS_CONFIRMED)) {
        // 3DS verification successful, payment approved
        $pp_response = [
            'order_status'   => fn_netopia_map_order_status($ntp_status, $params),
            'reason_text'    => 'Payment approved after 3D Secure. NTP ID: ' . $verify_ntp,
            'transaction_id' => $verify_ntp,
        ];
    } else {
        // 3DS verification failed or payment not approved
        $error_msg = $verify_data['error']['message'] ?? 'Verification failed';
        $pp_response = [
            'order_status'   => fn_netopia_map_order_status($ntp_status, $params),
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

// ---------------------------------------------------------------------------
// IPN verification
// ---------------------------------------------------------------------------

/**
 * Verify a NETOPIA IPN callback by validating the JWT signature.
 *
 * @param string $public_key_pem   NETOPIA's public key in PEM format
 * @param string $pos_signature    Merchant POS signature
 * @param string $raw_post_body    Raw POST body
 * @return array{verified: bool, payload: array|null, error: string}
 */
function fn_netopia_verify_ipn(string $public_key_pem, string $pos_signature, string $raw_post_body): array
{
    $verification_token = fn_netopia_extract_verification_token();
    if ($verification_token === null) {
        return ['verified' => false, 'payload' => null, 'error' => 'Missing Verification-Token header'];
    }

    if (strlen($verification_token) > NETOPIA_MAX_JWT_TOKEN_SIZE) {
        return ['verified' => false, 'payload' => null, 'error' => 'JWT token exceeds maximum allowed size'];
    }

    $parts = explode('.', $verification_token);
    if (count($parts) !== 3) {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid JWT format'];
    }

    [$header_b64, $payload_b64, $signature_b64] = $parts;

    $header = json_decode(fn_netopia_base64url_decode($header_b64), true);
    if ($header === null || ($header['typ'] ?? '') !== 'JWT') {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid JWT type'];
    }

    $alg = $header['alg'] ?? 'RS512';
    $openssl_alg = match ($alg) {
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
        default => OPENSSL_ALGO_SHA512,
    };

    $public_key = openssl_pkey_get_public($public_key_pem);
    if ($public_key === false) {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid public key'];
    }

    $data_to_verify = $header_b64 . '.' . $payload_b64;
    $signature = fn_netopia_base64url_decode($signature_b64);
    if (openssl_verify($data_to_verify, $signature, $public_key, $openssl_alg) !== 1) {
        return ['verified' => false, 'payload' => null, 'error' => 'JWT signature verification failed'];
    }

    $jwt_claims = json_decode(fn_netopia_base64url_decode($payload_b64), true);
    if ($jwt_claims === null || json_last_error() !== JSON_ERROR_NONE) {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid JWT payload'];
    }

    // Use hash_equals for all security comparisons to prevent timing attacks
    if (!hash_equals('NETOPIA Payments', (string) ($jwt_claims['iss'] ?? ''))) {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid JWT issuer'];
    }

    $aud = $jwt_claims['aud'] ?? '';
    if (is_array($aud)) {
        $aud = $aud[0] ?? '';
    }
    if (!hash_equals($pos_signature, (string) $aud)) {
        return ['verified' => false, 'payload' => null, 'error' => 'JWT audience mismatch'];
    }

    $payload_hash = base64_encode(hash('sha512', $raw_post_body, true));
    if (!hash_equals($payload_hash, (string) ($jwt_claims['sub'] ?? ''))) {
        return ['verified' => false, 'payload' => null, 'error' => 'Payload integrity check failed'];
    }

    $ipn_data = json_decode($raw_post_body, true);
    if ($ipn_data === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid IPN payload JSON'];
    }

    return ['verified' => true, 'payload' => $ipn_data, 'error' => ''];
}

/**
 * Extract the Verification-Token from HTTP headers.
 */
function fn_netopia_extract_verification_token(): ?string
{
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Verification-Token') === 0) {
                return $value;
            }
        }
    }

    return $_SERVER['HTTP_VERIFICATION_TOKEN'] ?? null;
}

/**
 * Base64url decode (JWT-compatible).
 */
function fn_netopia_base64url_decode(string $data): string
{
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    return $decoded !== false ? $decoded : '';
}

// ---------------------------------------------------------------------------
// Status mapping
// ---------------------------------------------------------------------------

/**
 * Return NETOPIA status definitions: code, label, and default CS-Cart mapping.
 *
 * Accepts a dummy parameter so it can be called as a Smarty modifier:
 *   {$ntp_statuses = ""|fn_netopia_get_status_definitions}
 *
 * @param mixed $dummy Unused (required for Smarty modifier compatibility)
 * @return array<int, array{label: string, default: string, group: string}>
 */
function fn_netopia_get_status_definitions($dummy = null): array
{
    return [
        NETOPIA_STATUS_PAID      => ['label' => 'Paid',        'default' => 'P', 'group' => 'success'],
        NETOPIA_STATUS_CONFIRMED => ['label' => 'Confirmed',   'default' => 'P', 'group' => 'success'],
        1  => ['label' => 'New',         'default' => 'O', 'group' => 'pending'],
        2  => ['label' => 'Opened',      'default' => 'O', 'group' => 'pending'],
        6  => ['label' => 'Pending',     'default' => 'O', 'group' => 'pending'],
        14 => ['label' => 'PendingAuth', 'default' => 'O', 'group' => 'pending'],
        NETOPIA_STATUS_3DS_REQUIRED => ['label' => '3D Secure', 'default' => 'O', 'group' => 'pending'],
        18 => ['label' => 'PendingAny',  'default' => 'O', 'group' => 'pending'],
        4  => ['label' => 'Canceled',    'default' => 'I', 'group' => 'cancel'],
        8  => ['label' => 'Refund',      'default' => 'I', 'group' => 'cancel'],
        17 => ['label' => 'Reversed',    'default' => 'I', 'group' => 'cancel'],
        11 => ['label' => 'Error',       'default' => 'F', 'group' => 'fail'],
        12 => ['label' => 'Declined',    'default' => 'F', 'group' => 'fail'],
        13 => ['label' => 'Fraud',       'default' => 'F', 'group' => 'fail'],
        23 => ['label' => 'Expired',     'default' => 'F', 'group' => 'fail'],
    ];
}

/**
 * Map NETOPIA payment status code to CS-Cart order status.
 *
 * @param int   $netopia_status   NETOPIA payment status code
 * @param array $processor_params Processor configuration (optional, for custom mapping)
 * @return string CS-Cart order status letter
 */
function fn_netopia_map_order_status(int $netopia_status, array $processor_params = []): string
{
    $map_key = 'status_map_' . $netopia_status;
    if (!empty($processor_params[$map_key])) {
        return $processor_params[$map_key];
    }

    $definitions = fn_netopia_get_status_definitions();
    if (isset($definitions[$netopia_status])) {
        return $definitions[$netopia_status]['default'];
    }

    return 'O';
}

// ---------------------------------------------------------------------------
// Verify auth
// ---------------------------------------------------------------------------

/**
 * Build the JSON payload for NETOPIA VerifyAuth request.
 *
 * @param string $authentication_token Token from start payment response
 * @param string $ntp_id               NETOPIA transaction ID
 * @param string $pa_res               Payer authentication response from 3DS
 * @return string JSON-encoded request body
 */
function fn_netopia_build_verify_auth_request(string $authentication_token, string $ntp_id, string $pa_res): string
{
    return (string) json_encode([
        'authenticationToken' => $authentication_token,
        'ntpID'               => $ntp_id,
        'formData'            => [
            'paRes' => $pa_res,
        ],
    ]);
}

// ---------------------------------------------------------------------------
// Payment link
// ---------------------------------------------------------------------------

/**
 * Build the JSON payload for a NETOPIA payment link request.
 *
 * Uses the same /payment/card/start endpoint but with empty instrument fields.
 *
 * @param array $processor_params Processor configuration from admin
 * @param array $order_info       CS-Cart order information
 * @param int   $installments     Number of installments (1 = no installments)
 * @return string JSON-encoded request body
 */
function fn_netopia_build_payment_link_request(array $processor_params, array $order_info, int $installments = 1): string
{
    $notify_url  = fn_url('payment_notification.notify?payment=netopia_payments', AREA, 'current');
    $redirect_url = fn_url('payment_notification.return?payment=netopia_payments', AREA, 'current');
    $resolved = fn_netopia_resolve_currency_and_amount($processor_params, $order_info);

    $three_ds_data = [
        'BROWSER_USER_AGENT'    => fn_netopia_sanitize_3ds_field($_SERVER['HTTP_USER_AGENT'] ?? 'NETOPIA Payment Link'),
        'OS'                    => php_uname('s'),
        'OS_VERSION'            => php_uname('r'),
        'MOBILE'                => 'false',
        'SCREEN_POINT'          => 'false',
        'SCREEN_PRINT'          => 'Current Resolution: 1920x1080',
        'BROWSER_COLOR_DEPTH'   => '24',
        'BROWSER_SCREEN_HEIGHT' => '1080',
        'BROWSER_SCREEN_WIDTH'  => '1920',
        'BROWSER_PLUGINS'       => '',
        'BROWSER_JAVA_ENABLED'  => 'false',
        'BROWSER_LANGUAGE'      => 'en-US',
        'BROWSER_TZ'            => 'Europe/Bucharest',
        'BROWSER_TZ_OFFSET'     => '0',
        'IP_ADDRESS'            => fn_netopia_sanitize_ip($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
    ];

    $payload = [
        'config' => [
            'emailTemplate' => 'confirm',
            'notifyUrl'     => $notify_url,
            'redirectUrl'   => $redirect_url,
            'language'      => 'RO',
        ],
        'payment' => [
            'options' => [
                'installments' => max(1, $installments),
                'bonus'        => 0,
            ],
            'instrument' => [
                'type'       => 'card',
                'account'    => '',
                'expMonth'   => 0,
                'expYear'    => 0,
                'secretCode' => '',
                'token'      => null,
            ],
            'data' => $three_ds_data,
        ],
        'order' => fn_netopia_build_order_section(
            $processor_params, $order_info,
            $resolved['currency'], $resolved['amount'],
            $installments
        ),
    ];

    return (string) json_encode($payload);
}

/**
 * Generate a NETOPIA payment link for an order.
 *
 * @param int $order_id CS-Cart order ID
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

    $params = $processor_data['processor_params'];
    if (empty($params['pos_signature']) || empty($params['api_key'])) {
        return ['success' => false, 'payment_url' => '', 'error' => 'NETOPIA POS Signature or API Key missing.'];
    }

    $is_live = !empty($params['mode']) && $params['mode'] === 'live';
    $installments = 1;
    if (!empty($params['allow_installments']) && $params['allow_installments'] === 'Y') {
        $installments = (int) ($params['max_installments'] ?? 1);
    }

    $json_request = fn_netopia_build_payment_link_request($params, $order_info, $installments);
    $response = fn_netopia_api_request('payment/card/start', $json_request, $params['api_key'], $is_live);

    if ($response['status'] !== 1 || empty($response['data'])) {
        $error_message = $response['data']['message'] ?? $response['message'] ?? 'Connection error';
        return ['success' => false, 'payment_url' => '', 'error' => 'NETOPIA API error: ' . $error_message];
    }

    $data = $response['data'];
    $inner_data = $data['data'] ?? $data;
    $error_code = (string) ($inner_data['error']['code'] ?? $data['error']['code'] ?? '');
    $payment_data = $inner_data['payment'] ?? $data['payment'] ?? [];
    $payment_url = (string) ($payment_data['paymentURL'] ?? '');
    $ntp_id = (string) ($payment_data['ntpID'] ?? '');

    if ($error_code === NETOPIA_ERROR_CODE_HOSTED_PAGE && !empty($payment_url)) {
        fn_update_order_payment_info($order_id, [
            'netopia_ntp_id'          => $ntp_id,
            'netopia_payment_link'    => $payment_url,
            'netopia_payment_link_at' => date('c'),
            'transaction_id'          => $ntp_id,
        ]);
        fn_change_order_status($order_id, 'O', '', false);
        return ['success' => true, 'payment_url' => $payment_url, 'error' => ''];
    }

    $error_msg = $inner_data['error']['message'] ?? $data['error']['message'] ?? 'Unexpected response (code: ' . $error_code . ')';
    return ['success' => false, 'payment_url' => '', 'error' => $error_msg];
}

// ---------------------------------------------------------------------------
// Payment link email
// ---------------------------------------------------------------------------

/**
 * Send a payment link email to the customer for an order.
 *
 * @param int    $order_id    CS-Cart order ID
 * @param string $payment_url NETOPIA hosted payment page URL
 * @return bool True if email was sent successfully
 */
function fn_netopia_send_payment_link_email(int $order_id, string $payment_url): bool
{
    $order_info = fn_get_order_info($order_id);
    if (empty($order_info) || empty($order_info['email'])) {
        return false;
    }

    $customer_name = trim(($order_info['b_firstname'] ?? '') . ' ' . ($order_info['b_lastname'] ?? ''));
    if (empty($customer_name)) {
        $customer_name = $order_info['email'];
    }

    $company_name = Registry::get('settings.Company.company_name') ?: 'Our Store';
    $amount = fn_format_price($order_info['total'], $order_info['secondary_currency'] ?? CART_PRIMARY_CURRENCY);
    $currency = $order_info['secondary_currency'] ?? CART_PRIMARY_CURRENCY;

    $subject = __('netopia_payment_link_email_subject', ['[order_id]' => $order_id]);
    $body = __('netopia_payment_link_email_body', [
        '[customer_name]' => $customer_name,
        '[order_id]'      => $order_id,
        '[amount]'        => $amount . ' ' . $currency,
        '[payment_url]'   => $payment_url,
        '[company_name]'  => $company_name,
    ]);

    $mailer = Tygh::$app['mailer'];
    $result = $mailer->send([
        'to'      => $order_info['email'],
        'from'    => 'default_company_orders_department',
        'data'    => [
            'subject'     => $subject,
            'body'        => nl2br($body),
            'order_info'  => $order_info,
            'payment_url' => $payment_url,
        ],
        'template_code' => 'netopia_payment_link',
        'tpl'     => 'addons/netopia_payments/payment_link_email.tpl',
    ], 'A');

    if (!$result) {
        $result = $mailer->send([
            'to'      => $order_info['email'],
            'from'    => 'default_company_orders_department',
            'data'    => [],
            'subject' => $subject,
            'body'    => nl2br($body),
        ], 'A');
    }

    return (bool) $result;
}

// ---------------------------------------------------------------------------
// Status query
// ---------------------------------------------------------------------------

/**
 * Build the JSON payload for NETOPIA Status query.
 *
 * @param string $pos_signature Merchant POS signature
 * @param string $ntp_id        NETOPIA transaction ID
 * @param string $order_id      Merchant order ID
 * @return string JSON-encoded request body
 */
function fn_netopia_build_status_request(string $pos_signature, string $ntp_id, string $order_id): string
{
    return (string) json_encode([
        'posID'   => $pos_signature,
        'ntpID'   => $ntp_id,
        'orderID' => $order_id,
    ]);
}
