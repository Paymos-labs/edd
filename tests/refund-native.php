<?php

declare(strict_types=1);

$contract = array(
    'platform' => 'Easy Digital Downloads',
    'required_package_matrix' => array('3.2.0/refund-capability-refused', '3.3.0/modern-refund-mail', '3.7.2/modern-refund-mail'),
    'observed_packages' => array(),
    'required_environment_evidence' => array('wordpress_version', 'edd_version', 'package_sha256', 'php_version',
        'plugin_revision', 'sdk_revision', 'transactional_tables', 'mail_hook_registration'),
    'scenarios' => array(
        'protected_native_form' => 'Initialize Refund/Submit Refund: capability, native nonce, Parser/Validator and original paid full draft precede provider POST.',
        'partial_and_manual_refusal' => 'Partial, prior manual refund and unprotected direct/legacy refund refuse before HTTP or native write; other gateways retain native behavior.',
        'managed_over20_then_full100' => 'G120/P100: over20 has one cash note, refunded0, paid order and unchanged stock; full100 has one offline native document and negative transaction.',
        'connection_runtime_gate' => 'Connect disabled then server enabled works without reconnect; disabled new keys fail while accepted replay/read/reconcile continue.',
        'lost_response_same_token' => 'Restart after committed intent or lost HTTP acknowledgement: original mode/key, one Withdrawal and verified primary GET.',
        'callback_before_binding' => 'Signed scoped callback is only a read signal; mismatched mode/key/id/project/environment causes no effects.',
        'native_transaction_crashes' => 'Kill before and after native commit: refund, order/items/stats, transaction, marker and module projection are atomic on the same InnoDB connection.',
        'cash_only_boundary' => 'An overpayment note cannot change native refunded, paid status or stock; unsafe mutation rolls back.',
        'reorg_recompletion' => 'Rewind preserves accounting and the cash note; recompletion reuses markers and does not repeat stock/stat/mail effects.',
        'scoped_native_mail' => 'Suppress the exact finalizing refund before priority10; unrelated gateway mail works; saved defaults are respected.',
        'smtp_lost_ack' => 'Commit started before native Registry send; process death after SMTP acceptance becomes unknown/review, without automatic resend.',
        'external_discovery' => 'Lost dashboard/API callback: bounded scoped list plus independent GET produce one durable observation, full without draft stays review, overpayment has one cash note, POST0.',
        'concurrent_full_actions' => 'Separate DB connections serialize one native payment; write-once Withdrawal binding and a stale connection owner cannot mutate effects.',
        'schema_install_upgrade' => 'Clean/network install, versioned upgrade and deactivate/reactivate retain intent, token, binding and recovery history.',
    ),
    'status' => 'skipped',
    'reason' => 'No approved WordPress/EDD stand or installed native package evidence. Service stubs do not certify native behavior.',
);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    foreach ($contract['scenarios'] as $name => $assertion) { echo 'SKIP ' . $name . ': ' . $contract['reason'] . PHP_EOL; }
    echo 'SKIPPED ' . count($contract['scenarios']) . ' native scenarios; observed packages: 0' . PHP_EOL;
}

return $contract;
