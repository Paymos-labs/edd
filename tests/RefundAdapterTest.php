<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/refund-service.php';

function edd_refund_expect_failure(callable $action, $reason)
{
    try { $action(); } catch (Throwable $error) { assertContainsValue($reason, $error->getMessage(), 'The action must fail for its actual guard.'); return; }
    throw new RuntimeException('Expected failure: ' . $reason);
}

function edd_refund_posts($transport)
{
    return array_values(array_filter($transport->requests, static function ($request) { return $request[0] === 'POST'; }));
}

function test_edd_refund_overpayment_durable_intent_only_creates_cash_note_on_verified_completion()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $intent = $adapter->request('100', 'overpayment', 'overpayment_token_01');
    assertSameValue('created', $intent->cashState(), 'A successful create remains pending.');
    assertSameValue(0, count(edd_get_order_refunds(100)), 'Pending creates no native refund.');
    assertSameValue(array(), $GLOBALS['paymos_edd_payment_notes'], 'Pending creates no completion note.');
    assertSameValue(array('invoice_id', 'mode', 'external_order_id'), array_keys(json_decode(edd_refund_posts($transport)[0][2], true)), 'The API has no native amount/address input.');
    $transport->refunds[$intent->withdrawalId()]['status'] = 'completed';
    $adapter->callback($transport->refunds[$intent->withdrawalId()], 'sandbox');
    $adapter->reconcile($intent->operationId());
    assertSameValue('publish', edd_get_payment_status(100), 'Overpayment keeps the paid order status.');
    assertSameValue(100.0, edd_get_order_total(100), 'Overpayment leaves native total intact.');
    assertSameValue(0, count(edd_get_order_refunds(100)), 'Overpayment creates no EDD refund.');
    assertSameValue(1, count($GLOBALS['paymos_edd_payment_notes'][100]), 'A real native cash note is created once.');
    assertSameValue(array(), EDD\Emails\Registry::$mail, 'Overpayment sends no full-refund mail.');
    assertSameValue('applied', $store->getIntent($intent->operationId())->projectionState(), 'The once note is a completed cash-only projection.');
}

function test_edd_refund_full_waits_for_cash_then_atomically_marks_one_native_document_and_two_mail_attempts()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $intent = $adapter->request('100', 'full', 'full_refund_token_01');
    assertSameValue(0, count(edd_get_order_refunds(100)), 'Created is not cash completion.');
    $transport->refunds[$intent->withdrawalId()]['status'] = 'completed';
    $adapter->callback($transport->refunds[$intent->withdrawalId()], 'sandbox');
    $adapter->reconcile($intent->operationId());
    $native = edd_get_order_refunds(100);
    assertSameValue(1, count($native), 'Exactly one native full refund.');
    assertSameValue('-100', $native[0]->total, 'Native full uses original order total, not crypto gross.');
    assertSameValue($intent->operationId(), edd_get_order_meta($native[0]->id, '_paymos_refund_operation_id', true), 'Native marker is saved before email and commit.');
    assertSameValue('refunded', edd_get_payment_status(100), 'The native state changes only after cash completion.');
    assertSameValue(2, count(EDD\Emails\Registry::$mail), 'Native inline email was suppressed; separate customer and admin effects sent once.');
    assertSameValue('delivered', $store->getIntent($intent->operationId())->toArray()['effects']['email_order_refund:1'], 'Customer outcome recorded.');
    assertSameValue(false, PaymosEasyDigitalDownloads\RefundAdapter::isFinalizing(100), 'The scoped bypass is cleared.');
}

function test_edd_refund_cached_disabled_connect_does_not_override_fresh_summary_and_disable_blocks_new_actions()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    assertSameValue(true, $adapter->summary('100')['can_refund_full'], 'Fresh enabled beats the old Connect enabled=false snapshot.');
    $transport->summaryEnabled = false;
    assertSameValue(false, $adapter->summary('100')['can_refund_full'], 'Fresh disabled is respected.');
    edd_refund_expect_failure(static function () use ($adapter) { $adapter->request('100', 'full', 'disabled_token_01'); }, 'refund_server_not_eligible');
    assertSameValue(0, count(edd_refund_posts($transport)), 'Disabled sends no new POST.');
    assertSameValue(0, count($store->intents), 'Disabled does not create a new intent.');
}

function test_edd_refund_partial_manual_or_nontransactional_native_input_is_refused_before_post()
{
    foreach (array('partial', 'manual_refund', 'changed_total', 'nontransactional', 'missing_mail') as $case) {
        list($adapter, $store, $transport) = edd_refund_harness();
        $input = array(); $reason = 'refund_original_full_paid_required';
        if ($case === 'partial') { $input = array('amount' => '25'); $reason = 'refund_partial_draft_rejected'; }
        if ($case === 'manual_refund') { $GLOBALS['paymos_edd_payments'][999] = array('type' => 'refund', 'parent' => 100, 'price' => -25, 'status' => 'complete', 'gateway' => 'paymos'); }
        if ($case === 'changed_total') { $GLOBALS['paymos_edd_payments'][100]['price'] = '90'; }
        if ($case === 'nontransactional') { $store->transactional = false; $reason = 'refund_transactional_tables_required'; }
        if ($case === 'missing_mail') { EDD\Emails\Registry::$available = false; $reason = 'refund_edd_33_native_email_required'; }
        edd_refund_expect_failure(static function () use ($adapter, $input) { $adapter->request('100', 'full', 'rejected_token_01', $input); }, $reason);
        assertSameValue(0, count(edd_refund_posts($transport)), 'Unsafe local prerequisites never create cash.');
    }
}

function test_edd_refund_lost_response_reuses_one_cash_key_and_stable_native_intent()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $transport->loseReply = true;
    $intent = $adapter->request('100', 'overpayment', 'lost_response_token1');
    $again = $adapter->request('100', 'overpayment', 'lost_response_token1');
    assertSameValue($intent->operationId(), $again->operationId(), 'The same native token recovers the same intent.');
    assertSameValue(1, count($store->intents), 'One durable intent.');
    assertSameValue(1, count($transport->refunds), 'One server cash operation despite HTTP replay.');
    $posts = edd_refund_posts($transport);
    assertSameValue(2, count($posts), 'Only the lost acknowledgement requires a replay.');
    assertSameValue($posts[0][2], $posts[1][2], 'Byte-identical mode/key replay.');
    edd_refund_expect_failure(static function () use ($adapter) { $adapter->request('100', 'full', 'lost_response_token1'); }, 'refund_request_conflict');
}

function test_edd_refund_external_dashboard_refunds_are_observations_without_fake_local_intents_or_post()
{
    foreach (array('overpayment', 'full') as $mode) {
        list($adapter, $store, $transport) = edd_refund_harness();
        $wire = edd_refund_wire('wdl_external', $mode, 'dashboard_refund', 'completed');
        $transport->refunds['wdl_external'] = $wire;
        $adapter->callback($wire, 'sandbox'); $adapter->callback($wire, 'sandbox');
        assertSameValue(1, count($store->externals), 'A durable observation is deduped by cash ID.');
        assertSameValue(0, count($store->intents), 'No fake cash-create intent or invented native full draft.');
        assertSameValue(0, count(edd_refund_posts($transport)), 'Callbacks only read.');
        assertSameValue(0, count(edd_get_order_refunds(100)), 'An external full without native draft is review only.');
        assertSameValue('publish', edd_get_payment_status(100), 'The order remains paid.');
        if ($mode === 'overpayment') { assertSameValue(1, count($GLOBALS['paymos_edd_payment_notes'][100]), 'External overpayment has one real note.'); }
        else { assertSameValue('refund_external_full_without_draft', $store->externals['wdl_external']['review_reason'], 'External full cannot guess native accounting.'); }
    }
}

function test_edd_refund_external_lost_callback_is_discovered_by_cron_without_post()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $transport->refunds['wdl_external'] = edd_refund_wire('wdl_external', 'overpayment', 'api_refund');
    $adapter->sweep(); $adapter->sweep();
    assertSameValue(1, count($store->externals), 'Scoped list discovery recovers a refund with no callback or local intent.');
    assertSameValue(1, count($GLOBALS['paymos_edd_payment_notes'][100]), 'Discovery is idempotent at the real note boundary.');
    assertSameValue(0, count(edd_refund_posts($transport)), 'The discovery worker has no cash create.');
}

function test_edd_refund_foreign_evidence_and_lost_lock_cannot_create_native_effects()
{
    foreach (array('project', 'lock') as $case) {
        list($adapter, $store, $transport) = edd_refund_harness();
        $wire = edd_refund_wire('wdl_external', 'overpayment', 'dashboard_refund'); $transport->refunds['wdl_external'] = $wire;
        if ($case === 'project') { $transport->tamperProject = true; }
        else { $transport->beforeGet = static function () use ($store) { $store->lockLost = true; }; }
        try { $adapter->callback($wire, 'sandbox'); throw new RuntimeException('Expected rejection.'); }
        catch (Throwable $error) { assertSameValue(0, count($store->externals), 'No observation is accepted without scoped owned evidence.'); }
        assertSameValue(array(), $GLOBALS['paymos_edd_payment_notes'], 'No foreign or unlocked native note.');
    }
}

function test_edd_refund_reorg_recomplete_keeps_one_full_document_and_never_resends_after_lost_smtp_ack()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $intent = $adapter->request('100', 'full', 'full_reorg_token01');
    EDD\Emails\Registry::$throwAfterSend = true;
    $transport->refunds[$intent->withdrawalId()]['status'] = 'completed'; $adapter->reconcile($intent->operationId());
    assertSameValue('unknown', $store->getIntent($intent->operationId())->toArray()['effects']['email_order_refund:1'], 'Lost acknowledgement is unknown.');
    $transport->refunds[$intent->withdrawalId()]['status'] = 'signed'; $adapter->reconcile($intent->operationId());
    assertSameValue('native_review', $store->getIntent($intent->operationId())->projectionState(), 'A cash reorg does not erase historical accounting.');
    $transport->refunds[$intent->withdrawalId()]['status'] = 'completed'; $adapter->reconcile($intent->operationId());
    assertSameValue(1, count(edd_get_order_refunds(100)), 'Recompletion reuses the marker.');
    assertSameValue(2, count(EDD\Emails\Registry::$mail), 'Each configured customer/admin send is attempted at most once.');
}

function test_edd_refund_overpayment_then_full_uses_original_native_total_and_no_second_over_note()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $over = $adapter->request('100', 'overpayment', 'sequence_over_token1');
    $transport->refunds[$over->withdrawalId()]['status'] = 'completed'; $adapter->reconcile($over->operationId());
    assertSameValue('20', $store->getIntent($over->operationId())->observation()->principalDecimal(), 'Verified overpayment cash is twenty.');
    assertSameValue('100', $adapter->summary('100')['full_refund_amount'], 'Fresh summary exposes the remaining full crypto amount.');
    assertSameValue('publish', edd_get_payment_status(100), 'Overpayment preserves paid native status.');
    assertSameValue(100.0, edd_get_order_total(100), 'Overpayment leaves native refunded at zero.');
    $full = $adapter->request('100', 'full', 'sequence_full_token1');
    $transport->refunds[$full->withdrawalId()]['status'] = 'completed'; $adapter->reconcile($full->operationId());
    assertSameValue('100', $store->getIntent($full->operationId())->observation()->principalDecimal(), 'Full after overpayment independently verifies the remaining crypto hundred.');
    assertSameValue(1, count(edd_get_order_refunds(100)), 'Full accounting is once, independent of overpayment cash.');
    assertSameValue(1, count($GLOBALS['paymos_edd_payment_notes'][100]), 'Only the overpayment note is written.');
    assertSameValue(2, count(edd_refund_posts($transport)), 'Exactly two explicit cash intentions.');
    $transport->refunds[$over->withdrawalId()]['status'] = 'signed'; $adapter->reconcile($over->operationId());
    assertSameValue('completed', $store->getIntent($full->operationId())->cashState(), 'An old overpayment reorg cannot overwrite a completed full intent.');
    $transport->refunds[$over->withdrawalId()]['status'] = 'completed'; $adapter->reconcile($over->operationId());
    assertSameValue(1, count($GLOBALS['paymos_edd_payment_notes'][100]), 'Overpayment recompletion reuses its cash note after full.');
    edd_refund_expect_failure(static function () use ($adapter) { $adapter->request('100', 'overpayment', 'after_full_token_01'); }, 'refund_original_full_paid_required');
}

function test_edd_refund_signed_http_callback_dispatches_to_adapter_and_rejects_bad_signature_without_reads()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $wire = edd_refund_wire('wdl_http_external', 'overpayment', 'dashboard_refund');
    $transport->refunds['wdl_http_external'] = $wire;
    $body = json_encode(array('event_id' => 'evt_edd_external_full_http', 'event_type' => 'withdrawal.completed',
        'version' => 1, 'occurred_at' => time(), 'data' => $wire));
    $response = PaymosEasyDigitalDownloads\WebhookController::handle(new WP_REST_Request($body,
        array('x-webhook-signature' => 'invalid')), $adapter);
    assertSameValue(401, $response->get_status(), 'Signature is verified before the refund callback dispatcher.');
    assertSameValue(0, count($transport->requests), 'Bad signatures cause no reverse reads or effects.');
    $response = PaymosEasyDigitalDownloads\WebhookController::handle(new WP_REST_Request($body,
        array('x-webhook-signature' => paymos_edd_signed_header('whsec_sandbox', $body))), $adapter);
    assertSameValue(200, $response->get_status(), 'Actual HTTP callback handler routes the verified refund to the adapter.');
    assertSameValue(1, count($store->externals), 'Signed HTTP callback persists the external observation.');
    assertSameValue(1, count($GLOBALS['paymos_edd_payment_notes'][100]), 'One native cash note from the production dispatch seam.');
    assertSameValue(0, count(edd_refund_posts($transport)), 'HTTP callback does not create cash.');
}

function test_edd_refund_production_registration_has_real_native_admin_cron_and_mail_hooks_and_existing_asset()
{
    $expected = array('wp_ajax_paymos_edd_refund' => 'managedAjax', 'wp_ajax_edd_process_refund_form' => 'nativeAjax',
        'edd_view_order_details_payment_meta_after' => 'panel', 'edd_refund_order' => 'markNativeRefund',
        'paymos_edd_refunds_reconcile' => 'run', 'edd_should_send_email_order_refund' => 'emailEnabled',
        'edd_should_send_email_admin_order_refund' => 'emailEnabled');
    foreach ($expected as $hook => $method) {
        $found = false;
        foreach ($GLOBALS['paymos_edd_hooks'][$hook] ?? array() as $callbacks) {
            foreach ($callbacks as $callback) {
                if ($callback[0] === array(PaymosEasyDigitalDownloads\RefundAdapter::class, $method)) { $found = true; }
            }
        }
        assertSameValue(true, $found, 'The plugin entry must register the actual ' . $hook . ' native hook.');
    }
    assertSameValue(true, is_file(dirname(__DIR__) . '/assets/js/refunds.js'), 'The enqueued administrative script exists.');
}

function test_edd_refund_protected_native_form_checks_nonce_permission_full_input_and_native_validator_before_http()
{
    $original = $_POST;
    try {
        foreach (array('full', 'partial', 'nonce', 'permission', 'validator', 'missing_items') as $case) {
            list($adapter, $store, $transport) = edd_refund_harness();
            $_POST = paymos_edd_refund_form_post($case === 'partial' ? '25' : '100');
            $_POST['paymos_refund_token'] = 'protected_form_token1';
            if ($case === 'nonce') { $_POST['data'] = str_replace('test-nonce', 'invalid', $_POST['data']); }
            if ($case === 'permission') { $GLOBALS['paymos_edd_refund_permission'] = false; }
            if ($case === 'validator') { EDD\Orders\Refunds\Validator::$reject = true; }
            if ($case === 'missing_items') { $_POST['data'] = 'edd_process_refund=test-nonce'; }
            PaymosEasyDigitalDownloads\RefundAdapter::nativeAjax($adapter);
            assertSameValue($case === 'full' ? 1 : 0, count(edd_refund_posts($transport)), 'Only a protected full native draft may create cash: ' . $case);
            if ($case !== 'full') { assertSameValue(0, count($transport->requests), 'Rejected native input fails before all provider HTTP.'); }
            assertSameValue(0, count(edd_get_order_refunds(100)), 'Submit Refund never prematurely books native accounting.');
            if ($case === 'full') {
                assertSameValue('created', $GLOBALS['paymos_edd_refund_json']['data']['paymos_refund']['cash_state'], 'Native entry returns the module pending state.');
                assertSameValue(false, $GLOBALS['paymos_edd_refund_json']['success'], 'Native form cannot report financial success while pending.');
            }
        }
    } finally { $_POST = $original; }
}

function test_edd_refund_managed_overpayment_action_rejects_amount_input_and_requires_its_own_nonce()
{
    $original = $_POST;
    try {
        foreach (array('eligible', 'amount', 'nonce', 'permission') as $case) {
            list($adapter, $store, $transport) = edd_refund_harness();
            $_POST = array('order_id' => 100, 'mode' => 'overpayment', 'security' => 'test-nonce', 'paymos_refund_token' => 'managed_over_token1');
            if ($case === 'amount') { $_POST['amount'] = '20'; }
            if ($case === 'nonce') { $_POST['security'] = 'invalid'; }
            if ($case === 'permission') { $GLOBALS['paymos_edd_refund_permission'] = false; }
            PaymosEasyDigitalDownloads\RefundAdapter::managedAjax($adapter);
            assertSameValue($case === 'eligible' ? 1 : 0, count(edd_refund_posts($transport)), 'Managed action guards must run before POST: ' . $case);
            assertSameValue('publish', edd_get_payment_status(100), 'Managed overpayment keeps the native order paid.');
        }
    } finally { $_POST = $original; }
}

function test_edd_refund_accepted_unknown_replays_after_disable_with_the_original_mode_key_and_binding()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $transport->loseAllReplies = true;
    $intent = $adapter->request('100', 'overpayment', 'unknown_restart_token1');
    assertSameValue('unknown', $intent->cashState(), 'Both acknowledgements were lost.');
    assertSameValue(null, $intent->withdrawalId(), 'Callback data did not invent a cash binding.');
    $transport->summaryEnabled = false;
    $transport->loseAllReplies = false;
    $recovered = $adapter->request('100', 'overpayment', 'unknown_restart_token1');
    $posts = edd_refund_posts($transport);
    foreach ($posts as $post) { assertSameValue($posts[0][2], $post[2], 'Disabled accepted replay uses the original payload/key.'); }
    assertSameValue(1, count($transport->refunds), 'Exactly one Withdrawal across unknown recovery.');
    $transport->refunds[$recovered->withdrawalId()]['status'] = 'completed';
    $adapter->reconcile($recovered->operationId());
    assertSameValue('applied', $store->getIntent($intent->operationId())->projectionState(), 'Disable does not block read/projection of accepted cash.');
}

function test_edd_refund_crash_after_intent_commit_before_post_is_recovered_by_the_reconciler()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $intent = $adapter->request('100', 'overpayment', 'committed_intent_token');
    $store->intents[$intent->operationId()]['cash_state'] = 'not_submitted';
    $store->intents[$intent->operationId()]['withdrawal_id'] = null;
    $store->intents[$intent->operationId()]['observation'] = null;
    $transport->requests = array(); $transport->refunds = array();
    $adapter->sweep();
    assertSameValue(1, count(edd_refund_posts($transport)), 'A persisted unsubmitted action is resumed once.');
    assertSameValue($intent->externalOrderId(), json_decode(edd_refund_posts($transport)[0][2], true)['external_order_id'], 'Recovery preserves the committed identity.');
}

function test_edd_refund_native_transaction_failure_rolls_back_document_marker_and_financial_transaction()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $intent = $adapter->request('100', 'full', 'transaction_fail_token');
    $GLOBALS['paymos_edd_refund_transaction_fail'] = true;
    $transport->refunds[$intent->withdrawalId()]['status'] = 'completed';
    assertSameValue('show_review', $adapter->reconcile($intent->operationId()), 'A partial native write is review, never success.');
    assertSameValue(0, count(edd_get_order_refunds(100)), 'Native document rolled back.');
    assertSameValue(array(), $GLOBALS['paymos_edd_refund_transactions'], 'The negative transaction rolled back.');
    assertSameValue('publish', edd_get_payment_status(100), 'Native order remains paid.');
    assertSameValue(array(), EDD\Emails\Registry::$mail, 'Uncommitted native writes never send mail.');
    assertSameValue(false, PaymosEasyDigitalDownloads\RefundAdapter::isFinalizing(100), 'A failed finalization closes its internal bypass.');
    $GLOBALS['paymos_edd_refund_transaction_fail'] = false;
    $adapter->reconcile($intent->operationId());
    assertSameValue(1, count(edd_get_order_refunds(100)), 'Known rollback permits one exact recovery document.');
    assertSameValue(1, count($GLOBALS['paymos_edd_refund_transactions']), 'Exactly one negative financial transaction.');
    $transaction = reset($GLOBALS['paymos_edd_refund_transactions']);
    assertSameValue('-100', $transaction->total, 'Native cash record uses original paid total.');
    assertSameValue($intent->withdrawalId(), $transaction->transaction_id, 'Financial record references the verified Withdrawal.');
}

function test_edd_refund_native_commit_lost_ack_recovers_its_exact_marker_without_a_second_document()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $intent = $adapter->request('100', 'full', 'native_commit_ack_token');
    $store->loseCommitAck = true;
    $transport->refunds[$intent->withdrawalId()]['status'] = 'completed';
    $adapter->reconcile($intent->operationId());
    assertSameValue(1, count(edd_get_order_refunds(100)), 'Native commit survived loss of acknowledgement.');
    $adapter->reconcile($intent->operationId());
    assertSameValue(1, count(edd_get_order_refunds(100)), 'Recovery binds the existing native document.');
    assertSameValue(1, count($GLOBALS['paymos_edd_refund_transactions']), 'Recovery does not repeat negative transactions.');
    assertSameValue(2, count(EDD\Emails\Registry::$mail), 'Mail is attempted only after committed projection recovery.');
}

function test_edd_refund_overpayment_cash_note_cannot_mutate_native_financial_or_stock_state()
{
    foreach (array('total', 'status', 'stock', 'refunded') as $case) {
        list($adapter, $store, $transport) = edd_refund_harness();
        update_post_meta(10, '_stock', '3');
        $intent = $adapter->request('100', 'overpayment', 'cash_only_mutation_token');
        $transport->refunds[$intent->withdrawalId()]['status'] = 'completed';
        $store->afterNote = static function () use ($case) {
            if ($case === 'total') { $GLOBALS['paymos_edd_payments'][100]['price'] = '80'; }
            if ($case === 'status') { $GLOBALS['paymos_edd_payments'][100]['status'] = 'refunded'; }
            if ($case === 'stock') { update_post_meta(10, '_stock', '4'); }
            if ($case === 'refunded') { $GLOBALS['paymos_edd_payments'][999] = array('type' => 'refund', 'parent' => 100, 'price' => -20, 'status' => 'complete', 'gateway' => 'paymos'); }
        };
        assertSameValue('show_review', $adapter->reconcile($intent->operationId()), 'Cash-only state mutation is rejected: ' . $case);
        assertSameValue(0, count(edd_get_order_refunds(100)), 'Cash-only projection cannot alter native refunded.');
        assertSameValue('3', get_post_meta(10, '_stock', true), 'Cash-only projection cannot change stock.');
        assertSameValue('publish', edd_get_payment_status(100), 'Cash-only projection cannot change paid status.');
        assertSameValue(array(), $GLOBALS['paymos_edd_payment_notes'], 'The unsafe note and its marker roll back together.');
    }
}

function test_edd_refund_callback_conflicting_mode_key_or_withdrawal_never_selects_another_operation()
{
    foreach (array('mode', 'external_order_id', 'withdrawal_id') as $field) {
        list($adapter, $store, $transport) = edd_refund_harness();
        $intent = $adapter->request('100', 'full', 'callback_conflict_token');
        $wire = $transport->refunds[$intent->withdrawalId()];
        $wire[$field] = $field === 'mode' ? 'overpayment' : 'foreign';
        $transport->requests = array();
        edd_refund_expect_failure(static function () use ($adapter, $wire) { $adapter->callback($wire, 'sandbox'); }, 'refund_callback_scope_conflict');
        assertSameValue(array(), $transport->requests, 'An inconsistent callback is rejected before selecting provider evidence.');
        assertSameValue(0, count(edd_get_order_refunds(100)), 'Inconsistent callback has no native effect.');
    }
}

function test_edd_refund_native_mail_suppression_is_scoped_and_saved_disabled_defaults_stay_disabled()
{
    list($adapter, $store, $transport) = edd_refund_harness();
    $GLOBALS['paymos_edd_refund_mail_enabled']['admin_order_refund'] = false;
    $intent = $adapter->request('100', 'full', 'mail_defaults_token1');
    $GLOBALS['paymos_edd_refund_mail_enabled']['admin_order_refund'] = true;
    $probe = static function ($orderId, $refundId) {
        $foreign = (object) array('refund' => (object) array('id' => 999, 'parent' => 200));
        assertSameValue(true, PaymosEasyDigitalDownloads\RefundAdapter::emailEnabled(true, $foreign), 'Another refund mail is not suppressed.');
        assertSameValue(false, PaymosEasyDigitalDownloads\RefundAdapter::emailEnabled(false, $foreign), 'Earlier mail vetoes remain intact.');
        assertSameValue(true, PaymosEasyDigitalDownloads\RefundAdapter::refundWindow(false, $orderId), 'The exact accepted operation can finalize from cron.');
        assertSameValue(false, PaymosEasyDigitalDownloads\RefundAdapter::refundWindow(false, 200), 'Another order gets no refund-window bypass.');
    };
    add_action('edd_refund_order', $probe, 6, 2);
    try {
        $transport->refunds[$intent->withdrawalId()]['status'] = 'completed'; $adapter->reconcile($intent->operationId());
    } finally { array_pop($GLOBALS['paymos_edd_hooks']['edd_refund_order'][6]); }
    assertSameValue(1, count(EDD\Emails\Registry::$mail), 'Only the customer choice saved at submission is dispatched.');
    assertSameValue(false, PaymosEasyDigitalDownloads\RefundAdapter::refundWindow(false, 100), 'The refund-window exception is cleared.');
}

function test_edd_refund_native_fixture_never_treats_service_stubs_as_package_proof()
{
    $contract = require __DIR__ . '/refund-native.php';
    assertSameValue('skipped', $contract['status'], 'Without an approved native stand there is no native PASS.');
    assertSameValue(array(), $contract['observed_packages'], 'Service doubles are not installed package evidence.');
    assertTrueValue(count($contract['scenarios']) >= 10, 'The native stand contract must cover crash, accounting and version boundaries.');
}

function test_edd_refund_production_store_commits_intent_before_return_and_preserves_token_and_withdrawal_cas()
{
    list($adapter, $memory) = edd_refund_harness();
    $template = $adapter->request('100', 'full', 'production_store_token')->toArray();
    $template = array_replace($template, array('cash_state' => 'not_submitted', 'withdrawal_id' => null,
        'observation' => null, 'projection_state' => 'none', 'projection' => null, 'effects' => array()));
    $originalDb = $GLOBALS['wpdb']; $db = new EddRefundDatabase(); $GLOBALS['wpdb'] = $db;
    try {
        $store = new PaymosEasyDigitalDownloads\RefundIntentStore();
        $store->withPaymentLock('100', static function () use ($store, $db, $template) {
            $intent = $store->createIntent(new Paymos\Plugin\RefundIntent($template));
            assertSameValue(1, $db->commits, 'Production createIntent commits before returning to the HTTP coordinator.');
            $same = $store->createIntent(new Paymos\Plugin\RefundIntent($template));
            assertSameValue($intent->operationId(), $same->operationId(), 'Identical request token is durable and stable.');
            $changed = $template; $changed['mode'] = 'overpayment'; $changed['full_draft'] = null; unset($changed['payload_hash']);
            edd_refund_expect_failure(static function () use ($store, $changed) { $store->createIntent(new Paymos\Plugin\RefundIntent($changed)); }, 'refund_request_conflict');
            assertSameValue('bound', $store->bindWithdrawal($intent->operationId(), 'wdl_committed'), 'Production binding succeeds once.');
            assertSameValue('same', $store->bindWithdrawal($intent->operationId(), 'wdl_committed'), 'The same binding is idempotent.');
            assertSameValue('conflict', $store->bindWithdrawal($intent->operationId(), 'wdl_other'), 'A second Withdrawal cannot overwrite the first.');
            assertSameValue('wdl_committed', $store->getIntent($intent->operationId())->withdrawalId(), 'Conflict retains historical provider identity.');
            assertSameValue('native_review', $store->getIntent($intent->operationId())->projectionState(), 'Binding conflict requires review.');
            $claim = $store->claimEffect($intent->operationId(), 'email_order_refund', 1);
            assertSameValue('claimed', $claim->kind(), 'The production effect claim is committed.');
            assertSameValue('already_started', $store->claimEffect($intent->operationId(), 'email_order_refund', 1)->kind(), 'Started cannot be automatically reclaimed.');
            $db->rejectUpdate = true;
            edd_refund_expect_failure(static function () use ($store, $intent) {
                $store->recordState($intent->operationId(), 'failed', 'none', null);
            }, 'refund_store_write_conflict');
            assertSameValue('review', $store->getIntent($intent->operationId())->cashState(), 'A stale CAS never reports a successful state change.');
        });
        assertSameValue(array(), $db->locks, 'The production named mutex is released.');
    } finally { $GLOBALS['wpdb'] = $originalDb; }
}

function test_edd_ton_raw_pin_accepts_friendly_same_account_and_rejects_another()
{
    require_once __DIR__ . '/CmsTonPinContract.php';
    $originalDb = $GLOBALS['wpdb'];
    $GLOBALS['wpdb'] = new EddRefundDatabase();
    try {
        paymos_cms_ton_pin_contract(new PaymosEasyDigitalDownloads\RefundIntentStore(), 'edd', 'fixture-installation', '100');
    } finally { $GLOBALS['wpdb'] = $originalDb; }
}

function test_edd_refund_production_schema_upgrade_retries_without_marking_a_partial_install_as_ready()
{
    edd_refund_harness();
    $originalDb = $GLOBALS['wpdb']; $db = new EddRefundDatabase(); $GLOBALS['wpdb'] = $db;
    try {
        $db->failObservations = true;
        PaymosEasyDigitalDownloads\RefundIntentStore::maybeUpgrade();
        assertSameValue('', get_option(PaymosEasyDigitalDownloads\RefundIntentStore::SCHEMA_OPTION, ''), 'Partial schema installation is not ready.');
        $db->failObservations = false;
        PaymosEasyDigitalDownloads\RefundIntentStore::maybeUpgrade();
        assertSameValue('1', get_option(PaymosEasyDigitalDownloads\RefundIntentStore::SCHEMA_OPTION, ''), 'A subsequent upgrade can recover both tables.');
        $queries = count($db->queries);
        PaymosEasyDigitalDownloads\RefundIntentStore::maybeUpgrade();
        assertSameValue($queries, count($db->queries), 'A current schema is reused without DDL.');
    } finally { $GLOBALS['wpdb'] = $originalDb; }
}

function test_edd_refund_duplicate_native_mapping_or_changed_environment_is_rejected_before_http()
{
    foreach (array('duplicate', 'environment') as $case) {
        list($adapter, $store, $transport) = edd_refund_harness();
        if ($case === 'duplicate') {
            $GLOBALS['paymos_edd_payments'][200] = $GLOBALS['paymos_edd_payments'][100];
            $GLOBALS['paymos_edd_payment_meta'][200] = $GLOBALS['paymos_edd_payment_meta'][100];
        } else { $GLOBALS['paymos_edd_payments'][100]['mode'] = 'live'; }
        edd_refund_expect_failure(static function () use ($adapter) {
            $adapter->request('100', 'full', 'mapping_failure_token');
        }, $case === 'duplicate' ? 'refund_payment_mapping_ambiguous' : 'refund_payment_scope_conflict');
        assertSameValue(array(), $transport->requests, 'Ambiguous or foreign environment mapping fails before all HTTP.');
        assertSameValue(array(), $store->intents, 'No durable cash intent can target an ambiguous native payment.');
    }
}

function test_edd_refund_control_normalization_cannot_launder_invalid_mode_or_token()
{
    $original = $_POST;
    try {
        foreach (array(array('mode' => 'FULL'), array('mode' => 'full<script>'), array('mode' => array('full')),
            array('paymos_refund_token' => 'valid_token_123456<script>'), array('paymos_refund_token' => array('valid_token_123456'))) as $change) {
            list($adapter, $store, $transport) = edd_refund_harness();
            $_POST = array_replace(array('order_id' => 100, 'mode' => 'overpayment', 'security' => 'test-nonce',
                'paymos_refund_token' => 'control_valid_token1'), $change);
            PaymosEasyDigitalDownloads\RefundAdapter::managedAjax($adapter);
            assertSameValue(array(), $transport->requests, 'Malformed controls cannot become a valid new cash request after sanitization.');
            assertSameValue(array(), $store->intents, 'Malformed controls do not create a durable intent.');
            assertSameValue(false, $GLOBALS['paymos_edd_refund_json']['success'], 'Invalid controls are rejected explicitly.');
        }
    } finally { $_POST = $original; }
}

function test_edd_refund_native_encoded_form_preserves_the_wire_and_native_full_draft()
{
    $original = $_POST;
    try {
        list($adapter, $store, $transport) = edd_refund_harness();
        $_POST = paymos_edd_refund_form_post('100');
        $_POST['paymos_refund_token'] = 'encoded_form_token_01';
        $before = $_POST;
        assertContainsValue('%5B', $_POST['data'], 'The test uses real encoded nested form keys.');
        PaymosEasyDigitalDownloads\RefundAdapter::nativeAjax($adapter);
        assertSameValue($before, $_POST, 'Transport decoding cannot rewrite the core native form or durable token.');
        assertSameValue(1, count(edd_refund_posts($transport)), 'The exact valid full native form is still accepted once.');
        $intent = reset($store->intents);
        assertSameValue('100', $intent['full_draft']['amount_decimal'], 'The server-owned native total is not rounded or sanitized into another amount.');
        assertSameValue(0, count(edd_get_order_refunds(100)), 'Pending crypto cash never becomes a premature native refund.');
    } finally { $_POST = $original; }
}
