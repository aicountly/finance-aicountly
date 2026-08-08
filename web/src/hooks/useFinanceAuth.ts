import { useCallback, useEffect, useState } from 'react'
import { fetchConsoleSession, fetchMe, type FinanceUser } from '../lib/financeApi'
import { redirectToConsoleLoginAfterSignOut } from '../lib/consoleAuth'

const TOKEN_KEY = 'finance_token'

function stripAuthParamsFromUrl(): void {
  const url = new URL(window.location.href)
  url.searchParams.delete('token')
  url.searchParams.delete('sso_error')
  window.history.replaceState({}, '', url.toString())
}

/**
 * Finance has no local login form. On mount this either:
 *  1. Picks up the one-time token the sso-callback redirect left in the URL
 *     (clicked from Console's App Launcher), or
 *  2. Reuses a previously stored Finance token, or
 *  3. Silently exchanges the shared Console cookie for a Finance token
 *     (direct visit while already signed in to Console).
 * If none of those produce a session, `user` stays null and the caller
 * should show a "Sign in via Console" prompt.
 */
export function useFinanceAuth() {
  const [user, setUser] = useState<FinanceUser | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false

    async function bootstrap() {
      const url = new URL(window.location.href)
      const callbackToken = url.searchParams.get('token')
      const ssoError = url.searchParams.get('sso_error')

      if (ssoError) {
        stripAuthParamsFromUrl()
        if (!cancelled) {
          setError('Could not sign you in via Console. Ask an admin to check your Finance access grant.')
          setLoading(false)
        }
        return
      }

      if (callbackToken) {
        stripAuthParamsFromUrl()
        localStorage.setItem(TOKEN_KEY, callbackToken)
      }

      const token = localStorage.getItem(TOKEN_KEY)
      if (token) {
        try {
          const me = await fetchMe(token)
          if (!cancelled) {
            setUser(me)
            setLoading(false)
          }
          return
        } catch {
          localStorage.removeItem(TOKEN_KEY)
        }
      }

      try {
        const session = await fetchConsoleSession()
        if (session.active && session.token && session.user) {
          localStorage.setItem(TOKEN_KEY, session.token)
          if (!cancelled) {
            setUser(session.user)
          }
        }
      } catch {
        /* Console unreachable — fall through to the sign-in prompt */
      } finally {
        if (!cancelled) {
          setLoading(false)
        }
      }
    }

    bootstrap()
    return () => {
      cancelled = true
    }
  }, [])

  const signOut = useCallback(async () => {
    localStorage.removeItem(TOKEN_KEY)
    setUser(null)
    await redirectToConsoleLoginAfterSignOut()
  }, [])

  return { user, loading, error, signOut }
}
