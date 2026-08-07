<?php

declare(strict_types=1);

use PaymosEasyDigitalDownloads\PaymentMapper;

// An underpaid invoice and a reorg rollback share one action but not one meaning:
// only the first is something the customer can resolve by sending more. Reporting
// both as "confirming" hid that from the merchant — see
// .claude/memory/catalog/plugins/live-cms-test-log.md, finding L-7.

function test_edd_payment_mapper_names_the_shortfall_on_an_underpaid_invoice()
{
    paymos_edd_reset_test_state();
    $paymentId = 4242;

    (new PaymentMapper())->apply($paymentId, array(
        'event_type' => 'invoice.underpaid_waiting',
        'data' => array(
            'status' => 'underpaid_waiting',
            'payment' => array('currency' => 'USDT', 'paid' => '4.94', 'remaining' => '7.41'),
        ),
    ));

    $note = implode(' ', $GLOBALS['paymos_edd_payment_notes'][$paymentId] ?? array());
    assertTrueValue(strpos($note, '7.41') !== false, 'The outstanding amount must be named in the payment note.');
    assertTrueValue(strpos($note, 'USDT') !== false, 'The token must be named in the payment note.');
    assertTrueValue(
        stripos($note, 'confirming') === false,
        'An underpaid invoice must not be reported as a payment that is confirming.'
    );
}

function test_edd_payment_mapper_reports_a_rolled_back_payment_without_inventing_a_shortfall()
{
    paymos_edd_reset_test_state();
    $paymentId = 4243;

    (new PaymentMapper())->apply($paymentId, array(
        'event_type' => 'invoice.awaiting_payment',
        'data' => array('status' => 'awaiting_payment', 'payment' => array('currency' => 'USDT', 'remaining' => '0')),
    ));

    $note = implode(' ', $GLOBALS['paymos_edd_payment_notes'][$paymentId] ?? array());
    assertTrueValue(
        stripos($note, 'outstanding') === false,
        'A reorg rollback must not tell the merchant the customer still owes money.'
    );
}

function test_edd_payment_mapper_still_reports_a_confirming_payment_as_confirming()
{
    paymos_edd_reset_test_state();
    $paymentId = 4244;

    (new PaymentMapper())->apply($paymentId, array(
        'event_type' => 'invoice.confirming',
        'data' => array('status' => 'confirming'),
    ));

    $note = implode(' ', $GLOBALS['paymos_edd_payment_notes'][$paymentId] ?? array());
    assertTrueValue(stripos($note, 'confirming') !== false, 'A confirming payment must still say so.');
}
