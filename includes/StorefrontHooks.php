<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

defined('ABSPATH') || exit;

/**
 * Buyer- and merchant-facing presentation hooks that surface Paymos data EDD
 * already stores on the order: the on-chain transaction hash + explorer link on
 * the purchase receipt and in the order-receipt email, and a Settings shortcut
 * on the Plugins screen. None touch the payment flow — they only render existing
 * order meta.
 */
final class StorefrontHooks
{
    public static function register()
    {
        add_action('edd_order_receipt_after', array(__CLASS__, 'receipt_transaction'), 10, 2);
        add_filter('edd_order_receipt', array(__CLASS__, 'email_transaction'), 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(PAYMOS_EDD_PLUGIN_FILE), array(__CLASS__, 'plugin_action_links'));
    }

    /**
     * Render the tx hash + explorer link beneath the EDD purchase receipt.
     *
     * @param object $order EDD order object (has ->id)
     * @param array<string, mixed> $receiptArgs
     */
    public static function receipt_transaction($order, $receiptArgs)
    {
        $paymentId = self::orderId($order);
        if ($paymentId === 0) {
            return;
        }

        $hash = PaymentRepository::getMeta($paymentId, '_paymos_tx_hash');
        if ($hash === '') {
            return;
        }

        $explorer = PaymentRepository::getMeta($paymentId, '_paymos_explorer_url');

        echo '<tr><td><strong>' . esc_html__('Payment confirmation', 'paymos-easy-digital-downloads') . '</strong></td><td>';
        if ($explorer !== '') {
            echo '<a href="' . esc_url($explorer) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__('View transaction', 'paymos-easy-digital-downloads') . '</a>';
        } else {
            echo '<code>' . esc_html($hash) . '</code>';
        }
        echo '</td></tr>';
    }

    /**
     * Append the tx hash + explorer link to the EDD order-receipt email body.
     *
     * @param string $bodyContent
     * @param object $order EDD order object (has ->id)
     * @return string
     */
    public static function email_transaction($bodyContent, $order)
    {
        $paymentId = self::orderId($order);
        if ($paymentId === 0) {
            return $bodyContent;
        }

        $hash = PaymentRepository::getMeta($paymentId, '_paymos_tx_hash');
        if ($hash === '') {
            return $bodyContent;
        }

        $explorer = PaymentRepository::getMeta($paymentId, '_paymos_explorer_url');

        $block = '<p><strong>' . esc_html__('Payment confirmation', 'paymos-easy-digital-downloads') . '</strong><br>';
        if ($explorer !== '') {
            $block .= esc_html__('Settled on-chain.', 'paymos-easy-digital-downloads') . ' '
                . '<a href="' . esc_url($explorer) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__('View transaction', 'paymos-easy-digital-downloads') . '</a>.';
        } else {
            $block .= esc_html__('Transaction:', 'paymos-easy-digital-downloads') . ' ' . esc_html($hash);
        }
        $block .= '</p>';

        return $bodyContent . $block;
    }

    /**
     * @param array<int|string, string> $actions
     * @return array<int|string, string>
     */
    public static function plugin_action_links($actions)
    {
        $url = admin_url('admin.php?page=edd-settings&tab=gateways&section=paymos');
        $link = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'paymos-easy-digital-downloads') . '</a>';
        array_unshift($actions, $link);

        return $actions;
    }

    /**
     * @param mixed $order
     * @return int
     */
    private static function orderId($order)
    {
        if (is_object($order) && isset($order->id)) {
            return (int) $order->id;
        }

        if (is_numeric($order)) {
            return (int) $order;
        }

        return 0;
    }
}
