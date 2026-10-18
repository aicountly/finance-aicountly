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
 */
$view     = ['auth' => true, 'gate' => 'view'];
$edit     = ['auth' => true, 'gate' => 'edit'];
$approve  = ['auth' => true, 'gate' => 'approve'];
$settings = ['auth' => true, 'gate' => 'settings'];

$r = static fn (string $controller, string $method, array $opts): array => ['controller' => $controller, 'method' => $method] + $opts;

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
    ],
    'PUT' => [
        'v1/settings'           => $r('Admin\\SettingsController', 'update', $settings),
        'v1/bank-accounts/{id}' => $r('Admin\\BankAccountController', 'update', $settings),
    ],
];
