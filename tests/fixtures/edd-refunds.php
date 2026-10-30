<?php

declare(strict_types=1);

// Keep EDD 3.7.2's hook ordering and legacy failure fallback observable without WordPress.
final class FakeEddRefundError
{
    public $code;

    public function __construct($code)
    {
        $this->code = $code;
    }
}

function edd_refund_order($orderId, $orderItems = 'all', $adjustments = 'all')
{
    $orderId = (int) $orderId;
    if (!isset($GLOBALS['paymos_edd_payments'][$orderId])) {
        return new FakeEddRefundError('invalid_order');
    }
    if (true !== apply_filters('edd_should_process_order_refund', true, $orderId)) {
        return new FakeEddRefundError('refund_not_allowed');
    }

    $amount = $orderItems === 'all' ? (float) edd_get_order_total($orderId) : (float) $orderItems[0]['subtotal'];
    $refundId = $GLOBALS['paymos_edd_next_payment_id']++;
    $GLOBALS['paymos_edd_payments'][$refundId] = array(
        'id' => $refundId, 'parent' => $orderId, 'type' => 'refund',
        'gateway' => edd_get_payment_gateway($orderId), 'status' => 'complete', 'price' => -$amount,
    );
    $GLOBALS['paymos_edd_native_refund_writes'][] = array('insert', $refundId);
    $allRefunded = edd_get_order_total($orderId) <= 0;
    edd_update_order($orderId, array('status' => $allRefunded ? 'refunded' : 'partially_refunded'));
    do_action('edd_refund_order', $orderId, $refundId, $allRefunded);
    return $refundId;
}

function edd_get_order_total($orderId)
{
    $total = (float) $GLOBALS['paymos_edd_payments'][(int) $orderId]['price'];
    foreach ($GLOBALS['paymos_edd_payments'] as $row) {
        if (isset($row['type'], $row['parent']) && $row['type'] === 'refund' && $row['parent'] === (int) $orderId) {
            $total += (float) $row['price'];
        }
    }
    return $total;
}

function edd_update_order($orderId, array $data)
{
    if (!empty($GLOBALS['paymos_edd_fail_status'])) {
        return false;
    }
    if (isset($data['status']) && $data['status'] === 'complete') {
        $data['status'] = 'publish';
        $GLOBALS['paymos_edd_completion_count']++;
    }
    $GLOBALS['paymos_edd_payments'][(int) $orderId] = array_merge($GLOBALS['paymos_edd_payments'][(int) $orderId], $data);
    unset($GLOBALS['paymos_edd_row_cache'][(int) $orderId]);
    $GLOBALS['paymos_edd_native_refund_writes'][] = array('update', (int) $orderId, $data);
    return true;
}

function edd_update_order_status($orderId, $status)
{
    if (!isset($GLOBALS['paymos_edd_payments'][(int) $orderId])) {
        return false;
    }
    $oldStatus = edd_get_payment_status($orderId);
    $allowed = apply_filters('edd_should_update_payment_status', true, $orderId, $status, $oldStatus);
    $allowed = apply_filters('edd_should_update_order_status', $allowed, $orderId, $status, $oldStatus);
    if (!$allowed) {
        return false;
    }
    $payment = new EDD_Payment($orderId);
    $payment->status = $status;
    return $payment->save();
}

final class EDD_Payment
{
    public $ID;
    public $status;
    public $old_status;

    public function __construct($orderId)
    {
        $this->ID = (int) $orderId;
        $this->status = edd_get_payment_status($orderId);
        $this->old_status = $this->status;
    }

    public function refund()
    {
        $this->old_status = $this->status;
        $this->status = 'refunded';
        $this->save();
    }

    public function save()
    {
        return $this->update_status($this->status);
    }

    public function update_status($status = '')
    {
        if (!apply_filters('edd_should_update_payment_status', true, $this->ID, $status, $this->old_status)) {
            return false;
        }
        if ($status !== 'refunded') {
            return edd_update_order($this->ID, array('status' => $status));
        }
        $refund = edd_refund_order($this->ID);
        // Core still writes Refunded when edd_refund_order returns an error.
        if ($refund instanceof FakeEddRefundError) {
            edd_update_order($this->ID, array('status' => $status));
            edd_insert_payment_note($this->ID, 'The refund order could not be created, but the order status was manually set to Refunded.');
        }
        return true;
    }
}

function edd_ajax_process_refund_form()
{
    parse_str($_POST['data'], $formData);
    $items = array();
    foreach ($formData['refund_order_item'] as $itemId => $item) {
        if (empty($item['quantity']) || (empty($item['subtotal']) && empty($item['tax']))) {
            continue;
        }
        $items[] = array(
            'order_item_id' => (int) $itemId, 'quantity' => (int) $item['quantity'],
            'subtotal' => $item['subtotal'], 'tax' => $item['tax'],
        );
    }
    $GLOBALS['paymos_edd_refund_form_result'] = edd_refund_order((int) $_POST['order_id'], $items, array());
}

add_action('wp_ajax_edd_process_refund_form', 'edd_ajax_process_refund_form');
