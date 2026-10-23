<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);
define('PAYMOS_EDD_TESTING', true);
define('PAYMOS_EDD_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);

// Any deprecation, notice or warning inside plugin code must fail the run:
// platform installers (Magento DI compile above all) escalate PHP 8.4+
// deprecations to fatals, and a silent one here is how rejections slip through.
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
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
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1) { return true; }
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1) { return true; }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
}
if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain($domain, $deprecated = false, $relative = false) { return true; }
}
if (!function_exists('__return_false')) {
    function __return_false() { return false; }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook()
    {
    }
}
function plugin_dir_path($file) { return dirname($file) . DIRECTORY_SEPARATOR; }
function plugin_basename($file) { return basename($file); }
function plugins_url($path = '', $file = '') { return 'https://shop.example.com/wp-content/plugins/paymos-easy-digital-downloads/' . ltrim((string) $path, '/'); }
function rest_url($path = '') { return 'https://shop.example.com/wp-json/' . ltrim((string) $path, '/'); }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['paymos_edd_options']) ? $GLOBALS['paymos_edd_options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['paymos_edd_options'][(string) $key] = $value; return true; }
function wp_salt($scheme = 'auth') { return 'paymos-edd-test-salt-' . (string) $scheme; }
function update_post_meta($paymentId, $key, $value) { if (isset($GLOBALS['paymos_edd_fail_meta_key']) && $GLOBALS['paymos_edd_fail_meta_key'] === $key) { return false; } unset($GLOBALS['paymos_edd_meta_cache'][(int) $paymentId]); $GLOBALS['paymos_edd_payment_meta'][(int) $paymentId][(string) $key] = $value; return true; }
function get_post_meta($paymentId, $key, $single = false) { return isset($GLOBALS['paymos_edd_payment_meta'][(int) $paymentId][(string) $key]) ? $GLOBALS['paymos_edd_payment_meta'][(int) $paymentId][(string) $key] : ''; }
function edd_get_option($key, $default = '') { $settings = get_option('edd_settings', array()); return is_array($settings) && array_key_exists($key, $settings) ? $settings[$key] : $default; }
function edd_get_currency() { return isset($GLOBALS['paymos_edd_currency']) ? $GLOBALS['paymos_edd_currency'] : 'USD'; }
function edd_get_errors() { return $GLOBALS['paymos_edd_errors']; }
function edd_set_error($code, $message) { $GLOBALS['paymos_edd_errors'][(string) $code] = (string) $message; }
function edd_send_back_to_checkout($query = '') { $GLOBALS['paymos_edd_locks_at_checkout_exit'][] = count($GLOBALS['wpdb']->locks); $GLOBALS['paymos_edd_redirects'][] = 'checkout' . (string) $query; }
function edd_empty_cart() { $GLOBALS['paymos_edd_cart_empty'] = true; }
function wp_redirect($url) { $GLOBALS['paymos_edd_locks_at_success_exit'][] = count($GLOBALS['wpdb']->locks); $GLOBALS['paymos_edd_redirects'][] = (string) $url; return true; }

function edd_insert_payment($paymentData)
{
    $id = !empty($GLOBALS['paymos_edd_resume_id']) ? $GLOBALS['paymos_edd_resume_id'] : $GLOBALS['paymos_edd_next_payment_id']++;
    $GLOBALS['paymos_edd_payments'][$id] = $paymentData;
    $GLOBALS['paymos_edd_payments'][$id]['id'] = $id;
    $GLOBALS['paymos_edd_payments'][$id]['status'] = isset($paymentData['status']) ? $paymentData['status'] : 'pending';
    return $id;
}

function edd_update_payment_meta($paymentId, $key, $value) { update_post_meta($paymentId, $key, $value); }
function edd_get_payment_meta($paymentId, $key, $single = true) {
    if (empty($GLOBALS['paymos_edd_cache_enabled'])) { return get_post_meta($paymentId, $key, $single); }
    if (!isset($GLOBALS['paymos_edd_meta_cache'][(int) $paymentId])) { $GLOBALS['paymos_edd_meta_cache'][(int) $paymentId] = isset($GLOBALS['paymos_edd_payment_meta'][(int) $paymentId]) ? $GLOBALS['paymos_edd_payment_meta'][(int) $paymentId] : array(); }
    return isset($GLOBALS['paymos_edd_meta_cache'][(int) $paymentId][$key]) ? $GLOBALS['paymos_edd_meta_cache'][(int) $paymentId][$key] : '';
}
// Mirror real EDD: the 'complete' status key is stored as 'publish'.
function edd_update_payment_status($paymentId, $status) { if (!empty($GLOBALS['paymos_edd_fail_status'])) { return false; } $status = (string) $status === 'complete' ? 'publish' : (string) $status; $GLOBALS['paymos_edd_payments'][(int) $paymentId]['status'] = $status; unset($GLOBALS['paymos_edd_row_cache'][(int) $paymentId]); if ($status === 'publish') { $GLOBALS['paymos_edd_completion_count']++; } return true; }
// Mirror real EDD: with $label=true the LOCALIZED label is returned, not the key
// (so a roll-back guard that compares the label is correctly caught by tests).
function edd_get_payment_status($paymentId, $label = false) {
    $row = paymos_edd_read_native_row($paymentId);
    $key = isset($row['status']) ? $row['status'] : '';
    if (!$label) { return $key; }
    $labels = array('publish' => 'Завершён', 'complete' => 'Завершён', 'pending' => 'В ожидании', 'failed' => 'Ошибка', 'abandoned' => 'Брошен');
    return isset($labels[$key]) ? $labels[$key] : $key;
}
function edd_get_payment_amount($paymentId) { $row = paymos_edd_read_native_row($paymentId); return isset($row['price']) ? $row['price'] : '0.00'; }
function edd_get_payment_currency_code($paymentId) { $row = paymos_edd_read_native_row($paymentId); return isset($row['currency']) ? $row['currency'] : 'USD'; }
function edd_get_payment_gateway($paymentId) { $row = paymos_edd_read_native_row($paymentId); return isset($row['gateway']) ? $row['gateway'] : ''; }
function edd_set_payment_transaction_id($paymentId, $transactionId) { $GLOBALS['paymos_edd_payments'][(int) $paymentId]['transaction_id'] = (string) $transactionId; }
function edd_insert_payment_note($paymentId, $note) { $GLOBALS['paymos_edd_payment_notes'][(int) $paymentId][] = (string) $note; return 1; }

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
    $GLOBALS['paymos_edd_currency'] = 'USD';
    $GLOBALS['paymos_edd_cache_enabled'] = false;
    $GLOBALS['paymos_edd_row_cache'] = array();
    $GLOBALS['paymos_edd_meta_cache'] = array();
    $GLOBALS['paymos_edd_cache_deletes'] = array();
    $GLOBALS['paymos_edd_fail_status'] = false;
    $GLOBALS['paymos_edd_fail_meta_key'] = null;
    $GLOBALS['paymos_edd_completion_count'] = 0;
    $GLOBALS['paymos_edd_resume_id'] = null;
    $GLOBALS['paymos_edd_locks_at_checkout_exit'] = array();
    $GLOBALS['paymos_edd_locks_at_success_exit'] = array();
    $GLOBALS['wpdb'] = new FakeEddWpdb();
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

class FakePaymosInvoices
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
            'payment_url' => 'https://checkout.paymos.io/pay/inv_123',
            'status' => 'awaiting_client',
        );
        $this->getResponse = $getResponse ?: array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'is_test' => true,
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

    public function cancel($invoiceId, $reason)
    {
        return array('invoice_id' => $invoiceId, 'status' => 'cancelled');
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

    public function __construct(?FakePaymosInvoices $invoices = null)
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
        'is_test' => true,
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


if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
function register_deactivation_hook() {}
function wp_next_scheduled($hook) { return false; }
function wp_schedule_event($time, $recurrence, $hook) { return true; }
function wp_clear_scheduled_hook($hook) { $GLOBALS['paymos_edd_cleared_cron'][] = array($GLOBALS['wpdb']->prefix, $hook); return true; }

/** Deterministic durable DB/connection double. SQL branches preserve WHERE ownership predicates. */
final class FakeEddWpdb
{
    public $prefix = 'wp_';
    public $rows = array();
    public $locks = array();
    public $connection = '41';
    public $fail = false;
    public $beforeInsert;
    public $queries = array();
    public function prepare($query, ...$args) { return serialize(array($query, $args)); }
    private function unpack($query) { return unserialize($query); }
    public function get_charset_collate() { return ''; }
    public function get_var($prepared)
    {
        list($sql, $args) = $this->unpack($prepared);
        if ($this->fail) { return null; }
        if (strpos($sql, 'GET_LOCK(') !== false) {
            if (isset($this->locks[$args[0]])) { return '0'; }
            $this->locks[$args[0]] = $this->connection;
            return $this->connection;
        }
        $owned = $this->connection === (string) $args[0]
            && isset($this->locks[$args[1]]) && $this->locks[$args[1]] === (string) $args[2];
        if (strpos($sql, 'RELEASE_LOCK') !== false && $owned) { unset($this->locks[$args[1]]); }
        return $owned ? '1' : '0';
    }
    public function get_row($prepared, $mode = null)
    {
        list($sql, $args) = $this->unpack($prepared);
        if ($this->fail) { return null; }
        if (strpos($sql, 'SHOW COLUMNS') === 0) { return array('Field' => 'owner_token'); }
        return isset($this->rows[$args[1]]) ? $this->rows[$args[1]] : null;
    }
    public function query($prepared)
    {
        list($sql, $a) = $this->unpack($prepared);
        $this->queries[] = array($sql, $a);
        if ($this->fail) { return false; }
        if (strpos($sql, 'DROP TABLE') === 0) { $GLOBALS['paymos_edd_dropped_tables'][] = $a[0]; return 0; }
        if (strpos($sql, 'CREATE TABLE') === 0 || strpos($sql, 'ALTER TABLE') === 0) { return 0; }
        if (strpos($sql, 'INSERT IGNORE') === 0) {
            if ($this->beforeInsert !== null) { $callback = $this->beforeInsert; $this->beforeInsert = null; $callback($this, $a); }
            if (isset($this->rows[$a[1]])) { return 0; }
            $this->rows[$a[1]] = array('event_hash'=>$a[1], 'event_id'=>$a[2], 'owner_token'=>$a[3], 'status'=>$a[4], 'expires_at'=>$a[5], 'created_at'=>$a[6], 'updated_at'=>$a[7]);
            return 1;
        }
        if (strpos($sql, 'DELETE FROM') === 0 && strpos($sql, 'LIMIT 100') !== false) {
            $count = 0;
            uasort($this->rows, static function ($x, $y) { return $x['expires_at'] <=> $y['expires_at']; });
            foreach ($this->rows as $hash => $row) { if ($row['expires_at'] <= $a[1] && $count < 100) { unset($this->rows[$hash]); $count++; } }
            return $count;
        }
        if (strpos($sql, 'DELETE FROM') === 0) {
            if (isset($this->rows[$a[1]]) && $this->rows[$a[1]]['owner_token'] === $a[2] && $this->rows[$a[1]]['status'] === $a[3]) { unset($this->rows[$a[1]]); return 1; }
            return 0;
        }
        if (strpos($sql, 'UPDATE') === 0 && strpos($sql, 'SET owner_token') !== false) {
            if (!isset($this->rows[$a[5]])) { return 0; }
            $r = $this->rows[$a[5]];
            if ($r['owner_token'] !== $a[6] || $r['status'] !== $a[7] || $r['expires_at'] !== $a[8] || $r['expires_at'] > $a[9]) { return 0; }
            $this->rows[$a[5]] = array_merge($r,array('owner_token'=>$a[1],'status'=>$a[2],'expires_at'=>$a[3],'updated_at'=>$a[4])); return 1;
        }
        if (strpos($sql, 'UPDATE') === 0) {
            if (!isset($this->rows[$a[4]])) { return 0; }
            $r = $this->rows[$a[4]];
            if ($r['owner_token'] !== $a[5] || $r['status'] !== $a[6] || $r['expires_at'] <= $a[7]) { return 0; }
            $this->rows[$a[4]] = array_merge($r,array('status'=>$a[1],'expires_at'=>$a[2],'updated_at'=>$a[3])); return 1;
        }
        throw new RuntimeException('Unhandled mock SQL: '.$sql);
    }
}
$GLOBALS['wpdb'] = new FakeEddWpdb();

function is_multisite() { return !empty($GLOBALS['paymos_edd_multisite']); }
function get_sites($args) { return array_slice(array(1,2), $args['offset'], $args['number']); }
function switch_to_blog($id) {
    $GLOBALS['paymos_edd_blogs'][$GLOBALS['paymos_edd_blog_id']] = $GLOBALS['paymos_edd_options'];
    $GLOBALS['paymos_edd_blog_stack'][] = $GLOBALS['paymos_edd_blog_id'];
    $GLOBALS['paymos_edd_blog_id'] = $id;
    $GLOBALS['paymos_edd_options'] = $GLOBALS['paymos_edd_blogs'][$id];
    $GLOBALS['wpdb']->prefix = $id === 1 ? 'wp_' : 'wp_'.$id.'_';
}
function restore_current_blog() {
    $current = $GLOBALS['paymos_edd_blog_id'];
    $previous = array_pop($GLOBALS['paymos_edd_blog_stack']);
    $GLOBALS['paymos_edd_blogs'][$current] = $GLOBALS['paymos_edd_options'];
    $GLOBALS['paymos_edd_blog_id'] = $previous;
    $GLOBALS['paymos_edd_options'] = $GLOBALS['paymos_edd_blogs'][$previous];
    $GLOBALS['wpdb']->prefix = $previous === 1 ? 'wp_' : 'wp_'.$previous.'_';
}

function EDD() { return (object) array('session'=>new FakeEddSession()); }
final class FakeEddSession { public function get($key) { return $key === 'edd_resume_payment' ? $GLOBALS['paymos_edd_resume_id'] : null; } }

/** Native persistence and cached row deliberately differ in freshness regressions. */
function paymos_edd_read_native_row($paymentId) {
    $id = (int) $paymentId;
    if (empty($GLOBALS['paymos_edd_cache_enabled'])) { return isset($GLOBALS['paymos_edd_payments'][$id]) ? $GLOBALS['paymos_edd_payments'][$id] : array(); }
    if (!isset($GLOBALS['paymos_edd_row_cache'][$id])) { $GLOBALS['paymos_edd_row_cache'][$id] = isset($GLOBALS['paymos_edd_payments'][$id]) ? $GLOBALS['paymos_edd_payments'][$id] : array(); }
    return $GLOBALS['paymos_edd_row_cache'][$id];
}
function wp_cache_delete($key, $group = '') {
    $GLOBALS['paymos_edd_cache_deletes'][] = array($key,$group);
    if ($group === 'edd-orders') { unset($GLOBALS['paymos_edd_row_cache'][(int) $key]); }
    if ($group === 'edd_order_meta') { unset($GLOBALS['paymos_edd_meta_cache'][(int) $key]); }
    return true;
}
function clean_post_cache($postId) { $GLOBALS['paymos_edd_cache_deletes'][] = array($postId,'legacy-post'); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
