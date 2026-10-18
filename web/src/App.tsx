import './App.css'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import { useFinanceAuth } from './hooks/useFinanceAuth'
import { SignInPrompt } from './components/SignInPrompt'
import { Layout } from './components/Layout'
import { FinanceAccessContext, financeAccessFor } from './lib/financeRoles'
import { DashboardPage } from './pages/DashboardPage'
import { PayoutsPage } from './pages/PayoutsPage'
import { BatchPage } from './pages/BatchPage'
import { PayeesPage } from './pages/PayeesPage'
import { PayeePage } from './pages/PayeePage'
import { TdsRegisterPage } from './pages/TdsRegisterPage'
import { SettingsPage } from './pages/SettingsPage'
import { AuditLogPage } from './pages/AuditLogPage'
import { InvoicesPage } from './pages/InvoicesPage'
import { InvoicePage } from './pages/InvoicePage'
import { CreditNotePage } from './pages/CreditNotePage'
import { OfflinePaymentsPage } from './pages/OfflinePaymentsPage'

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
      <BrowserRouter>
        <Routes>
          <Route element={<Layout user={user} onSignOut={signOut} />}>
            <Route index element={<DashboardPage />} />
            <Route path="payouts" element={<PayoutsPage />} />
            <Route path="payouts/:id" element={<BatchPage />} />
            <Route path="payees" element={<PayeesPage />} />
            <Route path="payees/:id" element={<PayeePage />} />
            <Route path="tds" element={<TdsRegisterPage />} />
            <Route path="invoices" element={<InvoicesPage />} />
            <Route path="invoices/:id" element={<InvoicePage />} />
            <Route path="credit-notes/:id" element={<CreditNotePage />} />
            <Route path="offline-payments" element={<OfflinePaymentsPage />} />
            <Route path="settings" element={<SettingsPage />} />
            <Route path="audit" element={<AuditLogPage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </FinanceAccessContext.Provider>
  )
}

export default App
