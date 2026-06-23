<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\StatusMapper;

defined('ABSPATH') || exit;

final class PaymentMapper
{
    /**
     * @param array<string, mixed> $event
     */
    public function apply($paymentId, array $event)
    {
        $paymentId = (int) $paymentId;
        $eventType = isset($event['event_type']) && is_scalar($event['event_type']) ? (string) $event['event_type'] : '';
        $status = isset($event['data']['status']) && is_scalar($event['data']['status']) ? (string) $event['data']['status'] : null;
        $action = StatusMapper::invoiceAction($eventType, $status);

        $this->recordEvent($paymentId, $event, $eventType);

        if (PaymentRepository::isComplete($paymentId) && $this->wouldRollBackCompletePayment($action)) {
            PaymentRepository::insertNote($paymentId, __('Payment already complete — later status update from Paymos disregarded.', 'paymos-easy-digital-downloads'));
            return;
        }

        switch ($action) {
            case StatusMapper::ACTION_CONFIRMING:
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                PaymentRepository::updateStatus($paymentId, 'pending');
                PaymentRepository::insertNote($paymentId, __('Paymos payment is confirming.', 'paymos-easy-digital-downloads'));
                break;

            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                // A second distinct terminal event (e.g. paid then paid_over) is a
                // re-complete, not a downgrade, so the roll-back guard lets it
                // through; short-circuit to avoid a duplicate completion note.
                if (PaymentRepository::isComplete($paymentId)) {
                    break;
                }

                if (!$this->isSafeToComplete($paymentId, $event)) {
                    PaymentRepository::updateMeta($paymentId, '_paymos_amount_mismatch', 'yes');
                    PaymentRepository::insertNote($paymentId, $this->mismatchNote($paymentId, $event));
                    break;
                }

                $transfer = $this->selectedTransfer($event);
                $hash = $transfer['tx_hash'] !== '' ? $transfer['tx_hash'] : $this->fallbackTransactionId($event);
                if ($transfer['tx_hash'] !== '') {
                    PaymentRepository::updateMeta($paymentId, '_paymos_tx_hash', $transfer['tx_hash']);
                }
                if ($transfer['explorer_url'] !== '') {
                    PaymentRepository::updateMeta($paymentId, '_paymos_explorer_url', $transfer['explorer_url']);
                }
                PaymentRepository::updateMeta($paymentId, '_paymos_amount_mismatch', 'no');
                PaymentRepository::setTransactionId($paymentId, $hash);
                PaymentRepository::updateStatus($paymentId, 'complete');
                PaymentRepository::insertNote($paymentId, __('Paymos payment completed.', 'paymos-easy-digital-downloads'));
                break;

            case StatusMapper::ACTION_FAIL_ORDER:
                PaymentRepository::updateStatus($paymentId, 'failed');
                PaymentRepository::insertNote($paymentId, __('Underpaid — the on-chain amount was less than the invoice total.', 'paymos-easy-digital-downloads'));
                break;

            case StatusMapper::ACTION_CANCEL_ORDER:
                // EDD 3.x has no 'cancelled' order status; an invoice that expired
                // or was cancelled before payment is idiomatically 'abandoned'.
                PaymentRepository::updateStatus($paymentId, 'abandoned');
                PaymentRepository::insertNote($paymentId, __('Paymos invoice was cancelled or expired.', 'paymos-easy-digital-downloads'));
                break;
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function isSafeToComplete($paymentId, array $event)
    {
        return AmountGuard::isSafeToComplete(
            PaymentRepository::getMeta($paymentId, '_paymos_invoice_amount'),
            PaymentRepository::getMeta($paymentId, '_paymos_invoice_currency'),
            PaymentRepository::amount($paymentId),
            PaymentRepository::currency($paymentId),
            $this->eventOrderField($event, 'amount'),
            $this->eventOrderField($event, 'currency')
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function mismatchNote($paymentId, array $event)
    {
        return AmountGuard::mismatchSummary(
            PaymentRepository::getMeta($paymentId, '_paymos_invoice_amount'),
            PaymentRepository::getMeta($paymentId, '_paymos_invoice_currency'),
            PaymentRepository::amount($paymentId),
            PaymentRepository::currency($paymentId),
            $this->eventOrderField($event, 'amount'),
            $this->eventOrderField($event, 'currency')
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function eventOrderField(array $event, $field)
    {
        return isset($event['data']['order'][$field]) && is_scalar($event['data']['order'][$field])
            ? (string) $event['data']['order'][$field]
            : '';
    }

    /**
     * Select the on-chain transfer (tx_hash + explorer_url) representing the
     * payment. Canonical location is data.payment.transfers; data.transfers
     * (top-level) is read only as a defensive fallback for any payload still
     * queued from an older server build. Prefer the latest confirmed transfer.
     * Returns empty strings when no transfers are present (sandbox/simulated).
     *
     * @param array<string, mixed> $event
     * @return array{tx_hash: string, explorer_url: string}
     */
    private function selectedTransfer(array $event)
    {
        $transfers = null;
        if (isset($event['data']['payment']['transfers']) && is_array($event['data']['payment']['transfers'])) {
            $transfers = $event['data']['payment']['transfers'];
        } elseif (isset($event['data']['transfers']) && is_array($event['data']['transfers'])) {
            $transfers = $event['data']['transfers'];
        }

        $confirmed = null;
        $latest = null;
        if ($transfers !== null) {
            foreach ($transfers as $transfer) {
                if (!is_array($transfer) || !isset($transfer['tx_hash']) || !is_string($transfer['tx_hash']) || $transfer['tx_hash'] === '') {
                    continue;
                }

                $latest = $transfer;
                $status = isset($transfer['status']) && is_string($transfer['status']) ? strtolower($transfer['status']) : '';
                if ($status === 'confirmed') {
                    $confirmed = $transfer;
                }
            }
        }

        $chosen = $confirmed !== null ? $confirmed : $latest;
        if ($chosen === null) {
            return array('tx_hash' => '', 'explorer_url' => '');
        }

        return array(
            'tx_hash' => (string) $chosen['tx_hash'],
            'explorer_url' => isset($chosen['explorer_url']) && is_string($chosen['explorer_url']) ? $chosen['explorer_url'] : '',
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function fallbackTransactionId(array $event)
    {
        return isset($event['data']['invoice_id']) && is_scalar($event['data']['invoice_id']) ? (string) $event['data']['invoice_id'] : '';
    }

    /**
     * @param array<string, mixed> $event
     */
    private function recordEvent($paymentId, array $event, $eventType)
    {
        PaymentRepository::updateMeta($paymentId, '_paymos_last_event_type', $eventType);
        if (isset($event['event_id']) && is_scalar($event['event_id'])) {
            PaymentRepository::updateMeta($paymentId, '_paymos_last_event_id', (string) $event['event_id']);
        }
        if (isset($event['data']['status']) && is_scalar($event['data']['status'])) {
            PaymentRepository::updateMeta($paymentId, '_paymos_last_status', (string) $event['data']['status']);
        }
        // Paymos serializes timestamps as Unix seconds (int); fall back to
        // data.created_at when the envelope omits occurred_at.
        $ts = null;
        if (isset($event['occurred_at']) && is_numeric($event['occurred_at'])) {
            $ts = (int) $event['occurred_at'];
        } elseif (isset($event['data']['created_at']) && is_numeric($event['data']['created_at'])) {
            $ts = (int) $event['data']['created_at'];
        }
        if ($ts !== null) {
            PaymentRepository::updateMeta($paymentId, '_paymos_last_event_at', gmdate('c', $ts));
        }
    }

    private function wouldRollBackCompletePayment($action)
    {
        return in_array($action, array(
            StatusMapper::ACTION_CONFIRMING,
            StatusMapper::ACTION_AWAITING_PAYMENT,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }
}
