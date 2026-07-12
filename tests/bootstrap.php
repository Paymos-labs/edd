<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);
define('PAYMOS_EDD_TESTING', true);
define('PAYMOS_EDD_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('PAYMOS_EDD_PLUGIN_FILE', PAYMOS_EDD_PLUGIN_DIR . 'paymos-easy-digital-downloads.php');

$GLOBALS['paymos_edd_options'] = array();
$GLOBALS['paymos_edd_payments'] = array();
$GLOBALS['paymos_edd_payment_meta'] = array();
$GLOBALS['paymos_edd_payment_notes'] = array();
$GLOBALS['paymos_edd_transients'] = array();
$GLOBALS['paymos_edd_redirects'] = array();
$GLOBALS['paymos_edd_errors'] = array();
$GLOBALS['paymos_edd_next_payment_id'] = 100;

spl_autoload_register(static function ($class) {
    $prefix = 'PaymosEasyDigitalDownloads\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        require PAYMOS_EDD_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
        return;
    }

    $sdkPrefix = 'Paymos\\';
    if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) === 0) {
        $relative = substr($class, strlen($sdkPrefix));
        $candidates = array(
            PAYMOS_EDD_PLUGIN_DIR . 'vendor/paymos/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
            dirname(dirname(__DIR__)) . '/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
        );
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                require $candidate;
                return;
            }
        }
    }
});

register_shutdown_function(static function () {
    paymos_edd_delete_generated_config();
});

class WP_REST_Request
{
    /** @var string */
    private $body;

    /** @var array<string, string> */
    private $headers;

    public function __construct($body, array $headers = array())
    {
        $this->body = (string) $body;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function get_body()
    {
        return $this->body;
    }

    public function get_header($name)
    {
        $key = strtolower((string) $name);
        return isset($this->headers[$key]) ? $this->headers[$key] : '';
    }
}

class WP_REST_Response
{
    /** @var mixed */
    public $data;

    /** @var int */
    public $status;

    public function __construct($data = null, $status = 200)
    {
        $this->data = $data;
        $this->status = (int) $status;
    }

    public function get_status()
    {
        return $this->status;
    }
}

function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return esc_html($text); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function wp_kses_post($text) { return (string) $text; }
function wp_json_encode($value) { return json_encode($value); }
function plugin_dir_path($file) { return dirname($file) . DIRECTORY_SEPARATOR; }
function plugin_basename($file) { return basename($file); }
function plugins_url($path = '', $file = '') { return 'https://shop.example.com/wp-content/plugins/paymos-easy-digital-downloads/' . ltrim((string) $path, '/'); }
function rest_url($path = '') { return 'https://shop.example.com/wp-json/' . ltrim((string) $path, '/'); }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['paymos_edd_options']) ? $GLOBALS['paymos_edd_options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['paymos_edd_options'][(string) $key] = $value; return true; }
function wp_salt($scheme = 'auth') { return 'paymos-edd-test-salt-' . (string) $scheme; }
function update_post_meta($paymentId, $key, $value) { $GLOBALS['paymos_edd_payment_meta'][(int) $paymentId][(string) $key] = $value; return true; }
function get_post_meta($paymentId, $key, $single = false) { return isset($GLOBALS['paymos_edd_payment_meta'][(int) $paymentId][(string) $key]) ? $GLOBALS['paymos_edd_payment_meta'][(int) $paymentId][(string) $key] : ''; }
function edd_get_option($key, $default = '') { $settings = get_option('edd_settings', array()); return is_array($settings) && array_key_exists($key, $settings) ? $settings[$key] : $default; }
function edd_get_currency() { return 'USD'; }
function edd_get_errors() { return $GLOBALS['paymos_edd_errors']; }
function edd_set_error($code, $message) { $GLOBALS['paymos_edd_errors'][(string) $code] = (string) $message; }
function edd_send_back_to_checkout($query = '') { $GLOBALS['paymos_edd_redirects'][] = 'checkout' . (string) $query; }
function edd_empty_cart() { $GLOBALS['paymos_edd_cart_empty'] = true; }
function wp_redirect($url) { $GLOBALS['paymos_edd_redirects'][] = (string) $url; return true; }

function edd_insert_payment($paymentData)
{
    $id = $GLOBALS['paymos_edd_next_payment_id']++;
    $GLOBALS['paymos_edd_payments'][$id] = $paymentData;
    $GLOBALS['paymos_edd_payments'][$id]['id'] = $id;
    $GLOBALS['paymos_edd_payments'][$id]['status'] = isset($paymentData['status']) ? $paymentData['status'] : 'pending';
    return $id;
}

function edd_update_payment_meta($paymentId, $key, $value) { update_post_meta($paymentId, $key, $value); }
function edd_get_payment_meta($paymentId, $key, $single = true) { return get_post_meta($paymentId, $key, $single); }
// Mirror real EDD: the 'complete' status key is stored as 'publish'.
function edd_update_payment_status($paymentId, $status) { $status = (string) $status === 'complete' ? 'publish' : (string) $status; $GLOBALS['paymos_edd_payments'][(int) $paymentId]['status'] = $status; }
// Mirror real EDD: with $label=true the LOCALIZED label is returned, not the key
// (so a roll-back guard that compares the label is correctly caught by tests).
function edd_get_payment_status($paymentId, $label = false) {
    $key = isset($GLOBALS['paymos_edd_payments'][(int) $paymentId]['status']) ? $GLOBALS['paymos_edd_payments'][(int) $paymentId]['status'] : '';
    if (!$label) { return $key; }
    $labels = array('publish' => 'Завершён', 'complete' => 'Завершён', 'pending' => 'В ожидании', 'failed' => 'Ошибка', 'abandoned' => 'Брошен');
    return isset($labels[$key]) ? $labels[$key] : $key;
}
function edd_get_payment_amount($paymentId) { return isset($GLOBALS['paymos_edd_payments'][(int) $paymentId]['price']) ? $GLOBALS['paymos_edd_payments'][(int) $paymentId]['price'] : '0.00'; }
function edd_get_payment_currency_code($paymentId) { return isset($GLOBALS['paymos_edd_payments'][(int) $paymentId]['currency']) ? $GLOBALS['paymos_edd_payments'][(int) $paymentId]['currency'] : 'USD'; }
function edd_set_payment_transaction_id($paymentId, $transactionId) { $GLOBALS['paymos_edd_payments'][(int) $paymentId]['transaction_id'] = (string) $transactionId; }
function edd_insert_payment_note($paymentId, $note) { $GLOBALS['paymos_edd_payment_notes'][(int) $paymentId][] = (string) $note; }

function get_transient($key) { return isset($GLOBALS['paymos_edd_transients'][(string) $key]) ? $GLOBALS['paymos_edd_transients'][(string) $key] : false; }
function set_transient($key, $value, $expiration = 0) { $GLOBALS['paymos_edd_transients'][(string) $key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['paymos_edd_transients'][(string) $key]); return true; }
function add_option($key, $value = '', $deprecated = '', $autoload = 'yes') { if (array_key_exists((string) $key, $GLOBALS['paymos_edd_transients'])) { return false; } $GLOBALS['paymos_edd_transients'][(string) $key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['paymos_edd_options'][(string) $key], $GLOBALS['paymos_edd_transients'][(string) $key]); return true; }

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue($actual, $message)
{
    if ($actual !== true) {
        throw new RuntimeException($message . ' Expected true, got ' . var_export($actual, true));
    }
}

function assertContainsValue($needle, $haystack, $message)
{
    if (strpos((string) $haystack, (string) $needle) === false) {
        throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

function paymos_edd_reset_test_state()
{
    $GLOBALS['paymos_edd_options'] = array('edd_settings' => array('paymos_mode' => 'sandbox'));
    $GLOBALS['paymos_edd_payments'] = array();
    $GLOBALS['paymos_edd_payment_meta'] = array();
    $GLOBALS['paymos_edd_payment_notes'] = array();
    $GLOBALS['paymos_edd_transients'] = array();
    $GLOBALS['paymos_edd_redirects'] = array();
    $GLOBALS['paymos_edd_errors'] = array();
    $GLOBALS['paymos_edd_next_payment_id'] = 100;
    PaymosEasyDigitalDownloads\Config::reset_for_tests();
    PaymosEasyDigitalDownloads\Gateway::set_client_factory_for_tests(null);
    PaymosEasyDigitalDownloads\WebhookController::set_client_factory_for_tests(null);
}

function paymos_edd_delete_generated_config()
{
    if (class_exists('PaymosEasyDigitalDownloads\\Config')) {
        PaymosEasyDigitalDownloads\Config::use_config_for_tests(array());
    }
}

function paymos_edd_write_generated_config($php)
{
    $config = eval('return ' . $php . ';');
    PaymosEasyDigitalDownloads\Config::use_config_for_tests(is_array($config) ? $config : array());
}

function paymos_edd_purchase(array $overrides = array())
{
    return array_merge(array(
        'price' => '100.00',
        'date' => '2026-06-16 12:00:00',
        'user_email' => 'buyer@example.com',
        'purchase_key' => 'abc123',
        'downloads' => array(),
        'cart_details' => array(),
        'user_info' => array('id' => 77, 'email' => 'buyer@example.com'),
        'post_data' => array('edd-gateway' => 'paymos'),
    ), $overrides);
}

function paymos_edd_invoice_event($eventId, $eventType, $status, array $overrides = array())
{
    return array_replace_recursive(array(
        'event_id' => $eventId,
        'event_type' => $eventType,
        'version' => 1,
        'occurred_at' => 1781600000,
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => $status,
            'is_test' => true,
            'order' => array(
                'external_id' => 'edd_100_abc123',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        ),
    ), $overrides);
}

function paymos_edd_signed_header($secret, $body, $timestamp = null)
{
    $timestamp = $timestamp === null ? time() : (int) $timestamp;
    return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', (string) $timestamp . '.' . (string) $body, (string) $secret);
}

final class FakePaymosInvoices
{
    /** @var array<int, array<string, mixed>> */
    public $payloads = array();

    /** @var array<string, mixed> */
    private $createResponse;

    /** @var array<string, mixed> */
    private $getResponse;

    public function __construct(array $createResponse = array(), array $getResponse = array())
    {
        $this->createResponse = $createResponse ?: array(
            'invoice_id' => 'inv_123',
            'payment_url' => 'https://paymos.test/pay/inv_123',
            'status' => 'awaiting_client',
        );
        $this->getResponse = $getResponse ?: array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array(
                'external_id' => 'edd_100_abc123',
                // Server trims trailing zeros on the wire ("100.00" -> "100");
                // the snapshot is "100.00". Reverse-verify must treat them equal.
                'amount' => '100',
                'currency' => 'USD',
            ),
        );
    }

    public function create(array $payload)
    {
        $this->payloads[] = $payload;
        return $this->createResponse;
    }

    public function get($invoiceId)
    {
        return $this->getResponse;
    }
}

final class FakePaymosClient
{
    /** @var FakePaymosInvoices */
    public $invoices;

    public function __construct(FakePaymosInvoices $invoices = null)
    {
        $this->invoices = $invoices ?: new FakePaymosInvoices();
    }

    public function invoices()
    {
        return $this->invoices;
    }
}

function paymos_edd_reverse_verify_client(array $invoice = array())
{
    $invoice = $invoice ?: array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'paid',
        'order' => array(
            'external_id' => 'edd_100_abc123',
            // Server-trimmed amount ("100"), snapshot is "100.00" — must match.
            'amount' => '100',
            'currency' => 'USD',
        ),
    );

    return new Paymos\Client(
        new Paymos\ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test', 30),
        new Paymos\Http\MockTransport(array(
            new Paymos\Http\HttpResponse(200, json_encode($invoice), array('content-type' => 'application/json')),
        ))
    );
}
