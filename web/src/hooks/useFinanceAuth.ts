import { useCallback, useEffect, useState } from 'react'
import {
  TOKEN_KEY,
  fetchConsoleSession,
  fetchMe,
  type ConsoleSessionReason,
  type FinanceUser,
} from '../lib/financeApi'
import { redirectToConsoleLogin, redirectToConsoleLoginAfterSignOut } from '../lib/consoleAuth'


/**
 * Timestamp of the last hand-off to Console's login form, cleared as soon as
 * a Finance session exists. Coming back still signed out within the window
 * below means the round trip did not work, so we show the sign-in prompt
 * instead of bouncing again — otherwise a Console that cannot complete the
 * login would ping-pong the user between the two domains forever. Outside
 * the window a fresh visit is treated as a fresh attempt.
 */
const REDIRECT_GUARD_KEY = 'finance.console_login_attempted_at'
const REDIRECT_GUARD_WINDOW_MS = 60_000

function stripAuthParamsFromUrl(): void {
  const url = new URL(window.location.href)
  url.searchParams.delete('token')
  url.searchParams.delete('sso_error')
  window.history.replaceState({}, '', url.toString())
}

/** True when we already sent this browser to Console moments ago. */
function readRedirectGuard(): boolean {
  try {
    const at = Number(sessionStorage.getItem(REDIRECT_GUARD_KEY))
    return Number.isFinite(at) && at > 0 && Date.now() - at < REDIRECT_GUARD_WINDOW_MS
  } catch {
    // Private-mode / storage-disabled browsers: treat as "already tried" so
    // we prefer a visible prompt over a possible redirect loop.
    return true
  }
}

function writeRedirectGuard(value: boolean): void {
  try {
    if (value) {
      sessionStorage.setItem(REDIRECT_GUARD_KEY, String(Date.now()))
    } else {
      sessionStorage.removeItem(REDIRECT_GUARD_KEY)
    }
  } catch {
    /* storage unavailable — the read side already fails closed */
  }
}

// Mirrors AuthController::classifyExchangeFailure() on the server-php side —
// keep both in sync when adding a new sso_error reason.
const SSO_ERROR_MESSAGES: Record<string, string> = {
  missing_token: 'Console did not send a sign-in token. Open Finance from the Console App Launcher instead of visiting this link directly.',
  invalid_or_expired_token: 'Your Console sign-in link expired before it was used. Go back to Console and click Finance again.',
  no_finance_access: 'Your Console account does not have Finance access. Ask a Console admin to grant it under Access Management → Controller App Access.',
  console_unreachable: 'Could not reach Console to verify your sign-in. Check your connection and try again in a moment.',
  role_not_assigned: 'Access not assigned. Ask a Console superadmin to assign you a role.',
}

// Mirrors AuthController::classifyIntrospectFailure() — the direct-visit
// equivalent of the messages above.
const SESSION_ERROR_MESSAGES: Record<ConsoleSessionReason, string> = {
  no_console_session: 'Your Console session has ended. Sign in again to open Finance.',
  no_finance_access: 'You are signed in to Console, but this account does not have Finance access. Ask a Console admin to grant it under Access Management → Controller App Access.',
  console_unreachable: 'Could not reach Console to verify your sign-in. Check your connection and try again in a moment.',
  role_not_assigned: 'Access not assigned. Ask a Console superadmin to assign you a role.',
}

function ssoErrorMessage(code: string): string {
  return SSO_ERROR_MESSAGES[code] || 'Could not sign you in via Console. Ask an admin to check your Finance access grant.'
}

/**
 * Finance has no local login form. On mount this either:
 *  1. Picks up the one-time token the sso-callback redirect left in the URL
 *     (clicked from Console's App Launcher), or
 *  2. Reuses a previously stored Finance token, or
 *  3. Silently exchanges the shared Console cookie for a Finance token
 *     (direct visit while already signed in to Console).
 *
 * If none of those produce a session and the reason is simply "not signed in
 * to Console", the browser is sent to the Console login form with a `return`
 * pointing back here, so a successful login lands on the Finance dashboard.
 * Every other reason (no Finance grant, Console unreachable, a failed SSO
 * exchange) stops on the sign-in prompt with an explanation, because
 * redirecting would either loop or hide the real problem.
 */
export function useFinanceAuth() {
  const [user, setUser] = useState<FinanceUser | null>(null)
  const [loading, setLoading] = useState(true)
  const [redirecting, setRedirecting] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false

    function signIn(nextUser: FinanceUser) {
      // A session exists, so the next sign-out is allowed to redirect again.
      writeRedirectGuard(false)
      if (!cancelled) {
        setUser(nextUser)
        setLoading(false)
      }
    }

    function bounceToConsoleLogin() {
      writeRedirectGuard(true)
      if (!cancelled) {
        setRedirecting(true)
      }
      // `return` brings the browser back to the Finance root once Console has
      // set the shared cookie; the direct-visit exchange below then runs with
      // a valid session and drops the user on the dashboard.
      redirectToConsoleLogin()
    }

    async function bootstrap() {
      const url = new URL(window.location.href)
      const callbackToken = url.searchParams.get('token')
      const ssoError = url.searchParams.get('sso_error')

      if (ssoError) {
        stripAuthParamsFromUrl()
        writeRedirectGuard(true)
        if (!cancelled) {
          setError(ssoErrorMessage(ssoError))
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
          signIn(await fetchMe(token))
          return
        } catch {
          localStorage.removeItem(TOKEN_KEY)
        }
      }

      let session
      try {
        session = await fetchConsoleSession()
      } catch {
        // Finance's own API is unreachable or misrouted. Sending the user to
        // Console would not help — they would come straight back to the same
        // broken call — so stop here and say so.
        if (!cancelled) {
          setError(SESSION_ERROR_MESSAGES.console_unreachable)
          setLoading(false)
        }
        return
      }

      // StrictMode runs this effect twice in development; the torn-down pass
      // must not re-decide the outcome (or fire a second redirect).
      if (cancelled) {
        return
      }

      if (session.active && session.token && session.user) {
        localStorage.setItem(TOKEN_KEY, session.token)
        signIn(session.user)
        return
      }

      const reason = session.reason ?? 'no_console_session'

      if (reason === 'no_console_session' && !readRedirectGuard()) {
        bounceToConsoleLogin()
        return
      }

      if (!cancelled) {
        setError(SESSION_ERROR_MESSAGES[reason] ?? SESSION_ERROR_MESSAGES.no_console_session)
        setLoading(false)
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
    // Console's sign-out page must not be undone by an immediate automatic
    // sign-in bounce, so leave the guard set.
    writeRedirectGuard(true)
    setRedirecting(true)
    await redirectToConsoleLoginAfterSignOut()
  }, [])

  return { user, loading, redirecting, error, signOut }
}
