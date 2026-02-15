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
function fn_netopia_load_key(array $processor_params, string $key_type, int $payment_id, string $mode = ''): string
{
    // Determine the environment prefix (sandbox_ or live_)
    if (empty($mode)) {
        $mode = (!empty($processor_params['mode']) && $processor_params['mode'] === 'live') ? 'live' : 'sandbox';
    }
    $env_key = $mode . '_' . $key_type;          // e.g. "sandbox_public_key"
    $env_file = $env_key . '_file';               // e.g. "sandbox_public_key_file"

    $keys_dir = fn_netopia_get_keys_dir($payment_id);

    // Priority 1: environment-specific uploaded file
    if (!empty($processor_params[$env_file])) {
        $file_path = $keys_dir . $processor_params[$env_file];
        if (file_exists($file_path) && is_readable($file_path)) {
            return trim(file_get_contents($file_path));
        }
    }

    // Priority 2: environment-specific textarea content
    if (!empty($processor_params[$env_key])) {
        return trim($processor_params[$env_key]);
    }

    // Priority 3: legacy non-prefixed uploaded file (backward compat)
    $legacy_file = $key_type . '_file';
    if (!empty($processor_params[$legacy_file])) {
        $file_path = $keys_dir . $processor_params[$legacy_file];
        if (file_exists($file_path) && is_readable($file_path)) {
            return trim(file_get_contents($file_path));
        }
    }

    // Priority 4: legacy non-prefixed textarea
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
        $params = unserialize($payment_row['processor_params'], ['allowed_classes' => false]);
        if (!is_array($params)) {
            $params = [];
        }
    }

    // All 4 key slots: sandbox/live x public/private
    $key_slots = [
        'sandbox_public_key'  => 'netopia_sandbox_public_key_file',
        'sandbox_private_key' => 'netopia_sandbox_private_key_file',
        'live_public_key'     => 'netopia_live_public_key_file',
        'live_private_key'    => 'netopia_live_private_key_file',
    ];

    foreach ($key_slots as $param_key => $file_input_name) {
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

        // Read and validate content
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

        // Preserve original NETOPIA filename (e.g. sandbox.XXXX-XXXX.private.key)
        $original_name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $upload['name']);
        $file_field = $param_key . '_file';

        // Remove any old file for this key slot
        if (!empty($params[$file_field])) {
            $old_file = $keys_dir . $params[$file_field];
            if (file_exists($old_file)) {
                unlink($old_file);
            }
        }

        $dest_path = $keys_dir . $original_name;

        if (move_uploaded_file($upload['tmp_name'], $dest_path)) {
            chmod($dest_path, 0640);
            $params[$file_field] = $original_name;
            $params[$param_key] = trim($content);
            $updated = true;

            fn_set_notification('N', __('notice'), __('netopia_key_uploaded', ['[key]' => $param_key]));
        } else {
            fn_set_notification('W', __('warning'), __('netopia_key_upload_failed'));
        }
    }

    // Handle deletion requests for all 4 key slots
    foreach (array_keys($key_slots) as $param_key) {
        if (!empty($_POST['delete_netopia_' . $param_key])) {
            $file_field = $param_key . '_file';
            if (!empty($params[$file_field])) {
                $file_to_delete = $keys_dir . $params[$file_field];
                if (file_exists($file_to_delete)) {
                    unlink($file_to_delete);
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
            $currency = $order_info['secondary_currency'] ?? CART_PRIMARY_CURRENCY;
        } else {
            $currency = $processor_params['currency'];
        }
    }

    // Convert amount to the selected currency
    $order_currency = $order_info['secondary_currency'] ?? CART_PRIMARY_CURRENCY;
    $amount = (float) $order_info['total'];
    if ($currency !== $order_currency) {
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
 * @param string $raw_post_body    Raw POST body (read once, passed in to avoid double-read of php://input)
 * @return array{verified: bool, payload: array|null, error: string}
 */
function fn_netopia_verify_ipn(string $public_key_pem, string $pos_signature, string $raw_post_body): array
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
    $payload_hash = base64_encode(hash('sha512', $raw_post_body, true));
    if (($jwt_claims['sub'] ?? '') !== $payload_hash) {
        return ['verified' => false, 'payload' => null, 'error' => 'Payload integrity check failed'];
    }

    // Decode IPN payload from raw POST body
    $ipn_data = json_decode($raw_post_body, true);
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
 * Return NETOPIA status definitions: code, label, and default CS-Cart mapping.
 *
 * Used by both the admin template (to render dropdowns) and the mapping function.
 * Accepts a dummy parameter so it can be called as a Smarty modifier:
 *   {$ntp_statuses = ""|fn_netopia_get_status_definitions}
 *
 * @param mixed $dummy Unused (required for Smarty modifier compatibility)
 * @return array Array of [code => ['label' => string, 'default' => string, 'group' => string]]
 */
function fn_netopia_get_status_definitions($dummy = null): array
{
    return [
        3  => ['label' => 'Paid',        'default' => 'P', 'group' => 'success'],
        5  => ['label' => 'Confirmed',   'default' => 'P', 'group' => 'success'],
        1  => ['label' => 'New',         'default' => 'O', 'group' => 'pending'],
        2  => ['label' => 'Opened',      'default' => 'O', 'group' => 'pending'],
        6  => ['label' => 'Pending',     'default' => 'O', 'group' => 'pending'],
        14 => ['label' => 'PendingAuth', 'default' => 'O', 'group' => 'pending'],
        15 => ['label' => '3D Secure',   'default' => 'O', 'group' => 'pending'],
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
 * Checks the custom mapping in processor_params first (keys like "status_map_3"),
 * then falls back to built-in defaults.
 *
 * @param int   $netopia_status   NETOPIA payment status code
 * @param array $processor_params Processor configuration (optional, for custom mapping)
 * @return string CS-Cart order status letter
 */
function fn_netopia_map_order_status(int $netopia_status, array $processor_params = []): string
{
    // Check custom mapping from admin configuration
    $map_key = 'status_map_' . $netopia_status;
    if (!empty($processor_params[$map_key])) {
        return $processor_params[$map_key];
    }

    // Fall back to built-in defaults
    $definitions = fn_netopia_get_status_definitions();
    if (isset($definitions[$netopia_status])) {
        return $definitions[$netopia_status]['default'];
    }

    return 'O'; // Unknown status → Open
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
 * Build the JSON payload for a NETOPIA payment link request.
 *
 * Uses the same /payment/card/start endpoint but with empty instrument fields,
 * which causes NETOPIA to return error code 101 with a paymentURL for a
 * hosted payment page the customer can use to pay.
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

    // Determine currency
    $currency = 'RON';
    if (!empty($processor_params['currency'])) {
        if ($processor_params['currency'] === 'order_currency') {
            $currency = $order_info['secondary_currency'] ?? CART_PRIMARY_CURRENCY;
        } else {
            $currency = $processor_params['currency'];
        }
    }

    // Convert amount to the selected currency
    $order_currency = $order_info['secondary_currency'] ?? CART_PRIMARY_CURRENCY;
    $amount = (float) $order_info['total'];
    if ($currency !== $order_currency) {
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

    // 3DS data — use server defaults since this is an admin-initiated request
    $three_ds_data = [
        'BROWSER_USER_AGENT'    => $_SERVER['HTTP_USER_AGENT'] ?? 'NETOPIA Payment Link',
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
        'IP_ADDRESS'            => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
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
                'installments' => $installments_selected,
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
 * Generate a NETOPIA payment link for an order.
 *
 * Calls /payment/card/start with empty instrument fields to get a hosted
 * payment page URL. Stores the payment link and NTP ID in order payment info.
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

    // Determine installments
    $installments = 1;
    if (!empty($params['allow_installments']) && $params['allow_installments'] === 'Y') {
        $installments = (int) ($params['max_installments'] ?? 1);
    }

    // Build the payment link request (empty instrument)
    $json_request = fn_netopia_build_payment_link_request($params, $order_info, $installments);

    // Send to NETOPIA API
    $response = fn_netopia_api_request('payment/card/start', $json_request, $params['api_key'], $is_live);

    if ($response['status'] !== 1 || empty($response['data'])) {
        $error_message = $response['data']['message'] ?? $response['message'] ?? 'Connection error';
        return ['success' => false, 'payment_url' => '', 'error' => 'NETOPIA API error: ' . $error_message];
    }

    $data = $response['data'];

    // Handle nested data structure (API may wrap in data.data)
    $inner_data = $data['data'] ?? $data;
    $error_code = (string) ($inner_data['error']['code'] ?? $data['error']['code'] ?? '');
    $payment_data = $inner_data['payment'] ?? $data['payment'] ?? [];
    $payment_url = (string) ($payment_data['paymentURL'] ?? '');
    $ntp_id = (string) ($payment_data['ntpID'] ?? '');

    // Error code 101 = "Redirect user to payment page" — this is the expected response
    if ($error_code === '101' && !empty($payment_url)) {
        // Store payment link info in order payment info
        $payment_info_update = [
            'netopia_ntp_id'          => $ntp_id,
            'netopia_payment_link'    => $payment_url,
            'netopia_payment_link_at' => date('c'),
            'transaction_id'          => $ntp_id,
        ];
        fn_update_order_payment_info($order_id, $payment_info_update);

        // Set order to Open status while awaiting payment
        fn_change_order_status($order_id, 'O', '', false);

        return ['success' => true, 'payment_url' => $payment_url, 'error' => ''];
    }

    // If we got a different response, report it
    $error_msg = $inner_data['error']['message'] ?? $data['error']['message'] ?? 'Unexpected response (code: ' . $error_code . ')';
    return ['success' => false, 'payment_url' => '', 'error' => $error_msg];
}

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

    // Use CS-Cart's mailer
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

    // Fallback: if template-based sending fails, try simple mail
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
