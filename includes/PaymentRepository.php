<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

defined('ABSPATH') || exit;

final class PaymentRepository
{
    /** Discard EDD's request/object-cache snapshots after obtaining the mutex. */
    public static function refresh($paymentId)
    {
        $paymentId = (int) $paymentId;
        // EDD 3.x Query::get_item_by caches native orders in this group; its
        // cache_delete/clean_item_cache helpers are private. Use public WP APIs
        // for these exact keys, without flushing another order or the site.
        wp_cache_delete($paymentId, 'edd-orders');
        wp_cache_delete($paymentId, 'edd_order_meta');
        if (function_exists('clean_post_cache')) {
            clean_post_cache($paymentId);
        }
    }

    public static function updateMeta($paymentId, $key, $value)
    {
        if (function_exists('edd_update_payment_meta')) {
            edd_update_payment_meta((int) $paymentId, (string) $key, $value);
        } else {
            update_post_meta((int) $paymentId, (string) $key, $value);
        }
        if (is_scalar($value) && self::getMeta($paymentId, $key) !== (string) $value) {
            throw new \RuntimeException('Paymos payment metadata could not be saved.');
        }
    }

    public static function getMeta($paymentId, $key)
    {
        if (function_exists('edd_get_payment_meta')) {
            $value = edd_get_payment_meta((int) $paymentId, (string) $key, true);
            return is_scalar($value) ? (string) $value : '';
        }

        $value = get_post_meta((int) $paymentId, (string) $key, true);
        return is_scalar($value) ? (string) $value : '';
    }

    public static function updateStatus($paymentId, $status)
    {
        if (!function_exists('edd_update_payment_status')) {
            throw new \RuntimeException('EDD payment status API is unavailable.');
        }
        $result = edd_update_payment_status((int) $paymentId, (string) $status);
        $expected = $status === 'complete' ? array('complete', 'publish', 'edd_complete') : array((string) $status);
        if ($result === false || !in_array(self::status($paymentId), $expected, true)) {
            throw new \RuntimeException('EDD payment status could not be saved.');
        }
    }

    public static function status($paymentId)
    {
        if (function_exists('edd_get_payment_status')) {
            // Pass no second argument so EDD returns the status KEY, never the
            // localized label — comparing a translated label ("Завершён") against
            // a literal key would silently defeat the roll-back guard on any
            // non-English locale.
            $status = edd_get_payment_status((int) $paymentId);
            return is_scalar($status) ? strtolower((string) $status) : '';
        }

        return self::getMeta($paymentId, '_status');
    }

    public static function amount($paymentId)
    {
        if (function_exists('edd_get_payment_amount')) {
            return Gateway::formatAmount(edd_get_payment_amount((int) $paymentId));
        }

        return self::getMeta($paymentId, '_paymos_invoice_amount');
    }

    public static function currency($paymentId)
    {
        if (function_exists('edd_get_payment_currency_code')) {
            $currency = edd_get_payment_currency_code((int) $paymentId);
            if (is_scalar($currency) && trim((string) $currency) !== '') {
                return strtoupper((string) $currency);
            }
        }

        $currency = self::getMeta($paymentId, '_paymos_invoice_currency');
        return $currency !== '' ? strtoupper($currency) : (function_exists('edd_get_currency') ? strtoupper((string) edd_get_currency()) : '');
    }

    public static function isComplete($paymentId)
    {
        // EDD remaps the 'complete' status key to 'publish' on write, so a read
        // returns 'publish'; accept both (plus the legacy 'edd_complete') so the
        // roll-back guard recognizes a completed payment regardless of EDD version.
        return in_array(self::status((int) $paymentId), array('complete', 'publish', 'edd_complete'), true);
    }

    public static function isProtected($paymentId)
    {
        return self::isComplete($paymentId)
            || in_array(self::status($paymentId), array('refunded', 'revoked', 'partially_refunded'), true);
    }

    public static function assertPaymosPayment($paymentId)
    {
        if (self::status($paymentId) === '' || !function_exists('edd_get_payment_gateway')
            || edd_get_payment_gateway((int) $paymentId) !== Gateway::ID) {
            throw new \RuntimeException('EDD payment does not belong to Paymos.');
        }
    }

    public static function setTransactionId($paymentId, $transactionId)
    {
        $transactionId = (string) $transactionId;
        if ($transactionId === '') {
            return;
        }

        if (function_exists('edd_set_payment_transaction_id')) {
            edd_set_payment_transaction_id((int) $paymentId, $transactionId);
            return;
        }

        self::updateMeta((int) $paymentId, '_edd_payment_transaction_id', $transactionId);
    }

    public static function insertNote($paymentId, $note)
    {
        if (function_exists('edd_insert_payment_note')) {
            if (edd_insert_payment_note((int) $paymentId, (string) $note) === false) {
                throw new \RuntimeException('EDD payment note could not be saved.');
            }
        }
    }

    public static function paymentIdFromExternalOrderId($externalOrderId)
    {
        if (preg_match('/^edd_([0-9]+)(?:_|$)/', (string) $externalOrderId, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }
}
