<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

defined('ABSPATH') || exit;

final class Logger
{
    public static function info($message, array $context = array())
    {
        self::log('info', $message, $context);
    }

    public static function error($message, array $context = array())
    {
        self::log('error', $message, $context);
    }

    private static function log($level, $message, array $context)
    {
        $debug = strtolower((string) Config::get('paymos_debug_logging', 'no'));
        if (!in_array($debug, array('1', 'yes', 'on', 'true'), true)) {
            return;
        }

        $line = '[Paymos EDD][' . $level . '] ' . self::redact($message);
        if (count($context) > 0) {
            $line .= ' ' . wp_json_encode(self::redactContext($context));
        }

        error_log($line);
    }

    private static function redact($value)
    {
        return preg_replace('/(sk|pk|rk|whsec)_(test|live)_[A-Za-z0-9_-]+/', '$1_$2_[redacted]', (string) $value);
    }

    private static function redactContext(array $context)
    {
        $redacted = array();
        foreach ($context as $key => $value) {
            $redacted[$key] = is_scalar($value) ? self::redact((string) $value) : '[non-scalar]';
        }

        return $redacted;
    }
}
