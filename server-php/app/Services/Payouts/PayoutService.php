<?php
declare(strict_types=1);

namespace App\Services\Payouts;

use App\Services\AuditLog;
use App\Services\DataVault;
use App\Services\SettingsService;
use App\Support\ApiException;
use App\Support\FiscalYear;
use App\Support\Money;

/**
 * Weekly seller payouts: Engage batch → DRAFT → review → approval (four-eyes) →
 * bank bulk-upload file → payment results recorded by a person (UTR) → paid.
 *
 * Finance never moves money. "Paid" only ever follows a recorded UTR (also a DB
 * CHECK). Every change writes audit_log; every status Engage must learn about is
 * queued in the outbox in the same transaction.
 *
 * $actor = ['actor' => email, 'name' => display name, 'ip' => client ip].
 */
final class PayoutService
{
    private const TDS_LOCK = 7_302_119_002;
    private const SYSTEM   = ['actor' => 'system:engage', 'name' => 'Engage (inbound batch)', 'ip' => null];

    private AuditLog $audit;
    private OutboxService $outbox;

    public function __construct(private \PDO $pdo, private ?DataVault $vault = null)
    {
        $this->audit  = new AuditLog($pdo);
        $this->outbox = new OutboxService($pdo);
    }

    private function vault(): DataVault
    {
        return $this->vault ??= DataVault::fromEnv();
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        return SettingsService::typed((new SettingsService($this->pdo))->all());
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function tx(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $out = $fn();
            $this->pdo->commit();

            return $out;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------ inbound

    /**
     * POST v1/inbound/engage/payout-batches. Idempotent on batch_ref: the same
     * payload again → the existing batch (duplicate: true); a different payload
     * under a known batch_ref → 409.
     *
     * @param array<string,mixed> $body
     * @return array{batch_id:int, status:string, duplicate:bool}
     */
    public function receiveBatch(array $body): array
    {
        $batch = InboundBatchValidator::validate($body);
        $vault = $this->vault();
        $sha   = hash('sha256', json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $this->tx(function () use ($batch, $vault, $sha): array {
            $this->pdo->query('SELECT pg_advisory_xact_lock(' . self::TDS_LOCK . ')');

            $existing = $this->pdo->prepare('SELECT id, payload_sha256 FROM payout_batches WHERE batch_ref = ?');
            $existing->execute([$batch['batch_ref']]);
            if ($row = $existing->fetch()) {
                if (!hash_equals((string) $row['payload_sha256'], $sha)) {
                    throw ApiException::conflict('batch_ref ' . $batch['batch_ref'] . ' was already received with different content.');
                }

                return ['batch_id' => (int) $row['id'], 'status' => 'received', 'duplicate' => true];
            }

            $settings = $this->settings();
            $fy       = FiscalYear::forDate($batch['period_end']);
            $ins      = $this->pdo->prepare(
                'INSERT INTO payout_batches (batch_ref, period_start, period_end, currency, fy, payload_sha256)
                 VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            );
            $ins->execute([$batch['batch_ref'], $batch['period_start'], $batch['period_end'], 'INR', $fy, $sha]);
            $batchId = (int) $ins->fetch()['id'];

            $gross = 0;
            $held  = 0;
            foreach ($batch['items'] as $item) {
                $payee   = $this->upsertPayee($item, $vault);
                $prior   = $this->priorTds($payee['pan_hash'], $fy, null);
                $tax     = TaxCalculator::compute($item['gross'], $item['payee']['gstin'] !== null, $settings, $prior['taxable'], $prior['tds']);
                $status  = 'draft';
                $reason  = null;
                if ($settings['minimum_payout'] > 0 && $tax['net'] < $settings['minimum_payout']) {
                    $status = 'on_hold';
                    $reason = sprintf('Net ₹%s is below the Finance minimum payout of ₹%s.', Money::format($tax['net']), Money::format($settings['minimum_payout']));
                    $held++;
                }
                $stmt = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO payout_items (batch_id, item_ref, payee_id, seller_code, legal_name, entity_type, pan_enc, pan_hash,
                        pan_last4, gstin_enc, gstin_masked, bank_holder_name, bank_account_enc, bank_account_last4, bank_ifsc, bank_changed, entry_ids,
                        gross_amount, taxable_amount, gst_mode, gst_rate, gst_amount, tds_rate, tds_amount, tds_note,
                        adjustment_total, net_amount, status, hold_reason)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
                    RETURNING id
                SQL);
                $stmt->execute([
                    $batchId, $item['item_ref'], $payee['id'], $item['seller_code'], $item['payee']['legal_name'],
                    $item['payee']['entity_type'], $vault->encrypt($item['payee']['pan']), $payee['pan_hash'],
                    DataVault::last4($item['payee']['pan']), self::encGstin($vault, $item['payee']['gstin']), DataVault::maskGstin($item['payee']['gstin']),
                    $item['payee']['bank']['account_holder'],
                    $vault->encrypt($item['payee']['bank']['account_number']), DataVault::last4($item['payee']['bank']['account_number']),
                    $item['payee']['bank']['ifsc'], $payee['bank_changed'] ? 'true' : 'false',
                    json_encode($item['entries'], JSON_THROW_ON_ERROR),
                    Money::format($item['gross']), Money::format($tax['taxable']), $tax['gst_mode'], Money::formatRate($tax['gst_rate_bp']),
                    Money::format($tax['gst']), Money::formatRate($tax['tds_rate_bp']), Money::format($tax['tds']), $tax['tds_note'],
                    Money::format($tax['net']), $status, $reason,
                ]);
                $itemId = (int) $stmt->fetch()['id'];
                $this->pdo->prepare(
                    'INSERT INTO tds_ledger (item_id, pan_hash, fy, taxable_amount, tds_rate, tds_amount) VALUES (?, ?, ?, ?, ?, ?)',
                )->execute([$itemId, $payee['pan_hash'], $fy, Money::format($tax['taxable']), Money::formatRate($tax['tds_rate_bp']), Money::format($tax['tds'])]);
                $gross += $item['gross'];
            }

            $this->audit->record(self::SYSTEM, 'batch.received', 'payout_batch', $batchId, null, [
                'batch_ref'    => $batch['batch_ref'],
                'period_start' => $batch['period_start'],
                'period_end'   => $batch['period_end'],
                'items'        => count($batch['items']),
                'gross_amount' => Money::format($gross),
                'held_below_minimum' => $held,
                'status'       => 'draft',
            ]);

            return ['batch_id' => $batchId, 'status' => 'received', 'duplicate' => false];
        });
    }

    /**
     * @param array<string,mixed> $item normalised inbound item
     * @return array{id:int, pan_hash:string, bank_changed:bool}
     */
    private function upsertPayee(array $item, DataVault $vault): array
    {
        $p           = $item['payee'];
        $panHash     = $vault->blindIndex($p['pan']);
        $accountHash = $vault->blindIndex($p['bank']['account_number'] . '|' . $p['bank']['ifsc']);
        $find        = $this->pdo->prepare('SELECT * FROM payees WHERE seller_code = ? FOR UPDATE');
        $find->execute([$item['seller_code']]);
        $row = $find->fetch();

        $after = [
            'legal_name'   => $p['legal_name'],
            'entity_type'  => $p['entity_type'],
            'pan'          => DataVault::mask(DataVault::last4($p['pan'])),
            'gstin'        => DataVault::maskGstin($p['gstin']),
            'bank_holder'  => $p['bank']['account_holder'],
            'bank_account' => DataVault::mask(DataVault::last4($p['bank']['account_number'])),
            'bank_ifsc'    => $p['bank']['ifsc'],
        ];

        if (!$row) {
            $ins = $this->pdo->prepare(<<<'SQL'
                INSERT INTO payees (seller_code, legal_name, entity_type, pan_enc, pan_last4, pan_hash, gstin_enc, gstin_masked,
                                    bank_holder_name, bank_account_enc, bank_account_last4, bank_account_hash, bank_ifsc)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
            SQL);
            $ins->execute([
                $item['seller_code'], $p['legal_name'], $p['entity_type'], $vault->encrypt($p['pan']), DataVault::last4($p['pan']),
                $panHash, self::encGstin($vault, $p['gstin']), DataVault::maskGstin($p['gstin']), $p['bank']['account_holder'],
                $vault->encrypt($p['bank']['account_number']),
                DataVault::last4($p['bank']['account_number']), $accountHash, $p['bank']['ifsc'],
            ]);
            $id = (int) $ins->fetch()['id'];
            $this->audit->record(self::SYSTEM, 'payee.created', 'payee', $id, null, ['seller_code' => $item['seller_code']] + $after);

            return ['id' => $id, 'pan_hash' => $panHash, 'bank_changed' => false];
        }

        $before = [
            'legal_name'   => $row['legal_name'],
            'entity_type'  => $row['entity_type'],
            'pan'          => DataVault::mask((string) $row['pan_last4']),
            'gstin'        => $row['gstin_masked'],
            'bank_holder'  => $row['bank_holder_name'],
            'bank_account' => DataVault::mask((string) $row['bank_account_last4']),
            'bank_ifsc'    => $row['bank_ifsc'],
        ];
        $bankChanged = !hash_equals((string) $row['bank_account_hash'], $accountHash);
        $panChanged  = !hash_equals((string) $row['pan_hash'], $panHash);
        if ($before !== $after || $bankChanged || $panChanged) {
            $this->pdo->prepare(<<<'SQL'
                UPDATE payees SET legal_name = ?, entity_type = ?, pan_enc = ?, pan_last4 = ?, pan_hash = ?, gstin_enc = ?, gstin_masked = ?,
                       bank_holder_name = ?, bank_account_enc = ?, bank_account_last4 = ?, bank_account_hash = ?, bank_ifsc = ?,
                       updated_at = now()
                 WHERE id = ?
            SQL)->execute([
                $p['legal_name'], $p['entity_type'], $vault->encrypt($p['pan']), DataVault::last4($p['pan']), $panHash,
                self::encGstin($vault, $p['gstin']), DataVault::maskGstin($p['gstin']),
                $p['bank']['account_holder'], $vault->encrypt($p['bank']['account_number']), DataVault::last4($p['bank']['account_number']),
                $accountHash, $p['bank']['ifsc'], $row['id'],
            ]);
            $this->audit->record(self::SYSTEM, 'payee.updated', 'payee', (int) $row['id'], $before + ['bank_changed' => false], $after + ['bank_changed' => $bankChanged]);
        }

        return ['id' => (int) $row['id'], 'pan_hash' => $panHash, 'bank_changed' => $bankChanged];
    }

    private static function encGstin(DataVault $vault, ?string $gstin): ?string
    {
        return $gstin === null ? null : $vault->encrypt($gstin);
    }

    /**
     * FY cumulative taxable value and TDS for a PAN (non-void ledger rows),
     * optionally only rows before a given ledger id (recalculation).
     *
     * @return array{taxable:int, tds:int}
     */
    private function priorTds(string $panHash, string $fy, ?int $beforeLedgerId): array
    {
        $sql    = "SELECT coalesce(sum(taxable_amount), 0)::text AS taxable, coalesce(sum(tds_amount), 0)::text AS tds
                     FROM tds_ledger WHERE pan_hash = ? AND fy = ? AND status <> 'void'";
        $params = [$panHash, $fy];
        if ($beforeLedgerId !== null) {
            $sql     .= ' AND id < ?';
            $params[] = $beforeLedgerId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return ['taxable' => Money::parse((string) $row['taxable']) ?? 0, 'tds' => Money::parse((string) $row['tds']) ?? 0];
    }

    // ------------------------------------------------------------------ loading

    /** @return array<string,mixed> */
    private function lockBatch(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payout_batches WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Payout batch not found.');
        }

        return $row;
    }

    /** @return array{0:array<string,mixed>, 1:array<string,mixed>} [item, batch], both locked */
    private function lockItem(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT batch_id FROM payout_items WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Payout item not found.');
        }
        $batch = $this->lockBatch((int) $row['batch_id']);
        $stmt  = $this->pdo->prepare('SELECT i.*, b.batch_ref FROM payout_items i JOIN payout_batches b ON b.id = i.batch_id WHERE i.id = ? FOR UPDATE OF i');
        $stmt->execute([$id]);

        return [$stmt->fetch(), $batch];
    }

    /** @return list<array<string,mixed>> */
    private function batchItems(int $batchId, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, b.batch_ref FROM payout_items i JOIN payout_batches b ON b.id = i.batch_id
              WHERE i.batch_id = ? ORDER BY i.id' . ($lock ? ' FOR UPDATE OF i' : ''),
        );
        $stmt->execute([$batchId]);

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $actor */
    private function touchBatch(int $batchId, array $actor, bool $edited = true): void
    {
        $sql = $edited
            ? 'UPDATE payout_batches SET version = version + 1, last_edited_by = ?, last_edited_at = now(), updated_at = now() WHERE id = ?'
            : 'UPDATE payout_batches SET version = version + 1, updated_at = now() WHERE id = ?';
        $this->pdo->prepare($sql)->execute($edited ? [$actor['actor'], $batchId] : [$batchId]);
    }

    private static function requireReason(mixed $reason, string $what = 'A reason'): string
    {
        $reason = is_string($reason) ? trim($reason) : '';
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ApiException::validation("{$what} is required (at most 500 characters).", ['reason' => 'Required.']);
        }

        return $reason;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> masked snapshot for audit */
    private static function itemSnapshot(array $item): array
    {
        return [
            'item_ref'         => $item['item_ref'],
            'status'           => $item['status'],
            'gross_amount'     => $item['gross_amount'],
            'gst_amount'       => $item['gst_amount'],
            'tds_amount'       => $item['tds_amount'],
            'adjustment_total' => $item['adjustment_total'],
            'net_amount'       => $item['net_amount'],
            'hold_reason'      => $item['hold_reason'],
            'failure_reason'   => $item['failure_reason'],
            'utr'              => $item['utr'],
            'paid_at'          => $item['paid_at'],
        ];
    }

    private function reloadItem(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT i.*, b.batch_ref FROM payout_items i JOIN payout_batches b ON b.id = i.batch_id WHERE i.id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }

    // ------------------------------------------------------------------ review (edit gate)

    /** @param array<string,mixed> $actor */
    public function submitForReview(int $batchId, array $actor): void
    {
        $this->tx(function () use ($batchId, $actor): void {
            $b = $this->lockBatch($batchId);
            PayoutStateMachine::assertBatch((string) $b['status'], 'in_review');
            $this->pdo->prepare(
                "UPDATE payout_batches SET status = 'in_review', prepared_by = ?, prepared_at = now(), version = version + 1, updated_at = now() WHERE id = ?",
            )->execute([$actor['actor'], $batchId]);
            $this->audit->record($actor, 'batch.submitted', 'payout_batch', $batchId, ['status' => $b['status']], ['status' => 'in_review', 'prepared_by' => $actor['actor']]);
        });
    }

    /** @param array<string,mixed> $actor */
    public function returnToDraft(int $batchId, mixed $reason, array $actor): void
    {
        $reason = self::requireReason($reason);
        $this->tx(function () use ($batchId, $reason, $actor): void {
            $b = $this->lockBatch($batchId);
            PayoutStateMachine::assertBatch((string) $b['status'], 'draft');
            $this->pdo->prepare("UPDATE payout_batches SET status = 'draft', version = version + 1, updated_at = now() WHERE id = ?")->execute([$batchId]);
            $this->audit->record($actor, 'batch.returned', 'payout_batch', $batchId, ['status' => $b['status']], ['status' => 'draft', 'reason' => $reason]);
        });
    }

    /** @param array<string,mixed> $actor */
    public function hold(int $itemId, mixed $reason, array $actor): array
    {
        $reason = self::requireReason($reason, 'A hold reason');

        return $this->tx(function () use ($itemId, $reason, $actor): array {
            [$item, $batch] = $this->lockItem($itemId);
            $this->assertEditable($batch);
            PayoutStateMachine::assertItem((string) $item['status'], 'on_hold');
            $this->pdo->prepare("UPDATE payout_items SET status = 'on_hold', hold_reason = ?, updated_at = now() WHERE id = ?")->execute([$reason, $itemId]);
            $this->touchBatch((int) $batch['id'], $actor);
            $after = $this->reloadItem($itemId);
            $this->audit->record($actor, 'item.held', 'payout_item', $itemId, self::itemSnapshot($item), self::itemSnapshot($after) + ['reason' => $reason]);

            return $after;
        });
    }

    /** @param array<string,mixed> $actor */
    public function unhold(int $itemId, mixed $reason, array $actor): array
    {
        $reason = self::requireReason($reason, 'A reason for releasing the hold');

        return $this->tx(function () use ($itemId, $reason, $actor): array {
            [$item, $batch] = $this->lockItem($itemId);
            $this->assertEditable($batch);
            if ($item['status'] !== 'on_hold') {
                throw ApiException::conflict('Only an item on hold can be released.');
            }
            $this->pdo->prepare("UPDATE payout_items SET status = 'draft', hold_reason = NULL, updated_at = now() WHERE id = ?")->execute([$itemId]);
            $this->touchBatch((int) $batch['id'], $actor);
            $after = $this->reloadItem($itemId);
            $this->audit->record($actor, 'item.unheld', 'payout_item', $itemId, self::itemSnapshot($item), self::itemSnapshot($after) + ['reason' => $reason]);

            return $after;
        });
    }

    /**
     * Signed correction to the amount payable (e.g. "-250.00" recovering an
     * earlier overpayment). It changes net only — TDS and GST stay on the
     * commission Engage sent.
     *
     * @param array<string,mixed> $actor
     */
    public function adjust(int $itemId, mixed $amount, mixed $reason, array $actor): array
    {
        $paise  = Money::parse($amount, true);
        $reason = self::requireReason($reason, 'An adjustment reason');
        if ($paise === null || $paise === 0) {
            throw ApiException::validation('amount must be a non-zero amount such as "-250.00" or "100".', ['amount' => 'Invalid.']);
        }

        return $this->tx(function () use ($itemId, $paise, $reason, $actor): array {
            [$item, $batch] = $this->lockItem($itemId);
            $this->assertEditable($batch);
            if (!in_array($item['status'], ['draft', 'on_hold'], true)) {
                throw ApiException::conflict('Only a draft or held item can be adjusted.');
            }
            $newTotal = (Money::parse((string) $item['adjustment_total'], true) ?? 0) + $paise;
            $net      = (Money::parse((string) $item['taxable_amount']) ?? 0) + (Money::parse((string) $item['gst_amount']) ?? 0)
                      - (Money::parse((string) $item['tds_amount']) ?? 0) + $newTotal;
            if ($net < 0) {
                throw ApiException::validation('This adjustment would make the net payable negative (₹' . Money::format($net) . ').');
            }
            $this->pdo->prepare('INSERT INTO payout_adjustments (item_id, amount, reason, created_by) VALUES (?, ?, ?, ?)')
                ->execute([$itemId, Money::format($paise), $reason, $actor['actor']]);
            $this->pdo->prepare('UPDATE payout_items SET adjustment_total = ?, net_amount = ?, updated_at = now() WHERE id = ?')
                ->execute([Money::format($newTotal), Money::format($net), $itemId]);
            $this->touchBatch((int) $batch['id'], $actor);
            $after = $this->reloadItem($itemId);
            $this->audit->record($actor, 'item.adjusted', 'payout_item', $itemId, self::itemSnapshot($item), self::itemSnapshot($after) + [
                'adjustment' => Money::format($paise), 'reason' => $reason,
            ]);

            return $after;
        });
    }

    /**
     * Re-run GST/TDS for every item of a draft / in-review batch with the
     * current settings and ledger (e.g. after a settings change, or after an
     * earlier payout for the same PAN was cancelled).
     *
     * @param array<string,mixed> $actor
     * @return int items whose figures changed
     */
    public function recalculate(int $batchId, array $actor): int
    {
        return $this->tx(function () use ($batchId, $actor): int {
            $this->pdo->query('SELECT pg_advisory_xact_lock(' . self::TDS_LOCK . ')');
            $batch = $this->lockBatch($batchId);
            $this->assertEditable($batch);
            $settings = $this->settings();
            $changed  = 0;
            $stmt     = $this->pdo->prepare(
                "SELECT i.*, b.batch_ref, l.id AS ledger_id FROM payout_items i JOIN payout_batches b ON b.id = i.batch_id
                   JOIN tds_ledger l ON l.item_id = i.id WHERE i.batch_id = ? AND i.status IN ('draft','on_hold') ORDER BY l.id FOR UPDATE OF i, l",
            );
            $stmt->execute([$batchId]);
            foreach ($stmt->fetchAll() as $item) {
                $prior = $this->priorTds((string) $item['pan_hash'], (string) $batch['fy'], (int) $item['ledger_id']);
                $adj   = Money::parse((string) $item['adjustment_total'], true) ?? 0;
                $tax   = TaxCalculator::compute(Money::parse((string) $item['gross_amount']) ?? 0, $item['gstin_masked'] !== null, $settings, $prior['taxable'], $prior['tds'], $adj);
                if ($tax['net'] < 0) {
                    throw ApiException::validation("Item {$item['item_ref']}: the net payable would be negative after recalculation; remove its adjustment first.");
                }
                $new = [
                    'taxable_amount' => Money::format($tax['taxable']),
                    'gst_mode'       => $tax['gst_mode'],
                    'gst_rate'       => Money::formatRate($tax['gst_rate_bp']),
                    'gst_amount'     => Money::format($tax['gst']),
                    'tds_rate'       => Money::formatRate($tax['tds_rate_bp']),
                    'tds_amount'     => Money::format($tax['tds']),
                    'tds_note'       => $tax['tds_note'],
                    'net_amount'     => Money::format($tax['net']),
                ];
                $old = array_intersect_key($item, $new);
                if ($old == $new) {
                    continue;
                }
                $this->pdo->prepare(
                    'UPDATE payout_items SET taxable_amount = ?, gst_mode = ?, gst_rate = ?, gst_amount = ?, tds_rate = ?, tds_amount = ?,
                            tds_note = ?, net_amount = ?, updated_at = now() WHERE id = ?',
                )->execute([...array_values($new), $item['id']]);
                $this->pdo->prepare('UPDATE tds_ledger SET taxable_amount = ?, tds_rate = ?, tds_amount = ?, updated_at = now() WHERE id = ?')
                    ->execute([$new['taxable_amount'], $new['tds_rate'], $new['tds_amount'], $item['ledger_id']]);
                $this->audit->record($actor, 'item.recalculated', 'payout_item', (int) $item['id'], $old, $new);
                $changed++;
            }
            $this->touchBatch($batchId, $actor);
            $this->audit->record($actor, 'batch.recalculated', 'payout_batch', $batchId, null, ['items_changed' => $changed]);

            return $changed;
        });
    }

    /** @param array<string,mixed> $batch */
    private function assertEditable(array $batch): void
    {
        if (!in_array($batch['status'], PayoutStateMachine::EDITABLE, true)) {
            throw ApiException::conflict("The batch is {$batch['status']}; items can only be changed while it is a draft or in review.");
        }
    }

    // ------------------------------------------------------------------ approval (approve gate)

    /**
     * in_review → approved. Draft items become approved; held items stay on hold
     * (excluded from the bank file). Four-eyes: the approver must differ from the
     * person who submitted the batch and from whoever last edited it (setting
     * four_eyes_enabled). $version must match what the approver reviewed.
     *
     * @param array<string,mixed> $actor
     */
    public function approve(int $batchId, mixed $version, array $actor): array
    {
        return $this->tx(function () use ($batchId, $version, $actor): array {
            $batch = $this->lockBatch($batchId);
            PayoutStateMachine::assertBatch((string) $batch['status'], 'approved');
            if (!is_numeric($version) || (int) $version !== (int) $batch['version']) {
                throw ApiException::conflict('The batch changed since you loaded it. Reload and review it again before approving.');
            }
            $settings = $this->settings();
            if ($settings['four_eyes_enabled']) {
                $violation = PayoutStateMachine::fourEyesViolation($actor['actor'], $batch['prepared_by'], $batch['last_edited_by']);
                if ($violation !== null) {
                    throw ApiException::forbidden($violation);
                }
            }
            $items    = $this->batchItems($batchId, true);
            $approve  = array_values(array_filter($items, static fn (array $i) => $i['status'] === 'draft'));
            $held     = array_values(array_filter($items, static fn (array $i) => $i['status'] === 'on_hold'));
            if ($approve === []) {
                throw ApiException::validation('Nothing to approve: every item is on hold.');
            }
            $zero = array_values(array_filter($approve, static fn (array $i) => (Money::parse((string) $i['net_amount']) ?? 0) <= 0));
            if ($zero !== []) {
                throw ApiException::validation('Items with a zero net payable cannot be paid; put them on hold first.', [
                    'items' => array_map(static fn (array $i) => $i['item_ref'], $zero),
                ]);
            }

            $this->pdo->prepare("UPDATE payout_items SET status = 'approved', updated_at = now() WHERE batch_id = ? AND status = 'draft'")->execute([$batchId]);
            $this->pdo->prepare(
                "UPDATE payout_batches SET status = 'approved', approved_by = ?, approved_at = now(), version = version + 1, updated_at = now() WHERE id = ?",
            )->execute([$actor['actor'], $batchId]);

            $net = 0;
            foreach ($approve as $i) {
                $net += Money::parse((string) $i['net_amount']) ?? 0;
                $this->outbox->enqueueItemStatus($i, 'approved');
            }
            foreach ($held as $i) {
                $this->outbox->enqueueItemStatus($i, 'on_hold', (string) $i['hold_reason']);
            }
            $this->audit->record($actor, 'batch.approved', 'payout_batch', $batchId, ['status' => $batch['status'], 'version' => (int) $batch['version']], [
                'status'         => 'approved',
                'approved_items' => count($approve),
                'held_items'     => count($held),
                'net_amount'     => Money::format($net),
                'four_eyes'      => $settings['four_eyes_enabled'],
            ]);

            return ['approved_items' => count($approve), 'held_items' => count($held), 'net_amount' => Money::format($net)];
        });
    }

    /**
     * Cancel a batch before its bank file exists. Every unpaid item is reported
     * to Engage as failed (so its commission entries return to approved and the
     * next weekly run picks them up) and its TDS ledger row is voided.
     *
     * @param array<string,mixed> $actor
     */
    public function cancel(int $batchId, mixed $reason, array $actor): void
    {
        $reason = self::requireReason($reason, 'A cancellation reason');
        $this->tx(function () use ($batchId, $reason, $actor): void {
            $batch = $this->lockBatch($batchId);
            PayoutStateMachine::assertBatch((string) $batch['status'], 'cancelled');
            $why = 'Batch cancelled in Finance: ' . $reason;
            foreach ($this->batchItems($batchId, true) as $i) {
                $this->pdo->prepare("UPDATE payout_items SET status = 'failed', failure_reason = ?, updated_at = now() WHERE id = ?")->execute([$why, $i['id']]);
                $this->pdo->prepare("UPDATE tds_ledger SET status = 'void', updated_at = now() WHERE item_id = ?")->execute([$i['id']]);
                $this->outbox->enqueueItemStatus($i, 'failed', $why);
            }
            $this->pdo->prepare(
                "UPDATE payout_batches SET status = 'cancelled', cancelled_by = ?, cancelled_at = now(), cancel_reason = ?, version = version + 1, updated_at = now() WHERE id = ?",
            )->execute([$actor['actor'], $reason, $batchId]);
            $this->audit->record($actor, 'batch.cancelled', 'payout_batch', $batchId, ['status' => $batch['status']], ['status' => 'cancelled', 'reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------ bank file (approve gate)

    /**
     * Produce the bank bulk-upload file for the approved items. Regenerating
     * (before any payment result is recorded) needs a reason and supersedes the
     * previous file — upload only the latest one.
     *
     * @param array<string,mixed> $input {format?, value_date?, reason?}
     * @param array<string,mixed> $actor
     * @return array<string,mixed> file metadata
     */
    public function generateBankFile(int $batchId, array $input, array $actor): array
    {
        $vault = $this->vault();

        return $this->tx(function () use ($batchId, $input, $actor, $vault): array {
            $batch    = $this->lockBatch($batchId);
            if (!in_array($batch['status'], ['approved', 'file_generated'], true)) {
                throw ApiException::conflict("A bank file can only be generated for an approved batch (this one is {$batch['status']}).");
            }
            $settings = $this->settings();
            $format   = is_string($input['format'] ?? null) && $input['format'] !== '' ? $input['format'] : $settings['bank_file_format'];
            if (!isset(BankFileFormatter::FORMATS[$format])) {
                throw ApiException::validation('Unknown bank file format.', ['format' => 'One of: ' . implode(', ', array_keys(BankFileFormatter::FORMATS))]);
            }
            $valueDate = $input['value_date'] ?? FiscalYear::today();
            if (!FiscalYear::isDate($valueDate) || $valueDate < FiscalYear::today()) {
                throw ApiException::validation('value_date must be today or later (YYYY-MM-DD).', ['value_date' => 'Invalid.']);
            }
            if ($settings['debit_account_number'] === '') {
                throw ApiException::validation('Set the debit account number in Settings before generating a bank file.');
            }

            $items = $this->batchItems($batchId, true);
            $regenerate = $batch['status'] === 'file_generated';
            if ($regenerate) {
                $settled = array_filter($items, static fn (array $i) => in_array($i['status'], ['paid', 'failed', 'reversed'], true) && !str_starts_with((string) $i['failure_reason'], 'Released'));
                if ($settled !== []) {
                    throw ApiException::conflict('Payment results are already recorded for this batch; the bank file can no longer be regenerated.');
                }
                $reason = self::requireReason($input['reason'] ?? null, 'A reason for regenerating the bank file');
            }

            $payable = array_values(array_filter($items, static fn (array $i) => $i['status'] === 'approved'));
            if ($payable === []) {
                throw ApiException::validation('No approved items to pay.');
            }
            $rows = array_map(static fn (array $i) => [
                'item_ref' => (string) $i['item_ref'],
                'holder'   => (string) $i['bank_holder_name'],
                'account'  => $vault->decrypt((string) $i['bank_account_enc']),
                'ifsc'     => (string) $i['bank_ifsc'],
                'net'      => Money::parse((string) $i['net_amount']) ?? 0,
            ], $payable);
            $file = BankFileFormatter::build($rows, [
                'format'           => $format,
                'payment_mode'     => $settings['payment_mode'],
                'debit_account'    => $settings['debit_account_number'],
                'value_date'       => $valueDate,
                'narration_prefix' => $settings['narration_prefix'],
                'batch_ref'        => (string) $batch['batch_ref'],
            ]);

            if ($regenerate) {
                $this->pdo->prepare("UPDATE bank_files SET superseded = true WHERE batch_id = ? AND kind = 'payment' AND NOT superseded")->execute([$batchId]);
            }
            $ins = $this->pdo->prepare(
                "INSERT INTO bank_files (batch_id, kind, format, filename, content_enc, sha256, item_count, total_amount, note, created_by)
                 VALUES (?, 'payment', ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id, created_at",
            );
            $ins->execute([$batchId, $format, $file['filename'], $vault->encrypt($file['content']), $file['sha256'], $file['item_count'],
                Money::format($file['total']), $regenerate ? 'Regenerated: ' . $reason : null, $actor['actor']]);
            $row = $ins->fetch();
            if (!$regenerate) {
                PayoutStateMachine::assertBatch((string) $batch['status'], 'file_generated');
                $this->pdo->prepare("UPDATE payout_batches SET status = 'file_generated', version = version + 1, updated_at = now() WHERE id = ?")->execute([$batchId]);
            }
            $meta = [
                'id'             => (int) $row['id'],
                'format'         => $format,
                'format_label'   => BankFileFormatter::FORMATS[$format]['label'],
                'confirm_format' => BankFileFormatter::FORMATS[$format]['confirm_format'],
                'filename'       => $file['filename'],
                'sha256'         => $file['sha256'],
                'item_count'     => $file['item_count'],
                'total_amount'   => Money::format($file['total']),
                'value_date'     => $valueDate,
                'created_at'     => $row['created_at'],
            ];
            $this->audit->record($actor, $regenerate ? 'bank_file.regenerated' : 'bank_file.generated', 'payout_batch', $batchId,
                ['status' => $batch['status']], $meta + ($regenerate ? ['reason' => $reason] : []));

            return $meta;
        });
    }

    /**
     * @param array<string,mixed> $actor
     * @return array{filename:string, content:string, sha256:string}
     */
    public function downloadBankFile(int $batchId, int $fileId, array $actor): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bank_files WHERE id = ? AND batch_id = ?');
        $stmt->execute([$fileId, $batchId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Bank file not found.');
        }
        $content = $this->vault()->decrypt((string) $row['content_enc']);
        if (!hash_equals((string) $row['sha256'], hash('sha256', $content))) {
            throw new \RuntimeException('Bank file checksum mismatch.');
        }
        $this->audit->record($actor, 'bank_file.downloaded', 'payout_batch', $batchId, null, [
            'file_id' => $fileId, 'filename' => $row['filename'], 'sha256' => $row['sha256'], 'kind' => $row['kind'],
        ]);

        return ['filename' => (string) $row['filename'], 'content' => $content, 'sha256' => (string) $row['sha256']];
    }

    // ------------------------------------------------------------------ payment results (approve gate)

    /**
     * approved → paid. Requires a UTR (6–35 letters/digits, unique) — there is
     * no other way to mark an item paid.
     *
     * @param array<string,mixed> $actor
     */
    public function recordPayment(int $itemId, mixed $utr, mixed $paidAt, array $actor): array
    {
        $utr  = self::normaliseUtr($utr);
        $when = self::paidAt($paidAt);

        return $this->tx(function () use ($itemId, $utr, $when, $actor): array {
            [$item, $batch] = $this->lockItem($itemId);

            return $this->applyPaid($item, $batch, $utr, $when, $actor);
        });
    }

    /** @param array<string,mixed> $actor */
    public function failItem(int $itemId, mixed $reason, array $actor): array
    {
        $reason = self::requireReason($reason, 'A failure reason');

        return $this->tx(function () use ($itemId, $reason, $actor): array {
            [$item, $batch] = $this->lockItem($itemId);

            return $this->applyFailed($item, $batch, $reason, $actor);
        });
    }

    /** @param array<string,mixed> $actor */
    public function reverseItem(int $itemId, mixed $reason, array $actor): array
    {
        $reason = self::requireReason($reason, 'A reversal reason');

        return $this->tx(function () use ($itemId, $reason, $actor): array {
            [$item, $batch] = $this->lockItem($itemId);
            PayoutStateMachine::assertItem((string) $item['status'], 'reversed');
            $this->pdo->prepare("UPDATE payout_items SET status = 'reversed', failure_reason = ?, updated_at = now() WHERE id = ?")->execute([$reason, $itemId]);
            $this->pdo->prepare("UPDATE tds_ledger SET status = 'void', updated_at = now() WHERE item_id = ?")->execute([$itemId]);
            $after = $this->reloadItem($itemId);
            $this->outbox->enqueueItemStatus($after, 'reversed', $reason);
            $this->settleBatch($batch);
            $this->audit->record($actor, 'item.reversed', 'payout_item', $itemId, self::itemSnapshot($item), self::itemSnapshot($after));

            return $after;
        });
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $batch
     * @param array<string,mixed> $actor
     */
    private function applyPaid(array $item, array $batch, string $utr, string $when, array $actor): array
    {
        if (!in_array($batch['status'], PayoutStateMachine::PAYABLE, true)) {
            throw ApiException::conflict('Payments can only be recorded after the bank file is generated.');
        }
        PayoutStateMachine::assertItem((string) $item['status'], 'paid');
        $dup = $this->pdo->prepare('SELECT item_ref FROM payout_items WHERE upper(utr) = ? AND id <> ?');
        $dup->execute([$utr, $item['id']]);
        if ($other = $dup->fetch()) {
            throw ApiException::conflict("UTR {$utr} is already recorded for item {$other['item_ref']}.");
        }
        $this->pdo->prepare(
            "UPDATE payout_items SET status = 'paid', utr = ?, paid_at = ?, payment_recorded_by = ?, updated_at = now() WHERE id = ?",
        )->execute([$utr, $when, $actor['actor'], $item['id']]);
        $this->pdo->prepare("UPDATE tds_ledger SET status = 'deducted', deducted_on = (?::timestamptz)::date, updated_at = now() WHERE item_id = ?")
            ->execute([$when, $item['id']]);
        $after = $this->reloadItem((int) $item['id']);
        $this->outbox->enqueueItemStatus($after, 'paid');
        $this->settleBatch($batch);
        $this->audit->record($actor, 'item.paid', 'payout_item', (int) $item['id'], self::itemSnapshot($item), self::itemSnapshot($after));

        return $after;
    }

    /**
     * approved → failed (bank rejected it), or — once the batch is approved —
     * on_hold → failed ("released back to Engage", so the commission returns to
     * the next weekly run).
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $batch
     * @param array<string,mixed> $actor
     */
    private function applyFailed(array $item, array $batch, string $reason, array $actor): array
    {
        if ($item['status'] === 'on_hold') {
            if (in_array($batch['status'], PayoutStateMachine::EDITABLE, true)) {
                throw ApiException::conflict('While the batch is under review, release the hold or leave it; an on-hold item is released back to Engage after approval.');
            }
            $reason = 'Released back to Engage: ' . $reason;
        } elseif (!in_array($batch['status'], PayoutStateMachine::PAYABLE, true)) {
            throw ApiException::conflict('Payment failures can only be recorded after the bank file is generated.');
        }
        PayoutStateMachine::assertItem((string) $item['status'], 'failed');
        $this->pdo->prepare("UPDATE payout_items SET status = 'failed', failure_reason = ?, updated_at = now() WHERE id = ?")->execute([$reason, $item['id']]);
        $this->pdo->prepare("UPDATE tds_ledger SET status = 'void', updated_at = now() WHERE item_id = ?")->execute([$item['id']]);
        $after = $this->reloadItem((int) $item['id']);
        $this->outbox->enqueueItemStatus($after, 'failed', $reason);
        if (in_array($batch['status'], PayoutStateMachine::PAYABLE, true)) {
            $this->settleBatch($batch);
        }
        $this->audit->record($actor, 'item.failed', 'payout_item', (int) $item['id'], self::itemSnapshot($item), self::itemSnapshot($after));

        return $after;
    }

    /** @param array<string,mixed> $batch */
    private function settleBatch(array $batch): void
    {
        $stmt = $this->pdo->prepare('SELECT status FROM payout_items WHERE batch_id = ?');
        $stmt->execute([$batch['id']]);
        $next = PayoutStateMachine::settledStatus(array_map(static fn (array $r) => (string) $r['status'], $stmt->fetchAll()));
        $cur  = $this->pdo->prepare('SELECT status FROM payout_batches WHERE id = ?');
        $cur->execute([$batch['id']]);
        $now = (string) $cur->fetch()['status'];
        if ($next !== $now) {
            PayoutStateMachine::assertBatch($now, $next);
            $this->pdo->prepare('UPDATE payout_batches SET status = ?, version = version + 1, updated_at = now() WHERE id = ?')->execute([$next, $batch['id']]);
        } else {
            $this->pdo->prepare('UPDATE payout_batches SET version = version + 1, updated_at = now() WHERE id = ?')->execute([$batch['id']]);
        }
    }

    /**
     * Apply the bank's response file. With dry_run the plan is returned and
     * nothing changes. Lines are matched to items by item_ref (the "Customer
     * Reference" Finance put in the bank file).
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function importBankResponse(int $batchId, mixed $csv, bool $dryRun, array $actor): array
    {
        if (!is_string($csv) || trim($csv) === '') {
            throw ApiException::validation('csv is required (the bank response file contents).');
        }
        if (strlen($csv) > 2_000_000) {
            throw ApiException::validation('The file is larger than 2 MB.');
        }
        try {
            $parsed = BankResponseParser::parse($csv);
        } catch (\InvalidArgumentException $e) {
            throw ApiException::validation($e->getMessage());
        }
        $vault = $dryRun ? null : $this->vault();

        return $this->tx(function () use ($batchId, $parsed, $dryRun, $actor, $csv, $vault): array {
            $batch = $this->lockBatch($batchId);
            if (!in_array($batch['status'], PayoutStateMachine::PAYABLE, true)) {
                throw ApiException::conflict('Payment results can only be recorded after the bank file is generated.');
            }
            $byRef = [];
            foreach ($this->batchItems($batchId, true) as $i) {
                $byRef[strtoupper((string) $i['item_ref'])] = $i;
            }
            $plan = [];
            $counts = ['paid' => 0, 'failed' => 0, 'skipped' => 0];
            $seen = [];
            foreach ($parsed['rows'] as $row) {
                $item = $byRef[strtoupper($row['reference'])] ?? null;
                $line = $row + ['item_id' => $item ? (int) $item['id'] : null, 'action' => 'skip'];
                if ($row['outcome'] === 'skip') {
                    $line['message'] = $row['reason'];
                } elseif ($item === null) {
                    $line['message'] = 'No item with this reference in this batch.';
                } elseif (isset($seen[(int) $item['id']])) {
                    $line['message'] = 'This item appears more than once in the file; only the first line was used.';
                } elseif ($row['outcome'] === 'paid' && $item['status'] === 'paid') {
                    $line['message'] = strtoupper((string) $item['utr']) === $row['utr'] ? 'Already recorded as paid with this UTR.' : 'Already paid with a different UTR (' . $item['utr'] . ') — check with the bank.';
                } elseif ($item['status'] !== 'approved') {
                    $line['message'] = "Item is {$item['status']}; only approved items can take a payment result.";
                } else {
                    $line['action']  = $row['outcome'];
                    $line['message'] = $row['outcome'] === 'paid' ? 'Will be marked paid.' : 'Will be marked failed: ' . $row['reason'];
                }
                if ($item !== null) {
                    $seen[(int) $item['id']] = true;
                }
                $plan[] = $line;
            }

            if (!$dryRun) {
                foreach ($plan as &$line) {
                    if ($line['action'] === 'skip') {
                        $counts['skipped']++;
                        continue;
                    }
                    [$item, $b] = $this->lockItem((int) $line['item_id']);
                    try {
                        $this->pdo->exec('SAVEPOINT line');
                        if ($line['action'] === 'paid') {
                            $this->applyPaid($item, $b, (string) $line['utr'], self::paidAt($line['paid_at']), $actor);
                            $line['message'] = 'Marked paid.';
                        } else {
                            $this->applyFailed($item, $b, (string) $line['reason'], $actor);
                            $line['message'] = 'Marked failed.';
                        }
                        $this->pdo->exec('RELEASE SAVEPOINT line');
                        $counts[$line['action']]++;
                    } catch (ApiException $e) {
                        $this->pdo->exec('ROLLBACK TO SAVEPOINT line');
                        $line['message'] = $e->getMessage();
                        $line['action']  = 'skip';
                        $counts['skipped']++;
                    }
                }
                unset($line);
                $ins = $this->pdo->prepare(
                    "INSERT INTO bank_files (batch_id, kind, format, filename, content_enc, sha256, item_count, total_amount, note, created_by)
                     VALUES (?, 'response', 'bank_response_csv', ?, ?, ?, ?, 0, ?, ?)",
                );
                $ins->execute([$batchId, 'bank-response-' . date('Ymd-His') . '.csv', $vault->encrypt($csv), hash('sha256', $csv), count($parsed['rows']),
                    sprintf('%d paid, %d failed, %d skipped', $counts['paid'], $counts['failed'], $counts['skipped']), $actor['actor']]);
                $this->audit->record($actor, 'bank_response.imported', 'payout_batch', $batchId, null, $counts + ['sha256' => hash('sha256', $csv), 'lines' => count($parsed['rows'])]);
            } else {
                foreach ($plan as $line) {
                    $counts[$line['action'] === 'skip' ? 'skipped' : $line['action']]++;
                }
            }

            return ['dry_run' => $dryRun, 'columns' => $parsed['columns'], 'counts' => $counts, 'lines' => $plan];
        });
    }

    public static function normaliseUtr(mixed $utr): string
    {
        $utr = is_string($utr) ? strtoupper(trim($utr)) : '';
        if ($utr === '') {
            throw ApiException::validation('A UTR is required to mark an item paid.', ['utr' => 'Required.']);
        }
        if (!preg_match(BankResponseParser::UTR_PATTERN, $utr)) {
            throw ApiException::validation('The UTR must be 6–35 letters or digits.', ['utr' => 'Invalid.']);
        }

        return $utr;
    }

    /** Date (YYYY-MM-DD, taken as noon IST) or ISO date-time; default now; not in the future. */
    public static function paidAt(mixed $value): string
    {
        $tz = FiscalYear::tz();
        if ($value === null || $value === '') {
            return (new \DateTimeImmutable('now', $tz))->format(\DateTimeInterface::ATOM);
        }
        if (FiscalYear::isDate($value)) {
            $d = new \DateTimeImmutable($value . ' 12:00:00', $tz);
            $now = new \DateTimeImmutable('now', $tz);
            if ($value === $now->format('Y-m-d') && $d > $now) {
                $d = $now;
            }
        } else {
            try {
                $d = is_string($value) ? new \DateTimeImmutable($value, $tz) : null;
            } catch (\Exception) {
                $d = null;
            }
            if ($d === null || !preg_match('/^\d{4}-\d{2}-\d{2}T/', (string) $value)) {
                throw ApiException::validation('paid_at must be a date (YYYY-MM-DD) or an ISO date-time.', ['paid_at' => 'Invalid.']);
            }
        }
        if ($d > new \DateTimeImmutable('+5 minutes', $tz)) {
            throw ApiException::validation('paid_at cannot be in the future.', ['paid_at' => 'Invalid.']);
        }

        return $d->format(\DateTimeInterface::ATOM);
    }
}
