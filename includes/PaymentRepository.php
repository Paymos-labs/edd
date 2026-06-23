<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

defined('ABSPATH') || exit;

final class PaymentRepository
{
    public static function updateMeta($paymentId, $key, $value)
    {
        if (function_exists('edd_update_payment_meta')) {
            edd_update_payment_meta((int) $paymentId, (string) $key, $value);
            return;
        }

        update_post_meta((int) $paymentId, (string) $key, $value);
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
        if (function_exists('edd_update_payment_status')) {
            edd_update_payment_status((int) $paymentId, (string) $status);
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
            edd_insert_payment_note((int) $paymentId, (string) $note);
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
