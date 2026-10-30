<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

defined('ABSPATH') || exit;

/** Selected copywriter strings; locale follows the current WordPress administrator. */
final class RefundCopy
{
    public static function labels(?string $locale = null): array
    {
        if ($locale === null) {
            $locale = function_exists('get_user_locale') ? get_user_locale()
                : (function_exists('determine_locale') ? determine_locale() : (function_exists('get_locale') ? get_locale() : 'en'));
        }
        return \Paymos\Plugin\RefundCopy::labels($locale);
    }

    public static function text(string $key, ?string $locale = null): string
    {
        $labels = self::labels($locale);
        return $labels[$key] ?? $labels['unavailable'];
    }

    public static function message(array $operation, ?string $locale = null): string
    {
        $state = $operation['cash_state'] ?? '';
        $projection = $operation['native_state'] ?? $operation['projection_state'] ?? '';
        if ($projection === 'native_review' || $state === 'review') { return self::text('review', $locale); }
        if ($state === 'unknown') { return self::text('unknown', $locale); }
        if ($state === 'failed' || $state === 'cancelled') { return self::text($state, $locale); }
        if ($state === 'completed' && $projection === 'applied') {
            return self::text(($operation['mode'] ?? '') === 'overpayment' ? 'overpayment_completed' : 'full_completed', $locale);
        }
        return self::text($state === 'not_submitted' ? 'requested' : 'pending', $locale);
    }

    public static function error(string $reason, ?string $locale = null): string
    {
        if ($reason === 'refund_edd_33_native_email_required') { return self::text('edd_upgrade', $locale); }
        if ($reason === 'refund_reconnect_required') { return self::text('reconnect', $locale); }
        return self::text('request_failed', $locale);
    }
}
