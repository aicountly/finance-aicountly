<?php
declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Support\ApiException;

/**
 * Gapless document numbers per financial year and document type (CGST Rule 46(b): consecutive, unique for the FY,
 * at most 16 characters of letters, digits, '-' and '/').
 *
 * next() must run inside the issuing transaction: it locks the fin_doc_series row (SELECT … FOR UPDATE), so a second
 * issuer waits until the first commits or rolls back. A rollback also undoes the increment, so no number is ever
 * skipped; a commit makes the number and the document visible together.
 *
 *   doc_no = prefix . str_pad(next_no, width, '0', STR_PAD_LEFT)       e.g. "AIC/26-27/" + 6 → AIC/26-27/000123
 */
final class DocumentNumbering
{
    public const DOC_TYPES = ['tax_invoice', 'credit_note'];
    public const MAX_LENGTH = 16;

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array{series_id:int, fy:string, seq_no:int, doc_no:string} */
    public function next(string $fy, string $docType): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('DocumentNumbering::next() must run inside the issuing transaction.');
        }
        $stmt = $this->pdo->prepare('SELECT id, prefix, next_no, width FROM fin_doc_series WHERE fy = ? AND doc_type = ? FOR UPDATE');
        $stmt->execute([$fy, $docType]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::coded(422, 'series_not_configured',
                "No {$this->label($docType)} series is set up for FY {$fy} (Settings → Invoicing).", ['fy' => $fy, 'doc_type' => $docType]);
        }
        $seq   = (int) $row['next_no'];
        $docNo = self::format((string) $row['prefix'], $seq, (int) $row['width']);
        if ($docNo === null) {
            throw ApiException::coded(422, 'series_exhausted',
                "The {$this->label($docType)} series for FY {$fy} has no numbers left (width {$row['width']}).", ['fy' => $fy, 'doc_type' => $docType]);
        }
        $this->pdo->prepare('UPDATE fin_doc_series SET next_no = next_no + 1, updated_at = now() WHERE id = ?')->execute([(int) $row['id']]);

        return ['series_id' => (int) $row['id'], 'fy' => $fy, 'seq_no' => $seq, 'doc_no' => $docNo];
    }

    /** The number as printed, or null when it no longer fits the width / 16 characters. */
    public static function format(string $prefix, int $seq, int $width): ?string
    {
        $digits = str_pad((string) $seq, $width, '0', STR_PAD_LEFT);
        if (strlen($digits) > $width || strlen($prefix) + $width > self::MAX_LENGTH) {
            return null;
        }

        return $prefix . $digits;
    }

    /**
     * Validate a series definition.
     *
     * @param array<string,mixed> $input
     * @return array{prefix:string, width:int, next_no:int}
     */
    public static function validateSeries(array $input): array
    {
        $errors = [];
        $prefix = strtoupper(trim(is_string($input['prefix'] ?? null) ? $input['prefix'] : ''));
        if (!preg_match('/^[A-Z0-9\/-]*[A-Z\/-]$/', $prefix)) {
            $errors['prefix'] = 'Letters, digits, "-" and "/", not ending in a digit (e.g. AIC/26-27/).';
        }
        $width = $input['width'] ?? 6;
        $width = is_int($width) ? $width : (is_string($width) && ctype_digit($width) ? (int) $width : 0);
        if ($width < 1 || $width > 12) {
            $errors['width'] = 'Number of digits, 1 to 12.';
        } elseif (!isset($errors['prefix']) && strlen($prefix) + $width > self::MAX_LENGTH) {
            $errors['width'] = 'Prefix plus digits must be at most 16 characters (Rule 46(b)); this is ' . (strlen($prefix) + $width) . '.';
        }
        $next = $input['next_no'] ?? 1;
        $next = is_int($next) ? $next : (is_string($next) && ctype_digit($next) ? (int) $next : 0);
        if ($next < 1) {
            $errors['next_no'] = 'The next number, 1 or more.';
        } elseif ($errors === [] && self::format($prefix, $next, $width) === null) {
            $errors['next_no'] = 'Does not fit in ' . $width . ' digits.';
        }
        if ($errors !== []) {
            throw ApiException::coded(422, 'validation_failed', 'The series is invalid.', $errors);
        }

        return ['prefix' => $prefix, 'width' => $width, 'next_no' => $next];
    }

    private function label(string $docType): string
    {
        return $docType === 'credit_note' ? 'credit note' : 'tax invoice';
    }
}
