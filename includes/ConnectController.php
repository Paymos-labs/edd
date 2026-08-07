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
        ));
    }

    public static function start()
    {
        self::authorize();
        try {
            $state = self::client()->start('easy-digital-downloads', self::sourceUrl(), self::settingsUrl());
            ConnectStateStore::save($state);
            wp_send_json_success(array(
                'verification_url' => $state['verification_url'],
                'user_code' => $state['user_code'],
                'interval' => $state['interval'],
            ));
        } catch (\Throwable $exception) {
            wp_send_json_error(array('message' => $exception->getMessage()), 400);
        }
    }

    public static function poll()
    {
        self::authorize();
        try {
            $state = ConnectStateStore::load();
            if (!isset($state['device_code'])) {
                throw new \RuntimeException('No active Paymos connection request.');
            }
            $result = self::client()->poll((string) $state['device_code']);
            if ($result['status'] === 'connected') {
                if ($result['plugin'] !== 'edd'
                    && $result['plugin'] !== 'easy-digital-downloads') {
                    throw new \RuntimeException('Paymos connection response is for another plugin.');
                }
                if (rtrim((string) $result['source_url'], '/') !== self::sourceUrl()) {
                    throw new \RuntimeException('Paymos connection response does not match this store.');
                }
                CredentialStore::save($result['credentials']);
                ConnectStateStore::clear();
                Config::reset_for_tests();
                wp_send_json_success(array('status' => 'connected'));
            }
            if (in_array($result['status'], array('authorization_pending', 'slow_down'), true)) {
                wp_send_json_success(array('status' => $result['status']));
            }
            ConnectStateStore::clear();
            wp_send_json_error(array('message' => 'Paymos connection was denied or expired.'), 409);
        } catch (\Throwable $exception) {
            ConnectStateStore::clear();
            wp_send_json_error(array('message' => $exception->getMessage()), 400);
        }
    }

    private static function authorize()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_shop_settings')) {
            wp_send_json_error(array('message' => 'Access denied.'), 403);
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
