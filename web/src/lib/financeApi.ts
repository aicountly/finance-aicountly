// Client for Finance's own server-php API (not Console's API — see
// consoleAuth.ts for that). VITE_API_BASE_URL points at this app's backend.

// The deploy puts server-php inside the SPA's own document root at /api
// (see docs/DEPLOYMENT.md), so same-origin is the correct default: it needs
// no CORS grant and the shared `.aicountly.org` cookie is sent as a
// first-party cookie. Only override this when the API lives elsewhere.
const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/$/, '')

export interface FinanceUser {
  id: string | number | null
  name: string
  email: string
  role: string
  global_superadmin: boolean
}

/**
 * Why the shared-cookie check failed. Mirrors
 * AuthController::classifyIntrospectFailure() on the server-php side.
 * `no_console_session` is the only value that may trigger an automatic
 * bounce to the Console login form — see useFinanceAuth.
 */
export type ConsoleSessionReason =
  | 'no_console_session'
  | 'no_finance_access'
  | 'console_unreachable'

export interface ConsoleSessionResult {
  active: boolean
  reason?: ConsoleSessionReason
  token?: string
  user?: FinanceUser
}

interface ApiEnvelope<T> {
  success: boolean
  message: string
  data: T | null
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...(options.headers || {}),
    },
  })

  const text = await response.text()

  // A misrouted /api request answers with the SPA's index.html rather than
  // JSON. Report that as an API-routing problem instead of letting a raw
  // SyntaxError surface.
  let body: ApiEnvelope<T> | null = null
  if (text) {
    try {
      body = JSON.parse(text) as ApiEnvelope<T>
    } catch {
      throw new Error(`The API did not return JSON for ${path} (status ${response.status}).`)
    }
  }

  if (!response.ok || !body || !body.success) {
    throw new Error(body?.message || `Request failed with status ${response.status}`)
  }

  return body.data as T
}

export function fetchMe(token: string): Promise<FinanceUser> {
  return request<FinanceUser>('/v1/auth/me', {
    headers: { Authorization: `Bearer ${token}` },
  })
}

export function fetchConsoleSession(): Promise<ConsoleSessionResult> {
  return request<ConsoleSessionResult>('/v1/auth/console-session', { method: 'POST' })
}
