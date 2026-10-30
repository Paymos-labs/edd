<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

defined('ABSPATH') || exit;

final class RefundGuard
{
    public static function register()
    {
        add_filter('edd_should_process_order_refund', array(__CLASS__, 'should_process_refund'), PHP_INT_MAX, 2);
        add_filter('edd_should_update_payment_status', array(__CLASS__, 'should_update_status'), PHP_INT_MAX, 3);
        add_filter('edd_should_update_order_status', array(__CLASS__, 'should_update_status'), PHP_INT_MAX, 3);
    }

    public static function should_process_refund($allowed, $orderId)
    {
        if (edd_get_payment_gateway((int) $orderId) !== Gateway::ID) { return $allowed; }
        return RefundAdapter::isFinalizing($orderId) ? $allowed : false;
    }

    public static function should_update_status($allowed, $orderId, $newStatus)
    {
        // The legacy payment API can set Refunded even after refund creation fails.
        if (in_array($newStatus, array('refunded', 'partially_refunded'), true)) {
            return self::should_process_refund($allowed, $orderId);
        }

        return $allowed;
    }
}
