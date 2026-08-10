import './App.css'
import { useFinanceAuth } from './hooks/useFinanceAuth'
import { SignInPrompt } from './components/SignInPrompt'
import { Dashboard } from './components/Dashboard'

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

  return <Dashboard user={user} onSignOut={signOut} />
}

export default App
