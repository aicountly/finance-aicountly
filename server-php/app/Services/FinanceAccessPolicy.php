<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Pure rules for turning Console's answer into Finance roles (no I/O, so
 * tests/run.php can exercise every branch).
 *
 * Console assigns roles per app. Finance's catalog (app_code `finance`,
 * Console levels in brackets):
 *   finance_admin    Finance Administrator (100)
 *   payout_approver  Payout Approver (70)
 *   accountant       Accountant (50)
 *   finance_viewer   Finance Viewer (10)
 * A Console superadmin (`app_superadmin`) holds the whole catalog. There is no
 * default role: a user Console has not assigned does not get in.
 *
 * Route gates (Config/Routes.php `gate`):
 *   view      every Finance role — dashboards, batches, payees (masked), TDS register, audit log
 *   edit      accountant, payout_approver, finance_admin — review drafts: submit, hold/unhold,
 *             adjust, recalculate; export the TDS register (full PAN)
 *   approve   payout_approver, finance_admin — approve / cancel a batch, generate and download the
 *             bank file, record payment results (UTR, failures, reversals)
 *   settings  finance_admin — payout settings, re-queue dead Engage callbacks
 */
final class FinanceAccessPolicy
{
    public const CATALOG = ['finance_admin', 'payout_approver', 'accountant', 'finance_viewer'];

    public const LABELS = [
        'finance_admin'   => 'Finance Administrator',
        'payout_approver' => 'Payout Approver',
        'accountant'      => 'Accountant',
        'finance_viewer'  => 'Finance Viewer',
    ];

    public const GATES = [
        'view'     => ['finance_admin', 'payout_approver', 'accountant', 'finance_viewer'],
        'edit'     => ['finance_admin', 'payout_approver', 'accountant'],
        'approve'  => ['finance_admin', 'payout_approver'],
        'settings' => ['finance_admin'],
    ];

    public const MSG_ROLE_FORBIDDEN = 'Your Finance role does not allow this.';

    /**
     * True when one of $roles opens $gate. An unknown gate opens for nobody.
     *
     * @param array<mixed> $roles
     */
    public static function allows(array $roles, string $gate): bool
    {
        $allowed = self::GATES[$gate] ?? [];
        foreach ($roles as $role) {
            if (is_string($role) && in_array($role, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every gate the roles open — the SPA uses it to show only the actions the server will accept.
     *
     * @param array<mixed> $roles
     * @return list<string>
     */
    public static function gatesFor(array $roles): array
    {
        return array_values(array_filter(array_keys(self::GATES), static fn (string $g) => self::allows($roles, $g)));
    }

    public const MSG_NOT_ASSIGNED = 'Access not assigned. Ask a Console superadmin to assign you a role.';

    /** How long a token's roles are trusted while Console cannot be asked. */
    public const OFFLINE_GRACE_SECONDS = 12 * 3600;

    /**
     * Roles for a sign-in, from an SSO exchange / introspect payload.
     *
     * @param array<string,mixed> $identity
     * @return array{allowed:bool, roles:list<string>, app_superadmin:bool, source:string}
     */
    public static function rolesFromIdentity(array $identity): array
    {
        $active        = !empty($identity['active']);
        $global        = !empty($identity['global_superadmin']);
        $appSuperadmin = !empty($identity['app_superadmin']);

        if (array_key_exists('app_roles', $identity) && is_array($identity['app_roles'])) {
            $roles   = self::normalizeRoles($identity['app_roles'], $appSuperadmin);
            $allowed = ($active || $global || $appSuperadmin) && $roles !== [];

            return [
                'allowed'        => $allowed,
                'roles'          => $allowed ? $roles : [],
                'app_superadmin' => $allowed && $appSuperadmin,
                'source'         => 'app_roles',
            ];
        }

        // Console without per-app roles (no `app_roles` key): only a Console
        // global superadmin may in, holding every Finance role.
        return [
            'allowed'        => $global,
            'roles'          => $global ? self::CATALOG : [],
            'app_superadmin' => $global,
            'source'         => 'legacy_global_superadmin',
        ];
    }

    /**
     * Roles from Console's user-access answer, or null when access is revoked.
     *
     * @param array<string,mixed> $data
     * @return list<string>|null
     */
    public static function rolesFromUserAccess(array $data): ?array
    {
        $appSuperadmin = !empty($data['app_superadmin']);

        if (empty($data['exists']) || empty($data['active'])) {
            return null;
        }
        if (empty($data['app_access']) && !$appSuperadmin) {
            return null;
        }

        $roles = self::normalizeRoles(is_array($data['app_roles'] ?? null) ? $data['app_roles'] : [], $appSuperadmin);

        return $roles === [] ? null : $roles;
    }

    /**
     * Keep catalog roles only (unknown slugs grant nothing); an app
     * superadmin holds the whole catalog.
     *
     * @param array<mixed> $raw
     * @return list<string>
     */
    public static function normalizeRoles(array $raw, bool $appSuperadmin): array
    {
        if ($appSuperadmin) {
            return self::CATALOG;
        }

        $roles = [];
        foreach ($raw as $slug) {
            if (!is_string($slug)) {
                continue;
            }
            $slug = strtolower(trim($slug));
            if (in_array($slug, self::CATALOG, true) && !in_array($slug, $roles, true)) {
                $roles[] = $slug;
            }
        }

        return $roles;
    }

    public static function withinOfflineGrace(int $issuedAt, int $now): bool
    {
        return $issuedAt > 0 && ($now - $issuedAt) < self::OFFLINE_GRACE_SECONDS;
    }
}
