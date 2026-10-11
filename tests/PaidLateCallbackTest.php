<?php

declare(strict_types=1);

use PaymosEasyDigitalDownloads\PaymentMapper;

// invoice-late-payment: a paid_late event on an invoice the payment already holds a FINAL status for
// (expired) produces exactly ONE payment note — never a status change or completion — and a
// sequential duplicate stays silent.

function edd_paid_late_event($eventId)
{
    return array(
        'event_id' => $eventId,
        'event_type' => 'invoice.paid_late',
        'occurred_at' => 1777978800,
        'data' => array(
            'invoice_id' => 'inv_late_1',
            'status' => 'expired',
            'paid_late_at' => 1777978800,
            'order' => array('external_id' => 'edd_4242_0', 'amount' => '100.00', 'currency' => 'USD'),
        ),
    );
}

function test_edd_paid_late_notes_once_and_changes_nothing()
{
    paymos_edd_reset_test_state();
    $paymentId = 4242;
    $GLOBALS['paymos_edd_payment_meta'][$paymentId]['_paymos_last_status'] = 'expired';

    (new PaymentMapper())->apply($paymentId, edd_paid_late_event('evt_late_1'));

    $notes = $GLOBALS['paymos_edd_payment_notes'][$paymentId] ?? array();
    assertTrueValue(count($notes) === 1, 'paid_late must add exactly one payment note.');
    assertTrueValue(($GLOBALS['paymos_edd_payments'][$paymentId]['status'] ?? null) === null, 'paid_late must never change the payment status.');
    assertSameValue('evt_late_1', $GLOBALS['paymos_edd_payment_meta'][$paymentId]['_paymos_paid_late_note:inv_late_1'], 'marker records the event id.');
    assertSameValue('expired', $GLOBALS['paymos_edd_payment_meta'][$paymentId]['_paymos_last_status'], 'the recorded final status stays untouched.');
}

function test_edd_paid_late_sequential_duplicate_is_silent()
{
    paymos_edd_reset_test_state();
    $paymentId = 4242;
    $GLOBALS['paymos_edd_payment_meta'][$paymentId]['_paymos_last_status'] = 'expired';
    $mapper = new PaymentMapper();

    $mapper->apply($paymentId, edd_paid_late_event('evt_late_1'));
    $mapper->apply($paymentId, edd_paid_late_event('evt_late_2'));

    $notes = $GLOBALS['paymos_edd_payment_notes'][$paymentId] ?? array();
    assertTrueValue(count($notes) === 1, 'a sequential duplicate paid_late must not add a second note.');
    assertTrueValue(($GLOBALS['paymos_edd_payments'][$paymentId]['status'] ?? null) === null, 'duplicates stay inert.');
}
