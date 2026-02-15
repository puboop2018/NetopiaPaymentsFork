<?php
/**
 * NETOPIA Payments helper functions for CS-Cart.
 *
 * Implements NETOPIA v2 API communication, JWT verification for IPN,
 * and country code conversion utilities.
 *
 * @package NetopiaPayments
 */

if (!defined('BOOTSTRAP')) { die('Access denied'); }

use Tygh\Registry;

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
 * @return string PEM key content, or empty string if not available
 */
function fn_netopia_load_key(array $processor_params, string $key_type, int $payment_id): string
{
    // Priority 1: uploaded file
    $file_field = $key_type . '_file';
    if (!empty($processor_params[$file_field])) {
        $keys_dir = fn_netopia_get_keys_dir($payment_id);
        $file_path = $keys_dir . $processor_params[$file_field];
        if (file_exists($file_path) && is_readable($file_path)) {
            return trim(file_get_contents($file_path));
        }
    }

    // Priority 2: textarea content
    return trim($processor_params[$key_type] ?? '');
}

/**
 * Hook: handle file uploads when a NETOPIA payment method is saved.
 *
 * Processes uploaded public_key and private_key files, validates them,
 * stores them securely in the addon's keys directory, and updates
 * the processor_params with the stored file names.
 *
 * @param array $payment_data  Payment method data being saved
 * @param int   $payment_id    Payment method ID
 * @param string $lang_code    Language code
 */
function fn_netopia_payments_update_payment_post(array $payment_data, int $payment_id, string $lang_code = ''): void
{
    // Only process if this is a NETOPIA payment processor
    if (empty($payment_data['processor_id'])) {
        return;
    }

    $processor_info = db_get_row('SELECT * FROM ?:payment_processors WHERE processor_id = ?i', $payment_data['processor_id']);
    if (empty($processor_info) || $processor_info['processor_script'] !== 'netopia_payments.php') {
        return;
    }

    $keys_dir = fn_netopia_get_keys_dir($payment_id);
    $updated = false;
    $params = [];

    // Get current processor params
    $payment_row = db_get_row('SELECT processor_params FROM ?:payments WHERE payment_id = ?i', $payment_id);
    if (!empty($payment_row['processor_params'])) {
        $params = unserialize($payment_row['processor_params']);
        if (!is_array($params)) {
            $params = [];
        }
    }

    foreach (['public_key' => 'netopia_public_key_file', 'private_key' => 'netopia_private_key_file'] as $key_type => $file_input_name) {
        if (empty($_FILES[$file_input_name]['name']) || $_FILES[$file_input_name]['error'] !== UPLOAD_ERR_OK) {
            continue;
        }

        $upload = $_FILES[$file_input_name];

        // Validate file extension
        $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['pem', 'key', 'cer', 'crt', 'pub', 'txt'];
        if (!in_array($ext, $allowed_extensions, true)) {
            fn_set_notification('W', __('warning'), __('netopia_key_invalid_extension', ['[ext]' => $ext]));
            continue;
        }

        // Validate file size (max 64KB - keys should be small)
        if ($upload['size'] > 65536) {
            fn_set_notification('W', __('warning'), __('netopia_key_too_large'));
            continue;
        }

        // Read and validate content looks like a PEM key
        $content = file_get_contents($upload['tmp_name']);
        if ($content === false || empty(trim($content))) {
            fn_set_notification('W', __('warning'), __('netopia_key_empty'));
            continue;
        }

        // Create directory if needed
        if (!is_dir($keys_dir)) {
            fn_mkdir($keys_dir);
        }

        // Secure the directory
        fn_netopia_secure_keys_dir($keys_dir);

        // Save the file with a clean name
        $safe_filename = $key_type . '.' . $ext;
        $dest_path = $keys_dir . $safe_filename;

        if (move_uploaded_file($upload['tmp_name'], $dest_path)) {
            chmod($dest_path, 0640);
            $params[$key_type . '_file'] = $safe_filename;

            // Also populate the textarea param with the file content for runtime use
            $params[$key_type] = trim($content);
            $updated = true;

            fn_set_notification('N', __('notice'), __('netopia_key_uploaded_' . $key_type));
        } else {
            fn_set_notification('W', __('warning'), __('netopia_key_upload_failed'));
        }
    }

    // Handle deletion requests
    foreach (['public_key', 'private_key'] as $key_type) {
        if (!empty($_POST['delete_netopia_' . $key_type])) {
            $file_field = $key_type . '_file';
            if (!empty($params[$file_field])) {
                $file_to_delete = $keys_dir . $params[$file_field];
                if (file_exists($file_to_delete)) {
                    unlink($file_to_delete);
                }
                unset($params[$file_field]);
                $params[$key_type] = '';
                $updated = true;
            }
        }
    }

    if ($updated) {
        db_query('UPDATE ?:payments SET processor_params = ?s WHERE payment_id = ?i', serialize($params), $payment_id);
    }
}

/**
 * Write security files (.htaccess, index.html) to prevent direct web access to keys.
 *
 * @param string $dir Directory to secure
 */
function fn_netopia_secure_keys_dir(string $dir): void
{
    $htaccess = $dir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n");
    }

    $index = $dir . 'index.html';
    if (!file_exists($index)) {
        file_put_contents($index, '');
    }
}

/**
 * ISO 3166-1 alpha-2 to numeric country code mapping.
 * NETOPIA API requires numeric country codes.
 */
function fn_netopia_get_country_numeric_code(string $alpha2): int
{
    $map = [
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

    return $map[strtoupper($alpha2)] ?? 642;
}

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
    $base_url = $is_live
        ? 'https://secure.netopia-payments.com/api/'
        : 'https://secure-sandbox.netopia-payments.com/';

    $url = rtrim($base_url, '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $json_body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $api_key,
            'Content-Type: application/json',
        ],
    ]);

    $result    = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error     = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return [
            'status'  => 0,
            'code'    => 0,
            'message' => 'cURL error: ' . $error,
            'data'    => null,
        ];
    }

    $data = json_decode($result, true);

    return [
        'status'  => ($http_code === 200) ? 1 : 0,
        'code'    => $http_code,
        'message' => ($http_code === 200) ? 'OK' : ('HTTP ' . $http_code),
        'data'    => $data,
    ];
}

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

    // Determine currency
    $currency = 'RON';
    if (!empty($processor_params['currency'])) {
        if ($processor_params['currency'] === 'order_currency') {
            $currency = $order_info['secondary_currency'] ?? $order_info['secondary_currency'];
        } else {
            $currency = $processor_params['currency'];
        }
    }

    // Convert amount to the selected currency
    $amount = (float) $order_info['total'];
    if ($currency !== $order_info['secondary_currency']) {
        $amount = fn_format_price_by_currency($order_info['total'], CART_PRIMARY_CURRENCY, $currency);
    }

    $billing_country = fn_netopia_get_country_numeric_code($order_info['b_country'] ?? 'RO');
    $shipping_country = fn_netopia_get_country_numeric_code($order_info['s_country'] ?? $order_info['b_country'] ?? 'RO');

    // Build product list
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

    // Installments configuration
    $installments_selected = max(1, $installments);
    $installments_available = [0];
    if ($installments_selected > 1) {
        $installments_available = range(2, $installments_selected);
        array_unshift($installments_available, 0);
    }

    $payload = [
        'config' => [
            'emailTemplate' => 'confirm',
            'notifyUrl'     => $notify_url,
            'redirectUrl'   => $redirect_url,
            'language'      => 'RO',
        ],
        'payment' => [
            'options' => [
                'installments' => $installments_selected,
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
        'order' => [
            'ntpID'        => null,
            'posSignature' => (string) $processor_params['pos_signature'],
            'dateTime'     => date('c'),
            'description'  => 'Order #' . $order_info['order_id'],
            'orderID'      => (string) $order_info['order_id'],
            'amount'       => $amount,
            'currency'     => $currency,
            'billing' => [
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
            'products'     => $products,
            'installments' => [
                'selected'  => $installments_selected,
                'available' => $installments_available,
            ],
            'data' => null,
        ],
    ];

    return json_encode($payload);
}

/**
 * Collect 3D Secure browser fingerprint data from server-side variables and POST data.
 *
 * @return array 3DS device data for the NETOPIA API
 */
function fn_netopia_get_3ds_data(): array
{
    return [
        'BROWSER_USER_AGENT'    => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'OS'                    => php_uname('s'),
        'OS_VERSION'            => php_uname('r'),
        'MOBILE'                => (isset($_POST['netopia_mobile']) && $_POST['netopia_mobile'] === 'true') ? 'true' : 'false',
        'SCREEN_POINT'          => $_POST['netopia_screen_point'] ?? 'false',
        'SCREEN_PRINT'          => $_POST['netopia_screen_print'] ?? 'Current Resolution: 1920x1080',
        'BROWSER_COLOR_DEPTH'   => $_POST['netopia_color_depth'] ?? '24',
        'BROWSER_SCREEN_HEIGHT' => $_POST['netopia_screen_height'] ?? '1080',
        'BROWSER_SCREEN_WIDTH'  => $_POST['netopia_screen_width'] ?? '1920',
        'BROWSER_PLUGINS'       => $_POST['netopia_plugins'] ?? '',
        'BROWSER_JAVA_ENABLED'  => $_POST['netopia_java_enabled'] ?? 'false',
        'BROWSER_LANGUAGE'      => $_POST['netopia_language'] ?? ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en-US'),
        'BROWSER_TZ'            => $_POST['netopia_tz'] ?? 'Europe/Bucharest',
        'BROWSER_TZ_OFFSET'     => $_POST['netopia_tz_offset'] ?? '0',
        'IP_ADDRESS'            => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ];
}

/**
 * Verify a NETOPIA IPN callback by validating the JWT signature.
 *
 * Implements JWT RS512 verification without requiring firebase/php-jwt,
 * using PHP's built-in OpenSSL functions.
 *
 * @param string $public_key_pem   NETOPIA's public key in PEM format
 * @param string $pos_signature    Merchant POS signature
 * @return array{verified: bool, payload: array|null, error: string}
 */
function fn_netopia_verify_ipn(string $public_key_pem, string $pos_signature): array
{
    // Get the Verification-Token from HTTP headers
    $verification_token = null;
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Verification-Token') === 0) {
                $verification_token = $value;
                break;
            }
        }
    }
    if ($verification_token === null) {
        $server_key = 'HTTP_VERIFICATION_TOKEN';
        $verification_token = $_SERVER[$server_key] ?? null;
    }

    if (empty($verification_token)) {
        return ['verified' => false, 'payload' => null, 'error' => 'Missing Verification-Token header'];
    }

    // Split JWT into parts
    $parts = explode('.', $verification_token);
    if (count($parts) !== 3) {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid JWT format'];
    }

    [$header_b64, $payload_b64, $signature_b64] = $parts;

    // Decode header
    $header = json_decode(fn_netopia_base64url_decode($header_b64), true);
    if (!$header || ($header['typ'] ?? '') !== 'JWT') {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid JWT type'];
    }

    $alg = $header['alg'] ?? 'RS512';
    $openssl_alg = match ($alg) {
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
        default => OPENSSL_ALGO_SHA512,
    };

    // Verify signature
    $public_key = openssl_pkey_get_public($public_key_pem);
    if ($public_key === false) {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid public key'];
    }

    $data_to_verify = $header_b64 . '.' . $payload_b64;
    $signature = fn_netopia_base64url_decode($signature_b64);

    $verify_result = openssl_verify($data_to_verify, $signature, $public_key, $openssl_alg);
    if ($verify_result !== 1) {
        return ['verified' => false, 'payload' => null, 'error' => 'JWT signature verification failed'];
    }

    // Decode JWT payload (claims)
    $jwt_claims = json_decode(fn_netopia_base64url_decode($payload_b64), true);
    if (!$jwt_claims) {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid JWT payload'];
    }

    // Verify issuer
    if (($jwt_claims['iss'] ?? '') !== 'NETOPIA Payments') {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid JWT issuer'];
    }

    // Verify audience matches POS signature
    $aud = $jwt_claims['aud'] ?? '';
    if (is_array($aud)) {
        $aud = $aud[0] ?? '';
    }
    if ($aud !== $pos_signature) {
        return ['verified' => false, 'payload' => null, 'error' => 'JWT audience mismatch'];
    }

    // Verify payload integrity (subject = hash of raw POST body)
    $raw_post = file_get_contents('php://input');
    $payload_hash = base64_encode(hash('sha512', $raw_post, true));
    if (($jwt_claims['sub'] ?? '') !== $payload_hash) {
        return ['verified' => false, 'payload' => null, 'error' => 'Payload integrity check failed'];
    }

    // Decode IPN payload from raw POST body
    $ipn_data = json_decode($raw_post, true);
    if (!$ipn_data) {
        return ['verified' => false, 'payload' => null, 'error' => 'Invalid IPN payload JSON'];
    }

    return ['verified' => true, 'payload' => $ipn_data, 'error' => ''];
}

/**
 * Base64url decode (JWT-compatible).
 */
function fn_netopia_base64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Map NETOPIA payment status code to CS-Cart order status.
 *
 * @param int $netopia_status NETOPIA payment status code
 * @return string CS-Cart order status letter
 */
function fn_netopia_map_order_status(int $netopia_status): string
{
    return match ($netopia_status) {
        3, 5    => 'P',  // Paid / Confirmed → Processed
        1       => 'O',  // New → Open
        2, 6,
        14, 15,
        18      => 'O',  // Opened/Pending/PendingAuth/3DAuth/PendingAny → Open
        4       => 'I',  // Canceled → Canceled (Incomplete)
        8       => 'I',  // Credit/Refund → Canceled
        11, 12  => 'F',  // Error/Declined → Failed
        13      => 'F',  // Fraud → Failed
        17      => 'I',  // Reversed → Canceled
        23      => 'F',  // Expired → Failed
        default => 'O',  // Default → Open
    };
}

/**
 * Build the JSON payload for NETOPIA VerifyAuth request.
 *
 * @param string $authentication_token Token from start payment response
 * @param string $ntp_id               NETOPIA transaction ID
 * @param string $pa_res               payer authentication response from 3DS
 * @return string JSON-encoded request body
 */
function fn_netopia_build_verify_auth_request(string $authentication_token, string $ntp_id, string $pa_res): string
{
    return json_encode([
        'authenticationToken' => $authentication_token,
        'ntpID'               => $ntp_id,
        'formData'            => [
            'paRes' => $pa_res,
        ],
    ]);
}

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
    return json_encode([
        'posID'   => $pos_signature,
        'ntpID'   => $ntp_id,
        'orderID' => $order_id,
    ]);
}
