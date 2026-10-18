<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\FinanceAccessPolicy;
use App\Services\Invoicing\GotenbergClient;
use App\Services\Invoicing\SellerProfileService;
use App\Support\FiscalYear;
use App\Support\Gstin;

/**
 * Settings → Invoicing: the seller profile and the document series (finance_admin changes them; every role reads).
 *
 *   GET  v1/invoicing/settings              view
 *   PUT  v1/invoicing/seller-profile        settings   saves a new profile (the previous one is retired, never edited)
 *   POST v1/invoicing/series                settings   {fy, doc_type, prefix, width, next_no}
 *   PUT  v1/invoicing/series/{id}           settings   only while no document is numbered from it
 */
class InvoicingSettingsController extends BaseController
{
    public function show(array $params, array $body): never
    {
        $svc = new SellerProfileService(Database::pdo());
        json_success([
            'profile'   => $svc->active(),
            'history'   => $svc->history(),
            'series'    => $svc->series(),
            'readiness' => $svc->readiness() + ['pdf_renderer' => GotenbergClient::isConfigured(), 'manage_inbound' => \S2sFilter::isConfigured(['manage'])],
            'states'    => Gstin::STATES,
            'current_fy' => FiscalYear::current(),
            'can_edit'  => FinanceAccessPolicy::allows($this->roles(), 'settings'),
        ]);
    }

    public function saveProfile(array $params, array $body): never
    {
        json_success((new SellerProfileService(Database::pdo()))->save($body, $this->actor()), 'Seller profile saved. New documents use it.');
    }

    public function createSeries(array $params, array $body): never
    {
        json_success((new SellerProfileService(Database::pdo()))->createSeries($body, $this->actor()), 'Series created.', 201);
    }

    public function updateSeries(array $params, array $body): never
    {
        json_success((new SellerProfileService(Database::pdo()))->updateSeries($this->idParam(), $body, $this->actor()), 'Series saved.');
    }
}
