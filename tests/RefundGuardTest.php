<?php

declare(strict_types=1);

function paymos_edd_refund_test_state($gateway = 'paymos')
{
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_payments'][100]['gateway'] = $gateway;
    $GLOBALS['paymos_edd_payments'][100]['status'] = 'publish';
    $GLOBALS['paymos_edd_next_payment_id'] = 101;
    $GLOBALS['paymos_edd_native_refund_writes'] = array();
    $GLOBALS['paymos_edd_refund_form_result'] = null;
    return $GLOBALS['paymos_edd_payments'];
}

function paymos_edd_assert_refund_unchanged(array $before)
{
    assertSameValue($before, $GLOBALS['paymos_edd_payments'], 'A rejected refund must preserve every order, amount and status.');
    assertSameValue(array(), $GLOBALS['paymos_edd_native_refund_writes'], 'A rejected refund must not persist a refund or a status change.');
    assertSameValue(array(), $GLOBALS['paymos_edd_payment_notes'], 'A rejected refund must not write a fallback note.');
    assertSameValue(100.0, edd_get_order_total(100), 'The net order amount must stay unchanged.');
}

function paymos_edd_refund_form_post($amount)
{
    return array('order_id' => '100', 'data' => http_build_query(array(
        'edd_process_refund' => 'test-nonce',
        'refund_order_item' => array(1 => array('quantity' => 1, 'subtotal' => $amount, 'tax' => '0.00')),
    )));
}

function test_edd_refund_form_blocks_full_and_partial_paymos_refunds_before_writes()
{
    $originalPost = $_POST;
    try {
        foreach (array('100.00', '25.00') as $amount) {
            $before = paymos_edd_refund_test_state();
            $_POST = paymos_edd_refund_form_post($amount);
            do_action('wp_ajax_edd_process_refund_form');
            assertSameValue('refund_not_allowed', $GLOBALS['paymos_edd_refund_form_result']->code, 'The form must receive the native refusal.');
            paymos_edd_assert_refund_unchanged($before);
        }
    } finally {
        $_POST = $originalPost;
    }
}

function test_edd_refund_form_preserves_other_gateways_full_and_partial_refunds()
{
    $originalPost = $_POST;
    try {
        foreach (array('all' => 100.0, 'partial' => 25.0) as $kind => $amount) {
            paymos_edd_refund_test_state('paypal');
            $_POST = paymos_edd_refund_form_post($amount);
            do_action('wp_ajax_edd_process_refund_form');
            assertSameValue(101, $GLOBALS['paymos_edd_refund_form_result'], 'Other gateways must still create a refund order.');
            assertSameValue('refund', $GLOBALS['paymos_edd_payments'][101]['type'], 'The refund must be persisted.');
            assertSameValue(-$amount, $GLOBALS['paymos_edd_payments'][101]['price'], 'The native refund amount must be retained.');
            assertSameValue(100.0 - $amount, edd_get_order_total(100), 'The native net total must include the refund.');
            assertSameValue($kind === 'all' ? 'refunded' : 'partially_refunded', edd_get_payment_status(100), 'The native refund status must be retained.');
        }
    } finally {
        $_POST = $originalPost;
    }
}

function test_edd_direct_refund_blocks_paymos_without_writes_or_status_change()
{
    foreach (array('all', array(array('subtotal' => '25.00'))) as $items) {
        $before = paymos_edd_refund_test_state();
        $result = edd_refund_order(100, $items);
        assertSameValue('refund_not_allowed', $result->code, 'Direct PHP refunds must be rejected.');
        paymos_edd_assert_refund_unchanged($before);
    }
}

function test_edd_direct_refund_preserves_other_gateway_behavior()
{
    foreach (array('all' => 100.0, 'partial' => 25.0) as $kind => $amount) {
        paymos_edd_refund_test_state('stripe');
        assertSameValue(101, edd_refund_order(100, $kind === 'all' ? 'all' : array(array('subtotal' => $amount))), 'Other gateway refunds must succeed.');
        assertSameValue(-$amount, $GLOBALS['paymos_edd_payments'][101]['price'], 'Other gateway refund totals must stay native.');
        assertSameValue($kind === 'all' ? 'refunded' : 'partially_refunded', edd_get_payment_status(100), 'Other gateway refund status must stay native.');
    }
}

function test_edd_legacy_payment_refund_and_status_save_cannot_write_paymos_refunds()
{
    foreach (array('refund', 'update_status', 'save') as $method) {
        $before = paymos_edd_refund_test_state();
        $payment = new EDD_Payment(100);
        if ($method === 'save') {
            $payment->status = 'refunded';
            $payment->save();
        } elseif ($method === 'update_status') {
            assertSameValue(false, $payment->update_status('refunded'), 'The legacy status API must refuse.');
        } else {
            $payment->refund();
        }
        paymos_edd_assert_refund_unchanged($before);
    }
}

function test_edd_legacy_payment_refund_and_status_save_preserve_other_gateways()
{
    foreach (array('refund', 'update_status', 'save') as $method) {
        paymos_edd_refund_test_state('paypal');
        $payment = new EDD_Payment(100);
        if ($method === 'save') {
            $payment->status = 'refunded';
            $payment->save();
        } elseif ($method === 'update_status') {
            $payment->update_status('refunded');
        } else {
            $payment->refund();
        }
        assertSameValue('refund', $GLOBALS['paymos_edd_payments'][101]['type'], 'The legacy API must still create other gateway refunds.');
        assertSameValue('refunded', edd_get_payment_status(100), 'The legacy API must still update other gateway status.');
    }
}

function test_edd_payment_and_order_status_apis_block_both_paymos_refund_states()
{
    foreach (array('edd_update_payment_status', 'edd_update_order_status') as $api) {
        foreach (array('refunded', 'partially_refunded') as $status) {
            $before = paymos_edd_refund_test_state();
            assertSameValue(false, $api(100, $status), 'Native status APIs must reject Paymos refund states.');
            paymos_edd_assert_refund_unchanged($before);
        }
    }
}

function test_edd_payment_and_order_status_apis_preserve_other_gateway_refund_states()
{
    foreach (array('edd_update_payment_status', 'edd_update_order_status') as $api) {
        foreach (array('refunded', 'partially_refunded') as $status) {
            paymos_edd_refund_test_state('stripe');
            assertSameValue(true, $api(100, $status), 'Other gateways must retain native status updates.');
            assertSameValue($status, edd_get_payment_status(100), 'Other gateway status must be persisted.');
        }
    }
}

function test_edd_refund_guards_preserve_earlier_vetoes_and_unrelated_status_changes()
{
    $hooks = $GLOBALS['paymos_edd_hooks'];
    try {
        foreach (array('paymos', 'stripe') as $gateway) {
            $before = paymos_edd_refund_test_state($gateway);
            add_filter('edd_should_process_order_refund', '__return_false', 5, 2);
            assertSameValue('refund_not_allowed', edd_refund_order(100)->code, 'An earlier veto must be retained.');
            add_filter('edd_should_update_payment_status', '__return_false', 5, 4);
            assertSameValue(false, edd_update_payment_status(100, 'pending'), 'An unrelated status veto must be retained.');
            paymos_edd_assert_refund_unchanged($before);
            $GLOBALS['paymos_edd_hooks'] = $hooks;
            assertSameValue(true, edd_update_payment_status(100, 'pending'), 'Ordinary status changes must remain available.');
            assertSameValue('pending', edd_get_payment_status(100), 'Ordinary status updates must still persist.');
        }
    } finally {
        $GLOBALS['paymos_edd_hooks'] = $hooks;
    }
}

function test_edd_order_status_guard_blocks_paymos_even_if_an_earlier_order_filter_allows_it()
{
    $hooks = $GLOBALS['paymos_edd_hooks'];
    try {
        $before = paymos_edd_refund_test_state();
        add_filter('edd_should_update_order_status', static function () { return true; }, 10, 4);
        assertSameValue(false, edd_update_order_status(100, 'refunded'), 'The order status filter must independently veto before persistence.');
        paymos_edd_assert_refund_unchanged($before);
    } finally {
        $GLOBALS['paymos_edd_hooks'] = $hooks;
    }
}
