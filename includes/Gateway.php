<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Exception\ApiException;

defined('ABSPATH') || exit;

final class Gateway
{
    public const ID = 'paymos';

    /** @var callable|null */
    private static $clientFactory;

    /**
     * @param array<string, mixed> $gateways
     * @return array<string, mixed>
     */
    public static function register_gateway($gateways)
    {
        if (!is_array($gateways)) {
            $gateways = array();
        }

        $gateways[self::ID] = array(
            'admin_label' => __('Paymos', 'paymos-easy-digital-downloads'),
            'checkout_label' => self::option('paymos_title', __('Pay with stablecoins', 'paymos-easy-digital-downloads')),
            'icons' => array(plugins_url('assets/img/paymos.svg', PAYMOS_EDD_PLUGIN_FILE)),
            'supports' => array(),
        );

        return $gateways;
    }

    /**
     * @param array<string, string> $icons
     * @return array<string, string>
     */
    public static function accepted_payment_icons($icons)
    {
        if (!is_array($icons)) {
            $icons = array();
        }

        $icons[plugins_url('assets/img/paymos.svg', PAYMOS_EDD_PLUGIN_FILE)] = 'Paymos';
        return $icons;
    }

    /**
     * @param array<string, string> $sections
     * @return array<string, string>
     */
    public static function register_settings_section($sections)
    {
        if (!is_array($sections)) {
            $sections = array();
        }

        $sections[self::ID] = __('Paymos', 'paymos-easy-digital-downloads');
        return $sections;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function register_settings($settings)
    {
        if (!is_array($settings)) {
            $settings = array();
        }

        $settings[self::ID] = array(
            'paymos_header' => array(
                'id' => 'paymos_header',
                'name' => '<strong>' . __('Paymos settings', 'paymos-easy-digital-downloads') . '</strong>',
                'desc' => __('Sandbox and live credentials are bundled in your generated package. Only display preferences and the active mode are stored here.', 'paymos-easy-digital-downloads'),
                'type' => 'header',
            ),
            'paymos_title' => array(
                'id' => 'paymos_title',
                'name' => __('Checkout label', 'paymos-easy-digital-downloads'),
                'desc' => __('Shown to customers on EDD checkout.', 'paymos-easy-digital-downloads'),
                'type' => 'text',
                'std' => __('Pay with stablecoins', 'paymos-easy-digital-downloads'),
            ),
            'paymos_mode' => array(
                'id' => 'paymos_mode',
                'name' => __('Mode', 'paymos-easy-digital-downloads'),
                'desc' => __('Switch to Live after you finish sandbox testing.', 'paymos-easy-digital-downloads'),
                'type' => 'select',
                'options' => array(
                    'sandbox' => __('Sandbox', 'paymos-easy-digital-downloads'),
                    'live' => __('Live', 'paymos-easy-digital-downloads'),
                ),
                'std' => 'sandbox',
            ),
            'paymos_webhook_url' => array(
                'id' => 'paymos_webhook_url',
                'name' => __('Webhook URL', 'paymos-easy-digital-downloads'),
                'desc' => '<code>' . esc_html(Config::webhook_url()) . '</code><br>' . esc_html__('Registered automatically with your Paymos project when you generate the plugin.', 'paymos-easy-digital-downloads'),
                'type' => 'descriptive_text',
            ),
            'paymos_config_status' => array(
                'id' => 'paymos_config_status',
                'name' => __('Generated config', 'paymos-easy-digital-downloads'),
                'desc' => wp_kses_post(self::config_status_html()),
                'type' => 'descriptive_text',
            ),
            'paymos_debug_logging' => array(
                'id' => 'paymos_debug_logging',
                'name' => __('Debug logging', 'paymos-easy-digital-downloads'),
                'desc' => __('Write redacted Paymos logs to PHP error_log.', 'paymos-easy-digital-downloads'),
                'type' => 'checkbox',
            ),
        );

        return $settings;
    }

    /**
     * @param array<string, mixed> $purchaseData
     */
    public static function process_payment($purchaseData)
    {
        if (function_exists('edd_get_errors') && edd_get_errors()) {
            self::sendBackToCheckout($purchaseData);
            return;
        }

        $amount = self::formatAmount(isset($purchaseData['price']) ? $purchaseData['price'] : '0');
        $currency = function_exists('edd_get_currency') ? (string) edd_get_currency() : 'USD';
        $paymentData = array(
            'price' => $amount,
            'date' => isset($purchaseData['date']) ? $purchaseData['date'] : gmdate('Y-m-d H:i:s'),
            'user_email' => isset($purchaseData['user_email']) ? $purchaseData['user_email'] : '',
            'purchase_key' => isset($purchaseData['purchase_key']) ? $purchaseData['purchase_key'] : '',
            'currency' => $currency,
            'downloads' => isset($purchaseData['downloads']) ? $purchaseData['downloads'] : array(),
            'cart_details' => isset($purchaseData['cart_details']) ? $purchaseData['cart_details'] : array(),
            'user_info' => isset($purchaseData['user_info']) ? $purchaseData['user_info'] : array(),
            'gateway' => self::ID,
            'status' => 'pending',
        );

        $paymentId = edd_insert_payment($paymentData);
        if (!$paymentId) {
            edd_set_error('paymos_payment_record_failed', __('Paymos payment error: payment could not be recorded.', 'paymos-easy-digital-downloads'));
            self::sendBackToCheckout($purchaseData);
            return;
        }

        try {
            $environment = Config::mode();
            $config = self::activeEnvironmentConfig($environment);
            $externalOrderId = self::externalOrderId((int) $paymentId, (string) $paymentData['purchase_key']);
            $payload = array(
                'project_id' => (string) $config['project_id'],
                'amount' => $amount,
                'currency' => $currency,
                'external_order_id' => $externalOrderId,
            );

            $clientId = self::clientId($purchaseData);
            if ($clientId !== '') {
                $payload['client_id'] = $clientId;
            }

            $invoice = self::client($config, $environment)->invoices()->create($payload);
        } catch (ApiException $e) {
            Logger::error('Paymos invoice create failed: ' . $e->getMessage(), array('payment_id' => (string) $paymentId));
            PaymentRepository::updateStatus((int) $paymentId, 'failed');
            edd_set_error('paymos_invoice_failed', __('Paymos payment error: unable to create invoice.', 'paymos-easy-digital-downloads'));
            self::sendBackToCheckout($purchaseData);
            return;
        } catch (\RuntimeException $e) {
            Logger::error('Paymos invoice create failed: ' . $e->getMessage(), array('payment_id' => (string) $paymentId));
            PaymentRepository::updateStatus((int) $paymentId, 'failed');
            edd_set_error('paymos_config_failed', __('Paymos payment error: configuration is invalid.', 'paymos-easy-digital-downloads'));
            self::sendBackToCheckout($purchaseData);
            return;
        } catch (\InvalidArgumentException $e) {
            // ClientConfig throws InvalidArgumentException (a LogicException, not a
            // RuntimeException) on empty/invalid credentials — route it to the same
            // clean configuration-error path instead of a fatal mid-checkout.
            Logger::error('Paymos invoice create failed: ' . $e->getMessage(), array('payment_id' => (string) $paymentId));
            PaymentRepository::updateStatus((int) $paymentId, 'failed');
            edd_set_error('paymos_config_failed', __('Paymos payment error: configuration is invalid.', 'paymos-easy-digital-downloads'));
            self::sendBackToCheckout($purchaseData);
            return;
        }

        $invoiceId = isset($invoice['invoice_id']) ? (string) $invoice['invoice_id'] : '';
        $paymentUrl = isset($invoice['payment_url']) ? (string) $invoice['payment_url'] : '';
        if ($invoiceId === '' || $paymentUrl === '') {
            PaymentRepository::updateStatus((int) $paymentId, 'failed');
            edd_set_error('paymos_invoice_response_invalid', __('Paymos payment error: invalid invoice response.', 'paymos-easy-digital-downloads'));
            self::sendBackToCheckout($purchaseData);
            return;
        }

        PaymentRepository::updateMeta((int) $paymentId, '_paymos_invoice_id', $invoiceId);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_external_order_id', $externalOrderId);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_payment_url', $paymentUrl);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_environment', $environment);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_project_id', (string) $config['project_id']);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_invoice_amount', $amount);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_invoice_currency', strtoupper($currency));
        PaymentRepository::insertNote((int) $paymentId, sprintf(__('Paymos invoice created: %s', 'paymos-easy-digital-downloads'), $invoiceId));

        if (function_exists('edd_empty_cart')) {
            edd_empty_cart();
        }

        Logger::info('Paymos invoice created.', array(
            'payment_id' => (string) $paymentId,
            'invoice_id' => $invoiceId,
            'environment' => $environment,
        ));

        self::redirect($paymentUrl);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function client(array $config, $environment)
    {
        if (self::$clientFactory !== null) {
            return call_user_func(self::$clientFactory, $environment, $config);
        }

        return new Client(new ClientConfig(
            (string) $config['api_key'],
            (string) $config['api_secret'],
            (string) $config['base_url'],
            30
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function activeEnvironmentConfig($environment)
    {
        $config = Config::environment_config($environment);
        foreach (array('api_key', 'api_secret', 'project_id', 'base_url') as $required) {
            if (!isset($config[$required]) || !is_scalar($config[$required]) || trim((string) $config[$required]) === '') {
                throw new \RuntimeException('Paymos generated config is missing ' . $required . ' for ' . (string) $environment . '.');
            }
        }

        return $config;
    }

    private static function option($key, $default = '')
    {
        return function_exists('edd_get_option') ? edd_get_option($key, $default) : $default;
    }

    private static function externalOrderId($paymentId, $purchaseKey)
    {
        $purchaseKey = preg_replace('/[^A-Za-z0-9_-]/', '', $purchaseKey);
        return 'edd_' . (int) $paymentId . ($purchaseKey !== '' ? '_' . $purchaseKey : '');
    }

    /**
     * @param array<string, mixed> $purchaseData
     */
    private static function clientId(array $purchaseData)
    {
        if (isset($purchaseData['user_info']['id']) && trim((string) $purchaseData['user_info']['id']) !== '' && (string) $purchaseData['user_info']['id'] !== '0') {
            return (string) $purchaseData['user_info']['id'];
        }

        if (isset($purchaseData['user_id']) && trim((string) $purchaseData['user_id']) !== '' && (string) $purchaseData['user_id'] !== '0') {
            return (string) $purchaseData['user_id'];
        }

        return '';
    }

    private static function sendBackToCheckout(array $purchaseData)
    {
        $gateway = isset($purchaseData['post_data']['edd-gateway']) ? (string) $purchaseData['post_data']['edd-gateway'] : self::ID;
        if (function_exists('edd_send_back_to_checkout')) {
            edd_send_back_to_checkout('?payment-mode=' . rawurlencode($gateway));
        }
    }

    private static function redirect($url)
    {
        wp_redirect($url);
        if (!defined('PAYMOS_EDD_TESTING')) {
            exit;
        }
    }

    private static function config_status_html()
    {
        $mode = Config::mode();
        $rows = array(
            __('Active mode', 'paymos-easy-digital-downloads') => $mode,
            __('Sandbox', 'paymos-easy-digital-downloads') => Config::has_environment('sandbox') ? __('Configured', 'paymos-easy-digital-downloads') : __('Missing', 'paymos-easy-digital-downloads'),
            __('Live', 'paymos-easy-digital-downloads') => Config::has_environment('live') ? __('Configured', 'paymos-easy-digital-downloads') : __('Missing', 'paymos-easy-digital-downloads'),
            __('Active API key', 'paymos-easy-digital-downloads') => Config::masked_api_key($mode),
            __('Project ID', 'paymos-easy-digital-downloads') => self::projectId($mode),
        );

        $html = '<table class="widefat striped">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th style="width: 180px;">' . esc_html($label) . '</th><td><code>' . esc_html((string) ($value === '' ? __('Missing', 'paymos-easy-digital-downloads') : $value)) . '</code></td></tr>';
        }

        return $html . '</table>';
    }

    private static function projectId($environment)
    {
        $config = Config::environment_config($environment);
        return isset($config['project_id']) && is_scalar($config['project_id']) ? (string) $config['project_id'] : '';
    }

    public static function formatAmount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    public static function set_client_factory_for_tests($factory)
    {
        self::$clientFactory = $factory;
    }
}
