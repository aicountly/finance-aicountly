import { createContext, useContext } from 'react'
import type { FinanceUser } from './financeApi'

/**
 * Finance roles are assigned in Console (app_code `finance`) and arrive as
 * `roles` on /v1/auth/me and the console-session response. Mirrors
 * FinanceAccessPolicy::CATALOG / LABELS / GATES in server-php — the server
 * enforces every gate; this only decides which controls to show.
 */
export type FinanceRole = 'finance_admin' | 'payout_approver' | 'accountant' | 'finance_viewer'

export const FINANCE_ROLE_LABELS: Record<FinanceRole, string> = {
  finance_admin: 'Finance Administrator',
  payout_approver: 'Payout Approver',
  accountant: 'Accountant',
  finance_viewer: 'Finance Viewer',
}

/**
 * view     every role — dashboards, batches, payees (masked), TDS register, audit log
 * edit     review drafts: submit, hold/unhold, adjust, recalculate; export TDS (full PAN)
 * approve  approve / cancel batches, bank file, record payment results
 * settings payout settings, company bank accounts, re-queue dead Engage callbacks
 */
export type FinanceGate = 'view' | 'edit' | 'approve' | 'settings'

export const FINANCE_GATES: Record<FinanceGate, FinanceRole[]> = {
  view: ['finance_admin', 'payout_approver', 'accountant', 'finance_viewer'],
  edit: ['finance_admin', 'payout_approver', 'accountant'],
  approve: ['finance_admin', 'payout_approver'],
  settings: ['finance_admin'],
}

export function roleLabel(role: string): string {
  return FINANCE_ROLE_LABELS[role as FinanceRole] ?? role
}

export function hasRole(user: FinanceUser | null, role: FinanceRole | FinanceRole[]): boolean {
  if (!user) return false
  const wanted = Array.isArray(role) ? role : [role]
  return wanted.some((r) => (user.roles ?? []).includes(r))
}

export function canGate(user: FinanceUser | null, gate: FinanceGate): boolean {
  return hasRole(user, FINANCE_GATES[gate])
}

/** Admin-only controls should render only when this is true. */
export function isFinanceAdmin(user: FinanceUser | null): boolean {
  return hasRole(user, 'finance_admin')
}

export interface FinanceAccess {
  user: FinanceUser | null
  roles: string[]
  isAdmin: boolean
  hasRole: (role: FinanceRole | FinanceRole[]) => boolean
  can: (gate: FinanceGate) => boolean
}

export function financeAccessFor(user: FinanceUser | null): FinanceAccess {
  return {
    user,
    roles: user?.roles ?? [],
    isAdmin: isFinanceAdmin(user),
    hasRole: (role) => hasRole(user, role),
    can: (gate) => canGate(user, gate),
  }
}

export const FinanceAccessContext = createContext<FinanceAccess>(financeAccessFor(null))

/** Current user's Finance roles, for any screen below <App>. */
export function useFinanceAccess(): FinanceAccess {
  return useContext(FinanceAccessContext)
}
