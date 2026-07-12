<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

use Paymos\Plugin\CredentialSet;
use Paymos\Plugin\WordPressEncryptedOption;

defined('ABSPATH') || exit;

final class CredentialStore
{
    public const OPTION_KEY = 'paymos_edd_credentials_v1';
    private const AAD = 'paymos-for-edd-credentials-v1';

    public static function load()
    {
        $payload = WordPressEncryptedOption::load(self::OPTION_KEY, self::AAD);
        if (count($payload) === 0) {
            return array();
        }
        if (!isset($payload['schema'], $payload['environments'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['environments'])) {
            throw new \RuntimeException('Stored Paymos credentials have an invalid schema.');
        }
        return CredentialSet::normalize($payload['environments']);
    }

    public static function save(array $environments)
    {
        $normalized = CredentialSet::normalize($environments);
        if (count($normalized) === 0) {
            WordPressEncryptedOption::delete(self::OPTION_KEY);
            return;
        }
        WordPressEncryptedOption::save(self::OPTION_KEY, self::AAD, array(
            'schema' => 1,
            'environments' => $normalized,
        ));
    }
}
