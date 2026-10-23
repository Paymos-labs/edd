<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Exception\ApiException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\WordPressOrderLock;

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
                'desc' => __('Connect this store to the project currently selected in Paymos. Existing merchant Payment keys are reused; store webhook secrets are created for this exact URL.', 'paymos-easy-digital-downloads'),
                'type' => 'header',
            ),
            'paymos_connect' => array(
                'id' => 'paymos_connect',
                'name' => __('Connect Paymos', 'paymos-easy-digital-downloads'),
                'desc' => '<button type="button" class="button button-primary" id="paymos-edd-connect-button">'
                    . esc_html__('Connect Paymos', 'paymos-easy-digital-downloads')
                    . '</button><p id="paymos-edd-connect-status" aria-live="polite">'
                    . esc_html__('Opens Paymos in a new tab for approval.', 'paymos-easy-digital-downloads')
                    . '</p>',
                'type' => 'descriptive_text',
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
                'desc' => '<code>' . esc_html(Config::webhook_url()) . '</code><br>' . esc_html__('Registered automatically when you connect this store.', 'paymos-easy-digital-downloads'),
                'type' => 'descriptive_text',
            ),
            'paymos_config_status' => array(
                'id' => 'paymos_config_status',
                'name' => __('Connection status', 'paymos-easy-digital-downloads'),
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

        try {
            $amount = self::formatAmount(isset($purchaseData['price']) ? $purchaseData['price'] : '0');
            if ($amount === '0' || $amount[0] === '-') {
                throw new \RuntimeException('Paymos purchase amount must be positive.');
            }
        } catch (\RuntimeException $e) {
            edd_set_error('paymos_payment_record_failed', __('Paymos payment error: payment could not be recorded.', 'paymos-easy-digital-downloads'));
            self::sendBackToCheckout($purchaseData);
            return;
        }
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

        $paymentId = 0;
        $lock = null;
        try {
            // EDD 3.x reads this exact session key before rebuilding a resumed
            // order. Acquire before edd_insert_payment can change its contents.
            $resumeId = self::resumePaymentId();
            if ($resumeId > 0) {
                $lock = new WordPressOrderLock('paymos-edd', $resumeId);
                if (!$lock->acquire()) {
                    throw new \RuntimeException('Paymos payment is already being processed.');
                }
                $lock->assertOwned();
                PaymentRepository::refresh($resumeId);
                if (PaymentRepository::isProtected($resumeId)) {
                    throw new \RuntimeException('EDD payment is already terminal.');
                }
            }
            $paymentId = edd_insert_payment($paymentData);
            if (!$paymentId) {
                edd_set_error('paymos_payment_record_failed', __('Paymos payment error: payment could not be recorded.', 'paymos-easy-digital-downloads'));
                self::sendBackToCheckout($purchaseData, $lock);
                return;
            }
            if ($lock === null || (int) $paymentId !== $resumeId) {
                if ($lock !== null) {
                    $lock->release();
                }
                $lock = new WordPressOrderLock('paymos-edd', (int) $paymentId);
                if (!$lock->acquire()) {
                    throw new \RuntimeException('Paymos payment is already being processed.');
                }
                PaymentRepository::refresh($paymentId);
            }
            $lock->assertOwned();
            PaymentRepository::assertPaymosPayment($paymentId);
            if (PaymentRepository::isProtected($paymentId)) {
                throw new \RuntimeException('EDD payment is already terminal.');
            }
            self::processRecordedPayment($paymentId, $purchaseData, $amount, $currency, $lock);
        } catch (\Throwable $e) {
            Logger::error('Paymos invoice create failed: ' . $e->getMessage(), array('payment_id' => (string) $paymentId));
            edd_set_error('paymos_invoice_failed', __('Paymos payment error: unable to create invoice.', 'paymos-easy-digital-downloads'));
            self::sendBackToCheckout($purchaseData, $lock);
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
    }

    private static function resumePaymentId()
    {
        if (!function_exists('EDD')) {
            return 0;
        }
        $edd = EDD();
        if (!is_object($edd) || !isset($edd->session) || !is_object($edd->session) || !method_exists($edd->session, 'get')) {
            return 0;
        }
        $id = $edd->session->get('edd_resume_payment');
        return is_scalar($id) && ctype_digit((string) $id) ? (int) $id : 0;
    }

    private static function processRecordedPayment($paymentId, array $purchaseData, $amount, $currency, WordPressOrderLock $lock)
    {
        try {
            $environment = Config::mode();
            $config = self::activeEnvironmentConfig($environment);
            $amount = self::formatAmount(edd_get_payment_amount((int) $paymentId));
            $currency = strtoupper((string) PaymentRepository::currency($paymentId));
            if ($amount === '0' || $amount[0] === '-' || $currency === '') {
                throw new \RuntimeException('Paymos purchase amount or currency is invalid.');
            }
            $externalOrderId = self::prepareIntent($paymentId, $environment, $config, $amount, $currency, self::clientId($purchaseData), $lock);
            $payload = array(
                'project_id' => (string) $config['project_id'],
                'amount' => $amount,
                'currency' => $currency,
                'external_order_id' => $externalOrderId,
            );

            $clientId = PaymentRepository::getMeta($paymentId, '_paymos_client_id');
            if ($clientId !== '') {
                $payload['client_id'] = $clientId;
            }

            $client = self::client($config, $environment);
            $invoice = $client->invoices()->create($payload);
            $lock->assertOwned();
            self::assertCreatedInvoice($invoice, PaymentRepository::getMeta($paymentId, '_paymos_invoice_id'));
            if (InvoiceRenewal::isRequired($invoice)) {
                // An idempotent create can return the same closed invoice on a
                // resumed purchase. Only API evidence and safe close permit a
                // new intent; cached deadlines never decide this transition.
                $closed = (new InvoiceReplacement($client))->close($invoice['invoice_id'], $invoice['status']);
                $lock->assertOwned();
                if (!$closed->isClosed()) {
                    throw new \RuntimeException('Paymos previous invoice needs manual review.');
                }
                $externalOrderId = self::storeNewIntent($paymentId, $environment, $config, $amount, $currency, $clientId, $lock);
                $payload['external_order_id'] = $externalOrderId;
                $invoice = $client->invoices()->create($payload);
                $lock->assertOwned();
                self::assertCreatedInvoice($invoice, '');
                if (InvoiceRenewal::isRequired($invoice)) {
                    throw new \RuntimeException('Paymos replacement invoice is not payable.');
                }
            }
        } catch (ApiException $e) {
            Logger::error('Paymos invoice create failed: ' . $e->getMessage(), array('payment_id' => (string) $paymentId));
            $lock->assertOwned();
            PaymentRepository::updateStatus((int) $paymentId, 'failed');
            edd_set_error('paymos_invoice_failed', __('Paymos payment error: unable to create invoice.', 'paymos-easy-digital-downloads'));
            self::sendBackToCheckout($purchaseData, $lock);
            return;
        } catch (\RuntimeException $e) {
            Logger::error('Paymos invoice create failed: ' . $e->getMessage(), array('payment_id' => (string) $paymentId));
            $lock->assertOwned();
            PaymentRepository::updateStatus((int) $paymentId, 'failed');
            edd_set_error('paymos_config_failed', __('Paymos payment error: configuration is invalid.', 'paymos-easy-digital-downloads'));
            self::sendBackToCheckout($purchaseData, $lock);
            return;
        } catch (\InvalidArgumentException $e) {
            // ClientConfig throws InvalidArgumentException (a LogicException, not a
            // RuntimeException) on empty/invalid credentials — route it to the same
            // clean configuration-error path instead of a fatal mid-checkout.
            Logger::error('Paymos invoice create failed: ' . $e->getMessage(), array('payment_id' => (string) $paymentId));
            $lock->assertOwned();
            PaymentRepository::updateStatus((int) $paymentId, 'failed');
            edd_set_error('paymos_config_failed', __('Paymos payment error: configuration is invalid.', 'paymos-easy-digital-downloads'));
            self::sendBackToCheckout($purchaseData, $lock);
            return;
        }

        $invoiceId = isset($invoice['invoice_id']) && is_string($invoice['invoice_id']) ? $invoice['invoice_id'] : '';
        $paymentUrl = isset($invoice['payment_url']) && is_string($invoice['payment_url']) ? $invoice['payment_url'] : '';
        if ($invoiceId === '' || !self::isPaymentUrlAllowed($paymentUrl)) {
            $lock->assertOwned();
            PaymentRepository::updateStatus((int) $paymentId, 'failed');
            edd_set_error('paymos_invoice_response_invalid', __('Paymos payment error: invalid invoice response.', 'paymos-easy-digital-downloads'));
            self::sendBackToCheckout($purchaseData, $lock);
            return;
        }

        $existingInvoiceId = PaymentRepository::getMeta($paymentId, '_paymos_invoice_id');
        if ($existingInvoiceId !== '' && $existingInvoiceId !== $invoiceId) {
            throw new \RuntimeException('Paymos idempotent create returned a different invoice.');
        }
        $lock->assertOwned();
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_invoice_id', $invoiceId);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_creation_intent', 'bound');
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_external_order_id', $externalOrderId);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_payment_url', $paymentUrl);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_environment', $environment);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_project_id', (string) $config['project_id']);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_invoice_amount', $amount);
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_invoice_currency', strtoupper($currency));
        // The status of THIS invoice. The mapper ignores every event after a
        // final status (PaymentMapper::apply), so a payment EDD resumes must not
        // carry the final status of an invoice it had before.
        PaymentRepository::updateMeta((int) $paymentId, '_paymos_observed_status', isset($invoice['status']) && is_scalar($invoice['status']) ? (string) $invoice['status'] : '');
        PaymentRepository::insertNote((int) $paymentId, sprintf(/* translators: %s: Paymos invoice identifier. */ __('Paymos invoice created: %s', 'paymos-easy-digital-downloads'), $invoiceId));

        if (function_exists('edd_empty_cart')) {
            edd_empty_cart();
        }

        Logger::info('Paymos invoice created.', array(
            'payment_id' => (string) $paymentId,
            'invoice_id' => $invoiceId,
            'environment' => $environment,
        ));

        // exit does not execute PHP finally blocks; release before redirect.
        $lock->release();
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
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic exception is caught and logged, never rendered as HTML.
                throw new \RuntimeException('Paymos connected credentials are missing ' . $required . ' for ' . (string) $environment . '.');
            }
        }

        return $config;
    }

    private static function option($key, $default = '')
    {
        return function_exists('edd_get_option') ? edd_get_option($key, $default) : $default;
    }

    private static function prepareIntent($paymentId, $environment, array $config, $amount, $currency, $clientId, WordPressOrderLock $lock)
    {
        $existing = PaymentRepository::getMeta($paymentId, '_paymos_external_order_id');
        $same = $existing !== ''
            && PaymentRepository::getMeta($paymentId, '_paymos_environment') === $environment
            && PaymentRepository::getMeta($paymentId, '_paymos_project_id') === (string) $config['project_id']
            && AmountGuard::amountsEqual(PaymentRepository::getMeta($paymentId, '_paymos_invoice_amount'), $amount)
            && PaymentRepository::getMeta($paymentId, '_paymos_invoice_currency') === $currency;
        if ($same) {
            return $existing;
        }
        if ($existing !== '') {
            $oldEnvironment = PaymentRepository::getMeta($paymentId, '_paymos_environment');
            $oldConfig = self::activeEnvironmentConfig($oldEnvironment);
            $oldClient = self::client($oldConfig, $oldEnvironment);
            $oldInvoiceId = PaymentRepository::getMeta($paymentId, '_paymos_invoice_id');
            if ($oldInvoiceId === '' && PaymentRepository::getMeta($paymentId, '_paymos_creation_intent') === 'pending') {
                $oldPayload = array(
                    'project_id' => PaymentRepository::getMeta($paymentId, '_paymos_project_id'),
                    'amount' => PaymentRepository::getMeta($paymentId, '_paymos_invoice_amount'),
                    'currency' => PaymentRepository::getMeta($paymentId, '_paymos_invoice_currency'),
                    'external_order_id' => $existing,
                );
                $oldClientId = PaymentRepository::getMeta($paymentId, '_paymos_client_id');
                if ($oldClientId !== '') {
                    $oldPayload['client_id'] = $oldClientId;
                }
                $old = $oldClient->invoices()->create($oldPayload);
                $oldInvoiceId = isset($old['invoice_id']) && is_string($old['invoice_id']) ? $old['invoice_id'] : '';
            }
            $closed = (new InvoiceReplacement($oldClient))->close($oldInvoiceId);
            $lock->assertOwned();
            if (!$closed->isClosed()) {
                throw new \RuntimeException('Paymos previous invoice needs manual review.');
            }
        }
        return self::storeNewIntent($paymentId, $environment, $config, $amount, $currency, $clientId, $lock);
    }

    private static function storeNewIntent($paymentId, $environment, array $config, $amount, $currency, $clientId, WordPressOrderLock $lock)
    {
        $external = 'edd_' . (int) $paymentId . '_' . bin2hex(random_bytes(16));
        $lock->assertOwned();
        // Durable intent precedes POST, including when the response is lost.
        PaymentRepository::updateMeta($paymentId, '_paymos_external_order_id', $external);
        PaymentRepository::updateMeta($paymentId, '_paymos_environment', $environment);
        PaymentRepository::updateMeta($paymentId, '_paymos_project_id', (string) $config['project_id']);
        PaymentRepository::updateMeta($paymentId, '_paymos_invoice_amount', $amount);
        PaymentRepository::updateMeta($paymentId, '_paymos_invoice_currency', $currency);
        PaymentRepository::updateMeta($paymentId, '_paymos_invoice_id', '');
        PaymentRepository::updateMeta($paymentId, '_paymos_client_id', (string) $clientId);
        PaymentRepository::updateMeta($paymentId, '_paymos_applied_status', '');
        PaymentRepository::updateMeta($paymentId, '_paymos_last_status', '');
        PaymentRepository::updateMeta($paymentId, '_paymos_creation_intent', 'pending');
        return $external;
    }

    private static function assertCreatedInvoice(array $invoice, $expectedId)
    {
        $id = isset($invoice['invoice_id']) && is_string($invoice['invoice_id']) ? $invoice['invoice_id'] : '';
        $status = isset($invoice['status']) && is_string($invoice['status']) ? $invoice['status'] : '';
        if ($id === '' || ($expectedId !== '' && $expectedId !== $id)
            || !in_array($status, array('awaiting_client', 'awaiting_payment', 'confirming', 'underpaid_waiting', 'paid', 'paid_over', 'expired', 'cancelled', 'underpaid'), true)) {
            throw new \RuntimeException('Paymos invoice response cannot be safely matched to the purchase.');
        }
    }

    private static function isPaymentUrlAllowed($url)
    {
        $parts = wp_parse_url((string) $url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        return in_array(strtolower($parts['host']), array('checkout.paymos.io', 'checkout-local.paymos.io'), true);
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

    private static function sendBackToCheckout(array $purchaseData, ?WordPressOrderLock $lock = null)
    {
        // EDD can exit here: finally is not executed by PHP exit.
        if ($lock !== null) {
            $lock->release();
        }
        $gateway = isset($purchaseData['post_data']['edd-gateway']) ? (string) $purchaseData['post_data']['edd-gateway'] : self::ID;
        if (function_exists('edd_send_back_to_checkout')) {
            edd_send_back_to_checkout('?payment-mode=' . rawurlencode($gateway));
        }
    }

    private static function redirect($url)
    {
        // The HTTPS checkout host was checked against the configured API origin.
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Hosted checkout intentionally uses a separately validated external host.
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
        $normalized = AmountGuard::normalizeDecimal($amount);
        if ($normalized === null) {
            throw new \RuntimeException('Paymos purchase amount is invalid.');
        }
        return $normalized;
    }

    public static function set_client_factory_for_tests($factory)
    {
        self::$clientFactory = $factory;
    }
}
