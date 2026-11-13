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
    $GLOBALS['paymos_edd_payments'][$paymentId] = array('id'=>$paymentId, 'status'=>'pending', 'gateway'=>'paymos');
    $GLOBALS['paymos_edd_payment_meta'][$paymentId]['_paymos_last_status'] = 'expired';

    (new PaymentMapper())->apply($paymentId, edd_paid_late_event('evt_late_1'));

    $notes = $GLOBALS['paymos_edd_payment_notes'][$paymentId] ?? array();
    assertTrueValue(count($notes) === 1, 'paid_late must add exactly one payment note.');
    assertTrueValue(($GLOBALS['paymos_edd_payments'][$paymentId]['status'] ?? null) === 'pending', 'paid_late must never change the payment status.');
    assertSameValue('evt_late_1', $GLOBALS['paymos_edd_payment_meta'][$paymentId]['_paymos_paid_late_note:inv_late_1'], 'marker records the event id.');
    assertSameValue('expired', $GLOBALS['paymos_edd_payment_meta'][$paymentId]['_paymos_last_status'], 'the recorded final status stays untouched.');
}

function test_edd_paid_late_sequential_duplicate_is_silent()
{
    paymos_edd_reset_test_state();
    $paymentId = 4242;
    $GLOBALS['paymos_edd_payments'][$paymentId] = array('id'=>$paymentId, 'status'=>'pending', 'gateway'=>'paymos');
    $GLOBALS['paymos_edd_payment_meta'][$paymentId]['_paymos_last_status'] = 'expired';
    $mapper = new PaymentMapper();

    $mapper->apply($paymentId, edd_paid_late_event('evt_late_1'));
    $mapper->apply($paymentId, edd_paid_late_event('evt_late_2'));

    $notes = $GLOBALS['paymos_edd_payment_notes'][$paymentId] ?? array();
    assertTrueValue(count($notes) === 1, 'a sequential duplicate paid_late must not add a second note.');
    assertTrueValue(($GLOBALS['paymos_edd_payments'][$paymentId]['status'] ?? null) === 'pending', 'duplicates stay inert.');
}

function r31_edd_renewed_payment()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_resume_id'] = 100;
    $GLOBALS['paymos_edd_payments'][100]['status'] = 'failed';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_applied_status'] = 'expired';
    $invoices = new FakeEddRenewalInvoices(array(paymos_edd_checkout_invoice('inv_123', 'expired'), paymos_edd_checkout_invoice('inv_fresh', 'awaiting_client')));
    PaymosEasyDigitalDownloads\Gateway::set_client_factory_for_tests(static function () use ($invoices) { return new FakePaymosClient($invoices); });
    PaymosEasyDigitalDownloads\Gateway::process_payment(paymos_edd_purchase());
    assertSameValue(array('invoice_id' => 'inv_123', 'external_order_id' => 'edd_100_abc123', 'project_id' => 'prj_123',
        'environment' => 'sandbox', 'amount' => '100.00', 'currency' => 'USD'),
        PaymosEasyDigitalDownloads\PaymentRepository::historicalInvoiceBinding(100, 'inv_123'), 'Renewal preserves exact history.');
    $GLOBALS['paymos_edd_payments'][100]['status'] = 'publish';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_applied_status'] = 'paid';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_last_status'] = 'paid';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_last_event_type'] = 'invoice.paid';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_last_event_id'] = 'evt_current_paid';
    $GLOBALS['paymos_edd_payment_notes'][100] = array();
}

function test_edd_r31_historical_paid_late_after_renewal_notes_once_without_touching_paid_generation()
{
    r31_edd_renewed_payment();
    $before = $GLOBALS['paymos_edd_payment_meta'][100];
    foreach (array('evt_history_late_1', 'evt_history_late_2') as $id) {
        $event = paymos_edd_invoice_event($id, 'invoice.paid_late', 'expired', array('data' => array('paid_late_at' => time())));
        PaymosEasyDigitalDownloads\WebhookController::set_client_factory_for_tests(static function () use ($event) {
            return paymos_edd_reverse_verify_client($event['data']);
        });
        assertSameValue(200, paymos_edd_deliver($event)->get_status(), 'Historical late payment must be accepted.');
    }
    assertSameValue(1, count($GLOBALS['paymos_edd_payment_notes'][100]), 'One historical invoice produces one note.');
    assertSameValue('evt_history_late_1', $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_paid_late_note:inv_123'], 'Marker belongs to A.');
    $after = $GLOBALS['paymos_edd_payment_meta'][100];
    unset($after['_paymos_paid_late_note:inv_123']);
    assertSameValue($before, $after, 'Current generation and its event metadata remain intact.');
    assertSameValue(0, $GLOBALS['paymos_edd_completion_count'], 'History never grants downloads.');
    assertSameValue('publish', edd_get_payment_status(100), 'Current native payment stays complete.');
}

function test_edd_r31_historical_binding_rejects_unowned_or_unverified_events()
{
    foreach (array('ordinary', 'unknown', 'project', 'amount', 'currency', 'external', 'environment', 'api', 'gateway') as $case) {
        r31_edd_renewed_payment();
        $event = paymos_edd_invoice_event('evt_history_bad_' . $case, 'invoice.paid_late', 'expired', array('data' => array('paid_late_at' => time())));
        if ($case === 'ordinary') { $event['event_type'] = 'invoice.confirming'; $event['data']['status'] = 'confirming'; }
        if ($case === 'unknown') { $event['data']['invoice_id'] = 'inv_unknown'; }
        if ($case === 'project') { $event['data']['project_id'] = 'prj_other'; }
        if ($case === 'amount') { $event['data']['order']['amount'] = '999'; }
        if ($case === 'currency') { $event['data']['order']['currency'] = 'EUR'; }
        if ($case === 'external') { $event['data']['order']['external_id'] = 'edd_100_wrong'; }
        if ($case === 'environment') { $event['data']['is_test'] = false; }
        if ($case === 'gateway') { $GLOBALS['paymos_edd_payments'][100]['gateway'] = 'other'; }
        $invoice = $event['data'];
        if ($case === 'api') { $invoice['invoice_id'] = 'inv_other'; }
        PaymosEasyDigitalDownloads\WebhookController::set_client_factory_for_tests(static function () use ($invoice) { return paymos_edd_reverse_verify_client($invoice); });
        $before = $GLOBALS['paymos_edd_payment_meta'][100];
        assertSameValue(400, paymos_edd_deliver($event)->get_status(), 'Unverified historical event must be rejected: ' . $case);
        assertSameValue($before, $GLOBALS['paymos_edd_payment_meta'][100], 'Rejected history must not mutate metadata.');
        assertSameValue(array(), $GLOBALS['paymos_edd_payment_notes'][100], 'Rejected history must not produce a note.');
    }
}

function test_edd_r31_failed_history_save_stops_replacement_before_second_post()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_resume_id'] = 100;
    $GLOBALS['paymos_edd_fail_meta_key'] = '_paymos_invoice_history';
    $invoices = new FakeEddRenewalInvoices(array(paymos_edd_checkout_invoice('inv_123', 'expired'), paymos_edd_checkout_invoice('inv_fresh', 'awaiting_client')));
    PaymosEasyDigitalDownloads\Gateway::set_client_factory_for_tests(static function () use ($invoices) { return new FakePaymosClient($invoices); });
    PaymosEasyDigitalDownloads\Gateway::process_payment(paymos_edd_purchase());
    assertSameValue(1, count($invoices->attempts), 'A failed history save must prevent replacement POST.');
    assertSameValue('inv_123', PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100, '_paymos_invoice_id'), 'Original binding survives failed history save.');
}

function r31_edd_pending_late_fixture($differentInvoice, &$transport)
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_invoice_id'] = '';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_creation_intent'] = 'pending';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_client_id'] = '77';
    $event = paymos_edd_invoice_event('evt_current_pending_late_' . ($differentInvoice ? 'wrong' : 'ok'),
        'invoice.paid_late', 'expired', array('data' => array('paid_late_at' => time())));
    $invoice = $event['data'];
    if ($differentInvoice) { $invoice['invoice_id'] = 'inv_independent_other'; }
    $transport = new Paymos\Http\MockTransport(array(
        new Paymos\Http\HttpResponse(200, json_encode($invoice), array()),
        new Paymos\Http\HttpResponse(200, json_encode($invoice), array()),
    ));
    $client = new Paymos\Client(new Paymos\ClientConfig('pk_test', 'sk_test', 'https://api.paymos.test'), $transport);
    PaymosEasyDigitalDownloads\WebhookController::set_client_factory_for_tests(static function () use ($client) { return $client; });
    return $event;
}

function test_edd_r31_current_pending_paid_late_recovers_exact_intent_before_note()
{
    $event = r31_edd_pending_late_fixture(false, $transport);
    assertSameValue(200, paymos_edd_deliver($event)->get_status(), 'Current pending late payment must recover the exact intent.');
    assertSameValue(array('POST', 'GET'), array_column($transport->requests(), 'method'), 'Recover independent ID, then reverse verify.');
    assertSameValue(array('project_id' => 'prj_123', 'external_order_id' => 'edd_100_abc123',
        'amount' => '100.00', 'currency' => 'USD', 'client_id' => '77'), json_decode($transport->requests()[0]['body'], true), 'Recovery retains all durable POST fields.');
    assertSameValue('inv_123', PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100, '_paymos_invoice_id'), 'Only independent response binds current invoice.');
    assertSameValue('bound', PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100, '_paymos_creation_intent'), 'Current generation is bound.');
    assertSameValue(1, count($GLOBALS['paymos_edd_payment_notes'][100]), 'Late payment adds one note.');
    assertSameValue(0, $GLOBALS['paymos_edd_completion_count'], 'Late payment never grants downloads.');
    assertSameValue('pending', edd_get_payment_status(100), 'Late payment does not change native status.');
}

function test_edd_r31_current_pending_paid_late_rejects_other_recovered_invoice()
{
    $event = r31_edd_pending_late_fixture(true, $transport);
    assertSameValue(400, paymos_edd_deliver($event)->get_status(), 'Late event cannot choose recovered invoice ID.');
    assertSameValue(array('POST'), array_column($transport->requests(), 'method'), 'Wrong independent ID is rejected before GET.');
    assertSameValue('', PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100, '_paymos_invoice_id'), 'Callback cannot supply a binding.');
    assertSameValue(array(), $GLOBALS['paymos_edd_payment_notes'][100] ?? array(), 'Rejected current late event adds no note.');
}
