<?php
declare(strict_types=1);

/**
 * Route table for the Finance API.
 *
 * Every entry: 'method' (controller method), 'controller' (relative to
 * app/Controllers, backslashes map to subfolders), 'auth' (require a valid
 * Finance JWT via AuthFilter), and for authenticated routes a 'gate'
 * (FinanceAccessPolicy::GATES — view | edit | approve | settings) checked
 * after AuthFilter. 'inbound' names a service whose key ServiceKeyFilter
 * checks instead of a user JWT.
 *
 * tests/run.php fails if an authenticated route other than v1/auth/me has
 * no gate, so a new route cannot silently be open to every role.
 *
 * 's2s' marks a signed server-to-server route (AIC-S2S v1, Filters/S2sFilter.php): never a user session,
 * never CORS; ['scope' => read|issue, 'clients' => [...], 'cost' => rate-limit units].
 * 'webhook' marks a payment-gateway webhook: no session and no CORS; the controller verifies the provider's signature.
 */
$view     = ['auth' => true, 'gate' => 'view'];
$edit     = ['auth' => true, 'gate' => 'edit'];
$approve  = ['auth' => true, 'gate' => 'approve'];
$settings = ['auth' => true, 'gate' => 'settings'];

$r = static fn (string $controller, string $method, array $opts): array => ['controller' => $controller, 'method' => $method] + $opts;

// Manage → Finance read API (commerce contract §1.2–1.4): signed, read keys only.
$manageRead = static fn (string $method, int $cost = 1): array => [
    'controller' => 'Inbound\\ManageDocumentsController',
    'method'     => $method,
    'auth'       => false,
    's2s'        => ['scope' => 'read', 'clients' => ['manage'], 'cost' => $cost],
];

// Engage → Finance: Aicountly Academy fee payment links (docs/ACADEMY_PAYMENTS.md). Signed, issue keys only.
$engageLinks = static fn (string $method): array => [
    'controller' => 'Inbound\\EngagePaymentLinksController',
    'method'     => $method,
    'auth'       => false,
    's2s'        => ['scope' => 'issue', 'clients' => ['engage'], 'cost' => 1],
];

// Payment-gateway webhooks: no session, no CORS; the provider's signature over the raw body is the authentication.
$webhook = static fn (string $method): array => [
    'controller' => 'Webhooks\\GatewayWebhookController',
    'method'     => $method,
    'auth'       => false,
    'webhook'    => true,
];

return [
    'GET' => [
        // Public readiness flags (booleans only) for post-deploy and live verification.
        'health' => [
            'controller' => 'Ops\\HealthController',
            'method'     => 'index',
            'auth'       => false,
        ],
        'v1/auth/sso-callback' => [
            'controller' => 'Auth\\AuthController',
            'method'     => 'ssoCallback',
            'auth'       => false,
        ],
        'v1/auth/me' => [
            'controller' => 'Auth\\AuthController',
            'method'     => 'me',
            'auth'       => true,
        ],

        'v1/dashboard'                                => $r('Ops\\DashboardController', 'index', $view),
        'v1/payout-batches'                           => $r('Payouts\\PayoutBatchController', 'index', $view),
        'v1/payout-batches/{id}'                      => $r('Payouts\\PayoutBatchController', 'show', $view),
        'v1/payout-batches/{id}/bank-files/{fileId}'  => $r('Payouts\\PayoutBatchController', 'downloadFile', $approve),
        'v1/payees'                                   => $r('Payouts\\PayeeController', 'index', $view),
        'v1/payees/{id}'                              => $r('Payouts\\PayeeController', 'show', $view),
        'v1/tds-register'                             => $r('Payouts\\TdsRegisterController', 'register', $view),
        'v1/tds-register/export'                      => $r('Payouts\\TdsRegisterController', 'export', $edit),
        'v1/settings'                                 => $r('Admin\\SettingsController', 'show', $view),
        'v1/bank-accounts'                            => $r('Admin\\BankAccountController', 'index', $view),
        'v1/audit-log'                                => $r('Admin\\AuditLogController', 'index', $view),
        'v1/outbox'                                   => $r('Admin\\OutboxController', 'index', $view),

        // Subscription invoicing (docs/INVOICING.md). Literal paths before {id} patterns: the first match wins.
        'v1/invoices'                => $r('Invoicing\\InvoiceController', 'index', $view),
        'v1/invoices/export'         => $r('Invoicing\\InvoiceController', 'export', $edit),
        'v1/invoices/{id}'           => $r('Invoicing\\InvoiceController', 'show', $view),
        'v1/invoices/{id}/pdf'       => $r('Invoicing\\InvoiceController', 'pdf', $view),
        'v1/credit-notes'            => $r('Invoicing\\CreditNoteController', 'index', $view),
        'v1/credit-notes/{id}'       => $r('Invoicing\\CreditNoteController', 'show', $view),
        'v1/credit-notes/{id}/pdf'   => $r('Invoicing\\CreditNoteController', 'pdf', $view),
        'v1/offline-payments'        => $r('Invoicing\\OfflinePaymentController', 'index', $view),
        'v1/offline-payments/{id}'   => $r('Invoicing\\OfflinePaymentController', 'show', $view),
        'v1/invoicing/settings'      => $r('Invoicing\\InvoicingSettingsController', 'show', $view),

        // Aicountly Academy fees (docs/ACADEMY_PAYMENTS.md).
        'v1/academy/overview'                => $r('AcademyFees\\AcademyFeesController', 'overview', $view),
        'v1/academy/payment-links'           => $r('AcademyFees\\PaymentLinkController', 'index', $view),
        'v1/academy/payment-links/{id}'      => $r('AcademyFees\\PaymentLinkController', 'show', $view),
        'v1/academy/payments'                => $r('AcademyFees\\AcademyFeesController', 'payments', $view),
        'v1/academy/webhook-events'          => $r('AcademyFees\\AcademyFeesController', 'webhookEvents', $view),
        'v1/academy/engage-events'           => $r('AcademyFees\\AcademyFeesController', 'engageEvents', $view),
        'v1/gateway-connections'             => $r('Admin\\GatewayConnectionController', 'index', $settings),

        'v1/inbound/engage/payment-links/{link_id}'       => $engageLinks('show'),

        'v1/inbound/manage/documents'                     => $manageRead('index'),
        'v1/inbound/manage/documents/{document_id}'       => $manageRead('show'),
        'v1/inbound/manage/documents/{document_id}/pdf'   => $manageRead('pdf', 5),
    ],
    'POST' => [
        'v1/auth/console-session' => [
            'controller' => 'Auth\\AuthController',
            'method'     => 'consoleSession',
            'auth'       => false,
        ],

        // Engage → Finance (X-Engage-Key = FINANCE_INBOUND_ENGAGE_KEY).
        'v1/inbound/engage/payout-batches' => [
            'controller' => 'Inbound\\EngageInboundController',
            'method'     => 'payoutBatches',
            'auth'       => false,
            'inbound'    => 'engage',
        ],

        'v1/inbound/engage/payment-links'                 => $engageLinks('create'),
        'v1/inbound/engage/payment-links/{link_id}/cancel' => $engageLinks('cancel'),

        'v1/webhooks/razorpay' => $webhook('razorpay'),
        'v1/webhooks/cashfree' => $webhook('cashfree'),

        // Academy fees: a maker raises a link (edit), someone else approves it (approve); finance_admin re-queues dead events.
        'v1/academy/payment-links/preview'        => $r('AcademyFees\\PaymentLinkController', 'preview', $edit),
        'v1/academy/payment-links'                => $r('AcademyFees\\PaymentLinkController', 'create', $edit),
        'v1/academy/payment-links/{id}/approve'   => $r('AcademyFees\\PaymentLinkController', 'approve', $approve),
        'v1/academy/payment-links/{id}/reject'    => $r('AcademyFees\\PaymentLinkController', 'reject', $approve),
        'v1/academy/payment-links/{id}/cancel'    => $r('AcademyFees\\PaymentLinkController', 'cancel', $approve),
        'v1/academy/payment-links/{id}/retry'     => $r('AcademyFees\\PaymentLinkController', 'retry', $approve),
        'v1/academy/payment-links/{id}/refresh'   => $r('AcademyFees\\PaymentLinkController', 'refresh', $edit),
        'v1/academy/payments/{id}/retry-invoice'  => $r('AcademyFees\\AcademyFeesController', 'retryInvoice', $approve),
        'v1/academy/engage-events/{id}/requeue'   => $r('AcademyFees\\AcademyFeesController', 'requeueEngageEvent', $settings),

        // Settings → Payment gateways (finance_admin).
        'v1/gateway-connections'               => $r('Admin\\GatewayConnectionController', 'create', $settings),
        'v1/gateway-connections/{id}/test'     => $r('Admin\\GatewayConnectionController', 'test', $settings),
        'v1/gateway-connections/{id}/enable'   => $r('Admin\\GatewayConnectionController', 'enable', $settings),
        'v1/gateway-connections/{id}/disable'  => $r('Admin\\GatewayConnectionController', 'disable', $settings),
        'v1/gateway-connections/{id}/default'  => $r('Admin\\GatewayConnectionController', 'makeDefault', $settings),

        'v1/payout-batches/{id}/submit'        => $r('Payouts\\PayoutBatchController', 'submit', $edit),
        'v1/payout-batches/{id}/return'        => $r('Payouts\\PayoutBatchController', 'returnToDraft', $edit),
        'v1/payout-batches/{id}/recalculate'   => $r('Payouts\\PayoutBatchController', 'recalculate', $edit),
        'v1/payout-batches/{id}/approve'       => $r('Payouts\\PayoutBatchController', 'approve', $approve),
        'v1/payout-batches/{id}/cancel'        => $r('Payouts\\PayoutBatchController', 'cancel', $approve),
        'v1/payout-batches/{id}/bank-file'     => $r('Payouts\\PayoutBatchController', 'generateFile', $approve),
        'v1/payout-batches/{id}/bank-response' => $r('Payouts\\PayoutBatchController', 'bankResponse', $approve),

        'v1/payout-items/{id}/hold'        => $r('Payouts\\PayoutItemController', 'hold', $edit),
        'v1/payout-items/{id}/unhold'      => $r('Payouts\\PayoutItemController', 'unhold', $edit),
        'v1/payout-items/{id}/adjustments' => $r('Payouts\\PayoutItemController', 'adjust', $edit),
        'v1/payout-items/{id}/payment'     => $r('Payouts\\PayoutItemController', 'pay', $approve),
        'v1/payout-items/{id}/fail'        => $r('Payouts\\PayoutItemController', 'fail', $approve),
        'v1/payout-items/{id}/reverse'     => $r('Payouts\\PayoutItemController', 'reverse', $approve),

        'v1/outbox/{id}/requeue' => $r('Admin\\OutboxController', 'requeue', $settings),

        // Company (debit) bank accounts: every role lists them masked; only finance_admin changes them.
        'v1/bank-accounts'                 => $r('Admin\\BankAccountController', 'create', $settings),
        'v1/bank-accounts/{id}/default'    => $r('Admin\\BankAccountController', 'makeDefault', $settings),
        'v1/bank-accounts/{id}/deactivate' => $r('Admin\\BankAccountController', 'deactivate', $settings),
        'v1/bank-accounts/{id}/activate'   => $r('Admin\\BankAccountController', 'activate', $settings),

        // Invoicing: offline payments are recorded by a maker (edit) and verified by someone else (approve);
        // credit notes are requested (edit) and approved by someone else (approve).
        'v1/offline-payments/preview'            => $r('Invoicing\\OfflinePaymentController', 'preview', $edit),
        'v1/offline-payments'                    => $r('Invoicing\\OfflinePaymentController', 'create', $edit),
        'v1/offline-payments/{id}/verify'        => $r('Invoicing\\OfflinePaymentController', 'verify', $approve),
        'v1/offline-payments/{id}/reject'        => $r('Invoicing\\OfflinePaymentController', 'reject', $approve),
        'v1/offline-payments/{id}/retry-invoice' => $r('Invoicing\\OfflinePaymentController', 'retry', $approve),
        'v1/invoices/{id}/credit-notes'          => $r('Invoicing\\InvoiceController', 'requestCreditNote', $edit),
        'v1/credit-notes/{id}/approve'           => $r('Invoicing\\CreditNoteController', 'approve', $approve),
        'v1/credit-notes/{id}/reject'            => $r('Invoicing\\CreditNoteController', 'reject', $approve),
        'v1/invoicing/series'                    => $r('Invoicing\\InvoicingSettingsController', 'createSeries', $settings),
    ],
    'PUT' => [
        'v1/settings'           => $r('Admin\\SettingsController', 'update', $settings),
        'v1/bank-accounts/{id}' => $r('Admin\\BankAccountController', 'update', $settings),

        'v1/invoicing/seller-profile' => $r('Invoicing\\InvoicingSettingsController', 'saveProfile', $settings),
        'v1/invoicing/series/{id}'    => $r('Invoicing\\InvoicingSettingsController', 'updateSeries', $settings),

        'v1/gateway-connections/{id}' => $r('Admin\\GatewayConnectionController', 'update', $settings),
    ],
];
