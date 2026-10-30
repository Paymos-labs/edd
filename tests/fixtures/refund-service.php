<?php

declare(strict_types=1);

namespace EDD\Emails {
    final class Registry
    {
        public static $available = true;
        public static $mail = array();
        public static $throwAfterSend = false;
        public static function is_registered($kind) { return self::$available; }
        public static function get($kind, $args) { return new RefundMail($kind, $args); }
    }
    final class RefundMail
    {
        public $refund;
        private $kind;
        private $args;
        public function __construct($kind, $args) { $this->kind = $kind; $this->args = $args; $this->refund = $args[0]; }
        public function send()
        {
            if (!\apply_filters('edd_should_send_email_' . $this->kind, true, $this)) { return null; }
            Registry::$mail[] = array($this->kind, $this->args[0]->id);
            if (Registry::$throwAfterSend) { throw new \RuntimeException('smtp_ack_lost'); }
            return true;
        }
    }
}

namespace EDD\Orders\Refunds {
    final class FormParser
    {
        public static function verify_nonce($input) { return \wp_verify_nonce($input['edd_process_refund'] ?? '', 'edd_process_refund'); }
        public static function parse_order_items($input)
        {
            $items = array();
            foreach ($input['refund_order_item'] ?? array() as $id => $item) {
                if (!empty($item['quantity'])) { $items[] = array_merge(array('order_item_id' => (int) $id), $item); }
            }
            return $items;
        }
        public static function parse_adjustments($input)
        {
            $items = array();
            foreach ($input['refund_order_adjustment'] ?? array() as $id => $item) { $items[] = array_merge(array('adjustment_id' => (int) $id), $item); }
            return $items;
        }
    }
    final class Validator
    {
        public $total = 0;
        public static $reject = false;
        public function __construct($order, $items = 'all', $adjustments = 'all')
        {
            if ($items === 'all') { $this->total = (string) $order->total; return; }
            foreach ($items as $item) { $this->total += (float) $item['subtotal'] + (float) ($item['tax'] ?? 0); }
            foreach ($adjustments as $item) { $this->total += (float) $item['subtotal'] + (float) ($item['tax'] ?? 0); }
        }
        public function validate_and_calculate_totals() { if (self::$reject) { throw new \RuntimeException('native_validator_rejected'); } }
    }
}

namespace {
    function current_user_can($capability) { return $GLOBALS['paymos_edd_refund_permission'] ?? true; }
    function wp_verify_nonce($nonce, $action) { return $nonce === 'test-nonce'; }
    function wp_unslash($value) { return $value; }
    function wp_send_json_error($data, $status = null) { $GLOBALS['paymos_edd_refund_json'] = array('success' => false, 'data' => $data, 'status' => $status); }
    function wp_send_json_success($data, $status = null) { $GLOBALS['paymos_edd_refund_json'] = array('success' => true, 'data' => $data, 'status' => $status); }
    function edd_add_order_transaction($data)
    {
        if (!empty($GLOBALS['paymos_edd_refund_transaction_fail'])) { return false; }
        $id = count($GLOBALS['paymos_edd_refund_transactions'] ?? array()) + 1;
        $GLOBALS['paymos_edd_refund_transactions'][$id] = (object) array_merge(array('id' => $id), $data);
        return $id;
    }
    function edd_get_order_transaction($id) { return $GLOBALS['paymos_edd_refund_transactions'][$id] ?? false; }
    function edd_get_email($kind) { return new EddRefundMailSettings($kind); }
    final class EddRefundMailSettings
    {
        private $kind;
        public function __construct($kind) { $this->kind = $kind; }
        public function is_enabled() { return $GLOBALS['paymos_edd_refund_mail_enabled'][$this->kind] ?? true; }
    }
    if (!defined('EDD_VERSION')) { define('EDD_VERSION', '3.7.2'); }
    function wp_generate_uuid4() { static $id = 1; return '00000000-0000-4000-8000-' . str_pad((string) $id++, 12, '0', STR_PAD_LEFT); }
    function edd_get_order($id)
    {
        $row = paymos_edd_read_native_row((int) $id);
        if (!$row) { return false; }
        return new EddNativeOrder(array_merge(array('id' => (int) $id, 'type' => 'sale', 'parent' => 0, 'currency' => 'USD', 'mode' => 'test',
            'total' => (string) ($row['price'] ?? '0'), 'items' => array(new EddRefundItem())), $row));
    }
    final class EddRefundItem
    {
        public $id = 1; public $product_id = 10; public $quantity = '1'; public $subtotal = '100'; public $tax = '0';
        public $price_id = 0; public $discount = '0'; public $total = '100';
        public function get_refundable_amounts() { return array('subtotal' => $this->subtotal, 'tax' => $this->tax); }
    }
    final class EddNativeOrder
    {
        public $id; public $type; public $parent; public $currency; public $total; public $items; public $gateway; public $status; public $mode;
        public function __construct(array $row) { foreach (array('id', 'type', 'parent', 'currency', 'total', 'items', 'gateway', 'status', 'mode') as $key) { $this->$key = $row[$key] ?? null; } }
        public function get_items() { return $this->items; }
        public function get_adjustments() { return $GLOBALS['paymos_edd_order_adjustments'][$this->id] ?? array(); }
        public function get_fees() { return array(); }
    }
    function edd_get_order_refunds($id)
    {
        $found = array();
        foreach ($GLOBALS['paymos_edd_payments'] as $key => $row) {
            if (($row['type'] ?? '') === 'refund' && ($row['parent'] ?? 0) === (int) $id) { $found[] = edd_get_order($key); }
        }
        return $found;
    }
    function edd_get_order_meta($id, $key, $single) { return edd_get_payment_meta($id, $key, $single); }
    function edd_update_order_meta($id, $key, $value) { return edd_update_payment_meta($id, $key, $value); }
    function edd_get_note($id) { return $GLOBALS['paymos_edd_native_notes'][(int) $id] ?? false; }
    function edd_currency_decimal_filter($precision, $currency) { return $currency === 'JPY' ? 0 : $precision; }

    add_action('edd_refund_order', static function ($id, $refundId) {
        foreach (array('order_refund', 'admin_order_refund') as $kind) {
            \EDD\Emails\Registry::get($kind, array(edd_get_order($refundId), $id))->send();
        }
    }, 10, 2);

    /** State persistence double, with a separate durable snapshot and rollback of native records. */
    final class EddRefundMemoryStore extends \PaymosEasyDigitalDownloads\RefundIntentStore
    {
        public $intents = array();
        public $externals = array();
        public $key;
        public $depth = 0;
        public $lockLost = false;
        public $transactional = true;
        public $committedBeforePost = false;
        public $afterNote;
        public $loseCommitAck = false;
        public function withPaymentLock(string $paymentKey, callable $action)
        {
            $old = $this->key;
            if ($old !== null && $old !== $paymentKey) { throw new \RuntimeException('refund_lock_scope_conflict'); }
            $this->key = $paymentKey;
            try { return $action(); } finally { $this->key = $old; }
        }
        public function assertOwned() { if ($this->key === null || $this->lockLost) { throw new \RuntimeException('refund_lock_lost'); } }
        public function paymentKey(): string { $this->assertOwned(); return $this->key; }
        public function loadOrder() {
            $this->assertOwned();
            if ($this->afterNote && !empty($GLOBALS['paymos_edd_payment_notes'][$this->key])) {
                $callback = $this->afterNote; $this->afterNote = null; $callback();
            }
            \PaymosEasyDigitalDownloads\PaymentRepository::refresh((int) $this->key);
            return edd_get_order($this->key);
        }
        public function assertTransactionalTables() { if (!$this->transactional) { throw new \RuntimeException('refund_transactional_tables_required'); } }
        public function assertNativeTransaction() { $this->assertOwned(); if ($this->depth === 0) { throw new \RuntimeException('refund_native_transaction_required'); } }
        public function transaction(callable $action)
        {
            $this->assertOwned();
            $snapshot = serialize(array($this->intents, $this->externals, $GLOBALS['paymos_edd_payments'],
                $GLOBALS['paymos_edd_payment_meta'], $GLOBALS['paymos_edd_payment_notes'], $GLOBALS['paymos_edd_native_notes'],
                $GLOBALS['paymos_edd_refund_transactions']));
            $this->depth++;
            try { $result = $action(); }
            catch (\Throwable $error) {
                list($this->intents, $this->externals, $GLOBALS['paymos_edd_payments'], $GLOBALS['paymos_edd_payment_meta'],
                    $GLOBALS['paymos_edd_payment_notes'], $GLOBALS['paymos_edd_native_notes'], $GLOBALS['paymos_edd_refund_transactions']) = unserialize($snapshot);
                throw $error;
            } finally { $this->depth--; }
            if ($this->depth === 0 && $this->loseCommitAck && count(edd_get_order_refunds((int) $this->key)) > 0) {
                $this->loseCommitAck = false;
                throw new \RuntimeException('commit_ack_lost');
            }
            return $result;
        }
        public function createIntent(\Paymos\Plugin\RefundIntent $intent): \Paymos\Plugin\RefundIntent
        {
            if ($this->depth !== 0) { throw new \RuntimeException('refund_intent_must_commit'); }
            foreach ($this->forPayment($intent->paymentKey()) as $old) {
                if ($this->blocksNewIntent($old)) { throw new \RuntimeException('refund_payment_has_unresolved_intent'); }
            }
            $this->intents[$intent->operationId()] = $intent->toArray(); $this->committedBeforePost = true; return $intent;
        }
        public function findByRequestToken(string $installationId, string $token): ?\Paymos\Plugin\RefundIntent
        {
            foreach ($this->intents as $data) {
                if ($data['installation_id'] === $installationId && $data['request_token'] === $token) { return new \Paymos\Plugin\RefundIntent($data); }
            }
            return null;
        }
        public function getIntent(string $id): \Paymos\Plugin\RefundIntent { return new \Paymos\Plugin\RefundIntent($this->intents[$id]); }
        public function findByExternalId(string $id): ?\Paymos\Plugin\RefundIntent { return $this->findField('external_order_id', $id); }
        public function findByWithdrawalId(string $id): ?\Paymos\Plugin\RefundIntent { return $this->findField('withdrawal_id', $id); }
        private function findField($field, $value) { foreach ($this->intents as $data) { if ($data[$field] === $value) { return new \Paymos\Plugin\RefundIntent($data); } } return null; }
        public function forPayment(string $key): array { return array_values(array_filter(array_map(static function ($data) { return new \Paymos\Plugin\RefundIntent($data); }, $this->intents), static function ($intent) use ($key) { return $intent->paymentKey() === $key; })); }
        public function bindWithdrawal(string $id, string $withdrawalId): string
        {
            $old = $this->intents[$id]['withdrawal_id'];
            if ($old !== null && $old !== $withdrawalId) { return 'conflict'; }
            $this->intents[$id]['withdrawal_id'] = $withdrawalId; return $old === null ? 'bound' : 'same';
        }
        public function recordObservation(string $id, \Paymos\Plugin\VerifiedRefund $snapshot): void { $this->intents[$id]['observation'] = $snapshot->toArray(); }
        public function recordProjection(string $id, \Paymos\Plugin\NativeProjectionResult $result): void { $this->intents[$id]['projection'] = $result->toArray(); }
        public function recordState(string $id, string $cash, string $projection, ?string $reason): void { $this->intents[$id] = array_replace($this->intents[$id], array('cash_state' => $cash, 'projection_state' => $projection, 'review_reason' => $reason)); }
        public function claimEffect(string $id, string $kind, int $generation): \Paymos\Plugin\EffectClaim
        {
            if ($this->depth !== 0) { throw new \RuntimeException('refund_effect_must_commit'); }
            $key = $kind . ':' . $generation;
            if (isset($this->intents[$id]['effects'][$key])) { return new \Paymos\Plugin\EffectClaim('already_started'); }
            $this->intents[$id]['effects'][$key] = 'started'; return new \Paymos\Plugin\EffectClaim('claimed');
        }
        public function recordEffectOutcome(string $id, string $kind, int $generation, string $state): void { $this->intents[$id]['effects'][$kind . ':' . $generation] = $state; }
        public function listDue(\DateTimeImmutable $now, int $limit, ?string $cursor): \Paymos\Plugin\RefundIntentPage { return new \Paymos\Plugin\RefundIntentPage(array_map(function ($id) { return $this->getIntent($id); }, array_keys($this->intents)), null); }
        public function defer(string $operationId): void {}
        public function allForInvoice(string $invoice, string $environment): array
        {
            $ids = array();
            foreach ($GLOBALS['paymos_edd_payments'] as $id => $row) {
                if (edd_get_payment_gateway($id) === 'paymos' && edd_get_payment_meta($id, '_paymos_invoice_id', true) === $invoice
                    && edd_get_payment_meta($id, '_paymos_environment', true) === $environment) { $ids[] = $id; }
            }
            return $ids;
        }
        public function external(string $id): ?array { return $this->externals[$id] ?? null; }
        public function saveExternal(\Paymos\Plugin\VerifiedRefund $snapshot, ?string $noteId): void
        {
            $this->externals[$snapshot->withdrawalId()] = array('payment_key' => $this->key, 'observation' => $snapshot->toArray(), 'note_id' => $noteId,
                'review_reason' => $snapshot->mode() === 'full' ? 'refund_external_full_without_draft' : ($snapshot->status() === 'completed' ? null : 'cash_rewound'));
        }
        public function externalForPayment(string $key): array { return array_values(array_filter($this->externals, static function ($data) use ($key) { return $data['payment_key'] === $key; })); }
    }

    final class EddRefundTransport implements \Paymos\Http\TransportInterface
    {
        public $store;
        public $requests = array();
        public $refunds = array();
        public $summaryEnabled = true;
        public $loseReply = false;
        public $loseAllReplies = false;
        public $tamperProject = false;
        public $beforeGet;
        public function request($method, $url, array $headers, $body, $timeoutSeconds)
        {
            if ($this->store->depth !== 0) { throw new \RuntimeException('HTTP_inside_DB_transaction'); }
            $path = parse_url($url, PHP_URL_PATH); $this->requests[] = array($method, $path, $body);
            if ($method === 'POST') {
                if (!$this->store->committedBeforePost) { throw new \RuntimeException('POST_before_durable_intent'); }
                $payload = json_decode($body, true); $id = 'wdl_' . substr(hash('sha256', $payload['external_order_id']), 0, 12);
                $this->refunds[$id] = $this->refunds[$id] ?? edd_refund_wire($id, $payload['mode'], $payload['external_order_id'], 'created');
                if ($payload['mode'] === 'full') {
                    foreach ($this->refunds as $previous) {
                        if ($previous['mode'] === 'overpayment' && $previous['status'] === 'completed') { $this->refunds[$id]['amount'] = '100'; }
                    }
                }
                if ($this->loseReply || $this->loseAllReplies) { $this->loseReply = false; throw new \RuntimeException('HTTP_reply_lost_after_server_commit'); }
                $data = $this->refunds[$id];
            } elseif (strpos($path, '/v1/refunds/') === 0) {
                if ($this->beforeGet) { $callback = $this->beforeGet; $this->beforeGet = null; $callback(); }
                $data = $this->refunds[basename($path)];
                if ($this->tamperProject) { $data['project_id'] = 'prj_foreign'; }
            } elseif ($path === '/v1/refunds') {
                $data = array('items' => array_values($this->refunds), 'next_cursor' => null);
            } else {
                $data = edd_refund_invoice($this->summaryEnabled);
                foreach ($this->refunds as $previous) {
                    if ($previous['mode'] === 'overpayment' && $previous['status'] === 'completed') {
                        $data['refund_summary']['full_refund_amount'] = '100';
                        $data['refund_summary']['overpayment_refund_amount'] = '0';
                        $data['refund_summary']['can_refund_overpayment'] = false;
                    }
                }
            }
            return new \Paymos\Http\HttpResponse(200, json_encode($data), array('content-type' => 'application/json'));
        }
    }

    final class EddRefundDatabase
    {
        public $prefix = 'wp_';
        public $last_error = '';
        public $rows = array();
        public $queries = array();
        public $locks = array();
        public $commits = 0;
        public $failObservations = false;
        public $rejectUpdate = false;
        private $snapshots = array();
        public function prepare($sql, ...$args) { return serialize(array($sql, $args)); }
        public function get_charset_collate() { return ''; }
        public function esc_like($value) { return addcslashes($value, '_%\\'); }
        public function query($sql)
        {
            $this->queries[] = $sql;
            if ($this->failObservations && strpos($sql, 'CREATE TABLE') === 0 && strpos($sql, 'refund_observations') !== false) { return false; }
            if ($sql === 'START TRANSACTION') { $this->snapshots[] = $this->rows; }
            if ($sql === 'COMMIT') { array_pop($this->snapshots); $this->commits++; }
            if ($sql === 'ROLLBACK' && $this->snapshots) { $this->rows = array_pop($this->snapshots); }
            return 0;
        }
        public function get_var($query)
        {
            list($sql, $args) = unserialize($query);
            if (strpos($sql, 'GET_LOCK(') !== false) { $this->locks[$args[0]] = '41'; return '41'; }
            $owned = isset($this->locks[$args[1]]) && $this->locks[$args[1]] === $args[0];
            if (strpos($sql, 'RELEASE_LOCK') !== false && $owned) { unset($this->locks[$args[1]]); }
            return $owned ? '1' : '0';
        }
        private function select($query)
        {
            list($sql, $args) = unserialize($query);
            if (strpos($sql, 'SHOW TABLE STATUS') === 0) { return array(array('Engine' => 'InnoDB')); }
            $rows = array_values($this->rows[$args[0]] ?? array());
            if (strpos($sql, 'installation_id = %s AND request_token') !== false) {
                return array_values(array_filter($rows, static function ($row) use ($args) { return $row['installation_id'] === $args[1] && $row['request_token'] === $args[2]; }));
            }
            if (strpos($sql, 'WHERE %i = %s') !== false) { $column = $args[1]; $value = $args[2]; }
            elseif (strpos($sql, 'native_payment_key = %s') !== false) { $column = 'native_payment_key'; $value = $args[1]; }
            else { throw new \RuntimeException('Unexpected refund database query: ' . $sql); }
            return array_values(array_filter($rows, static function ($row) use ($column, $value) { return $row[$column] === $value; }));
        }
        public function get_row($query, $mode = null) { $rows = $this->select($query); return $rows[0] ?? null; }
        public function get_results($query, $mode = null) { return $this->select($query); }
        public function insert($table, $data)
        {
            foreach ($this->rows[$table] ?? array() as $row) {
                if ($row['external_order_id'] === $data['external_order_id']
                    || ($row['installation_id'] === $data['installation_id'] && $row['request_token'] === $data['request_token'])) { return false; }
            }
            $this->rows[$table][$data['operation_id']] = $data;
            return 1;
        }
        public function update($table, $changes, $where)
        {
            if ($this->rejectUpdate) { return 0; }
            foreach ($this->rows[$table] ?? array() as $id => $row) {
                foreach ($where as $field => $value) { if ($row[$field] !== $value) { continue 2; } }
                $this->rows[$table][$id] = array_replace($row, $changes);
                return 1;
            }
            return 0;
        }
    }

    function edd_refund_invoice($enabled = true)
    {
        return array('invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true, 'status' => 'paid_over',
            'order' => array('external_id' => 'edd_100_abc123', 'amount' => '100', 'currency' => 'USD'),
            'refund_summary' => array('can_refund_full' => $enabled, 'can_refund_overpayment' => $enabled, 'full_refund_amount' => '120', 'overpayment_refund_amount' => '20'));
    }
    function edd_refund_wire($id, $mode, $external, $status = 'completed')
    {
        return array('withdrawal_id' => $id, 'type' => 'refund', 'invoice_id' => 'inv_123', 'project_id' => 'prj_123',
            'mode' => $mode, 'external_order_id' => $external, 'status' => $status, 'is_test' => true,
            'amount' => $mode === 'overpayment' ? '20' : '120', 'currency' => 'USDT', 'network' => 'tron',
            'first_sender' => 'Tfirst', 'destination_address' => 'Tfirst');
    }

    function edd_refund_harness()
    {
        paymos_edd_reset_test_state(); paymos_edd_seed_payment();
        $GLOBALS['paymos_edd_next_payment_id'] = 101;
        $GLOBALS['paymos_edd_refund_transactions'] = array();
        $GLOBALS['paymos_edd_refund_transaction_fail'] = false;
        $GLOBALS['paymos_edd_refund_permission'] = true;
        $GLOBALS['paymos_edd_refund_mail_enabled'] = array();
        $GLOBALS['paymos_edd_refund_json'] = null;
        \EDD\Orders\Refunds\Validator::$reject = false;
        $GLOBALS['paymos_edd_payments'][100]['status'] = 'publish';
        edd_update_payment_meta(100, '_paymos_applied_status', 'paid_over');
        $GLOBALS['paymos_edd_options']['paymos_edd_refund_installation'] = '00000000-0000-4000-8000-000000000001';
        \EDD\Emails\Registry::$available = true; \EDD\Emails\Registry::$mail = array(); \EDD\Emails\Registry::$throwAfterSend = false;
        $store = new EddRefundMemoryStore(); $transport = new EddRefundTransport(); $transport->store = $store;
        $client = new \Paymos\Client(new \Paymos\ClientConfig('pk_test', 'sk_test', 'https://api.paymos.test', 30), $transport);
        $config = array('api_key' => 'pk_test', 'project_id' => 'prj_123', 'capabilities' => array('invoice_refunds_v1'), 'refund_contract_version' => 1, 'refunds_enabled' => false);
        $adapter = new \PaymosEasyDigitalDownloads\RefundAdapter($store, static function () use ($client) { return $client; },
            static function ($environment) use ($config) { return $environment === 'sandbox' ? $config : array(); });
        return array($adapter, $store, $transport);
    }
}
