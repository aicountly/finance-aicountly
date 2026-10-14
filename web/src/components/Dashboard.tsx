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

/** The Finance role(s) Console assigned — e.g. "Finance Viewer". */
function roleLabel(user: FinanceUser): string {
  const labels = (user.roles ?? []).map(financeRoleLabel)
  if (labels.length > 0) return labels.join(' · ')
  return user.global_superadmin ? 'Super Admin' : 'No Finance role'
}

function displayName(user: FinanceUser): string {
  return user.name?.trim() || user.email
}

export function Dashboard({ user, onSignOut }: { user: FinanceUser; onSignOut: () => void }) {
  const { isAdmin } = useFinanceAccess()

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">Finance</div>
        <nav className="nav">
          <span className="nav-item nav-item-active">Dashboard</span>
        </nav>
        <div className="sidebar-user">
          <div className="sidebar-user-email">{user.email}</div>
          <div className="sidebar-user-role">{roleLabel(user)}</div>
        </div>
      </aside>

      <div className="main">
        <header className="topbar">
          <div className="topbar-title">Finance</div>
          <div className="topbar-actions">
            <div className="user-chip">
              <span className="avatar">{initials(user)}</span>
              <span className="user-chip-text">
                <span className="user-chip-name">{displayName(user)}</span>
                <span className="user-chip-role">{roleLabel(user)}</span>
              </span>
            </div>
            <button type="button" className="button button-outline" onClick={onSignOut}>
              Logout
            </button>
          </div>
        </header>

        <main className="content">
          <h1>Finance Dashboard</h1>
          <p className="tagline">Signed in via Console — no separate Finance login required.</p>

          <div className="card">
            <p>
              You're signed in as <strong>{displayName(user)}</strong>
              {user.name ? ` (${user.email})` : ''}.
            </p>
            <p>
              Your Finance role: <strong>{roleLabel(user)}</strong>
              {isAdmin ? '' : ' (read-only)'}.
            </p>
            <p className="muted">Finance reporting modules will appear here.</p>
          </div>
        </main>
      </div>
    </div>
  )
}
