import './App.css'
import { useFinanceAuth } from './hooks/useFinanceAuth'
import { SignInPrompt } from './components/SignInPrompt'
import { Dashboard } from './components/Dashboard'
import { FinanceAccessContext, financeAccessFor } from './lib/financeRoles'

function App() {
  const { user, loading, redirecting, error, signOut } = useFinanceAuth()

  if (redirecting) {
    return (
      <main className="auth-screen">
        <p className="muted">Redirecting to Console…</p>
      </main>
    )
  }

  if (loading) {
    return (
      <main className="auth-screen">
        <p className="muted">Signing you in…</p>
      </main>
    )
  }

  if (!user) {
    return <SignInPrompt error={error} />
  }

  return (
    <FinanceAccessContext.Provider value={financeAccessFor(user)}>
      <Dashboard user={user} onSignOut={signOut} />
    </FinanceAccessContext.Provider>
  )
}

export default App
