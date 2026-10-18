<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\Payouts\PayoutQueries;
use App\Services\Payouts\PayoutService;

/** Payout batches: list, detail, review, approval, bank file, bank response. Gates in Config/Routes.php. */
class PayoutBatchController extends BaseController
{
    public function index(array $params, array $body): never
    {
        json_success((new PayoutQueries(Database::pdo()))->batches($this->query('status')));
    }

    public function show(array $params, array $body): never
    {
        json_success((new PayoutQueries(Database::pdo()))->batch($this->idParam(), $this->actor()['actor'], $this->roles()));
    }

    public function submit(array $params, array $body): never
    {
        (new PayoutService(Database::pdo()))->submitForReview($this->idParam(), $this->actor());
        $this->showFresh('Submitted for review.');
    }

    public function returnToDraft(array $params, array $body): never
    {
        (new PayoutService(Database::pdo()))->returnToDraft($this->idParam(), $body['reason'] ?? null, $this->actor());
        $this->showFresh('Returned to draft.');
    }

    public function recalculate(array $params, array $body): never
    {
        $n = (new PayoutService(Database::pdo()))->recalculate($this->idParam(), $this->actor());
        $this->showFresh($n === 1 ? '1 item recalculated.' : "{$n} items recalculated.");
    }

    public function approve(array $params, array $body): never
    {
        $r = (new PayoutService(Database::pdo()))->approve($this->idParam(), $body['version'] ?? null, $this->actor());
        $this->showFresh("Approved {$r['approved_items']} item(s), net ₹{$r['net_amount']}.");
    }

    public function cancel(array $params, array $body): never
    {
        (new PayoutService(Database::pdo()))->cancel($this->idParam(), $body['reason'] ?? null, $this->actor());
        $this->showFresh('Batch cancelled.');
    }

    public function generateFile(array $params, array $body): never
    {
        $meta = (new PayoutService(Database::pdo()))->generateBankFile($this->idParam(), $body, $this->actor());
        json_success($meta, 'Bank file generated. Download it and upload it to the bank portal.', 201);
    }

    public function downloadFile(array $params, array $body): never
    {
        $file = (new PayoutService(Database::pdo()))->downloadBankFile($this->idParam(), $this->idParam('fileId'), $this->actor());
        $this->sendCsv($file['filename'], $file['content'], $file['sha256']);
    }

    public function bankResponse(array $params, array $body): never
    {
        $dry = !array_key_exists('dry_run', $body) || filter_var($body['dry_run'], FILTER_VALIDATE_BOOLEAN);
        $out = (new PayoutService(Database::pdo()))->importBankResponse($this->idParam(), $body['csv'] ?? null, $dry, $this->actor());
        json_success($out, $dry ? 'Preview only — nothing changed.' : 'Bank response applied.');
    }

    private function showFresh(string $message): never
    {
        json_success((new PayoutQueries(Database::pdo()))->batch($this->idParam(), $this->actor()['actor'], $this->roles()), $message);
    }
}
