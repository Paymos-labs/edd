<?php

declare(strict_types=1);

use PaymosEasyDigitalDownloads\Gateway;
use PaymosEasyDigitalDownloads\WebhookController;
use PaymosEasyDigitalDownloads\EventStore;
use Paymos\Plugin\WordPressOrderLock;

function paymos_edd_deliver(array $event)
{
    $body = json_encode($event);
    return WebhookController::handle(new WP_REST_Request($body, array('x-webhook-signature'=>paymos_edd_signed_header('whsec_sandbox', $body))));
}

function paymos_edd_api_invoice(array $event)
{
    return array(
        'invoice_id'=>$event['data']['invoice_id'], 'project_id'=>$event['data']['project_id'],
        'status'=>$event['data']['status'], 'is_test'=>$event['data']['is_test'], 'order'=>$event['data']['order'],
    );
}

function test_edd_exact_decimals_preserve_kwd_jpy_and_large_values()
{
    foreach (array('33.104'=>'33.104', '123'=>'123', '0.100000000001'=>'0.100000000001', '999999999999999999999.0001'=>'999999999999999999999.0001') as $input=>$expected) {
        assertSameValue($expected, Gateway::formatAmount((string) $input), 'Decimal must preserve all significant digits.');
    }
    foreach (array('1e3','NaN','1,20',array()) as $invalid) {
        try { Gateway::formatAmount($invalid); throw new LogicException('Invalid decimal was accepted.'); }
        catch (RuntimeException $expected) {}
    }
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_payments'][100]['price']='33.104';
    $GLOBALS['paymos_edd_payments'][100]['currency']='KWD';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_invoice_amount']='33.10';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_invoice_currency']='KWD';
    $event=paymos_edd_invoice_event('evt_kwd','invoice.paid','paid',array('data'=>array('order'=>array('amount'=>'33.10','currency'=>'KWD'))));
    WebhookController::set_client_factory_for_tests(static function () use ($event) { return paymos_edd_reverse_verify_client(paymos_edd_api_invoice($event)); });
    assertSameValue(400,paymos_edd_deliver($event)->get_status(),'Insufficient KWD precision must not complete.');
    assertSameValue('pending',edd_get_payment_status(100),'The original three-decimal total stays unpaid.');
}

function test_edd_checkout_rejects_invalid_amount_before_native_insert()
{
    foreach (array('NaN','1e3','-1','0',array()) as $amount) {
        paymos_edd_reset_test_state();
        Gateway::process_payment(paymos_edd_purchase(array('price'=>$amount)));
        assertSameValue(array(),$GLOBALS['paymos_edd_payments'],'Invalid purchase must not be inserted.');
    }
}

function test_edd_webhook_requires_exact_nonempty_snapshot_and_gateway()
{
    foreach (array('invoice','external','empty_invoice','empty_external','empty_project','empty_environment','other_gateway','missing_payment') as $case) {
        paymos_edd_reset_test_state(); paymos_edd_seed_payment();
        $event=paymos_edd_invoice_event('evt_binding_'.$case,'invoice.paid','paid');
        if ($case==='invoice') {$event['data']['invoice_id']='inv_OTHER';}
        if ($case==='external') {$event['data']['order']['external_id']='edd_100_DIFFERENT';}
        foreach (array('empty_invoice'=>'_paymos_invoice_id','empty_external'=>'_paymos_external_order_id','empty_project'=>'_paymos_project_id','empty_environment'=>'_paymos_environment') as $name=>$key) {
            if ($case===$name) {$GLOBALS['paymos_edd_payment_meta'][100][$key]='';}
        }
        if ($case==='other_gateway') {$GLOBALS['paymos_edd_payments'][100]['gateway']='paypal';}
        if ($case==='missing_payment') {unset($GLOBALS['paymos_edd_payments'][100]);}
        WebhookController::set_client_factory_for_tests(static function () use ($event) { return paymos_edd_reverse_verify_client(paymos_edd_api_invoice($event)); });
        assertSameValue(400,paymos_edd_deliver($event)->get_status(),'Wrong binding must be rejected even with a valid HMAC and matching API: '.$case);
        assertSameValue(0,$GLOBALS['paymos_edd_completion_count'],'Wrong binding cannot issue downloads.');
    }
}

function test_edd_native_failure_releases_event_and_same_event_retry_completes_once()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    WebhookController::set_client_factory_for_tests(static function () { return paymos_edd_reverse_verify_client(); });
    $event=paymos_edd_invoice_event('evt_native_false','invoice.paid','paid');
    $GLOBALS['paymos_edd_fail_status']=true;
    assertSameValue(400,paymos_edd_deliver($event)->get_status(),'False native save must not ACK.');
    assertSameValue('',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_applied_status'),'Failed native save must not write applied final state.');
    assertSameValue(false,isset($GLOBALS['wpdb']->rows[md5('evt_native_false')]),'Failed event reservation must be released.');
    $GLOBALS['paymos_edd_fail_status']=false;
    assertSameValue(200,paymos_edd_deliver($event)->get_status(),'The same delivery must be retryable after recovery.');
    assertSameValue(200,paymos_edd_deliver($event)->get_status(),'Committed duplicate remains successful.');
    assertSameValue(1,$GLOBALS['paymos_edd_completion_count'],'Native completion must run once.');
}

function test_edd_mismatch_correction_can_retry_the_same_paid_delivery()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_last_status']='paid'; // Legacy poisoned observed marker.
    $GLOBALS['paymos_edd_payments'][100]['price']='120';
    WebhookController::set_client_factory_for_tests(static function () { return paymos_edd_reverse_verify_client(); });
    $event=paymos_edd_invoice_event('evt_mismatch_retry','invoice.paid','paid');
    assertSameValue(400,paymos_edd_deliver($event)->get_status(),'Mismatch must remain retryable.');
    $GLOBALS['paymos_edd_payments'][100]['price']='100';
    assertSameValue(200,paymos_edd_deliver($event)->get_status(),'Restoring the amount must allow safe completion.');
    assertSameValue('publish',edd_get_payment_status(100),'Corrected order must complete.');
}

function test_edd_manual_terminal_native_states_are_preserved_without_prior_paymos_marker()
{
    foreach (array('refunded','revoked','partially_refunded','publish') as $status) {
        paymos_edd_reset_test_state(); paymos_edd_seed_payment();
        $GLOBALS['paymos_edd_payments'][100]['status']=$status;
        WebhookController::set_client_factory_for_tests(static function () { return paymos_edd_reverse_verify_client(); });
        assertSameValue(200,paymos_edd_deliver(paymos_edd_invoice_event('evt_native_'.$status,'invoice.paid','paid'))->get_status(),'Protected native state should be safely acknowledged.');
        assertSameValue($status,edd_get_payment_status(100),'Native terminal decision must be preserved.');
        assertSameValue(0,$GLOBALS['paymos_edd_completion_count'],'No repeated issuance for protected state.');
    }
}

function test_edd_different_events_for_one_order_are_serialized_and_busy_reservation_released()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $second=paymos_edd_invoice_event('evt_interleave_second','invoice.paid','paid');
    $nested=null;
    WebhookController::set_client_factory_for_tests(static function () use (&$nested,$second) {
        if ($nested===null) { $nested=paymos_edd_deliver($second); }
        return paymos_edd_reverse_verify_client();
    });
    assertSameValue(200,paymos_edd_deliver(paymos_edd_invoice_event('evt_interleave_first','invoice.paid','paid'))->get_status(),'First delivery should complete.');
    assertSameValue(409,$nested->get_status(),'A distinct event must not enter the locked payment.');
    assertSameValue(false,isset($GLOBALS['wpdb']->rows[md5('evt_interleave_second')]),'Busy order must release its own event lease.');
    assertSameValue(200,paymos_edd_deliver($second)->get_status(),'Busy delivery should retry after the first finishes.');
    assertSameValue(1,$GLOBALS['paymos_edd_completion_count'],'Interleaved events must not re-complete downloads.');
}

function test_edd_connection_loss_after_reverse_lookup_stops_native_mutation()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    WebhookController::set_client_factory_for_tests(static function () { $GLOBALS['wpdb']->connection='42'; return paymos_edd_reverse_verify_client(); });
    assertSameValue(400,paymos_edd_deliver(paymos_edd_invoice_event('evt_reconnect','invoice.paid','paid'))->get_status(),'Lost DB ownership must stop processing.');
    assertSameValue('pending',edd_get_payment_status(100),'Reconnected worker must not mutate payment.');
}

function test_edd_event_owner_fences_stale_commit_and_release()
{
    paymos_edd_reset_test_state();
    $a=new EventStore(); $b=new EventStore();
    assertTrueValue($a->remember('evt_lease',3600),'Worker A reserves.');
    $GLOBALS['wpdb']->rows[md5('evt_lease')]['expires_at']=time()-1;
    assertTrueValue($b->remember('evt_lease',3600),'Worker B takes expired reservation.');
    $token=$GLOBALS['wpdb']->rows[md5('evt_lease')]['owner_token'];
    try { $a->commit(); throw new LogicException('Stale commit was accepted.'); } catch (RuntimeException $expected) {}
    $a->release();
    assertSameValue($token,$GLOBALS['wpdb']->rows[md5('evt_lease')]['owner_token'],'Stale release must not delete the new owner.');
    $b->commit();
    assertTrueValue((new EventStore())->isCommitted('evt_lease'),'Current owner may commit.');
}

function test_edd_expired_reservation_takeover_uses_compare_and_swap()
{
    paymos_edd_reset_test_state();
    $GLOBALS['wpdb']->beforeInsert=static function ($db,$args) {
        $db->rows[$args[1]]=array('event_hash'=>$args[1],'event_id'=>$args[2],'owner_token'=>'old','status'=>'processing','expires_at'=>time()-1,'created_at'=>time()-1000,'updated_at'=>time()-1000);
    };
    $store=new EventStore();
    assertTrueValue($store->remember('evt_cas',3600),'An expired row inserted after purge can be claimed with CAS.');
    assertTrueValue($GLOBALS['wpdb']->rows[md5('evt_cas')]['owner_token']!=='old','CAS must install a new ownership token.');
    $store->release();
}

function test_edd_event_purge_is_bounded_and_preserves_live_reservations()
{
    paymos_edd_reset_test_state();
    for($i=0;$i<101;$i++) { $GLOBALS['wpdb']->rows['expired_'.$i]=array('expires_at'=>time()-10); }
    $GLOBALS['wpdb']->rows['active']=array('expires_at'=>time()+300);
    EventStore::purgeExpired();
    assertSameValue(2,count($GLOBALS['wpdb']->rows),'A purge removes no more than 100 expired rows.');
    assertTrueValue(isset($GLOBALS['wpdb']->rows['active']),'A live reservation survives purge.');
}

function test_edd_event_store_database_failure_is_fail_closed()
{
    paymos_edd_reset_test_state(); $GLOBALS['wpdb']->fail=true;
    try { (new EventStore())->remember('evt_db_failure',3600); throw new LogicException('Unavailable DB was accepted.'); }
    catch (RuntimeException $expected) {}
    assertSameValue(array(),$GLOBALS['paymos_edd_transients'],'No transient fallback may pretend atomicity.');
}

function test_edd_uninstall_cleans_each_blog_without_touching_purchases_or_other_adapter()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_blog_id']=1; $GLOBALS['paymos_edd_blog_stack']=array();
    $GLOBALS['paymos_edd_multisite']=true; $GLOBALS['paymos_edd_dropped_tables']=array(); $GLOBALS['paymos_edd_cleared_cron']=array();
    $options=array('paymos_edd_credentials_v1'=>'encrypted','paymos_edd_connect_state_v1'=>'encrypted','paymos_edd_event_schema_version'=>'2','paymos_wc_credentials_v1'=>'other','edd_settings'=>array('paymos_title'=>'Pay','paymos_mode'=>'live','paymos_debug_logging'=>true,'currency'=>'KWD'));
    $GLOBALS['paymos_edd_options']=$options; $GLOBALS['paymos_edd_blogs']=array(1=>$options,2=>$options);
    if (!defined('WP_UNINSTALL_PLUGIN')) { define('WP_UNINSTALL_PLUGIN',true); }
    require dirname(__DIR__).'/uninstall.php';
    require dirname(__DIR__).'/uninstall.php';
    foreach ($GLOBALS['paymos_edd_blogs'] as $blog) {
        assertSameValue(false,isset($blog['paymos_edd_credentials_v1']),'Credentials removed on every blog.');
        assertSameValue('other',$blog['paymos_wc_credentials_v1'],'Other adapter configuration is retained.');
        assertSameValue(array('currency'=>'KWD'),$blog['edd_settings'],'Other EDD settings are retained.');
    }
    assertSameValue(array('wp_paymos_edd_webhook_events','wp_2_paymos_edd_webhook_events','wp_paymos_edd_webhook_events','wp_2_paymos_edd_webhook_events'),$GLOBALS['paymos_edd_dropped_tables'],'Only scoped event tables are dropped, repeatably.');
    assertSameValue('pending',edd_get_payment_status(100),'Native purchase remains untouched.');
    assertSameValue('wp_',$GLOBALS['wpdb']->prefix,'Current blog context is restored.');
    $GLOBALS['paymos_edd_multisite']=false;
}

class FakeEddLostCreateInvoices extends FakePaymosInvoices
{
    public $attempts=array();
    public function create(array $payload)
    {
        $this->attempts[]=$payload;
        throw new RuntimeException('Mock transport lost the create response after the server accepted it.');
    }
}

function test_edd_lost_create_response_has_durable_intent_and_recovers_only_via_idempotent_post()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    unset($GLOBALS['paymos_edd_payments'][100],$GLOBALS['paymos_edd_payment_meta'][100]);
    $invoices=new FakeEddLostCreateInvoices();
    Gateway::set_client_factory_for_tests(static function () use ($invoices) { return new FakePaymosClient($invoices); });
    Gateway::process_payment(paymos_edd_purchase());
    assertSameValue(array(0),$GLOBALS['paymos_edd_locks_at_checkout_exit'],'Order mutex must be released before EDD can exit on checkout error.');
    $external=PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_external_order_id');
    assertSameValue($invoices->attempts[0]['external_order_id'],$external,'Intent must precede the request that lost its response.');
    assertSameValue('pending',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_creation_intent'),'Lost response keeps creation recoverable.');
    assertSameValue('',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_invoice_id'),'No invoice ID is invented locally.');
    $event=paymos_edd_invoice_event('evt_lost_create','invoice.paid','paid',array('data'=>array('order'=>array('external_id'=>$external))));
    $invoice=paymos_edd_api_invoice($event);
    $transport=null;
    WebhookController::set_client_factory_for_tests(static function () use ($invoice,&$transport) {
        $transport=new Paymos\Http\MockTransport(array(
            new Paymos\Http\HttpResponse(200,json_encode($invoice),array('content-type'=>'application/json')),
            new Paymos\Http\HttpResponse(200,json_encode($invoice),array('content-type'=>'application/json')),
        ));
        return new Paymos\Client(new Paymos\ClientConfig('pk_test','sk_test','https://api.paymos.test',30),$transport);
    });
    assertSameValue(200,paymos_edd_deliver($event)->get_status(),'A signed callback may recover through exact independent idempotent create plus API GET.');
    assertSameValue('publish',edd_get_payment_status(100),'Recovered payment completes only after reverse verification.');
    assertSameValue('inv_123',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_invoice_id'),'Independent create response binds the invoice.');
    assertSameValue(1,$GLOBALS['paymos_edd_completion_count'],'Recovered payment fulfills once.');
}

function test_edd_lost_create_callback_cannot_adopt_a_different_invoice()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_invoice_id']='';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_creation_intent']='pending';
    $event=paymos_edd_invoice_event('evt_lost_wrong','invoice.paid','paid',array('data'=>array('invoice_id'=>'inv_ATTACK')));
    WebhookController::set_client_factory_for_tests(static function () {
        $invoice=paymos_edd_api_invoice(paymos_edd_invoice_event('unused','invoice.paid','paid'));
        return new Paymos\Client(new Paymos\ClientConfig('pk_test','sk_test','https://api.paymos.test',30),new Paymos\Http\MockTransport(array(new Paymos\Http\HttpResponse(200,json_encode($invoice),array()))));
    });
    assertSameValue(400,paymos_edd_deliver($event)->get_status(),'Callback invoice cannot replace the independently recovered invoice.');
    assertSameValue('',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_invoice_id'),'Wrong callback must not become a snapshot.');
    assertSameValue('pending',edd_get_payment_status(100),'Wrong callback cannot fulfill.');
}

function test_edd_resumed_purchase_reuses_exact_intent_and_replaces_changed_amount_safely()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_resume_id']=100;
    $same=new FakePaymosInvoices();
    Gateway::set_client_factory_for_tests(static function () use ($same) {return new FakePaymosClient($same);});
    Gateway::process_payment(paymos_edd_purchase());
    assertSameValue('edd_100_abc123',$same->payloads[0]['external_order_id'],'Same snapshot reuses its old idempotency identity.');
    $changed=new FakePaymosInvoices(array('invoice_id'=>'inv_new','payment_url'=>'https://checkout.paymos.io/pay/inv_new','status'=>'awaiting_client'));
    Gateway::set_client_factory_for_tests(static function () use ($changed) {return new FakePaymosClient($changed);});
    Gateway::process_payment(paymos_edd_purchase(array('price'=>'120')));
    assertSameValue('120',$changed->payloads[0]['amount'],'Changed purchase has exact new amount.');
    assertTrueValue($changed->payloads[0]['external_order_id']!=='edd_100_abc123','Changed purchase gets a new opaque identity.');
    assertSameValue('inv_new',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_invoice_id'),'Replacement response binds the new invoice.');
}

function test_edd_resumed_purchase_cannot_rebuild_a_locked_or_completed_order()
{
    foreach(array('busy','complete') as $case) {
        paymos_edd_reset_test_state(); paymos_edd_seed_payment();
        $GLOBALS['paymos_edd_resume_id']=100;
        $lock=new WordPressOrderLock('paymos-edd',100);
        if($case==='busy') {assertTrueValue($lock->acquire(),'Concurrent worker owns the order.');}
        else {$GLOBALS['paymos_edd_payments'][100]['status']='publish';}
        $invoices=new FakePaymosInvoices();
        Gateway::set_client_factory_for_tests(static function () use ($invoices) {return new FakePaymosClient($invoices);});
        Gateway::process_payment(paymos_edd_purchase(array('price'=>'120')));
        assertSameValue('100.00',$GLOBALS['paymos_edd_payments'][100]['price'],'Native recovery must not rebuild a busy/complete order.');
        assertSameValue(array(),$invoices->payloads,'No second invoice is created.');
        $lock->release();
    }
}

function test_edd_checkout_rejects_untrusted_host_and_non_https_payment_urls()
{
    foreach(array('http://checkout.paymos.io/pay/inv_123','https://checkout.paymos.io.evil.test/pay/inv_123','https://checkout.paymos.io@evil.test/pay/inv_123','https://evil.test/pay/inv_123','javascript:alert(1)') as $url) {
        paymos_edd_reset_test_state();paymos_edd_seed_payment();
        unset($GLOBALS['paymos_edd_payments'][100],$GLOBALS['paymos_edd_payment_meta'][100]);
        Gateway::set_client_factory_for_tests(static function () use ($url) {return new FakePaymosClient(new FakePaymosInvoices(array('invoice_id'=>'inv_123','payment_url'=>$url,'status'=>'awaiting_client')));});
        Gateway::process_payment(paymos_edd_purchase());
        assertSameValue(false,in_array($url,$GLOBALS['paymos_edd_redirects'],true),'Untrusted checkout URL must never be navigated.');
    }
}

function test_edd_network_deactivation_clears_only_owned_jobs_and_preserves_credentials()
{
    paymos_edd_reset_test_state();
    $GLOBALS['paymos_edd_blog_id']=1; $GLOBALS['paymos_edd_blog_stack']=array();
    $GLOBALS['paymos_edd_multisite']=true; $GLOBALS['paymos_edd_cleared_cron']=array();
    $options=array('paymos_edd_credentials_v1'=>'encrypted','paymos_edd_connect_state_v1'=>'state');
    $GLOBALS['paymos_edd_options']=$options; $GLOBALS['paymos_edd_blogs']=array(1=>$options,2=>$options);
    EventStore::deactivate(true);
    assertSameValue(array(array('wp_','paymos_edd_cleanup_events'),array('wp_2_','paymos_edd_cleanup_events')),$GLOBALS['paymos_edd_cleared_cron'],'Network deactivation clears all occurrences on every blog.');
    foreach($GLOBALS['paymos_edd_blogs'] as $blog) {assertSameValue('encrypted',$blog['paymos_edd_credentials_v1'],'Deactivation retains connection credentials.');}
    $GLOBALS['paymos_edd_multisite']=false;
}

function test_edd_checkout_connection_loss_does_not_mark_payment_failed_after_losing_mutex()
{
    paymos_edd_reset_test_state();paymos_edd_seed_payment();
    unset($GLOBALS['paymos_edd_payments'][100],$GLOBALS['paymos_edd_payment_meta'][100]);
    Gateway::set_client_factory_for_tests(static function () {
        $GLOBALS['wpdb']->connection='42';
        return new FakePaymosClient();
    });
    Gateway::process_payment(paymos_edd_purchase());
    assertSameValue('pending',edd_get_payment_status(100),'Error handler must not mutate an order after losing the mutex.');
    assertSameValue('',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_invoice_id'),'Lost owner must not bind the response.');
}

function test_edd_paid_late_hmac_callback_is_reverse_verified_and_never_reopens_downloads()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_payments'][100]['status']='abandoned';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_applied_status']='expired';
    $event=paymos_edd_invoice_event('evt_late_verified','invoice.paid_late','expired',array('data'=>array('paid_late_at'=>time())));
    WebhookController::set_client_factory_for_tests(static function () use ($event) { return paymos_edd_reverse_verify_client(paymos_edd_api_invoice($event)); });
    assertSameValue(200,paymos_edd_deliver($event)->get_status(),'Verified late-payment fact should be acknowledged.');
    assertSameValue('abandoned',edd_get_payment_status(100),'Late payment never reopens downloads.');
    assertSameValue(0,$GLOBALS['paymos_edd_completion_count'],'Late payment must not invoke native completion.');
    assertSameValue(1,count($GLOBALS['paymos_edd_payment_notes'][100]),'Late payment creates one manual-review note.');
    assertSameValue(200,paymos_edd_deliver($event)->get_status(),'Duplicate fact remains acknowledged.');
    assertSameValue(1,count($GLOBALS['paymos_edd_payment_notes'][100]),'Duplicate note is suppressed.');
}


function test_edd_unexpected_platform_throwable_releases_owned_reservations_for_retry()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    WebhookController::set_client_factory_for_tests(static function () { throw new TypeError('Mock platform type failure.'); });
    $event=paymos_edd_invoice_event('evt_throwable','invoice.paid','paid');
    assertSameValue(400,paymos_edd_deliver($event)->get_status(),'Unexpected platform error must be non-2xx.');
    assertSameValue(array(),$GLOBALS['wpdb']->locks,'Unexpected failure releases the order lock.');
    assertSameValue(false,isset($GLOBALS['wpdb']->rows[md5('evt_throwable')]),'Unexpected failure releases its event lease.');
    WebhookController::set_client_factory_for_tests(static function () { return paymos_edd_reverse_verify_client(); });
    assertSameValue(200,paymos_edd_deliver($event)->get_status(),'Same event can be retried after platform recovers.');
}

function test_edd_resumed_checkout_refreshes_preloaded_unpaid_orm_row_before_native_rebuild()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_cache_enabled']=true;
    assertSameValue('pending',edd_get_payment_status(100),'Recovery verification preloads the unpaid EDD row.');
    $GLOBALS['paymos_edd_payments'][100]['status']='publish'; // Another request commits payment before this checkout acquires its lock.
    assertSameValue('pending',edd_get_payment_status(100),'Fixture must expose stale ORM cache independently of database state.');
    $GLOBALS['paymos_edd_resume_id']=100;
    $invoices=new FakePaymosInvoices();
    Gateway::set_client_factory_for_tests(static function () use ($invoices) { return new FakePaymosClient($invoices); });
    Gateway::process_payment(paymos_edd_purchase(array('price'=>'120')));
    assertSameValue('publish',$GLOBALS['paymos_edd_payments'][100]['status'],'Locked checkout must not rebuild a payment completed by another worker.');
    assertSameValue('100.00',$GLOBALS['paymos_edd_payments'][100]['price'],'Cart rebuild is forbidden for the freshly completed native row.');
    assertSameValue(array(),$invoices->payloads,'Fresh completed order must not create a second invoice.');
}

function test_edd_webhook_refreshes_preloaded_native_row_and_cannot_repeat_fulfillment_or_rollback()
{
    foreach(array('paid','cancelled') as $status) {
        paymos_edd_reset_test_state(); paymos_edd_seed_payment();
        $GLOBALS['paymos_edd_cache_enabled']=true;
        assertSameValue('pending',edd_get_payment_status(100),'Request preloads unpaid order.');
        $GLOBALS['paymos_edd_payments'][100]['status']='publish';
        assertSameValue('pending',edd_get_payment_status(100),'Fixture must retain stale request cache before mutex acquisition.');
        $event=paymos_edd_invoice_event('evt_cached_'.$status,'invoice.'.$status,$status);
        WebhookController::set_client_factory_for_tests(static function () use ($event) {return paymos_edd_reverse_verify_client(paymos_edd_api_invoice($event));});
        assertSameValue(200,paymos_edd_deliver($event)->get_status(),'Valid delayed event is acknowledged against the fresh native state.');
        assertSameValue('publish',$GLOBALS['paymos_edd_payments'][100]['status'],'Fresh completed native payment must never roll back.');
        assertSameValue(0,$GLOBALS['paymos_edd_completion_count'],'Fresh completed native payment must not fulfill again.');
    }
}

function test_edd_webhook_refreshes_order_meta_before_using_invoice_snapshot()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_cache_enabled']=true;
    assertSameValue('inv_123',edd_get_payment_meta(100,'_paymos_invoice_id',true),'Request preloads old invoice metadata.');
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_invoice_id']='inv_NEW';
    assertSameValue('inv_123',edd_get_payment_meta(100,'_paymos_invoice_id',true),'Fixture must preserve old metadata separately from DB.');
    WebhookController::set_client_factory_for_tests(static function () {return paymos_edd_reverse_verify_client();});
    assertSameValue(400,paymos_edd_deliver(paymos_edd_invoice_event('evt_cached_binding','invoice.paid','paid'))->get_status(),'Old callback must be rejected against the fresh invoice binding.');
    assertSameValue('inv_NEW',$GLOBALS['paymos_edd_payment_meta'][100]['_paymos_invoice_id'],'Current invoice metadata must remain intact.');
    assertSameValue(0,$GLOBALS['paymos_edd_completion_count'],'Stale metadata must not authorize downloads.');
}

function test_edd_direct_mapper_refreshes_native_row_after_its_own_mutex_acquire()
{
    paymos_edd_reset_test_state(); paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_cache_enabled']=true;
    edd_get_payment_status(100);
    $GLOBALS['paymos_edd_payments'][100]['status']='publish';
    (new PaymosEasyDigitalDownloads\PaymentMapper())->apply(100,paymos_edd_invoice_event('evt_direct_cache','invoice.paid','paid'));
    assertSameValue('publish',$GLOBALS['paymos_edd_payments'][100]['status'],'Direct mapper must read fresh native state under its own lock.');
    assertSameValue(0,$GLOBALS['paymos_edd_completion_count'],'Direct mapper must not repeat completion from a cached unpaid row.');
    assertSameValue(array(array(100,'edd-orders'),array(100,'edd_order_meta'),array(100,'legacy-post')),$GLOBALS['paymos_edd_cache_deletes'],'Only this native payment and metadata cache keys are invalidated after acquire.');
}

class FakeEddRenewalInvoices extends FakePaymosInvoices
{
    public $attempts=array();
    public $cancels=array();
    public $steps;
    public $onCreate;
    public $denyClose=false;
    public $unreadable=false;
    public function __construct(array $steps) { $this->steps=$steps; }
    public function create(array $payload) {
        $this->attempts[]=$payload;
        if ($this->onCreate!==null) { ($this->onCreate)($payload,count($this->attempts)); }
        if (!$this->steps) { throw new RuntimeException('Mock has no remaining create response.'); }
        $step=array_shift($this->steps);
        if ($step instanceof Throwable) { throw $step; }
        return $step;
    }
    public function cancel($id,$reason) {
        $this->cancels[]=$id;
        if ($this->denyClose) { throw new RuntimeException('Mock invoice cannot be cancelled.'); }
        return array('invoice_id'=>$id,'status'=>'cancelled');
    }
    public function get($id) {
        if ($this->unreadable) { throw new RuntimeException('Mock API read unavailable.'); }
        return array('invoice_id'=>$id,'status'=>'confirming');
    }
}

function paymos_edd_checkout_invoice($id,$status,array $extra=array())
{
    return array_merge(array('invoice_id'=>$id,'status'=>$status,'payment_url'=>'https://checkout.paymos.io/pay/'.$id),$extra);
}

function test_edd_same_amount_resume_renews_terminal_unpaid_invoice_with_durable_new_intent()
{
    foreach(array('expired','underpaid','cancelled') as $status) {
        paymos_edd_reset_test_state();paymos_edd_seed_payment();
        $GLOBALS['paymos_edd_resume_id']=100;
        $GLOBALS['paymos_edd_payments'][100]['status']='failed';
        $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_applied_status']=$status;
        $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_last_status']=$status;
        $invoices=new FakeEddRenewalInvoices(array(paymos_edd_checkout_invoice('inv_123',$status),paymos_edd_checkout_invoice('inv_fresh','awaiting_client')));
        $invoices->onCreate=static function($payload,$attempt) {
            assertSameValue($payload['external_order_id'],PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_external_order_id'),'Each POST must already have a durable exact intent.');
            if ($attempt===2) {
                assertSameValue('',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_invoice_id'),'Old invoice binding must be cleared before replacement POST.');
                assertSameValue('',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_applied_status'),'Old applied terminal state must not poison a new invoice.');
            }
        };
        Gateway::set_client_factory_for_tests(static function()use($invoices){return new FakePaymosClient($invoices);});
        Gateway::process_payment(paymos_edd_purchase());
        assertSameValue(2,count($invoices->attempts),'Same-amount terminal-unpaid resume must create one replacement.');
        assertSameValue('edd_100_abc123',$invoices->attempts[0]['external_order_id'],'Original intent is read idempotently first.');
        assertTrueValue($invoices->attempts[1]['external_order_id']!=='edd_100_abc123','Replacement must use a new opaque idempotency key.');
        assertSameValue('https://checkout.paymos.io/pay/inv_fresh',end($GLOBALS['paymos_edd_redirects']),'Customer must reach the new payable invoice.');
        assertSameValue('inv_fresh',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_invoice_id'),'New response is durably bound.');
        assertSameValue('',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_applied_status'),'Legacy final marker is reset for the new invoice.');
    }
}

function test_edd_resume_never_rotates_paid_confirming_or_partial_invoice_from_a_stale_deadline()
{
    foreach(array('paid','paid_over','awaiting_payment','confirming','underpaid_waiting') as $status) {
        paymos_edd_reset_test_state();paymos_edd_seed_payment();$GLOBALS['paymos_edd_resume_id']=100;
        $invoices=new FakeEddRenewalInvoices(array(paymos_edd_checkout_invoice('inv_123',$status,array('expires_at'=>time()-3600,'is_final'=>in_array($status,array('paid','paid_over'),true)))));
        Gateway::set_client_factory_for_tests(static function()use($invoices){return new FakePaymosClient($invoices);});
        Gateway::process_payment(paymos_edd_purchase());
        assertSameValue(1,count($invoices->attempts),'Potentially paid/payable invoice must not get a second create: '.$status);
        assertSameValue(array(),$invoices->cancels,'Started or paid invoice must not be cancelled: '.$status);
        assertSameValue('edd_100_abc123',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_external_order_id'),'Existing exact intent must be retained.');
    }
}

function test_edd_resumed_unstarted_deadline_requires_successful_close_and_unreadable_is_manual()
{
    foreach(array(false,true) as $blocked) {
        paymos_edd_reset_test_state();paymos_edd_seed_payment();$GLOBALS['paymos_edd_resume_id']=100;
        $invoices=new FakeEddRenewalInvoices(array(paymos_edd_checkout_invoice('inv_123','awaiting_client',array('expires_at'=>time()-3600)),paymos_edd_checkout_invoice('inv_fresh','awaiting_client')));
        $invoices->denyClose=$blocked;$invoices->unreadable=$blocked;
        Gateway::set_client_factory_for_tests(static function()use($invoices){return new FakePaymosClient($invoices);});
        Gateway::process_payment(paymos_edd_purchase());
        assertSameValue(array('inv_123'),$invoices->cancels,'Stale unstarted deadline must first cancel the existing invoice.');
        assertSameValue($blocked?1:2,count($invoices->attempts),'Unreadable/unclosable invoice must not rotate.');
        if($blocked) {
            assertSameValue('edd_100_abc123',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_external_order_id'),'Failed close keeps the original intent.');
            assertSameValue(false,in_array('https://checkout.paymos.io/pay/inv_123',$GLOBALS['paymos_edd_redirects'],true),'Failed close must not redirect to an inactive checkout.');
        }
    }
}

function test_edd_renewal_lost_response_retry_reuses_new_exact_intent_and_client_id()
{
    paymos_edd_reset_test_state();paymos_edd_seed_payment();$GLOBALS['paymos_edd_resume_id']=100;
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_client_id']='77';
    $invoices=new FakeEddRenewalInvoices(array(paymos_edd_checkout_invoice('inv_123','expired'),new RuntimeException('Mock accepted replacement but lost response.'),paymos_edd_checkout_invoice('inv_fresh','awaiting_client')));
    Gateway::set_client_factory_for_tests(static function()use($invoices){return new FakePaymosClient($invoices);});
    Gateway::process_payment(paymos_edd_purchase());
    $newExternal=PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_external_order_id');
    assertTrueValue($newExternal!=='edd_100_abc123','Replacement key is durable despite lost response.');
    assertSameValue('pending',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_creation_intent'),'Lost replacement response remains independently recoverable.');
    $GLOBALS['paymos_edd_errors']=array();
    Gateway::process_payment(paymos_edd_purchase(array('user_info'=>array('id'=>999))));
    assertSameValue(3,count($invoices->attempts),'Retry makes one exact idempotent POST, no third intent.');
    assertSameValue($invoices->attempts[1],$invoices->attempts[2],'Accepted POST timeout must replay every exact field even when current native user changes.');
    assertSameValue($newExternal,PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_external_order_id'),'Retry must preserve replacement idempotency identity.');
    assertSameValue('inv_fresh',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_invoice_id'),'Recovered replacement is bound.');
}

function test_edd_renewal_native_snapshot_failure_stops_before_second_post()
{
    paymos_edd_reset_test_state();paymos_edd_seed_payment();$GLOBALS['paymos_edd_resume_id']=100;
    $invoices=new FakeEddRenewalInvoices(array(paymos_edd_checkout_invoice('inv_123','expired'),paymos_edd_checkout_invoice('inv_fresh','awaiting_client')));
    $invoices->onCreate=static function($payload,$attempt) {if($attempt===1){$GLOBALS['paymos_edd_fail_meta_key']='_paymos_external_order_id';}};
    Gateway::set_client_factory_for_tests(static function()use($invoices){return new FakePaymosClient($invoices);});
    Gateway::process_payment(paymos_edd_purchase());
    assertSameValue(1,count($invoices->attempts),'A failed durable replacement intent must prevent POST.');
    assertSameValue('edd_100_abc123',PaymosEasyDigitalDownloads\PaymentRepository::getMeta(100,'_paymos_external_order_id'),'Failed save must not claim a new identity.');
}

function test_edd_renewal_refuses_wrong_unknown_or_repeatedly_closed_api_invoice()
{
    foreach(array('wrong_id','unknown_status','second_closed') as $case) {
        paymos_edd_reset_test_state();paymos_edd_seed_payment();$GLOBALS['paymos_edd_resume_id']=100;
        $first=paymos_edd_checkout_invoice($case==='wrong_id'?'inv_OTHER':'inv_123',$case==='unknown_status'?'unrecognized':'expired');
        $invoices=new FakeEddRenewalInvoices(array($first,paymos_edd_checkout_invoice('inv_fresh','expired')));
        Gateway::set_client_factory_for_tests(static function()use($invoices){return new FakePaymosClient($invoices);});
        Gateway::process_payment(paymos_edd_purchase());
        assertSameValue($case==='second_closed'?2:1,count($invoices->attempts),'Unsafe response must not start an unbounded renewal loop.');
        foreach($GLOBALS['paymos_edd_redirects'] as $url) {assertTrueValue(strpos($url,'https://checkout.paymos.io/')!==0,'Unsafe or closed API response must not be used as a checkout redirect.');}
        assertSameValue(0,$GLOBALS['paymos_edd_completion_count'],'Checkout renewal must never issue downloads.');
    }
}
