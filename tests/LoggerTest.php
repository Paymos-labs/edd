<?php

declare(strict_types=1);

use PaymosEasyDigitalDownloads\Logger;

function edd_debug_log($message = '', $force = false)
{
    $GLOBALS['paymos_edd_native_logs'][] = array($message, $force);
}

function test_edd_logger_respects_merchant_opt_in()
{
    foreach (array('', 'no', 'false', '0') as $value) {
        paymos_edd_reset_test_state();
        $GLOBALS['paymos_edd_options']['edd_settings']['paymos_debug_logging'] = $value;
        $GLOBALS['paymos_edd_native_logs'] = array();
        Logger::error('diagnostic sentinel');
        assertSameValue(array(), $GLOBALS['paymos_edd_native_logs'], 'Disabled merchant logging must not call EDD.');
    }
}

function test_edd_logger_uses_native_sink_and_masks_supported_secrets()
{
    foreach (array('1', 'YES', 'on', 'true') as $value) {
        paymos_edd_reset_test_state();
        $GLOBALS['paymos_edd_options']['edd_settings']['paymos_debug_logging'] = $value;
        $GLOBALS['paymos_edd_native_logs'] = array();
        Logger::info('Invoice sk_test_FAKESECRET', array('key' => 'pk_live_FAKE', 'nested' => array('secret' => 'not-for-logs')));
        assertSameValue(array(array(
            '[Paymos EDD][info] Invoice sk_test_[redacted] {"key":"pk_live_[redacted]","nested":"[non-scalar]"}', true,
        )), $GLOBALS['paymos_edd_native_logs'], 'Native EDD sink must preserve explicit opt-in and masked context.');
    }
}

function test_edd_logger_without_native_api_does_not_fall_back_to_php_log()
{
    $log = tempnam(sys_get_temp_dir(), 'paymos-edd-log-');
    unlink($log);
    $code = 'require ' . var_export(__DIR__ . '/bootstrap.php', true) . ';'
        . 'paymos_edd_reset_test_state();'
        . '$GLOBALS["paymos_edd_options"]["edd_settings"]["paymos_debug_logging"]="yes";'
        . 'if(function_exists("edd_debug_log")){exit(2);}'
        . '\\PaymosEasyDigitalDownloads\\Logger::error("diagnostic sentinel");echo "safe";';
    $pipes = array();
    $process = proc_open(array(PHP_BINARY, '-d', 'error_log=' . $log, '-r', $code),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    assertTrueValue(is_resource($process), 'Isolated missing-API probe must start.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $phpLog = is_file($log) ? file_get_contents($log) : '';
    if (is_file($log)) {
        unlink($log);
    }
    assertSameValue(0, $exit, 'Missing native API must not break payment handling.');
    assertSameValue('safe', $stdout, 'Missing native API should return quietly.');
    assertSameValue('', $stderr, 'Missing native API must not write stderr.');
    assertSameValue('', $phpLog, 'Missing native API must not fall back to PHP error_log.');
}
