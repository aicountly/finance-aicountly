<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';
require_once dirname(__DIR__, 2) . '/Helpers/S2sResponse.php';

use App\Database\Database;
use App\Services\AuditLog;
use App\Services\Invoicing\DocumentQueries;
use App\Services\Invoicing\InvoiceService;
use App\Support\ApiException;
use App\Support\FiscalYear;

/**
 * Manage → Finance read API (commerce contract §1.2–1.4), AIC-S2S v1 signed (S2sFilter, key scope "read").
 * Manage has already verified the my.aicountly user owns the company; Finance trusts that and filters every query by
 * cmp_id — another company's document answers exactly like a missing one (404 not_found).
 *
 *   GET v1/inbound/manage/documents?cmp_id=&fy=&doc_type=&limit=&cursor=     cursor-paged list (doc_date DESC, id DESC)
 *   GET v1/inbound/manage/documents/{document_id}?cmp_id=                   everything on the face of the document
 *   GET v1/inbound/manage/documents/{document_id}/pdf?cmp_id=               the PDF stored at issue (counts 5 for rate limits)
 *
 * List and detail reads go to fin_s2s_access_log (kept 180 days); each PDF download is an audit_log row.
 */
class ManageDocumentsController extends BaseController
{
    public function index(array $params, array $body): never
    {
        $this->guard(function (): never {
            $cmpId   = $this->cmpId();
            $fy      = $this->query('fy');
            $docType = $this->query('doc_type');
            $limit   = $this->query('limit');
            $cursor  = $this->query('cursor');
            if ($fy !== null && $fy !== '' && !FiscalYear::isValid($fy)) {
                throw ApiException::coded(400, 'bad_request', 'fy must look like 2026-27.', ['field' => 'fy']);
            }
            if ($docType !== null && $docType !== '' && !in_array($docType, ['tax_invoice', 'credit_note'], true)) {
                throw ApiException::coded(400, 'bad_request', 'doc_type must be tax_invoice or credit_note.', ['field' => 'doc_type']);
            }
            if ($limit !== null && $limit !== '' && (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100)) {
                throw ApiException::coded(400, 'bad_request', 'limit must be 1 to 100.', ['field' => 'limit']);
            }
            $page = (new DocumentQueries($this->pdo()))->listForCompany(
                $cmpId,
                $fy ?: null,
                $docType ?: null,
                $limit ? (int) $limit : 25,
                $cursor ? DocumentQueries::decodeCursor($cursor) : null,
            );
            $this->accessLog(200, $cmpId, null);
            s2s_success($page['rows'], ['next_cursor' => $page['next_cursor'], 'limit' => $page['limit']]);
        });
    }

    public function show(array $params, array $body): never
    {
        $this->guard(function () use ($params): never {
            $cmpId = $this->cmpId();
            $docId = (string) ($params['document_id'] ?? '');
            $doc   = (new DocumentQueries($this->pdo()))->byDocumentId($docId, $cmpId);
            if ($doc === null) {
                $this->accessLog(404, $cmpId, $docId);
                throw ApiException::coded(404, 'not_found', 'No such document.');
            }
            $this->accessLog(200, $cmpId, $docId);
            s2s_success(DocumentQueries::forManage($doc));
        });
    }

    public function pdf(array $params, array $body): never
    {
        $this->guard(function () use ($params): never {
            $cmpId = $this->cmpId();
            $docId = (string) ($params['document_id'] ?? '');
            $q     = new DocumentQueries($this->pdo());
            $doc   = $q->byDocumentId($docId, $cmpId);
            if ($doc === null) {
                $this->accessLog(404, $cmpId, $docId);
                throw ApiException::coded(404, 'not_found', 'No such document.');
            }
            $r = $q->rendering((string) $doc['doc_type'], (int) $doc['id']);
            if ($r === null || $r['pdf'] === null) {
                $this->accessLog(503, $cmpId, $docId);
                throw ApiException::coded(503, 'pdf_unavailable', 'The PDF of this document is not available yet. Try again later.');
            }
            $pdf = (string) $r['pdf'];
            if (!hash_equals((string) $r['pdf_sha256'], hash('sha256', $pdf))) {
                error_log("[finance] stored PDF checksum mismatch for {$docId}");
                throw ApiException::coded(500, 'internal_error', 'The stored document failed its integrity check.');
            }
            $caller = S2sFilter::$caller ?? [];
            (new AuditLog($this->pdo()))->record(
                ['actor' => 'service:' . ($caller['client'] ?? 'unknown'), 'name' => $caller['actor'] ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null],
                'document.pdf_downloaded',
                $doc['doc_type'] === 'credit_note' ? 'credit_note' : 'invoice',
                (int) $doc['id'],
                null,
                ['cmp_id' => $cmpId, 'document_id' => $docId, 'doc_no' => $doc['doc_no'], 'pdf_sha256' => $r['pdf_sha256'],
                    'request_id' => S2sFilter::$requestId, 'key_id' => $caller['key_id'] ?? null, 'via' => 'manage'],
            );
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . InvoiceService::pdfFilename((string) $doc['doc_no']) . '"');
            header('ETag: "' . $r['pdf_sha256'] . '"');
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            header('X-AIC-Request-Id: ' . S2sFilter::$requestId);
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        });
    }

    private ?\PDO $pdo = null;

    private function pdo(): \PDO
    {
        return $this->pdo ??= Database::pdo();
    }

    private function cmpId(): int
    {
        $raw = $this->query('cmp_id');
        if ($raw === null || !ctype_digit($raw) || (int) $raw <= 0 || strlen($raw) > 18) {
            throw ApiException::coded(400, 'bad_request', 'cmp_id (a positive integer) is required.', ['field' => 'cmp_id']);
        }

        return (int) $raw;
    }

    private function accessLog(int $status, ?int $cmpId, ?string $documentId): void
    {
        $c = S2sFilter::$caller ?? [];
        try {
            $this->pdo()->prepare(
                'INSERT INTO fin_s2s_access_log (client, key_id, actor, request_id, method, path, cmp_id, document_id, http_status, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([$c['client'] ?? 'unknown', $c['key_id'] ?? 'unknown', $c['actor'] ?? null, S2sFilter::$requestId,
                (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), S2sFilter::path(), $cmpId, $documentId, $status, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (\Throwable $e) {
            error_log('[finance] S2S access log write failed: ' . $e->getMessage());
        }
    }

    /** Every failure leaves in the contract envelope (never the staff one, never a stack trace). */
    private function guard(callable $fn): never
    {
        try {
            $fn();
        } catch (ApiException $e) {
            $code = $e->errorCode() ?? match ($e->status()) {
                400 => 'bad_request', 404 => 'not_found', 409 => 'conflict', 422 => 'validation_failed', 503 => 'database_unavailable', default => 'error',
            };
            $details = $e->errors();
            s2s_error($e->status(), $code, $e->status() === 503 && $e->errorCode() === null ? 'Finance is temporarily unavailable.' : $e->getMessage(), $details);
        } catch (\Throwable $e) {
            error_log('[finance] S2S request failed: ' . $e->getMessage());
            s2s_error(500, 'internal_error', 'Finance could not answer this request.', [], true);
        }
        exit;
    }
}
