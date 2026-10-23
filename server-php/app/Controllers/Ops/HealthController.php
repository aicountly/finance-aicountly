<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';
require_once dirname(__DIR__, 2) . '/Services/ConsoleIdentityService.php';
require_once dirname(__DIR__, 2) . '/Filters/ServiceKeyFilter.php';
require_once dirname(__DIR__, 2) . '/Filters/S2sFilter.php';

use App\Database\Database;
use App\Services\ConsoleIdentityService;
use App\Services\DataVault;
use App\Services\Invoicing\GotenbergClient;
use App\Services\Invoicing\SellerProfileService;
use App\Services\Payouts\OutboxService;

/**
 * GET /api/health — public readiness for post-deploy and live verification
 * (scripts/ci/live-feature-checks.py). Booleans only: no URL, key, version or count.
 *
 *   200 {"success":true,"data":{"app":"Finance","status":"ok"|"misconfigured","checks":{
 *         "jwt_secret": bool,           JWT_SECRET is usable (every Finance session is signed with it)
 *         "console_role_sync": bool,    CONSOLE_API_URL + CONSOLE_API_TOKEN are set, so every request
 *                                       re-checks the user's Finance roles with Console (production must)
 *         "database": bool,             FINANCE_DB_* reach PostgreSQL and the payout schema is migrated
 *         "data_key": bool,             FINANCE_DATA_KEY is a usable 32-byte key (PAN / bank encryption)
 *         "engage_inbound": bool,       FINANCE_INBOUND_ENGAGE_KEY is set (≥ 32 chars): Engage can send batches
 *         "engage_callbacks": bool      ENGAGE_API_BASE_URL + ENGAGE_FINANCE_KEY are set: status callbacks go out
 *       },
 *       "payouts": {"money_movement": "manual_bank_upload"},
 *       "invoicing": {"status": "dormant"|"ready"|"incomplete", "checks": {
 *         "schema": bool,               migration 003 applied (fin_invoices exists)
 *         "seller_profile": bool,       a seller profile is saved (Settings → Invoicing)
 *         "series_current_fy": bool,    tax_invoice and credit_note series exist for the current FY
 *         "pdf_renderer": bool,         GOTENBERG_URL is set (else documents are stored as HTML and the PDF routes answer 503)
 *         "manage_inbound": bool,       at least one FINANCE_INBOUND_MANAGE_SECRET_<KEYID> (≥ 32 bytes): Manage can read documents
 *         "issuance_blocked": bool,     true = a verified payment is waiting for its invoice (blocked_config / blocked_data)
 *         "pdfs_pending": bool          true = an issued document has no stored PDF yet
 *       }}}}
 *
 * Invoicing is dormant until configured and never changes the top-level "status", which stays the payout readiness.
 *
 *       "academy_fees": {"status": "dormant"|"ready"|"incomplete", "checks": {schema, gateway_connection, webhook_secret,
 *         live_mode, engage_inbound, engage_events, verification_pending, invoice_blocked, engage_events_dead}}
 *       (Aicountly Academy fee links, docs/ACADEMY_PAYMENTS.md; never changes the top-level status either)
 *
 * Finance never moves money out itself: payouts are released by a person uploading the generated bank file. Academy
 * fees come in through payment links on Aicountly's own Razorpay / Cashfree accounts.
 */
class HealthController extends BaseController
{
    public function index(array $params, array $body): never
    {
        $secret = (string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '');
        $checks = [
            'jwt_secret'        => strlen(trim($secret)) >= 32,
            'console_role_sync' => (new ConsoleIdentityService())->isUserAccessConfigured(),
            'database'          => Database::isReady(),
            'data_key'          => DataVault::isConfigured(),
            'engage_inbound'    => ServiceKeyFilter::isConfigured('engage'),
            'engage_callbacks'  => OutboxService::isConfigured(),
        ];
        header('Cache-Control: no-store');
        json_success([
            'app'       => 'Finance',
            'status'    => in_array(false, $checks, true) ? 'misconfigured' : 'ok',
            'checks'    => $checks,
            'payouts'   => ['money_movement' => 'manual_bank_upload'],
            'invoicing' => self::invoicing($checks['database']),
            'academy_fees' => self::academyFees($checks['database']),
        ]);
    }

    /**
     * Aicountly Academy fees (docs/ACADEMY_PAYMENTS.md): dormant until a gateway connection is enabled.
     *
     * @return array{status:string, checks:array<string,bool>}
     */
    private static function academyFees(bool $database): array
    {
        $c = [
            'schema'               => false,
            'gateway_connection'   => false,   // an enabled default connection exists
            'webhook_secret'       => false,   // that connection can verify webhooks (Razorpay: webhook secret saved; Cashfree: client secret)
            'live_mode'            => false,   // the default connection is a live one
            'engage_inbound'       => S2sFilter::isConfigured(['engage']),
            'engage_events'        => \App\Services\AcademyFees\EngagePaymentEventOutbox::isConfigured(),
            'verification_pending' => false,   // alert: a reported payment is not confirmed with the provider yet (older than 15 min) or did not match
            'invoice_blocked'      => false,   // alert: a verified fee payment has no invoice
            'engage_events_dead'   => false,   // alert: an event to Engage gave up (Settings → Academy fees → Engage callbacks)
        ];
        if ($database) {
            try {
                $pdo = Database::pdo();
                $c['schema'] = (bool) $pdo->query("SELECT to_regclass('public.fin_payment_links') IS NOT NULL AS ok")->fetch()['ok'];
                if ($c['schema']) {
                    $d = $pdo->query('SELECT provider, mode, webhook_secret_enc IS NOT NULL AS hook FROM fin_gateway_connections WHERE enabled AND is_default')->fetch();
                    $c['gateway_connection']   = (bool) $d;
                    $c['webhook_secret']       = $d && ($d['hook'] || $d['provider'] === 'cashfree');
                    $c['live_mode']            = $d && $d['mode'] === 'live';
                    $c['verification_pending'] = (bool) $pdo->query("SELECT 1 FROM fin_gateway_payments WHERE verification_status = 'mismatch'
                        OR (verification_status = 'pending' AND created_at < now() - interval '15 minutes') LIMIT 1")->fetch();
                    $c['invoice_blocked']      = (bool) $pdo->query("SELECT 1 FROM fin_gateway_payments WHERE verification_status = 'verified'
                        AND invoice_state IN ('blocked_config','blocked_data') LIMIT 1")->fetch();
                    $c['engage_events_dead']   = (bool) $pdo->query("SELECT 1 FROM fin_engage_payment_events WHERE status = 'dead' LIMIT 1")->fetch();
                }
            } catch (\Throwable) {
                // Stays false: health never fails because Academy fees cannot be read.
            }
        }
        $ready  = $c['schema'] && $c['gateway_connection'] && $c['webhook_secret'] && $c['engage_inbound'] && $c['engage_events']
            && !$c['verification_pending'] && !$c['invoice_blocked'] && !$c['engage_events_dead'];
        $status = $ready ? 'ready' : (!$c['gateway_connection'] && !$c['engage_inbound'] ? 'dormant' : 'incomplete');

        return ['status' => $status, 'checks' => $c];
    }

    /** @return array{status:string, checks:array<string,bool>} */
    private static function invoicing(bool $database): array
    {
        $c = [
            'schema'            => false,
            'seller_profile'    => false,
            'series_current_fy' => false,
            'pdf_renderer'      => GotenbergClient::isConfigured(),
            'manage_inbound'    => S2sFilter::isConfigured(['manage']),
            'issuance_blocked'  => false,
            'pdfs_pending'      => false,
        ];
        if ($database) {
            try {
                $pdo = Database::pdo();
                $c['schema'] = (bool) $pdo->query("SELECT to_regclass('public.fin_invoices') IS NOT NULL AS ok")->fetch()['ok'];
                if ($c['schema']) {
                    $r = (new SellerProfileService($pdo))->readiness();
                    $c['seller_profile']    = $r['seller_profile'];
                    $c['series_current_fy'] = $r['series']['tax_invoice'] && $r['series']['credit_note'];
                    $c['issuance_blocked']  = (bool) $pdo->query("SELECT 1 FROM fin_offline_payments WHERE status = 'verified' AND invoice_state IN ('blocked_config','blocked_data') LIMIT 1")->fetch();
                    $c['pdfs_pending']      = (bool) $pdo->query('SELECT 1 FROM fin_doc_pdfs WHERE pdf IS NULL LIMIT 1')->fetch();
                }
            } catch (\Throwable) {
                // Stays false: health never fails because invoicing cannot be read.
            }
        }
        $ready = $c['schema'] && $c['seller_profile'] && $c['series_current_fy'] && $c['pdf_renderer'] && $c['manage_inbound']
            && !$c['issuance_blocked'] && !$c['pdfs_pending'];
        $status = $ready ? 'ready' : (!$c['seller_profile'] && !$c['manage_inbound'] ? 'dormant' : 'incomplete');

        return ['status' => $status, 'checks' => $c];
    }
}
