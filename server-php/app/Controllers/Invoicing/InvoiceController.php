<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\AuditLog;
use App\Services\FinanceAccessPolicy;
use App\Services\Invoicing\CreditNoteService;
use App\Services\Invoicing\DocumentQueries;
use App\Services\Invoicing\GotenbergClient;
use App\Services\Invoicing\InvoiceService;
use App\Support\ApiException;
use App\Support\FiscalYear;

/**
 * Staff: the invoice register and documents (Finance roles; customers never sign in to Finance).
 *
 *   GET  v1/invoices?fy=&doc_type=&status=&cmp_id=&q=&page=   view      register (invoices + issued credit notes)
 *   GET  v1/invoices/export?…                                  edit      GSTR-1 friendly CSV (audited)
 *   GET  v1/invoices/{id}                                      view      invoice detail (+ credit notes)
 *   GET  v1/invoices/{id}/pdf                                  view      the PDF stored at issue (audited)
 *   POST v1/invoices/{id}/credit-notes                         edit      request a credit note (four-eyes: someone else approves)
 *   GET  v1/credit-notes…                                      see CreditNoteController
 */
class InvoiceController extends BaseController
{
    public function index(array $params, array $body): never
    {
        $page = (int) ($this->query('page') ?: 1);
        json_success((new DocumentQueries(Database::pdo()))->register($this->filters(), $page, (int) ($this->query('per_page') ?: 50)));
    }

    public function export(array $params, array $body): never
    {
        $pdo = Database::pdo();
        $f   = $this->filters();
        $csv = (new DocumentQueries($pdo))->gstr1Csv($f);
        $sha = hash('sha256', $csv);
        (new AuditLog($pdo))->record($this->actor(), 'invoices.exported', 'invoice_register', $f['fy'] ?? 'all', null,
            ['filters' => $f, 'rows' => substr_count($csv, "\r\n") - 1, 'sha256' => $sha]);
        $this->sendCsv('gstr1-' . ($f['fy'] ?? 'all') . ($f['doc_type'] ? '-' . $f['doc_type'] : '') . '.csv', $csv, $sha);
    }

    public function show(array $params, array $body): never
    {
        $pdo = Database::pdo();
        $doc = (new DocumentQueries($pdo))->invoice($this->idParam());
        if ($doc === null) {
            json_not_found('Invoice not found.');
        }
        json_success($doc + [
            'rendering' => $this->rendering($pdo, 'tax_invoice', (int) $doc['id']),
            'can'       => [
                'request_credit_note' => FinanceAccessPolicy::allows($this->roles(), 'edit') && $doc['status'] !== 'credited',
                'approve_credit_note' => FinanceAccessPolicy::allows($this->roles(), 'approve'),
            ],
            'credit_deadline' => CreditNoteService::deadline((string) $doc['fy']),
        ]);
    }

    public function pdf(array $params, array $body): never
    {
        $this->sendStoredPdf('tax_invoice', $this->idParam());
    }

    public function requestCreditNote(array $params, array $body): never
    {
        $cn = (new CreditNoteService(Database::pdo()))->request($this->idParam(), $body, $this->actor());
        json_success($cn, 'Credit note requested: someone else must approve it before it is issued.', 201);
    }

    /** @return array{fy:?string, doc_type:?string, status:?string, cmp_id:?int, q:?string} */
    private function filters(): array
    {
        $fy = $this->query('fy') ?: null;
        if ($fy !== null && !FiscalYear::isValid($fy)) {
            throw ApiException::validation('fy must look like 2026-27.', ['fy' => 'Invalid.']);
        }
        $docType = $this->query('doc_type') ?: null;
        if ($docType !== null && !in_array($docType, ['tax_invoice', 'credit_note'], true)) {
            throw ApiException::validation('doc_type must be tax_invoice or credit_note.', ['doc_type' => 'Invalid.']);
        }
        $status = $this->query('status') ?: null;
        if ($status !== null && !in_array($status, ['issued', 'partially_credited', 'credited', 'pending_approval', 'rejected'], true)) {
            throw ApiException::validation('Unknown status.', ['status' => 'Invalid.']);
        }
        $cmp = $this->query('cmp_id') ?: null;
        if ($cmp !== null && (!ctype_digit($cmp) || (int) $cmp <= 0)) {
            throw ApiException::validation('cmp_id must be a positive integer.', ['cmp_id' => 'Invalid.']);
        }
        $q = $this->query('q') ?: null;

        return ['fy' => $fy, 'doc_type' => $docType, 'status' => $status, 'cmp_id' => $cmp === null ? null : (int) $cmp, 'q' => $q === null ? null : mb_substr($q, 0, 60)];
    }

    /** @return array{pdf_stored:bool, pdf_sha256:?string, html_sha256:?string, renderer_configured:bool, last_error:?string} */
    protected function rendering(\PDO $pdo, string $docType, int $id): array
    {
        $r = (new DocumentQueries($pdo))->rendering($docType, $id);

        return [
            'pdf_stored'          => $r !== null && $r['pdf'] !== null,
            'pdf_sha256'          => $r['pdf_sha256'] ?? null,
            'html_sha256'         => $r['html_sha256'] ?? null,
            'rendered_at'         => $r['rendered_at'] ?? null,
            'renderer_configured' => GotenbergClient::isConfigured(),
            'last_error'          => $r['last_error'] ?? null,
        ];
    }

    protected function sendStoredPdf(string $docType, int $id): never
    {
        $pdo = Database::pdo();
        $q   = new DocumentQueries($pdo);
        $doc = $docType === 'credit_note' ? $q->creditNote($id) : $q->invoice($id);
        if ($doc === null || $doc['doc_no'] === null) {
            json_not_found('Document not found.');
        }
        $r = $q->rendering($docType, $id);
        if ($r === null || $r['pdf'] === null) {
            throw ApiException::coded(503, 'pdf_unavailable', GotenbergClient::isConfigured()
                ? 'The PDF has not been rendered yet (the PDF service was unavailable); it is retried every 5 minutes.'
                : 'No PDF renderer is configured (GOTENBERG_URL): the document is stored as HTML only.');
        }
        $pdf = (string) $r['pdf'];
        if (!hash_equals((string) $r['pdf_sha256'], hash('sha256', $pdf))) {
            throw new \RuntimeException('Stored PDF checksum mismatch.');
        }
        (new AuditLog($pdo))->record($this->actor(), 'document.pdf_downloaded', $docType === 'credit_note' ? 'credit_note' : 'invoice', $id, null,
            ['document_id' => $doc['document_id'], 'doc_no' => $doc['doc_no'], 'cmp_id' => $doc['cmp_id'], 'pdf_sha256' => $r['pdf_sha256'], 'via' => 'staff']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . InvoiceService::pdfFilename((string) $doc['doc_no']) . '"');
        header('ETag: "' . $r['pdf_sha256'] . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }
}
