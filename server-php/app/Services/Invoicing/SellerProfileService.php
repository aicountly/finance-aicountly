<?php
declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Services\AuditLog;
use App\Support\ApiException;
use App\Support\FiscalYear;
use App\Support\Gstin;

/**
 * Invoicing configuration (Settings → Invoicing, finance_admin): the seller profile printed on every document, and
 * the document series per FY and type. Until both exist nothing can be issued (422 seller_not_configured /
 * series_not_configured; /api/health invoicing.checks).
 *
 * The profile is never edited in place: saving inserts a new active row and retires the previous one, so the
 * history stays (every document also keeps its own snapshot).
 */
final class SellerProfileService
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null the active profile */
    public function active(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM fin_seller_profile WHERE active ORDER BY id DESC LIMIT 1')->fetch();

        return $row ? self::publicProfile($row) : null;
    }

    /** @return list<array<string,mixed>> newest first */
    public function history(): array
    {
        return array_map([self::class, 'publicProfile'], $this->pdo->query('SELECT * FROM fin_seller_profile ORDER BY id DESC LIMIT 20')->fetchAll());
    }

    /**
     * @param array<string,mixed> $input
     * @return array{legal_name:string, trade_name:?string, gstin:string, pan:string, address_line1:string, address_line2:?string,
     *               city:string, pincode:string, state_code:string, email:?string, phone:?string, signatory_name:string,
     *               signatory_designation:?string, effective_from:string}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $t = static fn (string $k, int $max = 200): string => InvoiceInput::text($input[$k] ?? null, $max);
        $out = [
            'legal_name'            => $t('legal_name'),
            'trade_name'            => $t('trade_name') ?: null,
            'gstin'                 => Gstin::normalise($input['gstin'] ?? null),
            'pan'                   => Gstin::normalise($input['pan'] ?? null),
            'address_line1'         => $t('address_line1', 200),
            'address_line2'         => $t('address_line2', 200) ?: null,
            'city'                  => $t('city', 100),
            'pincode'               => $t('pincode', 6),
            'state_code'            => '',
            'email'                 => strtolower($t('email', 254)) ?: null,
            'phone'                 => $t('phone', 20) ?: null,
            'signatory_name'        => $t('signatory_name'),
            'signatory_designation' => $t('signatory_designation') ?: null,
            'effective_from'        => is_string($input['effective_from'] ?? null) && $input['effective_from'] !== '' ? $input['effective_from'] : FiscalYear::today(),
        ];
        foreach (['legal_name', 'address_line1', 'city', 'signatory_name'] as $k) {
            if ($out[$k] === '') {
                $errors[$k] = 'Required.';
            }
        }
        if (($p = Gstin::problem($out['gstin'])) !== null) {
            $errors['gstin'] = $p;
        } else {
            $out['state_code'] = Gstin::stateOf($out['gstin']);
        }
        if ($out['pan'] === '' && !isset($errors['gstin'])) {
            $out['pan'] = Gstin::panOf($out['gstin']);
        }
        if (!preg_match(Gstin::PAN_PATTERN, $out['pan'])) {
            $errors['pan'] = 'A PAN (e.g. AAPFU0939F).';
        } elseif (!isset($errors['gstin']) && Gstin::panOf($out['gstin']) !== $out['pan']) {
            $errors['pan'] = 'Must be the PAN inside the GSTIN (' . Gstin::panOf($out['gstin']) . ').';
        }
        if (isset($input['state_code']) && $input['state_code'] !== '' && !isset($errors['gstin']) && (string) $input['state_code'] !== $out['state_code']) {
            $errors['state_code'] = 'The state of registration is the GSTIN\'s (' . Gstin::stateLabel($out['state_code']) . ').';
        }
        if (!preg_match('/^[1-9][0-9]{5}$/', $out['pincode'])) {
            $errors['pincode'] = 'A six-digit PIN code.';
        }
        if ($out['email'] !== null && filter_var($out['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'A valid email address.';
        }
        if (!FiscalYear::isDate($out['effective_from'])) {
            $errors['effective_from'] = 'A date (YYYY-MM-DD).';
        }
        if ($errors !== []) {
            throw ApiException::coded(422, 'validation_failed', 'The seller profile is invalid.', $errors);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $input
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array<string,mixed>
     */
    public function save(array $input, array $actor): array
    {
        $v = self::validate($input);
        $this->pdo->beginTransaction();
        try {
            $before = $this->pdo->query('SELECT * FROM fin_seller_profile WHERE active FOR UPDATE')->fetch() ?: null;
            if ($before) {
                $this->pdo->prepare('UPDATE fin_seller_profile SET active = false, retired_by = ?, retired_at = now() WHERE id = ?')
                    ->execute([$actor['actor'], (int) $before['id']]);
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO fin_seller_profile (legal_name, trade_name, gstin, pan, address_line1, address_line2, city, pincode, state_code,
                     email, phone, signatory_name, signatory_designation, effective_from, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING *',
            );
            $stmt->execute([$v['legal_name'], $v['trade_name'], $v['gstin'], $v['pan'], $v['address_line1'], $v['address_line2'], $v['city'],
                $v['pincode'], $v['state_code'], $v['email'], $v['phone'], $v['signatory_name'], $v['signatory_designation'], $v['effective_from'],
                $actor['actor']]);
            $row = self::publicProfile($stmt->fetch());
            (new AuditLog($this->pdo))->record($actor, 'invoicing.seller_profile_saved', 'seller_profile', $row['id'],
                $before ? self::publicProfile($before) : null, $row);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function series(): array
    {
        $rows = $this->pdo->query(
            "SELECT s.*,
                    (SELECT count(*) FROM fin_invoices i WHERE i.series_id = s.id) + (SELECT count(*) FROM fin_credit_notes c WHERE c.series_id = s.id) AS used
               FROM fin_doc_series s ORDER BY s.fy DESC, s.doc_type DESC",
        )->fetchAll();

        return array_map([self::class, 'publicSeries'], $rows);
    }

    /**
     * @param array<string,mixed> $input fy, doc_type, prefix, width, next_no
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array<string,mixed>
     */
    public function createSeries(array $input, array $actor): array
    {
        $fy      = is_string($input['fy'] ?? null) ? trim($input['fy']) : '';
        $docType = is_string($input['doc_type'] ?? null) ? trim($input['doc_type']) : '';
        $errors  = [];
        if (!FiscalYear::isValid($fy)) {
            $errors['fy'] = 'A financial year such as 2026-27.';
        }
        if (!in_array($docType, DocumentNumbering::DOC_TYPES, true)) {
            $errors['doc_type'] = 'tax_invoice or credit_note.';
        }
        try {
            $v = DocumentNumbering::validateSeries($input);
        } catch (ApiException $e) {
            $errors += $e->errors();
            $v = null;
        }
        if ($errors !== [] || $v === null) {
            throw ApiException::coded(422, 'validation_failed', 'The series is invalid.', $errors);
        }

        return $this->write($actor, function () use ($fy, $docType, $v, $actor): array {
            $dup = $this->pdo->prepare('SELECT doc_type, prefix FROM fin_doc_series WHERE fy = ? AND (doc_type = ? OR prefix = ?)');
            $dup->execute([$fy, $docType, $v['prefix']]);
            if ($d = $dup->fetch()) {
                throw ApiException::coded(409, 'conflict', $d['doc_type'] === $docType
                    ? "FY {$fy} already has a {$docType} series."
                    : "Prefix {$v['prefix']} is already used by the {$d['doc_type']} series of FY {$fy}: numbers must be unique within the FY.");
            }
            $stmt = $this->pdo->prepare('INSERT INTO fin_doc_series (fy, doc_type, prefix, next_no, width, created_by) VALUES (?, ?, ?, ?, ?, ?) RETURNING *, 0 AS used');
            $stmt->execute([$fy, $docType, $v['prefix'], $v['next_no'], $v['width'], $actor['actor']]);
            $row = self::publicSeries($stmt->fetch());
            (new AuditLog($this->pdo))->record($actor, 'invoicing.series_created', 'doc_series', $row['id'], null, $row);

            return $row;
        });
    }

    /**
     * Change prefix / width / next number — only while no document has been numbered from the series.
     *
     * @param array<string,mixed> $input
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array<string,mixed>
     */
    public function updateSeries(int $id, array $input, array $actor): array
    {
        return $this->write($actor, function () use ($id, $input, $actor): array {
            $stmt = $this->pdo->prepare(
                'SELECT s.*, (SELECT count(*) FROM fin_invoices i WHERE i.series_id = s.id) + (SELECT count(*) FROM fin_credit_notes c WHERE c.series_id = s.id) AS used
                   FROM fin_doc_series s WHERE s.id = ? FOR UPDATE',
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw ApiException::notFound('Series not found.');
            }
            if ((int) $row['used'] > 0) {
                throw ApiException::coded(409, 'series_in_use', 'Documents are already numbered from this series, so it can no longer change (numbers must stay consecutive).');
            }
            $v = DocumentNumbering::validateSeries($input + ['prefix' => $row['prefix'], 'width' => (int) $row['width'], 'next_no' => (int) $row['next_no']]);
            $dup = $this->pdo->prepare('SELECT 1 FROM fin_doc_series WHERE fy = ? AND prefix = ? AND id <> ?');
            $dup->execute([$row['fy'], $v['prefix'], $id]);
            if ($dup->fetch()) {
                throw ApiException::coded(409, 'conflict', "Prefix {$v['prefix']} is already used in FY {$row['fy']}.");
            }
            $upd = $this->pdo->prepare('UPDATE fin_doc_series SET prefix = ?, width = ?, next_no = ?, updated_by = ?, updated_at = now() WHERE id = ? RETURNING *, 0 AS used');
            $upd->execute([$v['prefix'], $v['width'], $v['next_no'], $actor['actor'], $id]);
            $after = self::publicSeries($upd->fetch());
            (new AuditLog($this->pdo))->record($actor, 'invoicing.series_updated', 'doc_series', $id, self::publicSeries($row), $after);

            return $after;
        });
    }

    /**
     * Readiness for issuing on $date (default today): seller profile and both series for that FY.
     *
     * @return array{seller_profile:bool, series:array{tax_invoice:bool, credit_note:bool}, fy:string}
     */
    public function readiness(?string $date = null): array
    {
        $fy   = FiscalYear::forDate($date ?? FiscalYear::today());
        $have = $this->pdo->prepare('SELECT doc_type FROM fin_doc_series WHERE fy = ?');
        $have->execute([$fy]);
        $types = array_column($have->fetchAll(), 'doc_type');

        return [
            'seller_profile' => (bool) $this->pdo->query('SELECT 1 FROM fin_seller_profile WHERE active')->fetch(),
            'series'         => ['tax_invoice' => in_array('tax_invoice', $types, true), 'credit_note' => in_array('credit_note', $types, true)],
            'fy'             => $fy,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function publicProfile(array $row): array
    {
        return [
            'id'                    => (int) $row['id'],
            'legal_name'            => (string) $row['legal_name'],
            'trade_name'            => $row['trade_name'],
            'gstin'                 => (string) $row['gstin'],
            'pan'                   => (string) $row['pan'],
            'address_line1'         => (string) $row['address_line1'],
            'address_line2'         => $row['address_line2'],
            'city'                  => (string) $row['city'],
            'pincode'               => (string) $row['pincode'],
            'state_code'            => (string) $row['state_code'],
            'state_name'            => Gstin::stateName((string) $row['state_code']),
            'email'                 => $row['email'],
            'phone'                 => $row['phone'],
            'signatory_name'        => (string) $row['signatory_name'],
            'signatory_designation' => $row['signatory_designation'],
            'effective_from'        => (string) $row['effective_from'],
            'active'                => (bool) $row['active'],
            'created_by'            => (string) $row['created_by'],
            'created_at'            => (string) $row['created_at'],
            'retired_at'            => $row['retired_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function publicSeries(array $row): array
    {
        $next = (int) $row['next_no'];

        return [
            'id'          => (int) $row['id'],
            'fy'          => (string) $row['fy'],
            'doc_type'    => (string) $row['doc_type'],
            'prefix'      => (string) $row['prefix'],
            'width'       => (int) $row['width'],
            'next_no'     => $next,
            'next_doc_no' => DocumentNumbering::format((string) $row['prefix'], $next, (int) $row['width']),
            'used'        => (int) ($row['used'] ?? 0),
            'editable'    => (int) ($row['used'] ?? 0) === 0,
            'updated_at'  => (string) $row['updated_at'],
        ];
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function write(array $actor, callable $fn): mixed
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
}
