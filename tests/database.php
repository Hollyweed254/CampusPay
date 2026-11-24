<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\DisputeService;

group('Dispute lifecycle (database)');

$pdo = Database::pdo();
$suite = bin2hex(random_bytes(4));
q(
    'INSERT INTO applications (name, slug, status, cash_on_meeting_enabled, settlement_model) VALUES (?, ?, ?, ?, ?)',
    ['Test Suite ' . $suite, 'test-suite-' . $suite, 'active', 1, 'merchant_collection']
);
$suiteAppId = (int) $pdo->lastInsertId();
$suiteRequestIds = [];
$suiteTxIds = [];
$suiteDisputeIds = [];

/**
 * Insert a payment request plus its single transaction.
 *
 * @return array{request_id:int, tx_id:int, reference:string}
 */
$makeCase = function (string $method, string $requestStatus, string $txStatus, string $provider) use ($suiteAppId, &$suiteRequestIds, &$suiteTxIds): array {
    $reference = 'CPTEST' . strtoupper(bin2hex(random_bytes(8)));
    q(
        'INSERT INTO payment_requests
           (reference, application_id, description, amount, currency, payment_method, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$reference, $suiteAppId, 'Dispute suite case', '150.00', 'KES', $method, $requestStatus]
    );
    $requestId = (int) Database::pdo()->lastInsertId();
    q(
        'INSERT INTO transactions (payment_request_id, provider, amount, currency, status) VALUES (?, ?, ?, ?, ?)',
        [$requestId, $provider, '150.00', 'KES', $txStatus]
    );
    $txId = (int) Database::pdo()->lastInsertId();
    $suiteRequestIds[] = $requestId;
    $suiteTxIds[] = $txId;
    return ['request_id' => $requestId, 'tx_id' => $txId, 'reference' => $reference];
};

$txStatus = fn (int $id): string => (string) q('SELECT status FROM transactions WHERE id = ?', [$id])->fetchColumn();
$requestStatus = fn (int $id): string => (string) q('SELECT status FROM payment_requests WHERE id = ?', [$id])->fetchColumn();
$disputeRow = fn (int $id): array => q('SELECT * FROM disputes WHERE id = ?', [$id])->fetch();
$auditCount = fn (string $action): int => (int) q(
    "SELECT COUNT(*) FROM audit_logs WHERE action = ? AND target_type = 'dispute'",
    [$action]
)->fetchColumn();

try {
    test('opening a confirmed dispute marks the transaction disputed and keeps the prior status', function () use ($makeCase, $txStatus, $disputeRow, $auditCount, &$suiteDisputeIds): void {
        $case = $makeCase('mpesa', 'succeeded', 'provider_confirmed', 'mpesa');
        $before = $auditCount('dispute.opened');
        $id = DisputeService::open($case['tx_id'], 'Buyer says the money left twice.', 1);
        $suiteDisputeIds[] = $id;

        $dispute = $disputeRow($id);
        same('open', $dispute['status']);
        same('provider_confirmed', $dispute['previous_transaction_status']);
        same('disputed', $txStatus($case['tx_id']));
        truthy($auditCount('dispute.opened') === $before + 1, 'audit row missing');
    });

    test('review then resolve as confirmed restores the confirmed record', function () use ($makeCase, $txStatus, $requestStatus, $disputeRow, $auditCount, &$suiteDisputeIds): void {
        $case = $makeCase('mpesa', 'succeeded', 'provider_confirmed', 'mpesa');
        $id = DisputeService::open($case['tx_id'], 'Receipt not recognised by the buyer.', 1);
        $suiteDisputeIds[] = $id;

        $before = $auditCount('dispute.review_started');
        DisputeService::startReview($id, 1);
        same('under_review', $disputeRow($id)['status']);
        truthy($auditCount('dispute.review_started') === $before + 1, 'review audit missing');

        DisputeService::resolve($id, 'payment_confirmed', 'Receipt matched against the provider result.', 1);
        $dispute = $disputeRow($id);
        same('resolved', $dispute['status']);
        truthy($dispute['resolved_at'] !== null, 'resolved_at not set');
        same('provider_confirmed', $txStatus($case['tx_id']));
        same('succeeded', $requestStatus($case['request_id']));
    });

    test('resolving as failed corrects both the transaction and the request', function () use ($makeCase, $txStatus, $requestStatus, &$suiteDisputeIds): void {
        $case = $makeCase('mpesa', 'succeeded', 'provider_confirmed', 'mpesa');
        $id = DisputeService::open($case['tx_id'], 'No money reached the merchant.', 1);
        $suiteDisputeIds[] = $id;

        DisputeService::resolve($id, 'payment_failed', 'Provider statement shows the payment failed.', 1);
        same('failed', $txStatus($case['tx_id']));
        same('failed', $requestStatus($case['request_id']));
    });

    test('rejecting restores the exact status from before the dispute', function () use ($makeCase, $txStatus, $disputeRow, &$suiteDisputeIds): void {
        $case = $makeCase('mpesa', 'failed', 'failed', 'mpesa');
        $id = DisputeService::open($case['tx_id'], 'Buyer claims a payment really went through.', 1);
        $suiteDisputeIds[] = $id;

        DisputeService::reject($id, 'No provider record of any successful payment.', 1);
        same('rejected', $disputeRow($id)['status']);
        same('failed', $txStatus($case['tx_id']));
    });

    test('cash disputes uphold the cash record instead of confirming M-Pesa', function () use ($makeCase, $txStatus, $requestStatus, &$suiteDisputeIds): void {
        $case = $makeCase('cash_on_meeting', 'recorded', 'reported', 'cash_on_meeting');
        $id = DisputeService::open($case['tx_id'], 'Seller says the cash was never handed over.', 1);
        $suiteDisputeIds[] = $id;

        throwsCode(fn () => DisputeService::resolve($id, 'payment_confirmed', 'Should not be allowed for cash.', 1), 'invalid_outcome');
        DisputeService::resolve($id, 'record_upheld', 'Both parties confirmed cash changed hands.', 1);
        same('reported', $txStatus($case['tx_id']));
        same('recorded', $requestStatus($case['request_id']));
    });

    test('guards reject ineligible, duplicated and invalid cases', function () use ($makeCase, &$suiteDisputeIds): void {
        $pending = $makeCase('mpesa', 'pending', 'pending', 'mpesa');
        throwsCode(fn () => DisputeService::open($pending['tx_id'], 'Too early to dispute this one.', 1), 'not_disputable');

        $confirmed = $makeCase('mpesa', 'succeeded', 'provider_confirmed', 'mpesa');
        $id = DisputeService::open($confirmed['tx_id'], 'First dispute on this transaction.', 1);
        $suiteDisputeIds[] = $id;
        throwsCode(fn () => DisputeService::open($confirmed['tx_id'], 'Second dispute attempt.', 1), 'already_disputed');
        throwsCode(fn () => DisputeService::open($confirmed['tx_id'], 'no', 1), 'invalid_reason');

        throwsCode(function () use ($id): void {
            DisputeService::startReview($id, 1);
            DisputeService::startReview($id, 1);
        }, 'invalid_transition');
        throwsCode(fn () => DisputeService::resolve($id, 'payment_confirmed', 'x', 1), 'invalid_resolution');
    });
} finally {
    if ($suiteDisputeIds !== []) {
        $in = implode(',', array_fill(0, count($suiteDisputeIds), '?'));
        q("DELETE FROM audit_logs WHERE target_type = 'dispute' AND target_id IN ($in)", array_map('strval', $suiteDisputeIds));
        q("DELETE FROM disputes WHERE id IN ($in)", $suiteDisputeIds);
    }
    if ($suiteTxIds !== []) {
        $in = implode(',', array_fill(0, count($suiteTxIds), '?'));
        q("DELETE FROM transactions WHERE id IN ($in)", $suiteTxIds);
    }
    if ($suiteRequestIds !== []) {
        $in = implode(',', array_fill(0, count($suiteRequestIds), '?'));
        q("DELETE FROM payment_requests WHERE id IN ($in)", $suiteRequestIds);
    }
    q('DELETE FROM applications WHERE id = ?', [$suiteAppId]);
}
