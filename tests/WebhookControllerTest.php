<?php

declare(strict_types=1);

use PaymosEasyDigitalDownloads\WebhookController;

function test_edd_webhook_completes_paid_payment_after_signature_and_reverse_lookup()
{
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();

    WebhookController::set_client_factory_for_tests(static function () {
        return paymos_edd_reverse_verify_client();
    });

    $body = json_encode(paymos_edd_invoice_event('evt_paid_1', 'invoice.paid', 'paid'));
    $request = new WP_REST_Request($body, array('x-webhook-signature' => paymos_edd_signed_header('whsec_sandbox', $body)));

    $response = WebhookController::handle($request);

    assertSameValue(200, $response->get_status(), 'Paid webhook should return 200.');
    assertSameValue('publish', $GLOBALS['paymos_edd_payments'][100]['status'], 'EDD payment should be completed (status key "publish").');
    assertSameValue('inv_123', $GLOBALS['paymos_edd_payments'][100]['transaction_id'], 'EDD transaction id should be invoice id when no transfer hash exists.');
}

function test_edd_webhook_does_not_roll_back_completed_payment_on_late_cancel()
{
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();

    WebhookController::set_client_factory_for_tests(static function () {
        return paymos_edd_reverse_verify_client();
    });

    // First: a paid webhook completes the payment (stored as 'publish').
    $paidBody = json_encode(paymos_edd_invoice_event('evt_paid_rb', 'invoice.paid', 'paid'));
    WebhookController::handle(new WP_REST_Request($paidBody, array('x-webhook-signature' => paymos_edd_signed_header('whsec_sandbox', $paidBody))));
    assertSameValue('publish', $GLOBALS['paymos_edd_payments'][100]['status'], 'Precondition: payment completed.');

    // Then: a stale cancelled webhook arrives (and the API now reports cancelled,
    // so reverse-verify passes and the event reaches the mapper). The roll-back
    // guard MUST keep the payment complete — it regresses if status() reads a
    // localized label instead of the status key.
    WebhookController::set_client_factory_for_tests(static function () {
        return paymos_edd_reverse_verify_client(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'cancelled',
            'order' => array('external_id' => 'edd_100_abc123', 'amount' => '100', 'currency' => 'USD'),
        ));
    });
    $cancelBody = json_encode(paymos_edd_invoice_event('evt_cancel_rb', 'invoice.cancelled', 'cancelled'));
    $response = WebhookController::handle(new WP_REST_Request($cancelBody, array('x-webhook-signature' => paymos_edd_signed_header('whsec_sandbox', $cancelBody))));

    assertSameValue(200, $response->get_status(), 'Late cancel webhook should still return 200.');
    assertSameValue('publish', $GLOBALS['paymos_edd_payments'][100]['status'], 'Roll-back guard must keep a completed payment complete after a late cancel.');
}

function test_edd_webhook_persists_tx_hash_and_explorer_url()
{
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();

    WebhookController::set_client_factory_for_tests(static function () {
        return paymos_edd_reverse_verify_client();
    });

    $body = json_encode(paymos_edd_invoice_event('evt_paid_tx', 'invoice.paid', 'paid', array(
        'data' => array(
            'payment' => array(
                'transfers' => array(
                    array('tx_hash' => '0xconfirming', 'status' => 'confirming'),
                    array('tx_hash' => '0xconfirmed', 'status' => 'confirmed', 'explorer_url' => 'https://etherscan.io/tx/0xconfirmed'),
                ),
            ),
        ),
    )));
    $response = WebhookController::handle(new WP_REST_Request($body, array('x-webhook-signature' => paymos_edd_signed_header('whsec_sandbox', $body))));

    assertSameValue(200, $response->get_status(), 'Paid webhook with transfers should return 200.');
    assertSameValue('0xconfirmed', PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100, '_paymos_tx_hash'), 'Confirmed tx hash must be persisted as meta.');
    assertSameValue('https://etherscan.io/tx/0xconfirmed', PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100, '_paymos_explorer_url'), 'Confirmed transfer explorer url must be persisted as meta.');
    assertSameValue('0xconfirmed', $GLOBALS['paymos_edd_payments'][100]['transaction_id'], 'Transaction id must be the confirmed tx hash.');
}

function test_edd_webhook_rejects_bad_signature()
{
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();

    $body = json_encode(paymos_edd_invoice_event('evt_bad_sig', 'invoice.paid', 'paid'));
    $request = new WP_REST_Request($body, array('x-webhook-signature' => paymos_edd_signed_header('wrong_secret', $body)));

    $response = WebhookController::handle($request);

    assertSameValue(401, $response->get_status(), 'Bad signature should return 401.');
    assertSameValue('pending', $GLOBALS['paymos_edd_payments'][100]['status'], 'Bad signature must not update payment.');
}

function test_edd_webhook_duplicate_event_is_idempotent()
{
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();

    WebhookController::set_client_factory_for_tests(static function () {
        return paymos_edd_reverse_verify_client();
    });

    $body = json_encode(paymos_edd_invoice_event('evt_dup', 'invoice.paid', 'paid'));
    $signature = paymos_edd_signed_header('whsec_sandbox', $body);

    $first = WebhookController::handle(new WP_REST_Request($body, array('x-webhook-signature' => $signature)));
    $second = WebhookController::handle(new WP_REST_Request($body, array('x-webhook-signature' => $signature)));

    assertSameValue(200, $first->get_status(), 'First webhook should succeed.');
    assertSameValue(200, $second->get_status(), 'Duplicate webhook should still return 200.');
    assertSameValue(true, isset($second->data['duplicate']), 'Second webhook should be marked duplicate.');
}

function test_edd_webhook_keeps_payment_pending_on_amount_mismatch()
{
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_payments'][100]['price'] = '120.00';

    WebhookController::set_client_factory_for_tests(static function () {
        return paymos_edd_reverse_verify_client();
    });

    $body = json_encode(paymos_edd_invoice_event('evt_amount_mismatch', 'invoice.paid', 'paid'));
    $request = new WP_REST_Request($body, array('x-webhook-signature' => paymos_edd_signed_header('whsec_sandbox', $body)));

    $response = WebhookController::handle($request);

    assertSameValue(200, $response->get_status(), 'Webhook with paid invoice should still be accepted.');
    assertSameValue('pending', $GLOBALS['paymos_edd_payments'][100]['status'], 'Amount mismatch should not complete payment.');
    assertSameValue('yes', $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_amount_mismatch'], 'Amount mismatch should be recorded.');
}

function test_edd_webhook_cancelled_invoice_marks_payment_abandoned()
{
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();

    WebhookController::set_client_factory_for_tests(static function () {
        return paymos_edd_reverse_verify_client(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'cancelled',
            'order' => array(
                'external_id' => 'edd_100_abc123',
                'amount' => '100',
                'currency' => 'USD',
            ),
        ));
    });

    $body = json_encode(paymos_edd_invoice_event('evt_cancelled', 'invoice.cancelled', 'cancelled'));
    $request = new WP_REST_Request($body, array('x-webhook-signature' => paymos_edd_signed_header('whsec_sandbox', $body)));

    $response = WebhookController::handle($request);

    assertSameValue(200, $response->get_status(), 'Cancelled webhook should return 200.');
    assertSameValue('abandoned', $GLOBALS['paymos_edd_payments'][100]['status'], 'Cancelled invoice must map to the valid EDD status "abandoned", never "cancelled".');
}

function test_edd_webhook_expired_invoice_marks_payment_abandoned()
{
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();

    WebhookController::set_client_factory_for_tests(static function () {
        return paymos_edd_reverse_verify_client(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'expired',
            'order' => array(
                'external_id' => 'edd_100_abc123',
                'amount' => '100',
                'currency' => 'USD',
            ),
        ));
    });

    $body = json_encode(paymos_edd_invoice_event('evt_expired', 'invoice.expired', 'expired'));
    $request = new WP_REST_Request($body, array('x-webhook-signature' => paymos_edd_signed_header('whsec_sandbox', $body)));

    $response = WebhookController::handle($request);

    assertSameValue(200, $response->get_status(), 'Expired webhook should return 200.');
    assertSameValue('abandoned', $GLOBALS['paymos_edd_payments'][100]['status'], 'Expired invoice should map to "abandoned".');
}

function paymos_edd_seed_payment()
{
    paymos_edd_write_generated_config("array(
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_test_123',
                'api_secret' => 'sk_test_123',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_sandbox',
                'base_url' => 'https://api.paymos.test',
            ),
        ),
    )");

    $GLOBALS['paymos_edd_payments'][100] = array(
        'id' => 100,
        'price' => '100.00',
        'currency' => 'USD',
        'status' => 'pending',
    );
    $GLOBALS['paymos_edd_payment_meta'][100] = array(
        '_paymos_invoice_id' => 'inv_123',
        '_paymos_external_order_id' => 'edd_100_abc123',
        '_paymos_environment' => 'sandbox',
        '_paymos_project_id' => 'prj_123',
        '_paymos_invoice_amount' => '100.00',
        '_paymos_invoice_currency' => 'USD',
    );
}
