<?php
declare(strict_types=1);

require_once __DIR__ . '/InvoiceController.php';

use App\Database\Database;
use App\Services\FinanceAccessPolicy;
use App\Services\Invoicing\CreditNoteService;
use App\Services\Invoicing\DocumentQueries;

/**
 * Staff: credit notes (four-eyes).
 *
 *   GET  v1/credit-notes?status=pending_approval     view      pending (or issued / rejected) credit notes
 *   GET  v1/credit-notes/{id}                        view      detail
 *   GET  v1/credit-notes/{id}/pdf                    view      the PDF stored at issue (audited)
 *   POST v1/credit-notes/{id}/approve                approve   issue it (not by whoever requested it)
 *   POST v1/credit-notes/{id}/reject                 approve   with a reason
 */
class CreditNoteController extends InvoiceController
{
    public function index(array $params, array $body): never
    {
        $status = $this->query('status') ?: 'pending_approval';
        if (!in_array($status, ['pending_approval', 'issued', 'rejected'], true)) {
            json_error('Unknown status.', ['status' => 'Invalid.'], 422);
        }
        $stmt = Database::pdo()->prepare('SELECT id FROM fin_credit_notes WHERE status = ? ORDER BY id DESC LIMIT 200');
        $stmt->execute([$status]);
        $q = new DocumentQueries(Database::pdo());
        json_success(array_map(fn (array $r) => $this->withCan($q->creditNote((int) $r['id'])), $stmt->fetchAll()));
    }

    public function show(array $params, array $body): never
    {
        $pdo = Database::pdo();
        $cn  = (new DocumentQueries($pdo))->creditNote($this->idParam());
        if ($cn === null) {
            json_not_found('Credit note not found.');
        }
        json_success($this->withCan($cn) + ['rendering' => $this->rendering($pdo, 'credit_note', (int) $cn['id'])]);
    }

    public function pdf(array $params, array $body): never
    {
        $this->sendStoredPdf('credit_note', $this->idParam());
    }

    public function approve(array $params, array $body): never
    {
        $cn = (new CreditNoteService(Database::pdo()))->approve($this->idParam(), $this->actor());
        json_success($this->withCan($cn), "Credit note {$cn['doc_no']} issued.");
    }

    public function reject(array $params, array $body): never
    {
        $cn = (new CreditNoteService(Database::pdo()))->reject($this->idParam(), $body['reason'] ?? null, $this->actor());
        json_success($this->withCan($cn), 'Credit note rejected.');
    }

    /**
     * @param array<string,mixed> $cn
     * @return array<string,mixed>
     */
    private function withCan(array $cn): array
    {
        $pending = $cn['status'] === 'pending_approval';
        $mine    = strtolower((string) $cn['requested_by']) === strtolower($this->actor()['actor']);
        $approve = FinanceAccessPolicy::allows($this->roles(), 'approve');

        return $cn + ['can' => [
            'approve'        => $pending && $approve && !$mine,
            'reject'         => $pending && $approve,
            'blocked_reason' => $pending && $approve && $mine ? 'Four-eyes rule: you requested this credit note, so someone else must approve it.' : null,
        ]];
    }
}
