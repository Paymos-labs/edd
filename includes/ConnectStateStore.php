<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

use Paymos\Plugin\WordPressEncryptedOption;

defined('ABSPATH') || exit;

final class ConnectStateStore
{
    public const OPTION_KEY = 'paymos_edd_connect_state_v1';
    private const AAD = 'paymos-for-edd-connect-state-v1';

    public static function save(array $state)
    {
        if (!isset($state['device_code'], $state['expires_in'], $state['started_at'])) {
            throw new \InvalidArgumentException('Paymos connection state is incomplete.');
        }
        $state['expires_at'] = (int) $state['started_at'] + (int) $state['expires_in'];
        WordPressEncryptedOption::save(self::OPTION_KEY, self::AAD, array(
            'schema' => 1,
            'state' => $state,
        ));
    }

    public static function load()
    {
        $payload = WordPressEncryptedOption::load(self::OPTION_KEY, self::AAD);
        if (count($payload) === 0) {
            return array();
        }
        if (!isset($payload['schema'], $payload['state'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['state'])) {
            throw new \RuntimeException('Stored Paymos connection state has an invalid schema.');
        }
        if (!isset($payload['state']['expires_at']) || time() >= (int) $payload['state']['expires_at']) {
            self::clear();
            return array();
        }
        return $payload['state'];
    }

    public static function clear()
    {
        WordPressEncryptedOption::delete(self::OPTION_KEY);
    }
}
