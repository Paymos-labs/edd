<?php
/**
 * Plugin Name: Paymos for Easy Digital Downloads
 * Plugin URI: https://paymos.io/docs/cms-easy-digital-downloads
 * Description: Accept stablecoin payments in Easy Digital Downloads through Paymos.
 * Version: 1.3.1
 * Author: Paymos
 * Author URI: https://paymos.io
 * License: GPL-2.0-or-later
 * Text Domain: paymos-easy-digital-downloads
 * Requires at least: 6.2
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: easy-digital-downloads
 */

defined('ABSPATH') || exit;

define('PAYMOS_EDD_PLUGIN_FILE', __FILE__);
define('PAYMOS_EDD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PAYMOS_EDD_PLUGIN_VERSION', '1.3.1');

require_once PAYMOS_EDD_PLUGIN_DIR . 'includes/Autoloader.php';

PaymosEasyDigitalDownloads\Autoloader::register();

add_action('init', static function () {
    load_plugin_textdomain(
        'paymos-easy-digital-downloads',
        false,
        dirname(plugin_basename(PAYMOS_EDD_PLUGIN_FILE)) . '/languages'
    );
});

add_filter('edd_payment_gateways', array(PaymosEasyDigitalDownloads\Gateway::class, 'register_gateway'));
add_filter('edd_accepted_payment_icons', array(PaymosEasyDigitalDownloads\Gateway::class, 'accepted_payment_icons'));
add_filter('edd_settings_sections_gateways', array(PaymosEasyDigitalDownloads\Gateway::class, 'register_settings_section'));
add_filter('edd_settings_gateways', array(PaymosEasyDigitalDownloads\Gateway::class, 'register_settings'));

add_action('edd_paymos_cc_form', '__return_false');
add_action('edd_gateway_paymos', array(PaymosEasyDigitalDownloads\Gateway::class, 'process_payment'));

add_action('rest_api_init', static function () {
    PaymosEasyDigitalDownloads\WebhookController::register_routes();
});

PaymosEasyDigitalDownloads\StorefrontHooks::register();
PaymosEasyDigitalDownloads\ConnectController::register();

register_activation_hook(PAYMOS_EDD_PLUGIN_FILE, array(PaymosEasyDigitalDownloads\EventStore::class, 'install'));
