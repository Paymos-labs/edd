<?php

declare(strict_types=1);

use PaymosEasyDigitalDownloads\ConnectStateStore;

function paymos_edd_connect_state()
{
    return array('device_code' => 'device-A', 'user_code' => 'ABCD-EFGH', 'verification_url' => 'https://app.paymos.io/connect/cms/verify',
        'interval' => 5, 'expires_in' => 600, 'started_at' => time());
}
function check_ajax_referer($action, $key = false, $stop = true) {
    $GLOBALS['paymos_edd_ajax_nonce_checks'][] = array($action, $key, $stop);
    $valid = $GLOBALS['paymos_edd_ajax_nonce_valid'] ?? true;
    if (!$valid && $stop) { throw new PaymosEddAjaxExit('Invalid AJAX nonce.', 403); }
    return $valid;
}
function home_url($path = '') { return 'https://shop.example.test/' . ltrim($path, '/'); }
function wp_safe_remote_request($url, array $args) {
    $GLOBALS['paymos_edd_http_requests'][] = array($url, $args);
    return call_user_func($GLOBALS['paymos_edd_remote_handler'], $url, $args);
}
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($value) { return $value['response']['code']; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function wp_remote_retrieve_headers($value) { return $value['headers']; }

function test_edd_r31_connect_stale_poll_cannot_clear_or_install_credentials()
{
    foreach (array('connected', 'denied', 'transport') as $result) {
        paymos_edd_reset_test_state();
        $original = paymos_edd_credentials();
        PaymosEasyDigitalDownloads\CredentialStore::save($original);
        $original = PaymosEasyDigitalDownloads\CredentialStore::load();
        $flowA = ConnectStateStore::save(paymos_edd_connect_state());
        $_POST['flow_id'] = $flowA;
        $flowB = '';
        $GLOBALS['paymos_edd_remote_handler'] = static function ($url, $args) use ($result, &$flowB) {
            $stateB = paymos_edd_connect_state();
            $stateB['device_code'] = 'device-B';
            $flowB = ConnectStateStore::save($stateB);
            if ($result === 'transport') { throw new RuntimeException('connection lost'); }
            if ($result === 'denied') {
                return array('response' => array('code' => 400), 'body' => '{"error":"access_denied"}', 'headers' => array());
            }
            $credentials = paymos_edd_credentials();
            $credentials['sandbox']['project_id'] = 'stale-project';
            return array('response' => array('code' => 200), 'headers' => array(),
                'body' => json_encode(array('paymos_config' => array('plugin' => 'easy-digital-downloads',
                    'source_url' => rtrim(home_url('/'), '/'), 'environments' => $credentials))));
        };
        PaymosEasyDigitalDownloads\ConnectController::poll();
        assertSameValue($flowB, ConnectStateStore::load()['flow_id'], 'A stale poll must preserve flow B.');
        assertSameValue('device-B', ConnectStateStore::load()['device_code'], 'B keeps its device code.');
        assertSameValue($original, PaymosEasyDigitalDownloads\CredentialStore::load(), 'Stale success must not install A credentials.');
        assertSameValue(false, $GLOBALS['paymos_edd_refund_json']['success'], 'Stale poll must report failure.');
    }
}

function test_edd_r31_connect_rejects_missing_or_stale_flow_before_http()
{
    paymos_edd_reset_test_state();
    $GLOBALS['paymos_edd_http_requests'] = array();
    $flow = ConnectStateStore::save(paymos_edd_connect_state());
    foreach (array('', 'another-flow') as $requested) {
        $_POST['flow_id'] = $requested;
        PaymosEasyDigitalDownloads\ConnectController::poll();
        assertSameValue(array(), $GLOBALS['paymos_edd_http_requests'], 'Wrong flow must not poll another tab device.');
        assertSameValue($flow, ConnectStateStore::load()['flow_id'], 'Wrong flow cannot clear current state.');
    }
}

function test_edd_r31_connect_current_generation_installs_credentials_once()
{
    paymos_edd_reset_test_state();
    $flow = ConnectStateStore::save(paymos_edd_connect_state());
    assertSameValue(true, ConnectStateStore::complete($flow, paymos_edd_credentials()), 'Current generation completes.');
    assertSameValue(array(), ConnectStateStore::load(), 'Successful generation is cleared.');
    assertSameValue(false, ConnectStateStore::complete($flow, paymos_edd_credentials()), 'Consumed generation cannot complete again.');
}

function test_edd_r31_connect_mutations_share_the_generation_mutex()
{
    paymos_edd_reset_test_state();
    $flow = ConnectStateStore::save(paymos_edd_connect_state());
    $lock = new Paymos\Plugin\WordPressOrderLock('edd-connect', ConnectStateStore::OPTION_KEY);
    assertSameValue(true, $lock->acquire(), 'Test owns the connection mutex.');
    try {
        foreach (array(
            static function () { ConnectStateStore::save(paymos_edd_connect_state()); },
            static function () use ($flow) { ConnectStateStore::clear($flow); },
            static function () use ($flow) { ConnectStateStore::complete($flow, paymos_edd_credentials()); },
        ) as $mutate) {
            $blocked = false;
            try { $mutate(); } catch (RuntimeException $expected) { $blocked = true; }
            assertSameValue(true, $blocked, 'All state/credential mutations must take the same mutex.');
        }
    } finally { $lock->release(); }
    assertSameValue($flow, ConnectStateStore::load()['flow_id'], 'Blocked mutation leaves original flow.');
}

function test_edd_connect_poll_rejects_noncanonical_flow_without_side_effects()
{
    foreach (array('missing', 'array', 'spaces', 'html', 'uppercase', 'foreign', 'slash') as $case) {
        paymos_edd_reset_test_state();
        PaymosEasyDigitalDownloads\CredentialStore::save(paymos_edd_credentials());
        $original = PaymosEasyDigitalDownloads\CredentialStore::load();
        $flow = ConnectStateStore::save(paymos_edd_connect_state());
        $inputs = array(
            'array' => array($flow),
            'spaces' => ' ' . $flow . ' ',
            'html' => '<b>' . $flow . '</b>',
            'uppercase' => 'A' . substr($flow, 1),
            'foreign' => ($flow[0] === 'a' ? 'b' : 'a') . substr($flow, 1),
            'slash' => chr(92) . $flow,
        );
        $_POST = $case === 'missing' ? array() : array('flow_id' => $inputs[$case]);
        PaymosEasyDigitalDownloads\ConnectController::poll();
        assertSameValue(array(), $GLOBALS['paymos_edd_http_requests'], $case . ': invalid flow must not reach the provider.');
        assertSameValue($flow, ConnectStateStore::load()['flow_id'], $case . ': invalid flow cannot clear the active request.');
        assertSameValue($original, PaymosEasyDigitalDownloads\CredentialStore::load(), $case . ': credentials must remain unchanged.');
    }
}

function test_edd_connect_ajax_requires_nonce_and_capability_before_side_effects()
{
    foreach (array('start', 'poll') as $handler) {
        foreach (array('nonce', 'capability') as $refusal) {
            paymos_edd_reset_test_state();
            PaymosEasyDigitalDownloads\CredentialStore::save(paymos_edd_credentials());
            $original = PaymosEasyDigitalDownloads\CredentialStore::load();
            $flow = ConnectStateStore::save(paymos_edd_connect_state());
            $_POST = array('flow_id' => $flow);
            $GLOBALS['paymos_edd_terminate_json_errors'] = true;
            if ($refusal === 'nonce') {
                $GLOBALS['paymos_edd_ajax_nonce_valid'] = false;
            } else {
                $GLOBALS['paymos_edd_refund_permission'] = false;
            }
            $status = null;
            try {
                PaymosEasyDigitalDownloads\ConnectController::$handler();
            } catch (PaymosEddAjaxExit $expected) {
                $status = $expected->getCode();
            }
            assertSameValue(403, $status, $handler . ': authorization refusal must end the request.');
            assertSameValue(array(array('paymos_edd_connect', 'nonce', true)),
                $GLOBALS['paymos_edd_ajax_nonce_checks'], $handler . ': exactly one correct nonce check is required.');
            assertSameValue(array(), $GLOBALS['paymos_edd_http_requests'], $handler . ': refusal must not reach the provider.');
            assertSameValue($flow, ConnectStateStore::load()['flow_id'], $handler . ': refusal cannot replace or clear a flow.');
            assertSameValue($original, PaymosEasyDigitalDownloads\CredentialStore::load(), $handler . ': refusal cannot replace credentials.');
        }
    }
    paymos_edd_reset_test_state();
}

function test_edd_connect_poll_accepts_exact_active_flow()
{
    paymos_edd_reset_test_state();
    $flow = ConnectStateStore::save(paymos_edd_connect_state());
    $_POST = array('flow_id' => $flow);
    $credentials = paymos_edd_credentials();
    $GLOBALS['paymos_edd_remote_handler'] = static function ($url, $args) use ($credentials) {
        assertSameValue('https://app.paymos.io/connect/token', $url, 'Connect uses the existing token endpoint.');
        return array('response' => array('code' => 200), 'headers' => array(),
            'body' => json_encode(array('paymos_config' => array('plugin' => 'easy-digital-downloads',
                'source_url' => rtrim(home_url('/'), '/'), 'environments' => $credentials))));
    };
    PaymosEasyDigitalDownloads\ConnectController::poll();
    assertSameValue(1, count($GLOBALS['paymos_edd_http_requests']), 'Exact active flow polls once.');
    assertSameValue(true, $GLOBALS['paymos_edd_refund_json']['success'], 'Exact active flow connects.');
    assertSameValue($credentials['sandbox']['project_id'], PaymosEasyDigitalDownloads\CredentialStore::load()['sandbox']['project_id'],
        'Verified credentials are installed for the current flow.');
    assertSameValue(array(), ConnectStateStore::load(), 'Completed flow is consumed.');
}
