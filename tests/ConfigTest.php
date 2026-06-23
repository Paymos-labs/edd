<?php

declare(strict_types=1);

use PaymosEasyDigitalDownloads\Config;
use PaymosEasyDigitalDownloads\Gateway;

function test_edd_config_reads_generated_environments()
{
    paymos_edd_reset_test_state();
    paymos_edd_write_generated_config("array(
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_test_123',
                'api_secret' => 'sk_test_123',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_sandbox',
                'base_url' => 'https://api.paymos.test',
            ),
            'live' => array(
                'api_key' => 'pk_live_123',
                'api_secret' => 'sk_live_123',
                'project_id' => 'prj_live_123',
                'webhook_secret' => 'whsec_live',
                'base_url' => 'https://api.paymos.test',
            ),
        ),
    )");

    assertSameValue('sandbox', Config::mode(), 'Default EDD Paymos mode should be sandbox.');
    assertSameValue('pk_test_123', Config::environment_config('sandbox')['api_key'], 'Sandbox key should load.');
    assertSameValue('pk_live_123', Config::environment_config('live')['api_key'], 'Live key should load.');
    assertSameValue(array('sandbox' => 'whsec_sandbox', 'live' => 'whsec_live'), Config::webhook_secrets(), 'Webhook secrets should load per environment.');
}

function test_edd_gateway_registers_gateway_and_settings()
{
    paymos_edd_reset_test_state();

    $gateways = Gateway::register_gateway(array());
    assertTrueValue(isset($gateways['paymos']), 'Paymos gateway should be registered.');
    assertSameValue('Paymos', $gateways['paymos']['admin_label'], 'Admin label should be Paymos.');

    $settings = Gateway::register_settings(array());
    assertTrueValue(isset($settings['paymos']['paymos_mode']), 'Paymos mode setting should exist.');
    assertTrueValue(isset($settings['paymos']['paymos_webhook_url']), 'Paymos webhook URL setting should exist.');
}
