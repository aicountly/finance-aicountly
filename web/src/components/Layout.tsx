import { NavLink, Outlet } from 'react-router'
import type { FinanceUser } from '../lib/financeApi'
import { roleLabel as financeRoleLabel, useFinanceAccess } from '../lib/financeRoles'

function initials(user: FinanceUser): string {
  const source = user.name?.trim() || user.email
  return source
    .split(/\s+/)
    .map((part) => part[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase()
}

/** The Finance role(s) Console assigned — e.g. "Payout Approver". */
export function userRoleLabel(user: FinanceUser): string {
  const labels = (user.roles ?? []).map(financeRoleLabel)
  if (labels.length > 0) return labels.join(' · ')
  return user.global_superadmin ? 'Super Admin' : 'No Finance role'
}

function displayName(user: FinanceUser): string {
  return user.name?.trim() || user.email
}

const NAV = [
  { to: '/', label: 'Dashboard', end: true },
  { to: '/payouts', label: 'Payouts' },
  { to: '/payees', label: 'Payees' },
  { to: '/tds', label: 'TDS register' },
  { to: '/settings', label: 'Settings' },
  { to: '/audit', label: 'Audit log' },
]

export function Layout({ user, onSignOut }: { user: FinanceUser; onSignOut: () => void }) {
  const { can } = useFinanceAccess()

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">Finance</div>
        <nav className="nav">
          {NAV.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => `nav-item${isActive ? ' nav-item-active' : ''}`}>
              {item.label}
            </NavLink>
          ))}
        </nav>
        <div className="sidebar-user">
          <div className="sidebar-user-email">{user.email}</div>
          <div className="sidebar-user-role">{userRoleLabel(user)}</div>
          {!can('edit') ? <div className="sidebar-user-note">Read-only access</div> : null}
        </div>
      </aside>

      <div className="main">
        <header className="topbar">
          <div className="topbar-title">Seller payouts</div>
          <div className="topbar-actions">
            <div className="user-chip">
              <span className="avatar">{initials(user)}</span>
              <span className="user-chip-text">
                <span className="user-chip-name">{displayName(user)}</span>
                <span className="user-chip-role">{userRoleLabel(user)}</span>
              </span>
            </div>
            <button type="button" className="button button-outline" onClick={onSignOut}>
              Logout
            </button>
          </div>
        </header>

        <main className="content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
