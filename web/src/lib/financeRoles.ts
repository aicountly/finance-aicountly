import { createContext, useContext } from 'react'
import type { FinanceUser } from './financeApi'

/**
 * Finance roles are assigned in Console (app_code `finance`) and arrive as
 * `roles` on /v1/auth/me and the console-session response. Mirrors
 * FinanceAccessPolicy::CATALOG / LABELS in server-php.
 */
export type FinanceRole = 'finance_admin' | 'finance_viewer'

export const FINANCE_ROLE_LABELS: Record<FinanceRole, string> = {
  finance_admin: 'Finance Administrator',
  finance_viewer: 'Finance Viewer',
}

export function roleLabel(role: string): string {
  return FINANCE_ROLE_LABELS[role as FinanceRole] ?? role
}

export function hasRole(user: FinanceUser | null, role: FinanceRole | FinanceRole[]): boolean {
  if (!user) return false
  const wanted = Array.isArray(role) ? role : [role]
  return wanted.some((r) => (user.roles ?? []).includes(r))
}

/** Admin-only controls should render only when this is true; finance_viewer is read-only. */
export function isFinanceAdmin(user: FinanceUser | null): boolean {
  return hasRole(user, 'finance_admin')
}

export interface FinanceAccess {
  user: FinanceUser | null
  roles: string[]
  isAdmin: boolean
  hasRole: (role: FinanceRole | FinanceRole[]) => boolean
}

export function financeAccessFor(user: FinanceUser | null): FinanceAccess {
  return {
    user,
    roles: user?.roles ?? [],
    isAdmin: isFinanceAdmin(user),
    hasRole: (role) => hasRole(user, role),
  }
}

export const FinanceAccessContext = createContext<FinanceAccess>(financeAccessFor(null))

/** Current user's Finance roles, for any screen below <App>. */
export function useFinanceAccess(): FinanceAccess {
  return useContext(FinanceAccessContext)
}
