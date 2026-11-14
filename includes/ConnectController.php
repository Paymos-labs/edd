<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

use Paymos\Connect\DeviceConnectClient;
use Paymos\Http\WordPressTransport;

defined('ABSPATH') || exit;

final class ConnectController
{
    public const START_ACTION = 'paymos_edd_connect_start';
    public const POLL_ACTION = 'paymos_edd_connect_poll';
    public const NONCE_ACTION = 'paymos_edd_connect';

    public static function register()
    {
        add_action('wp_ajax_' . self::START_ACTION, array(__CLASS__, 'start'));
        add_action('wp_ajax_' . self::POLL_ACTION, array(__CLASS__, 'poll'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    public static function enqueue_assets()
    {
        if (!current_user_can('manage_shop_settings')) {
            return;
        }
        wp_enqueue_script(
            'paymos-edd-connect',
            plugins_url('assets/js/connect.js', PAYMOS_EDD_PLUGIN_FILE),
            array(),
            PAYMOS_EDD_PLUGIN_VERSION,
            true
        );
        wp_localize_script('paymos-edd-connect', 'PaymosEddConnect', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'startAction' => self::START_ACTION,
            'pollAction' => self::POLL_ACTION,
            'labels' => array(
                'approval_link' => __('Open the approval page', 'paymos-easy-digital-downloads'),
                'blocked_tab' => __('Your browser blocked the approval tab.', 'paymos-easy-digital-downloads'),
                'code' => __('Code:', 'paymos-easy-digital-downloads'),
                'failed' => __('Paymos connection failed.', 'paymos-easy-digital-downloads'),
                'connected' => __('Paymos connected. Reloading…', 'paymos-easy-digital-downloads'),
                'waiting' => __('Waiting for approval in Paymos…', 'paymos-easy-digital-downloads'),
                'starting' => __('Starting secure connection…', 'paymos-easy-digital-downloads'),
            ),
        ));
    }

    public static function start()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        self::authorize();
        try {
            $state = self::client()->start('easy-digital-downloads', self::sourceUrl(), self::settingsUrl());
            $flowId = ConnectStateStore::save($state);
            wp_send_json_success(array(
                'verification_url' => $state['verification_url'],
                'user_code' => $state['user_code'],
                'interval' => $state['interval'],
                'flow_id' => $flowId,
            ));
        } catch (\Throwable $exception) {
            Logger::error('Paymos connection failed: ' . $exception->getMessage());
            wp_send_json_error(array('message' => __('Paymos connection failed.', 'paymos-easy-digital-downloads')), 400);
        }
    }

    public static function poll()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        self::authorize();
        $flowId = isset($_POST['flow_id']) && is_string($_POST['flow_id'])
            ? sanitize_text_field(wp_unslash($_POST['flow_id'])) : '';
        // A generated hex token needs no normalization: never accept a different raw value.
        if ($flowId !== '' && ($_POST['flow_id'] !== $flowId || preg_match('/\A[a-f0-9]{32}\z/', $flowId) !== 1)) {
            $flowId = '';
        }
        try {
            $state = ConnectStateStore::load();
            if (!isset($state['device_code'], $state['flow_id']) || $flowId === ''
                || !hash_equals($state['flow_id'], $flowId)) {
                throw new \RuntimeException('No active Paymos connection request.');
            }
            try {
                $result = self::client()->poll((string) $state['device_code']);
            } catch (\Throwable $exception) {
                // A network or server failure says nothing about the device code: keep the request
                // and let the page poll again until Paymos answers or the request expires locally.
                Logger::error('Paymos connection poll failed, retrying: ' . $exception->getMessage());
                wp_send_json_success(array('status' => 'retry', 'message' => $exception->getMessage()));
                return;
            }
            if ($result['status'] === 'connected') {
                if ($result['plugin'] !== 'edd'
                    && $result['plugin'] !== 'easy-digital-downloads') {
                    throw new \RuntimeException('Paymos connection response is for another plugin.');
                }
                if (rtrim((string) $result['source_url'], '/') !== self::sourceUrl()) {
                    throw new \RuntimeException('Paymos connection response does not match this store.');
                }
                if (!ConnectStateStore::complete($flowId, $result['credentials'])) {
                    throw new \RuntimeException('Paymos connection request was replaced.');
                }
                Config::reset_for_tests();
                wp_send_json_success(array('status' => 'connected'));
                return;
            }
            if (in_array($result['status'], array('authorization_pending', 'slow_down'), true)) {
                wp_send_json_success(array('status' => $result['status']));
                return;
            }
            ConnectStateStore::clear($flowId);
            wp_send_json_error(array('message' => __('Paymos connection was denied or expired.', 'paymos-easy-digital-downloads')), 409);
        } catch (\Throwable $exception) {
            try { ConnectStateStore::clear($flowId); } catch (\Throwable $cleanupError) { /* Preserve the original failure. */ }
            Logger::error('Paymos connection failed: ' . $exception->getMessage());
            wp_send_json_error(array('message' => __('Paymos connection failed.', 'paymos-easy-digital-downloads')), 400);
        }
    }

    private static function authorize()
    {
        if (!current_user_can('manage_shop_settings')) {
            wp_send_json_error(array('message' => __('Access denied.', 'paymos-easy-digital-downloads')), 403);
        }
    }

    private static function client()
    {
        return new DeviceConnectClient('https://app.paymos.io', new WordPressTransport(), 15);
    }

    private static function sourceUrl()
    {
        return rtrim((string) home_url('/'), '/');
    }

    /**
     * Where approval should drop the merchant back. Paymos only honours it when it shares
     * an origin with the store URL above, so a site whose admin lives on another host simply
     * gets the Paymos confirmation screen instead of a redirect.
     */
    private static function settingsUrl()
    {
        return (string) admin_url('admin.php?page=edd-settings&tab=gateways&section=paymos');
    }
}
