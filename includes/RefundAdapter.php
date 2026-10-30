<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\CredentialSet;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundDiscovery;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

defined('ABSPATH') || exit;

/** EDD owns accounting; the shared coordinator alone creates Paymos cash requests. */
final class RefundAdapter implements NativeRefundProjectorInterface
{
    public const HOOK = 'paymos_edd_refunds_reconcile';
    private $store;
    private $clientFactory;
    private $configProvider;
    private static $finalizing;

    public function __construct($store = null, ?callable $clientFactory = null, ?callable $configProvider = null)
    {
        $this->store = $store ?: new RefundIntentStore();
        $this->clientFactory = $clientFactory;
        $this->configProvider = $configProvider ?: array(Config::class, 'environment_config');
    }

    public static function register()
    {
        add_action('wp_ajax_paymos_edd_refund', array(__CLASS__, 'managedAjax'));
        add_action('wp_ajax_edd_process_refund_form', array(__CLASS__, 'nativeAjax'), 0);
        add_action('edd_view_order_details_payment_meta_after', array(__CLASS__, 'panel'));
        add_action('edd_refund_order', array(__CLASS__, 'markNativeRefund'), 5, 3);
        add_filter('edd_is_order_refundable_by_override', array(__CLASS__, 'refundWindow'), 0, 2);
        add_filter('edd_should_send_email_order_refund', array(__CLASS__, 'emailEnabled'), PHP_INT_MAX, 2);
        add_filter('edd_should_send_email_admin_order_refund', array(__CLASS__, 'emailEnabled'), PHP_INT_MAX, 2);
        add_filter('cron_schedules', array(__CLASS__, 'cronSchedules'));
        add_action('init', array(__CLASS__, 'schedule'));
        add_action(self::HOOK, array(__CLASS__, 'run'));
    }

    public static function cronSchedules($schedules)
    {
        $schedules['paymos_edd_refund_minute'] = array('interval' => 60, 'display' => 'Paymos');
        return $schedules;
    }

    public static function schedule()
    {
        if (!wp_next_scheduled(self::HOOK)) { wp_schedule_event(time() + 60, 'paymos_edd_refund_minute', self::HOOK); }
    }

    public static function unschedule() { wp_clear_scheduled_hook(self::HOOK); }

    /** No public setter: a native bypass is possible only during the verified projector call. */
    public static function isFinalizing($orderId): bool
    {
        return self::$finalizing !== null && self::$finalizing['payment_id'] === (int) $orderId;
    }

    public static function refundWindow($allowed, $orderId)
    {
        // Cron projects an already accepted full operation without the initiating administrator.
        return self::isFinalizing($orderId) ? true : $allowed;
    }

    public static function emailEnabled($enabled, $email = null)
    {
        if (self::$finalizing === null || !is_object($email)) { return $enabled; }
        $refund = $email->refund;
        if (is_object($refund) && (int) $refund->id === self::$finalizing['refund_id']
            && (int) $refund->parent === self::$finalizing['payment_id']) {
            return false;
        }
        return $enabled;
    }

    public function request(string $paymentKey, string $mode, string $token, array $input = array()): RefundIntent
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $token)
            || !in_array($mode, array(RefundStates::FULL, RefundStates::OVERPAYMENT), true)) {
            throw new \RuntimeException('refund_request_invalid');
        }
        return $this->store->withPaymentLock($paymentKey, function () use ($paymentKey, $mode, $token, $input) {
            $installation = $this->installationId();
            $old = $this->store->findByRequestToken($installation, $token);
            if ($old !== null) {
                if ($old->paymentKey() !== $paymentKey || $old->mode() !== $mode) {
                    throw new \RuntimeException('refund_request_conflict');
                }
                if ($mode === RefundStates::FULL) { $this->assertInput($old->fullDraft(), $input); }
                elseif ($input !== array()) { $this->assertModeOnly($input); }
                $this->assertMapping($this->store->loadOrder(), $old);
                $this->coordinator($old)->submit($old->operationId());
                $this->reconcile($old->operationId());
                return $this->store->getIntent($old->operationId());
            }
            $order = $this->store->loadOrder();
            $this->assertMapping($order);
            $this->assertOriginalPaid($order);
            $this->requireCapability($this->environment($order));
            $this->assertNativeSupport();
            $this->store->assertTransactionalTables();
            foreach ($this->store->externalForPayment($paymentKey) as $external) {
                if ($external['review_reason'] !== null || $external['observation']['mode'] === RefundStates::FULL) {
                    throw new \RuntimeException('refund_external_requires_review');
                }
            }
            $draft = $mode === RefundStates::FULL ? $this->draft($order) : null;
            if ($draft !== null) { $this->assertInput($draft, $input); }
            else { $this->assertModeOnly($input); }
            $intent = new RefundIntent(array(
                'operation_id' => wp_generate_uuid4(), 'request_token' => $token,
                'installation_id' => $installation, 'platform' => 'edd', 'native_payment_key' => $paymentKey,
                'invoice_id' => $this->meta($order, '_paymos_invoice_id'),
                'project_id' => $this->meta($order, '_paymos_project_id'), 'environment' => $this->environment($order),
                'mode' => $mode, 'full_draft' => $draft,
            ));
            if (($reason = $this->preflight($intent)) !== null) { throw new \RuntimeException($reason); }
            $intent = $this->store->createIntent($intent); // Commits before coordinator HTTP.
            $this->coordinator($intent)->submit($intent->operationId());
            $this->reconcile($intent->operationId());
            return $this->store->getIntent($intent->operationId());
        });
    }

    public function preflight(RefundIntent $intent): ?string
    {
        try {
            $this->requireCapability($intent->environment());
            $this->assertNativeSupport();
            $this->store->assertTransactionalTables();
            $order = $this->store->loadOrder();
            $this->assertMapping($order, $intent);
            $this->assertOriginalPaid($order);
            if ($intent->fullDraft() !== null) { $this->assertDraft($order, $intent->fullDraft()); }
            $invoice = $this->client($intent->environment())->invoices()->get($intent->invoiceId());
            $this->store->assertOwned();
            $this->assertInvoice($invoice, $order);
            $fresh = $this->store->loadOrder();
            $this->assertMapping($fresh, $intent);
            $this->assertOriginalPaid($fresh);
            if ($intent->fullDraft() !== null) { $this->assertDraft($fresh, $intent->fullDraft()); }
            $flag = $intent->mode() === RefundStates::FULL ? 'can_refund_full' : 'can_refund_overpayment';
            if (!isset($invoice['refund_summary'][$flag]) || $invoice['refund_summary'][$flag] !== true) {
                return 'refund_server_not_eligible';
            }
            return null;
        } catch (\Throwable $error) { return $error->getMessage(); }
    }

    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($operationId) {
            $current = $this->store->getIntent($operationId);
            $this->assertMapping($this->store->loadOrder(), $current);
            if ($current->cashState() === RefundStates::NOT_SUBMITTED) {
                $this->coordinator($current)->submit($operationId);
            }
            $action = $this->coordinator($this->store->getIntent($operationId))->reconcile($operationId);
            $this->dispatchMail($operationId);
            $this->store->defer($operationId);
            return $action;
        });
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->store->getIntent($operationId);
        $order = $this->store->loadOrder();
        $this->assertMapping($order, $intent);
        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            return $this->inspectNote($intent->paymentKey(), $operationId, $intent->observation());
        }
        $found = array();
        foreach (edd_get_order_refunds((int) $intent->paymentKey()) as $refund) {
            if (edd_get_order_meta((int) $refund->id, '_paymos_refund_operation_id', true) === $operationId) { $found[] = $refund; }
        }
        if (count($found) === 0) { return new NativeProjectionResult(NativeProjectionResult::ABSENT, $intent->mode()); }
        if (count($found) !== 1 || count(edd_get_order_refunds((int) $intent->paymentKey())) !== 1
            || !$this->matchesNativeRefund($found[0], $intent)) {
            return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), null, null, null, null, null, 'refund_native_marker_conflict');
        }
        return new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, $intent->mode(), (string) $found[0]->id,
            $intent->fullDraft()['amount_minor'], $intent->fullDraft()['currency']);
    }

    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        $this->store->assertNativeTransaction();
        if ($refund->status() !== 'completed' || $intent->withdrawalId() !== $refund->withdrawalId()
            || $intent->observation() === null || $intent->observation()->toArray() !== $refund->toArray()) {
            throw new \RuntimeException('refund_verified_completion_required');
        }
        $order = $this->store->loadOrder();
        $this->assertMapping($order, $intent);
        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            $this->assertOriginalPaid($order);
            return $this->writeNote($intent->paymentKey(), $intent->operationId(), $refund);
        }
        $this->assertNativeSupport();
        $this->assertOriginalPaid($order);
        $this->assertDraft($order, $intent->fullDraft());
        self::$finalizing = array('payment_id' => (int) $intent->paymentKey(), 'operation_id' => $intent->operationId(),
            'withdrawal_id' => $refund->withdrawalId(), 'store' => $this->store, 'refund_id' => null,
            'intent' => $intent);
        try {
            $draft = $intent->fullDraft();
            $items = array();
            foreach ($draft['items'] as $itemId => $item) { $items[] = array_merge(array('order_item_id' => (int) $itemId), $item); }
            $adjustments = array();
            foreach ($draft['adjustments'] as $adjustmentId => $adjustment) {
                unset($adjustment['type']);
                $adjustments[] = array_merge(array('adjustment_id' => (int) $adjustmentId), $adjustment);
            }
            $id = edd_refund_order((int) $intent->paymentKey(), $items, $adjustments);
            $this->store->assertOwned();
            if (!is_numeric($id) || (int) $id <= 0 || self::$finalizing['refund_id'] !== (int) $id) {
                throw new \RuntimeException('refund_native_write_unknown');
            }
            $result = $this->inspect($intent->operationId());
            if ($result->kind() !== NativeProjectionResult::EXACT_MATCH) { throw new \RuntimeException('refund_native_marker_conflict'); }
            return $result;
        } finally { self::$finalizing = null; }
    }

    /** Runs before EDD's priority10 mail, in the same native transaction. */
    public static function markNativeRefund($orderId, $refundId, $allRefunded)
    {
        if (!self::isFinalizing($orderId)) { return; }
        $context = self::$finalizing;
        $context['store']->assertNativeTransaction();
        $native = edd_get_order((int) $refundId);
        if (!$allRefunded || !$native || (int) $native->parent !== (int) $orderId || $native->type !== 'refund') {
            throw new \RuntimeException('refund_native_full_required');
        }
        edd_update_order_meta((int) $refundId, '_paymos_refund_operation_id', $context['operation_id']);
        edd_update_order_meta((int) $refundId, '_paymos_refund_withdrawal_id', $context['withdrawal_id']);
        if (edd_get_order_meta((int) $refundId, '_paymos_refund_operation_id', true) !== $context['operation_id']
            || edd_get_order_meta((int) $refundId, '_paymos_refund_withdrawal_id', true) !== $context['withdrawal_id']) {
            throw new \RuntimeException('refund_native_marker_failed');
        }
        self::$finalizing['refund_id'] = (int) $refundId;
        $intent = $context['intent'];
        $transactionId = edd_add_order_transaction(array('object_id' => (int) $refundId, 'object_type' => 'order',
            'transaction_id' => $context['withdrawal_id'], 'gateway' => Gateway::ID, 'status' => 'complete',
            'total' => '-' . $intent->fullDraft()['amount_decimal']));
        if (!$transactionId) { throw new \RuntimeException('refund_native_transaction_failed'); }
        edd_update_order_meta((int) $refundId, '_paymos_refund_transaction_id', (string) $transactionId);
        $result = new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, RefundStates::FULL, (string) $refundId,
            $intent->fullDraft()['amount_minor'], $intent->fullDraft()['currency']);
        $context['store']->recordProjection($context['operation_id'], $result);
    }

    public function dispatchMail(string $operationId): void
    {
        $intent = $this->store->getIntent($operationId);
        if ($intent->mode() !== RefundStates::FULL || $intent->cashState() !== RefundStates::COMPLETED
            || $intent->projectionState() !== RefundStates::PROJECTION_APPLIED) { return; }
        $projection = $this->inspect($operationId);
        if ($projection->kind() !== NativeProjectionResult::EXACT_MATCH) { return; }
        $native = edd_get_order((int) $projection->nativeId());
        foreach (array('order_refund', 'admin_order_refund') as $kind) {
            $key = 'email_' . $kind;
            if (empty($intent->fullDraft()['mail'][$kind])) { continue; }
            $claim = $this->store->claimEffect($operationId, $key, 1); // Durable started BEFORE external send.
            if ($claim->kind() !== EffectClaim::CLAIMED) {
                if (($this->store->getIntent($operationId)->toArray()['effects'][$key . ':1'] ?? '') === RefundStates::EFFECT_STARTED) {
                    $this->store->recordEffectOutcome($operationId, $key, 1, RefundStates::EFFECT_UNKNOWN);
                    $this->store->recordState($operationId, $intent->cashState(), RefundStates::NATIVE_REVIEW, 'refund_email_outcome_unknown');
                }
                continue;
            }
            try {
                $mail = \EDD\Emails\Registry::get($kind, array($native, (int) $intent->paymentKey()));
                $sent = $mail->send();
                $this->store->assertOwned();
                $this->store->recordEffectOutcome($operationId, $key, 1,
                    $sent === true ? RefundStates::EFFECT_DELIVERED : ($sent === false ? RefundStates::EFFECT_FAILED_NO_EFFECT : RefundStates::EFFECT_SKIPPED));
            } catch (\Throwable $error) {
                $this->store->recordEffectOutcome($operationId, $key, 1, RefundStates::EFFECT_UNKNOWN);
                $this->store->recordState($operationId, $intent->cashState(), RefundStates::NATIVE_REVIEW, 'refund_email_outcome_unknown');
            }
        }
    }

    /** VerifiedWebhookEvent already proved HMAC; event fields only select independent GETs. */
    public function callback(array $data, string $environment): void
    {
        if (($data['type'] ?? '') !== 'refund' || !isset($data['withdrawal_id'], $data['invoice_id'], $data['project_id'], $data['is_test'])
            || !in_array($data['mode'] ?? null, array(RefundStates::FULL, RefundStates::OVERPAYMENT), true)
            || !is_bool($data['is_test']) || $data['is_test'] !== ($environment === 'sandbox')) {
            throw new \RuntimeException('refund_callback_scope_missing');
        }
        foreach (array('withdrawal_id', 'invoice_id', 'project_id', 'external_order_id') as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw new \RuntimeException('refund_callback_scope_missing');
            }
        }
        $intent = $this->store->findByWithdrawalId($data['withdrawal_id']);
        if ($intent === null && isset($data['external_order_id'])) { $intent = $this->store->findByExternalId($data['external_order_id']); }
        if ($intent !== null) {
            if ($intent->invoiceId() !== $data['invoice_id'] || $intent->projectId() !== $data['project_id'] || $intent->environment() !== $environment
                || $intent->mode() !== $data['mode'] || $intent->externalOrderId() !== $data['external_order_id']
                || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $data['withdrawal_id'])) {
                throw new \RuntimeException('refund_callback_scope_conflict');
            }
            $this->reconcile($intent->operationId());
            return;
        }
        $this->observeExternal($data['withdrawal_id'], $data['invoice_id'], $environment, $data);
    }

    public function observeExternal(string $id, string $invoiceId, string $environment, array $signal = array()): void
    {
        $ids = $this->store->allForInvoice($invoiceId, $environment);
        if (count($ids) !== 1) { throw new \RuntimeException('refund_external_mapping_ambiguous'); }
        $this->store->withPaymentLock((string) $ids[0], function () use ($id, $invoiceId, $environment, $signal) {
            $order = $this->store->loadOrder();
            $this->assertMapping($order);
            $client = $this->client($environment);
            $wire = $client->refunds()->get($id);
            $invoice = $client->invoices()->get($invoiceId);
            $this->store->assertOwned();
            $this->assertInvoice($invoice, $this->store->loadOrder());
            $snapshot = VerifiedRefund::fromApi($wire, $invoice, new \DateTimeImmutable('now'));
            if ($snapshot->withdrawalId() !== $id || $snapshot->invoiceId() !== $invoiceId || $snapshot->environment() !== $environment) {
                throw new \RuntimeException('refund_external_scope_conflict');
            }
            if ($signal !== array() && ($signal['project_id'] !== $snapshot->projectId() || $signal['mode'] !== $snapshot->mode()
                || $signal['external_order_id'] !== $snapshot->externalOrderId())) {
                throw new \RuntimeException('refund_callback_scope_conflict');
            }
            $this->store->transaction(function () use ($snapshot) {
                $old = $this->store->external($snapshot->withdrawalId());
                $noteId = $old === null ? null : $old['note_id'];
                if ($snapshot->mode() === RefundStates::OVERPAYMENT && $snapshot->status() === 'completed') {
                    $projection = $this->writeNote($this->store->paymentKey(), 'external:' . $snapshot->withdrawalId(), $snapshot);
                    $noteId = $projection->nativeId();
                }
                $this->store->saveExternal($snapshot, $noteId);
            });
        });
    }

    public function sweep(): void
    {
        foreach ($this->store->listDue(new \DateTimeImmutable('now'), 100, null)->items() as $intent) {
            try { $this->reconcile($intent->operationId()); }
            catch (\Throwable $error) {
                Logger::error('Paymos refund reconcile failed: ' . $error->getMessage());
                try { $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent) { $this->store->defer($intent->operationId()); }); }
                catch (\Throwable $deferError) { Logger::error('Paymos refund defer failed: ' . $deferError->getMessage()); }
            }
        }
        // One bounded page per connection; keep rotating past bad mappings, then repeat history.
        foreach (array('sandbox', 'live') as $environment) {
            $config = call_user_func($this->configProvider, $environment);
            if (empty($config['api_key']) || empty($config['project_id'])) { continue; }
            $option = 'paymos_edd_refund_discovery_' . hash('sha256', $environment . ':' . $config['project_id'] . ':' . $config['api_key']);
            $cursor = get_option($option, null);
            $page = RefundDiscovery::page($this->client($environment), $environment, is_string($cursor) ? $cursor : null, function ($wire) use ($config, $environment) {
                if (($wire['project_id'] ?? '') !== $config['project_id']) { return false; }
                if (count($this->store->allForInvoice($wire['invoice_id'] ?? '', $environment)) === 0) { return false; }
                $this->callback($wire, $environment);
                return true;
            });
            update_option($option, $page['next_cursor'], false);
            foreach ($page['errors'] as $id) { Logger::error('Paymos external refund needs another discovery pass.', array('withdrawal_id' => $id)); }
        }
    }

    public static function run()
    {
        try { (new self())->sweep(); } catch (\Throwable $error) { Logger::error('Paymos refund sweep failed: ' . $error->getMessage()); }
    }

    public function summary(string $paymentKey): array
    {
        return $this->store->withPaymentLock($paymentKey, function () {
            $order = $this->store->loadOrder();
            $this->assertMapping($order);
            $invoice = $this->client($this->environment($order))->invoices()->get($this->meta($order, '_paymos_invoice_id'));
            $this->store->assertOwned();
            $order = $this->store->loadOrder();
            $this->assertInvoice($invoice, $order);
            $summary = $invoice['refund_summary'] ?? array();
            try {
                $this->assertNativeSupport(); $this->requireCapability($this->environment($order)); $this->assertOriginalPaid($order);
                $this->store->assertTransactionalTables();
                foreach ($this->store->forPayment($this->store->paymentKey()) as $intent) {
                    if ($this->store->blocksNewIntent($intent)) { throw new \RuntimeException('refund_unresolved'); }
                }
                foreach ($this->store->externalForPayment($this->store->paymentKey()) as $external) {
                    if ($external['review_reason'] !== null || $external['observation']['mode'] === RefundStates::FULL) { throw new \RuntimeException('refund_external_requires_review'); }
                }
            } catch (\Throwable $error) { $summary['can_refund_full'] = false; $summary['can_refund_overpayment'] = false; $summary['local_unavailable_reason'] = $error->getMessage(); }
            return $summary;
        });
    }

    private function requireCapability(string $environment): void
    {
        $config = call_user_func($this->configProvider, $environment);
        if (!CredentialSet::hasCapability($config, 'invoice_refunds_v1') || ($config['refund_contract_version'] ?? null) !== 1) {
            throw new \RuntimeException('refund_reconnect_required');
        }
        // refunds_enabled in Connect is a historical snapshot; fresh summary/create owns enablement.
    }

    private function assertNativeSupport(): void
    {
        if (!defined('EDD_VERSION') || version_compare(EDD_VERSION, '3.3.0', '<') || !function_exists('edd_refund_order')
            || !function_exists('edd_get_order_refunds') || !function_exists('edd_get_note')
            || !function_exists('edd_add_order_transaction') || !function_exists('edd_get_order_transaction')
            || !function_exists('edd_get_email') || !class_exists('EDD\\Orders\\Refunds\\Validator')
            || !class_exists('EDD\\Emails\\Registry') || !\EDD\Emails\Registry::is_registered('order_refund')
            || !\EDD\Emails\Registry::is_registered('admin_order_refund')) {
            throw new \RuntimeException('refund_edd_33_native_email_required');
        }
    }

    private function assertMapping($order, ?RefundIntent $intent = null): void
    {
        if (!$order || $order->gateway !== Gateway::ID || $order->type !== 'sale') { throw new \RuntimeException('refund_payment_mapping_invalid'); }
        foreach (array('_paymos_invoice_id', '_paymos_project_id', '_paymos_environment', '_paymos_external_order_id') as $key) {
            if ($this->meta($order, $key) === '') { throw new \RuntimeException('refund_payment_mapping_missing'); }
        }
        $environment = $this->environment($order);
        $config = call_user_func($this->configProvider, $environment);
        if (!in_array($environment, array('sandbox', 'live'), true) || ($config['project_id'] ?? null) !== $this->meta($order, '_paymos_project_id')
            || (string) $order->mode !== ($environment === 'live' ? 'live' : 'test')
            || ($intent !== null && ((string) $order->id !== $intent->paymentKey() || $intent->invoiceId() !== $this->meta($order, '_paymos_invoice_id')
                || $intent->projectId() !== $this->meta($order, '_paymos_project_id') || $intent->environment() !== $environment))) {
            throw new \RuntimeException('refund_payment_scope_conflict');
        }
        $ids = $this->store->allForInvoice($this->meta($order, '_paymos_invoice_id'), $environment);
        if (count($ids) !== 1 || (int) $ids[0] !== (int) $order->id) { throw new \RuntimeException('refund_payment_mapping_ambiguous'); }
    }

    private function assertOriginalPaid($order): void
    {
        if (!PaymentRepository::isComplete($order->id) || count(edd_get_order_refunds($order->id)) !== 0
            || !AmountGuard::amountsEqual((string) $order->total, $this->meta($order, '_paymos_invoice_amount'))
            || strtoupper((string) $order->currency) !== $this->meta($order, '_paymos_invoice_currency')
            || !in_array($this->meta($order, '_paymos_applied_status'), array('paid', 'paid_over'), true)) {
            throw new \RuntimeException('refund_original_full_paid_required');
        }
    }

    private function assertInvoice(array $invoice, $order): void
    {
        $this->assertMapping($order);
        if (($invoice['invoice_id'] ?? null) !== $this->meta($order, '_paymos_invoice_id')
            || ($invoice['project_id'] ?? null) !== $this->meta($order, '_paymos_project_id')
            || ($invoice['is_test'] ?? null) !== ($this->environment($order) === 'sandbox')
            || ($invoice['order']['external_id'] ?? null) !== $this->meta($order, '_paymos_external_order_id')
            || !AmountGuard::amountsEqual($invoice['order']['amount'] ?? '', $this->meta($order, '_paymos_invoice_amount'))
            || ($invoice['order']['currency'] ?? null) !== $this->meta($order, '_paymos_invoice_currency')) {
            throw new \RuntimeException('refund_invoice_scope_conflict');
        }
    }

    private function draft($order): array
    {
        $precision = function_exists('edd_currency_decimal_filter') ? (int) edd_currency_decimal_filter(2, $order->currency) : 2;
        $amount = AmountGuard::normalizeDecimal((string) $order->total);
        if ($amount === null || $amount === '0' || $precision < 0 || $precision > 8) { throw new \RuntimeException('refund_native_total_invalid'); }
        $parts = explode('.', $amount, 2); $fraction = $parts[1] ?? '';
        if (strlen($fraction) > $precision) { throw new \RuntimeException('refund_native_total_precision'); }
        $minor = ltrim($parts[0] . str_pad($fraction, $precision, '0'), '0');
        $items = array();
        foreach ($order->get_items() as $item) {
            $amounts = $item->get_refundable_amounts();
            $items[(string) $item->id] = array('quantity' => (string) $item->quantity,
                'subtotal' => (string) $amounts['subtotal'], 'tax' => (string) $amounts['tax']);
        }
        if (count($items) === 0 || count($items) >= 200) { throw new \RuntimeException('refund_native_item_scope_review'); }
        ksort($items);
        $adjustments = array();
        $fees = $order->get_fees(); $orderAdjustments = $order->get_adjustments();
        if (count($fees) >= 200 || count($orderAdjustments) >= 200) { throw new \RuntimeException('refund_native_adjustment_scope_review'); }
        foreach (array_merge($fees, $orderAdjustments) as $adjustment) {
            if (!in_array($adjustment->type, array('fee', 'credit'), true)) { continue; }
            $amounts = $adjustment->get_refundable_amounts();
            $adjustments[(string) $adjustment->id] = array('type' => (string) $adjustment->type,
                'subtotal' => (string) $amounts['subtotal'], 'tax' => (string) $amounts['tax']);
        }
        ksort($adjustments);
        $validator = new \EDD\Orders\Refunds\Validator($order, 'all', 'all');
        $validator->validate_and_calculate_totals();
        if (!AmountGuard::amountsEqual((string) $validator->total, $amount)) { throw new \RuntimeException('refund_native_total_changed'); }
        $mail = array();
        foreach (array('order_refund', 'admin_order_refund') as $kind) {
            $setting = edd_get_email($kind);
            $mail[$kind] = $setting && $setting->is_enabled();
        }
        return array('amount_decimal' => $amount, 'amount_minor' => $minor, 'currency' => strtoupper((string) $order->currency),
            'precision' => $precision, 'items' => $items, 'adjustments' => $adjustments, 'mail' => $mail,
            'snapshot' => $this->nativeSnapshot($order));
    }

    private function nativeSnapshot($order): string
    {
        $items = array();
        foreach ($order->get_items() as $item) {
            $values = array();
            foreach (array('product_id', 'price_id', 'quantity', 'subtotal', 'tax', 'discount', 'total') as $field) {
                $values[$field] = (string) $item->$field;
            }
            $items[(string) $item->id] = $values;
        }
        ksort($items);
        return hash('sha256', wp_json_encode(array((string) $order->total, (string) $order->currency, $items)));
    }

    private function assertDraft($order, array $saved): void
    {
        $current = $this->draft($order);
        unset($current['mail'], $saved['mail']);
        if ($current !== $saved) { throw new \RuntimeException('refund_native_draft_changed'); }
    }

    private function assertModeOnly(array $input): void
    {
        foreach (array('amount', 'refund_amount', 'refund_order_item', 'refund_order_adjustment', 'address', 'recipient') as $field) {
            if (array_key_exists($field, $input)) { throw new \RuntimeException('refund_mode_only_required'); }
        }
    }

    private function assertInput(array $draft, array $input): void
    {
        foreach (array('amount', 'refund_amount') as $field) {
            if (isset($input[$field]) && !AmountGuard::amountsEqual($input[$field], $draft['amount_decimal'])) { throw new \RuntimeException('refund_partial_draft_rejected'); }
        }
        if (isset($input['refund_order_item'])) {
            $items = $input['refund_order_item'];
            if (!is_array($items) || count($items) !== count($draft['items'])) { throw new \RuntimeException('refund_partial_draft_rejected'); }
            foreach ($draft['items'] as $id => $expected) {
                foreach ($expected as $field => $value) {
                    if (!isset($items[$id][$field]) || !AmountGuard::amountsEqual($items[$id][$field], $value)) { throw new \RuntimeException('refund_partial_draft_rejected'); }
                }
            }
        }
        if (isset($input['refund_order_item']) || isset($input['refund_order_adjustment'])) {
            $actual = $input['refund_order_adjustment'] ?? array();
            if (!is_array($actual) || count($actual) !== count($draft['adjustments'])) { throw new \RuntimeException('refund_partial_adjustment_rejected'); }
            foreach ($draft['adjustments'] as $id => $expected) {
                if (!isset($actual[$id]['subtotal'], $actual[$id]['tax'])
                    || !AmountGuard::amountsEqual($actual[$id]['subtotal'], $expected['subtotal'])
                    || !AmountGuard::amountsEqual($actual[$id]['tax'], $expected['tax'])) { throw new \RuntimeException('refund_partial_adjustment_rejected'); }
            }
        }
    }

    private function matchesNativeRefund($native, RefundIntent $intent): bool
    {
        $transaction = edd_get_order_transaction((int) edd_get_order_meta((int) $native->id, '_paymos_refund_transaction_id', true));
        return $native->type === 'refund' && (int) $native->parent === (int) $intent->paymentKey()
            && $this->nativeSnapshot($this->store->loadOrder()) === $intent->fullDraft()['snapshot']
            && $native->gateway === Gateway::ID && $native->status === 'complete'
            && strtoupper((string) $native->currency) === $intent->fullDraft()['currency']
            && strpos((string) $native->total, '-') === 0
            && AmountGuard::amountsEqual(substr((string) $native->total, 1), $intent->fullDraft()['amount_decimal'])
            && $transaction && (int) $transaction->object_id === (int) $native->id && $transaction->object_type === 'order'
            && $transaction->gateway === Gateway::ID && $transaction->status === 'complete'
            && $transaction->transaction_id === $intent->withdrawalId()
            && AmountGuard::amountsEqual((string) $transaction->total, '-' . $intent->fullDraft()['amount_decimal'])
            && AmountGuard::amountsEqual((string) edd_get_order_total((int) $intent->paymentKey()), '0')
            && edd_get_payment_status((int) $intent->paymentKey()) === 'refunded'
            && edd_get_order_meta((int) $native->id, '_paymos_refund_withdrawal_id', true) === $intent->withdrawalId();
    }

    private function inspectNote(string $paymentKey, string $marker, ?VerifiedRefund $snapshot): NativeProjectionResult
    {
        $mode = RefundStates::OVERPAYMENT;
        $id = PaymentRepository::getMeta((int) $paymentKey, '_paymos_refund_note_' . hash('sha256', $marker));
        if ($id === '') { return new NativeProjectionResult(NativeProjectionResult::ABSENT, $mode); }
        $note = edd_get_note((int) $id);
        if (!$note || $snapshot === null || (int) $note->object_id !== (int) $paymentKey || $note->object_type !== 'order'
            || $note->content !== $this->noteContent($marker, $snapshot)) {
            throw new \RuntimeException('refund_cash_note_marker_conflict');
        }
        return new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, $mode, $id, null, null,
            $snapshot->principalDecimal(), $snapshot->currency() . '/' . $snapshot->network());
    }

    private function writeNote(string $paymentKey, string $marker, VerifiedRefund $snapshot): NativeProjectionResult
    {
        $this->store->assertNativeTransaction();
        $found = $this->inspectNote($paymentKey, $marker, $snapshot);
        if ($found->kind() === NativeProjectionResult::EXACT_MATCH) { return $found; }
        $before = $this->cashOnlySnapshot($paymentKey);
        $id = edd_insert_payment_note((int) $paymentKey, $this->noteContent($marker, $snapshot));
        if (!is_numeric($id) || (int) $id < 1) { throw new \RuntimeException('refund_cash_note_failed'); }
        PaymentRepository::updateMeta((int) $paymentKey, '_paymos_refund_note_' . hash('sha256', $marker), (string) $id);
        if ($before !== $this->cashOnlySnapshot($paymentKey)) { throw new \RuntimeException('refund_cash_note_changed_native_state'); }
        return $this->inspectNote($paymentKey, $marker, $snapshot);
    }

    private function cashOnlySnapshot(string $paymentKey): array
    {
        $order = $this->store->loadOrder();
        $stock = array();
        foreach ($order->get_items() as $item) {
            $id = (int) $item->product_id;
            $stock[$id] = array(get_post_meta($id, '_stock', true), get_post_meta($id, '_edd_download_sales', true));
        }
        return array((string) $order->total, (string) $order->status, (string) edd_get_order_total((int) $paymentKey),
            array_map(static function ($refund) { return array((string) $refund->id, (string) $refund->total); }, edd_get_order_refunds((int) $paymentKey)), $stock);
    }

    private function noteContent(string $marker, VerifiedRefund $snapshot): string
    {
        // TODO-COPY: cash observation label.
        return 'Paymos [' . $marker . '] ' . $snapshot->withdrawalId() . ' | ' . RefundCopy::text('cash_note', 'en') . ' | '
            . $snapshot->principalDecimal() . ' ' . $snapshot->currency() . '/' . $snapshot->network();
    }

    public function view(RefundIntent $intent): array
    {
        $observation = $intent->observation();
        return array('operation_id' => $intent->operationId(), 'mode' => $intent->mode(),
            'cash_state' => $intent->cashState(), 'native_state' => $intent->projectionState(),
            'review_reason' => $intent->toArray()['review_reason'], 'withdrawal_id' => $intent->withdrawalId(),
            'amount' => $observation === null ? null : $observation->principalDecimal(),
            'asset' => $observation === null ? null : $observation->currency() . '/' . $observation->network());
    }

    private function meta($order, string $key): string { return PaymentRepository::getMeta((int) $order->id, $key); }
    private function environment($order): string { return $this->meta($order, '_paymos_environment'); }
    private function coordinator(RefundIntent $intent): RefundCoordinator { return new RefundCoordinator($this->client($intent->environment()), $this->store, $this); }
    private function client(string $environment)
    {
        if ($this->clientFactory !== null) { return call_user_func($this->clientFactory, $environment); }
        $config = call_user_func($this->configProvider, $environment);
        return new Client(new ClientConfig((string) $config['api_key'], (string) $config['api_secret'], (string) ($config['base_url'] ?? Config::DEFAULT_BASE_URL), 30));
    }
    private function installationId(): string
    {
        $key = 'paymos_edd_refund_installation'; $id = get_option($key, '');
        if ($id === '') { add_option($key, wp_generate_uuid4(), '', false); $id = get_option($key, ''); }
        if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/D', $id)) { throw new \RuntimeException('refund_installation_missing'); }
        return $id;
    }

    public static function nativeAjax(?self $adapter = null)
    {
        $id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        if ($id < 1 || edd_get_payment_gateway($id) !== Gateway::ID) { return; }
        // Without our durable token, leave the existing native refusal guard in charge.
        if (!isset($_POST['paymos_refund_token']) || !is_string($_POST['paymos_refund_token'])) { return; }
        self::ajax($id, RefundStates::FULL, true, $adapter);
    }
    public static function managedAjax(?self $adapter = null)
    {
        $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? $_POST['mode'] : '';
        self::ajax(isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0, $mode, false, $adapter);
    }
    private static function ajax(int $id, string $mode, bool $native, ?self $adapter = null): void
    {
        $input = $_POST;
        if ($native) { parse_str(isset($_POST['data']) && is_string($_POST['data']) ? wp_unslash($_POST['data']) : '', $input); }
        $nonce = $native ? ($input['edd_process_refund'] ?? '') : ($_POST['security'] ?? '');
        if (!current_user_can('edit_shop_payments')
            || !wp_verify_nonce($nonce, $native ? 'edd_process_refund' : 'paymos_edd_refund_' . $id)) {
            wp_send_json_error(array('message' => RefundCopy::text('unavailable')), 403); return;
        }
        try {
            if ($native) { $input = Gateway::refund_input($id, $input); }
            $adapter = $adapter ?: new self();
            if ($mode === 'summary') { wp_send_json_success($adapter->summary((string) $id)); return; }
            $token = isset($_POST['paymos_refund_token']) && is_string($_POST['paymos_refund_token']) ? $_POST['paymos_refund_token'] : '';
            $intent = $adapter->request((string) $id, $mode, $token, $input);
            if ($native) { wp_send_json_error(array('message' => RefundCopy::message($adapter->view($intent)), 'paymos_refund' => $adapter->view($intent))); return; }
            wp_send_json_success(array('paymos_refund' => $adapter->view($intent)));
        } catch (\Throwable $error) {
            Logger::error('Paymos refund entry failed: ' . $error->getMessage(), array('payment_id' => $id));
            wp_send_json_error(array('message' => RefundCopy::error($error->getMessage())), 409);
        }
    }

    public static function panel($order)
    {
        $id = is_object($order) ? (int) ($order->id ?? 0) : (int) $order;
        if ($id < 1 || !current_user_can('edit_shop_payments') || edd_get_payment_gateway($id) !== Gateway::ID) { return; }
        echo '<div id="paymos-edd-refunds" data-order-id="' . esc_attr($id) . '"></div>';
        // Administrative JavaScript fetches only this protected payment's summary/history.
        $settings = array('order_id' => $id, 'security' => wp_create_nonce('paymos_edd_refund_' . $id),
            'labels' => array_merge(RefundCopy::labels(), array('full' => RefundCopy::text('full_action'),
                'overpayment' => RefundCopy::text('overpayment_action'))),
            'tokens' => array('full' => wp_generate_uuid4(), 'overpayment' => wp_generate_uuid4()));
        try {
            RefundIntentStore::install(); $adapter = new self();
            echo '<h3>' . esc_html(RefundCopy::text('history_title')) . '</h3><ul>';
            foreach ($adapter->store->forPayment((string) $id) as $intent) {
                if (!in_array($intent->cashState(), array('failed', 'cancelled', 'completed'), true)) { $settings['tokens'][$intent->mode()] = $intent->toArray()['request_token']; }
                $view = $adapter->view($intent);
                echo '<li><p>' . esc_html(RefundCopy::text($intent->mode() === 'full' ? 'full_action' : 'overpayment_action'))
                    . '</p><p>' . esc_html(RefundCopy::message($view)) . '</p>';
                if ($view['amount'] !== null) { echo '<p><code>' . esc_html($view['amount'] . ' ' . $view['asset']) . '</code></p>'; }
                echo '</li>';
            }
            echo '</ul>';
        } catch (\Throwable $error) {
            Logger::error('Paymos refund panel failed: ' . $error->getMessage(), array('payment_id' => $id));
            echo '<p role="alert">' . esc_html(RefundCopy::error($error->getMessage())) . '</p>';
        }
        echo '<script>window.paymosEddRefunds=' . wp_json_encode($settings) . ';</script>';
        wp_enqueue_script('paymos-edd-refunds', plugins_url('assets/js/refunds.js', PAYMOS_EDD_PLUGIN_FILE), array('jquery'), PAYMOS_EDD_PLUGIN_VERSION, true);
    }
}
