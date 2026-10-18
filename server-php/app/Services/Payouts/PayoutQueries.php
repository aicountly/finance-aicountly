<?php
declare(strict_types=1);

namespace App\Services\Payouts;

use App\Services\CompanyBankAccountService;
use App\Services\DataVault;
use App\Services\FinanceAccessPolicy;
use App\Services\SettingsService;
use App\Support\ApiException;
use App\Support\FiscalYear;
use App\Support\Money;

/**
 * Read models for the staff UI. Everything here is masked: PAN and bank
 * account numbers appear as XXXXXX + last four. Full values only ever leave
 * through the bank file download and the TDS register export (both audited).
 */
final class PayoutQueries
{
    public function __construct(private \PDO $pdo)
    {
    }

    private const TOTALS = <<<'SQL'
        SELECT batch_id,
               count(*) AS item_count,
               count(*) FILTER (WHERE status = 'on_hold') AS held_count,
               count(*) FILTER (WHERE status = 'paid') AS paid_count,
               count(*) FILTER (WHERE status IN ('failed','reversed')) AS failed_count,
               count(*) FILTER (WHERE bank_changed) AS bank_changed_count,
               sum(gross_amount)::text AS gross_amount,
               sum(gst_amount)::text AS gst_amount,
               sum(tds_amount)::text AS tds_amount,
               sum(adjustment_total)::text AS adjustment_total,
               sum(net_amount)::text AS net_amount,
               coalesce(sum(net_amount) FILTER (WHERE status IN ('draft','approved','paid')), 0)::text AS payable_amount,
               coalesce(sum(net_amount) FILTER (WHERE status = 'paid'), 0)::text AS paid_amount
          FROM payout_items
    SQL;

    /** @return list<array<string,mixed>> */
    public function batches(?string $status): array
    {
        $sql    = 'SELECT b.id, b.batch_ref, b.period_start, b.period_end, b.fy, b.status, b.version, b.received_at, b.prepared_by,
                          b.approved_by, b.approved_at, t.item_count, t.held_count, t.paid_count, t.failed_count, t.bank_changed_count,
                          t.gross_amount, t.gst_amount, t.tds_amount, t.net_amount, t.payable_amount, t.paid_amount
                     FROM payout_batches b LEFT JOIN (' . self::TOTALS . ' GROUP BY batch_id) t ON t.batch_id = b.id';
        $params = [];
        if ($status !== null && $status !== '') {
            if (!in_array($status, PayoutStateMachine::BATCH_STATUSES, true) && $status !== 'open') {
                throw ApiException::validation('Unknown status filter.');
            }
            if ($status === 'open') {
                $sql .= " WHERE b.status NOT IN ('paid','cancelled')";
            } else {
                $sql     .= ' WHERE b.status = ?';
                $params[] = $status;
            }
        }
        $sql .= ' ORDER BY b.period_end DESC, b.id DESC LIMIT 200';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([self::class, 'castBatch'], $stmt->fetchAll());
    }

    /**
     * @param list<string> $roles
     * @return array<string,mixed>
     */
    public function batch(int $id, string $actor, array $roles): array
    {
        $stmt = $this->pdo->prepare('SELECT b.*, t.* FROM payout_batches b LEFT JOIN (' . self::TOTALS . ' WHERE batch_id = ? GROUP BY batch_id) t ON t.batch_id = b.id WHERE b.id = ?');
        $stmt->execute([$id, $id]);
        $batch = $stmt->fetch();
        if (!$batch) {
            throw ApiException::notFound('Payout batch not found.');
        }
        unset($batch['payload_sha256'], $batch['batch_id']);
        $batch = self::castBatch($batch);

        $items = $this->pdo->prepare(
            "SELECT i.id, i.item_ref, i.payee_id, i.seller_code, i.legal_name, i.entity_type, i.pan_last4, i.gstin_masked AS gstin, i.bank_holder_name,
                    i.bank_account_last4, i.bank_ifsc, i.bank_changed, i.entry_ids, i.gross_amount, i.taxable_amount, i.gst_mode, i.gst_rate,
                    i.gst_amount, i.tds_section, i.tds_rate, i.tds_amount, i.tds_note, i.adjustment_total, i.net_amount, i.status,
                    i.hold_reason, i.failure_reason, i.utr, i.paid_at, i.payment_recorded_by,
                    (SELECT o.status FROM outbox o WHERE o.aggregate_key = 'item:' || i.id ORDER BY o.id DESC LIMIT 1) AS engage_sync
               FROM payout_items i WHERE i.batch_id = ? ORDER BY i.id",
        );
        $items->execute([$id]);
        $adj = $this->pdo->prepare(
            'SELECT a.id, a.item_id, a.amount, a.reason, a.created_by, a.created_at FROM payout_adjustments a
               JOIN payout_items i ON i.id = a.item_id WHERE i.batch_id = ? ORDER BY a.id',
        );
        $adj->execute([$id]);
        $byItem = [];
        foreach ($adj->fetchAll() as $a) {
            $byItem[(int) $a['item_id']][] = ['id' => (int) $a['id'], 'amount' => $a['amount'], 'reason' => $a['reason'],
                'created_by' => $a['created_by'], 'created_at' => $a['created_at']];
        }
        $rows = array_map(static function (array $i) use ($byItem): array {
            $i['id']           = (int) $i['id'];
            $i['payee_id']     = (int) $i['payee_id'];
            $i['bank_changed'] = (bool) $i['bank_changed'];
            $i['entry_ids']    = json_decode((string) $i['entry_ids'], true);
            $i['pan_masked']   = DataVault::mask((string) $i['pan_last4']);
            $i['bank_account_masked'] = DataVault::mask((string) $i['bank_account_last4']);
            $i['adjustments']  = $byItem[$i['id']] ?? [];
            unset($i['pan_last4'], $i['bank_account_last4']);

            return $i;
        }, $items->fetchAll());

        $files = $this->pdo->prepare(
            'SELECT id, kind, format, filename, sha256, item_count, total_amount, superseded, note, created_by, created_at,
                    bank_account_id, debit_account_label, debit_account_last4
               FROM bank_files WHERE batch_id = ? ORDER BY id DESC',
        );
        $files->execute([$id]);
        $fileRows = array_map(static function (array $f): array {
            $f['id']             = (int) $f['id'];
            $f['item_count']     = (int) $f['item_count'];
            $f['superseded']     = (bool) $f['superseded'];
            $f['format_label']   = BankFileFormatter::FORMATS[$f['format']]['label'] ?? $f['format'];
            $f['confirm_format'] = BankFileFormatter::FORMATS[$f['format']]['confirm_format'] ?? false;
            // The company account this file debits, as it was when the file was generated (label + last four only).
            $f['debit_account']  = $f['bank_account_id'] === null ? null : [
                'id'                    => (int) $f['bank_account_id'],
                'label'                 => (string) $f['debit_account_label'],
                'account_number_masked' => DataVault::mask((string) $f['debit_account_last4']),
            ];
            unset($f['bank_account_id'], $f['debit_account_label'], $f['debit_account_last4']);

            return $f;
        }, $files->fetchAll());

        $settings = SettingsService::typed((new SettingsService($this->pdo))->all());
        $fourEyes = $settings['four_eyes_enabled']
            ? PayoutStateMachine::fourEyesViolation($actor, $batch['prepared_by'], $batch['last_edited_by'])
            : null;
        $status   = (string) $batch['status'];
        $edit     = FinanceAccessPolicy::allows($roles, 'edit');
        $approve  = FinanceAccessPolicy::allows($roles, 'approve');
        $anyPaid  = count(array_filter($rows, static fn (array $i) => in_array($i['status'], ['paid', 'reversed'], true) || ($i['status'] === 'failed' && !str_starts_with((string) $i['failure_reason'], 'Released')))) > 0;

        $batch['four_eyes'] = ['enabled' => $settings['four_eyes_enabled'], 'blocked_reason' => $fourEyes];
        $batch['can'] = [
            'edit'             => $edit && in_array($status, PayoutStateMachine::EDITABLE, true),
            'submit'           => $edit && $status === 'draft',
            'return_to_draft'  => $edit && $status === 'in_review',
            'approve'          => $approve && $status === 'in_review' && $fourEyes === null,
            'cancel'           => $approve && in_array($status, ['draft', 'in_review', 'approved'], true),
            'generate_file'    => $approve && ($status === 'approved' || ($status === 'file_generated' && !$anyPaid)),
            'download_file'    => $approve,
            'record_payments'  => $approve && in_array($status, PayoutStateMachine::PAYABLE, true),
            'release_held'     => $approve && !in_array($status, PayoutStateMachine::EDITABLE, true) && $status !== 'cancelled',
        ];
        $batch['items']       = $rows;
        $batch['bank_files']  = $fileRows;
        $batch['bank_file_formats'] = BankFileFormatter::FORMATS;
        $batch['default_bank_file_format'] = $settings['bank_file_format'];
        // Active company bank accounts (masked) for the generate-file account picker; the default comes first.
        $batch['bank_accounts'] = (new CompanyBankAccountService($this->pdo))->list(true);
        $batch['debit_account_configured'] = $batch['bank_accounts'] !== [];

        return $batch;
    }

    /** @param array<string,mixed> $b */
    private static function castBatch(array $b): array
    {
        foreach (['id', 'version', 'item_count', 'held_count', 'paid_count', 'failed_count', 'bank_changed_count'] as $k) {
            if (array_key_exists($k, $b)) {
                $b[$k] = (int) $b[$k];
            }
        }
        foreach (['gross_amount', 'gst_amount', 'tds_amount', 'net_amount', 'payable_amount', 'paid_amount', 'adjustment_total'] as $k) {
            if (array_key_exists($k, $b) && $b[$k] === null) {
                $b[$k] = '0.00';
            }
        }

        return $b;
    }

    /** @return list<array<string,mixed>> */
    public function payees(?string $search, ?int $id = null): array
    {
        $fy     = FiscalYear::current();
        $sql    = "SELECT p.id, p.seller_code, p.legal_name, p.entity_type, p.pan_last4, p.gstin_masked AS gstin, p.bank_holder_name, p.bank_account_last4,
                          p.bank_ifsc, p.created_at, p.updated_at,
                          (SELECT max(i.paid_at) FROM payout_items i WHERE i.payee_id = p.id AND i.status = 'paid') AS last_paid_at,
                          (SELECT coalesce(sum(i.net_amount), 0)::text FROM payout_items i JOIN payout_batches b ON b.id = i.batch_id
                            WHERE i.payee_id = p.id AND i.status = 'paid' AND b.fy = ?) AS fy_paid_net,
                          (SELECT coalesce(sum(l.taxable_amount), 0)::text FROM tds_ledger l WHERE l.pan_hash = p.pan_hash AND l.fy = ? AND l.status <> 'void') AS fy_cumulative,
                          (SELECT coalesce(sum(l.tds_amount), 0)::text FROM tds_ledger l WHERE l.pan_hash = p.pan_hash AND l.fy = ? AND l.status = 'deducted') AS fy_tds_deducted
                     FROM payees p";
        $params = [$fy, $fy, $fy];
        if ($id !== null) {
            $sql     .= ' WHERE p.id = ?';
            $params[] = $id;
        } elseif ($search !== null && trim($search) !== '') {
            $sql     .= ' WHERE p.seller_code ILIKE ? OR p.legal_name ILIKE ?';
            $like     = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($search)) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY p.legal_name LIMIT 500';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(static function (array $p) use ($fy): array {
            $p['id']                  = (int) $p['id'];
            $p['pan_masked']          = DataVault::mask((string) $p['pan_last4']);
            $p['bank_account_masked'] = DataVault::mask((string) $p['bank_account_last4']);
            $p['fy']                  = $fy;
            unset($p['pan_last4'], $p['bank_account_last4']);

            return $p;
        }, $stmt->fetchAll());
    }

    /** @return array<string,mixed> */
    public function payee(int $id): array
    {
        $rows = $this->payees(null, $id);
        if ($rows === []) {
            throw ApiException::notFound('Payee not found.');
        }
        $payee = $rows[0];
        $stmt  = $this->pdo->prepare(
            'SELECT i.id, i.item_ref, i.status, i.gross_amount, i.gst_amount, i.tds_amount, i.adjustment_total, i.net_amount, i.utr, i.paid_at,
                    i.bank_changed, i.bank_account_last4, i.bank_ifsc, b.id AS batch_id, b.batch_ref, b.period_start, b.period_end, b.status AS batch_status
               FROM payout_items i JOIN payout_batches b ON b.id = i.batch_id WHERE i.payee_id = ? ORDER BY b.period_end DESC, i.id DESC',
        );
        $stmt->execute([$id]);
        $payee['items'] = array_map(static function (array $i): array {
            $i['id']                  = (int) $i['id'];
            $i['batch_id']            = (int) $i['batch_id'];
            $i['bank_changed']        = (bool) $i['bank_changed'];
            $i['bank_account_masked'] = DataVault::mask((string) $i['bank_account_last4']);
            unset($i['bank_account_last4']);

            return $i;
        }, $stmt->fetchAll());

        return $payee;
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        $fy = FiscalYear::current();
        [$fyStart, $fyEnd] = FiscalYear::range($fy);
        $counts = array_fill_keys(PayoutStateMachine::BATCH_STATUSES, 0);
        foreach ($this->pdo->query('SELECT status, count(*) AS n FROM payout_batches GROUP BY status')->fetchAll() as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }
        $q = static function (\PDO $pdo, string $sql, array $params = []): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetch() ?: [];
        };
        $review = $q($this->pdo, "SELECT count(*) AS items, coalesce(sum(i.net_amount), 0)::text AS net FROM payout_items i
                                   JOIN payout_batches b ON b.id = i.batch_id WHERE b.status IN ('draft','in_review') AND i.status = 'draft'");
        $toPay  = $q($this->pdo, "SELECT count(*) AS items, coalesce(sum(i.net_amount), 0)::text AS net FROM payout_items i WHERE i.status = 'approved'");
        $held   = $q($this->pdo, "SELECT count(*) AS items, coalesce(sum(i.net_amount), 0)::text AS net FROM payout_items i WHERE i.status = 'on_hold'");
        $paid   = $q($this->pdo, "SELECT count(*) AS items, coalesce(sum(net_amount), 0)::text AS net, coalesce(sum(tds_amount), 0)::text AS tds,
                                          coalesce(sum(gst_amount), 0)::text AS gst, coalesce(sum(gross_amount), 0)::text AS gross
                                     FROM payout_items WHERE status = 'paid' AND paid_at::date BETWEEN ? AND ?", [$fyStart, $fyEnd]);
        $failed = $q($this->pdo, "SELECT count(*) AS items FROM payout_items WHERE status IN ('failed','reversed') AND updated_at::date BETWEEN ? AND ?", [$fyStart, $fyEnd]);
        $payees = $q($this->pdo, 'SELECT count(*) AS n FROM payees');
        $last   = $q($this->pdo, 'SELECT max(received_at) AS at FROM payout_batches');
        $outbox = ['pending' => 0, 'delivered' => 0, 'dead' => 0];
        foreach ($this->pdo->query('SELECT status, count(*) AS n FROM outbox GROUP BY status')->fetchAll() as $r) {
            $outbox[(string) $r['status']] = (int) $r['n'];
        }
        $recent = $this->batches('open');

        return [
            'fy'               => $fy,
            'batches_by_status' => $counts,
            'awaiting_review'  => ['items' => (int) ($review['items'] ?? 0), 'net_amount' => $review['net'] ?? '0.00'],
            'awaiting_payment' => ['items' => (int) ($toPay['items'] ?? 0), 'net_amount' => $toPay['net'] ?? '0.00'],
            'on_hold'          => ['items' => (int) ($held['items'] ?? 0), 'net_amount' => $held['net'] ?? '0.00'],
            'paid_this_fy'     => [
                'items' => (int) ($paid['items'] ?? 0), 'gross_amount' => $paid['gross'] ?? '0.00', 'gst_amount' => $paid['gst'] ?? '0.00',
                'tds_amount' => $paid['tds'] ?? '0.00', 'net_amount' => $paid['net'] ?? '0.00',
            ],
            'failed_this_fy'   => (int) ($failed['items'] ?? 0),
            'payees'           => (int) ($payees['n'] ?? 0),
            'last_batch_received_at' => $last['at'] ?? null,
            'engage_callbacks' => $outbox + ['configured' => OutboxService::isConfigured()],
            'open_batches'     => array_slice($recent, 0, 10),
        ];
    }

    /**
     * TDS register (section 194H) for one quarter: every paid item whose payment
     * date falls in the quarter — the deductee data Form 26Q needs.
     *
     * @return array{fy:string, quarter:string, from:string, to:string, rows:list<array<string,mixed>>, totals:array<string,mixed>}
     */
    public function tdsRegister(string $fy, string $quarter, ?DataVault $revealWith = null): array
    {
        if (!FiscalYear::isValid($fy)) {
            throw ApiException::validation('fy must look like 2026-27.');
        }
        $quarter = strtoupper($quarter);
        [$from, $to] = FiscalYear::quarterRange($fy, $quarter);
        $stmt = $this->pdo->prepare(
            "SELECT l.id AS ledger_id, l.section, l.taxable_amount, l.tds_rate, l.tds_amount, l.deducted_on,
                    i.id AS item_id, i.item_ref, i.seller_code, i.legal_name, i.entity_type, i.pan_last4, i.pan_enc, i.gst_amount,
                    i.net_amount, i.utr, i.paid_at, i.tds_note, b.batch_ref
               FROM tds_ledger l JOIN payout_items i ON i.id = l.item_id JOIN payout_batches b ON b.id = i.batch_id
              WHERE l.status = 'deducted' AND l.deducted_on BETWEEN ? AND ?
              ORDER BY l.deducted_on, l.id",
        );
        $stmt->execute([$from, $to]);
        $tot = ['rows' => 0, 'amount_paid' => 0, 'tds' => 0, 'rows_with_tds' => 0];
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $pan = $revealWith !== null ? $revealWith->decrypt((string) $r['pan_enc']) : DataVault::mask((string) $r['pan_last4']);
            $tds = Money::parse((string) $r['tds_amount']) ?? 0;
            $tot['rows']++;
            $tot['amount_paid'] += Money::parse((string) $r['taxable_amount']) ?? 0;
            $tot['tds'] += $tds;
            $tot['rows_with_tds'] += $tds > 0 ? 1 : 0;
            $rows[] = [
                'deductee_pan'       => $pan,
                'deductee_name'      => $r['legal_name'],
                'deductee_code'      => $r['entity_type'] === 'company' ? '01' : '02', // 26Q: 01 company, 02 other than company
                'entity_type'        => $r['entity_type'],
                'seller_code'        => $r['seller_code'],
                'section'            => $r['section'],
                'amount_paid'        => $r['taxable_amount'],
                'tds_rate'           => $r['tds_rate'],
                'tds_amount'         => $r['tds_amount'],
                'date_of_payment'    => $r['deducted_on'],
                'date_of_deduction'  => $r['deducted_on'],
                'gst_amount'         => $r['gst_amount'],
                'net_paid'           => $r['net_amount'],
                'utr'                => $r['utr'],
                'item_ref'           => $r['item_ref'],
                'batch_ref'          => $r['batch_ref'],
                'note'               => $r['tds_note'],
            ];
        }

        return [
            'fy' => $fy, 'quarter' => $quarter, 'from' => $from, 'to' => $to, 'rows' => $rows,
            'totals' => ['rows' => $tot['rows'], 'rows_with_tds' => $tot['rows_with_tds'],
                'amount_paid' => Money::format($tot['amount_paid']), 'tds_amount' => Money::format($tot['tds'])],
        ];
    }

    /** @param array<string,mixed> $register */
    public static function tdsCsv(array $register): string
    {
        $head = ['Deductee PAN', 'Deductee Name', 'Deductee Code', 'Section', 'Amount Paid/Credited', 'TDS Rate (%)', 'TDS Deducted',
            'Date of Payment/Credit', 'Date of Deduction', 'GST (not subject to TDS)', 'Net Paid', 'UTR', 'Seller Code', 'Item Ref', 'Batch Ref'];
        $lines = [BankFileFormatter::csv($head)];
        foreach ($register['rows'] as $r) {
            $lines[] = BankFileFormatter::csv(array_map('strval', [
                $r['deductee_pan'], $r['deductee_name'], $r['deductee_code'], $r['section'], $r['amount_paid'], $r['tds_rate'], $r['tds_amount'],
                self::dmy((string) $r['date_of_payment']), self::dmy((string) $r['date_of_deduction']), $r['gst_amount'], $r['net_paid'],
                (string) $r['utr'], $r['seller_code'], $r['item_ref'], $r['batch_ref'],
            ]));
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private static function dmy(string $ymd): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return $d ? $d->format('d/m/Y') : $ymd;
    }
}
