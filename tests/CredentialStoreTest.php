<?php

declare(strict_types=1);

use PaymosEasyDigitalDownloads\Config;
use PaymosEasyDigitalDownloads\ConnectStateStore;
use PaymosEasyDigitalDownloads\CredentialStore;

function paymos_edd_credentials()
{
    return array(
        'sandbox' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_test_1234567890',
            'api_secret' => 'sk_test_secret',
            'project_id' => 'prj_sandbox',
            'webhook_secret' => 'whsec_test_secret',
        ),
        'live' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_live_1234567890',
            'api_secret' => 'sk_live_secret',
            'project_id' => 'prj_live',
            'webhook_secret' => 'whsec_live_secret',
        ),
    );
}

function test_edd_credential_store_encrypts_and_config_reads_it()
{
    paymos_edd_reset_test_state();
    CredentialStore::save(paymos_edd_credentials());
    Config::reset_for_tests();

    $stored = (string) get_option(CredentialStore::OPTION_KEY, '');
    assertTrueValue(strpos($stored, 'sk_live_secret') === false, 'EDD API secret must not be stored in plaintext.');
    assertTrueValue(strpos($stored, 'whsec_test_secret') === false, 'EDD webhook secret must not be stored in plaintext.');
    assertSameValue('pk_test_1234567890', Config::environment_config('sandbox')['api_key'], 'EDD config must read encrypted credentials.');
}

function test_edd_connect_state_encrypts_device_code()
{
    paymos_edd_reset_test_state();
    ConnectStateStore::save(array(
        'device_code' => 'edd-device-secret',
        'expires_in' => 600,
        'started_at' => time(),
    ));

    $stored = (string) get_option(ConnectStateStore::OPTION_KEY, '');
    assertTrueValue(strpos($stored, 'edd-device-secret') === false, 'EDD device code must not be stored in plaintext.');
    assertSameValue('edd-device-secret', ConnectStateStore::load()['device_code'], 'EDD encrypted device code must round-trip.');
}
