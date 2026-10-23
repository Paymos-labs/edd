<?php

declare(strict_types=1);

use PaymosEasyDigitalDownloads\Gateway;

function test_edd_gateway_creates_invoice_stores_snapshot_and_redirects()
{
    paymos_edd_reset_test_state();
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

    $invoices = new FakePaymosInvoices();
    Gateway::set_client_factory_for_tests(static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    Gateway::process_payment(paymos_edd_purchase());

    assertSameValue(1, count($invoices->payloads), 'One Paymos invoice should be created.');
    assertSameValue('100', $invoices->payloads[0]['amount'], 'Invoice amount should match purchase.');
    assertSameValue('USD', $invoices->payloads[0]['currency'], 'Invoice currency should match EDD currency.');
    assertTrueValue((bool) preg_match('/^edd_100_[a-f0-9]{32}$/', $invoices->payloads[0]['external_order_id']), 'External order id must be opaque and bound to EDD payment id.');

    assertSameValue('inv_123', $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_invoice_id'], 'Invoice id should be stored.');
    assertSameValue('sandbox', $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_environment'], 'Environment should be stored.');
    assertSameValue('100', $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_invoice_amount'], 'Amount snapshot should be stored.');
    assertSameValue(array(0),$GLOBALS['paymos_edd_locks_at_success_exit'],'Order mutex must be released before production redirect can exit.');
    assertSameValue('https://checkout.paymos.io/pay/inv_123', end($GLOBALS['paymos_edd_redirects']), 'Customer should redirect to Paymos.');
}
