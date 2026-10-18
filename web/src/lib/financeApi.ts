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
  /** The user's Console role (not a Finance role). */
  role: string
  global_superadmin: boolean
  /** Finance roles assigned in Console: finance_admin, payout_approver, accountant, finance_viewer. */
  roles: string[]
  app_superadmin: boolean
  /** Route gates those roles open (view | edit | approve | settings). */
  gates?: string[]
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
  | 'role_not_assigned'
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
  errors?: Record<string, unknown>
}

/** localStorage key holding the Finance JWT (written by useFinanceAuth). */
export const TOKEN_KEY = 'finance_token'

export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, unknown>

  constructor(message: string, status: number, errors: Record<string, unknown> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

function storedToken(): string {
  try {
    return localStorage.getItem(TOKEN_KEY) ?? ''
  } catch {
    return ''
  }
}

/** The API's envelope message, or a readable fallback. */
async function parseEnvelope<T>(response: Response, path: string): Promise<{ data: T; message: string }> {
  const text = await response.text()
  let body: ApiEnvelope<T> | null = null
  if (text) {
    try {
      body = JSON.parse(text) as ApiEnvelope<T>
    } catch {
      throw new ApiError(`The API did not return JSON for ${path} (status ${response.status}).`, response.status)
    }
  }
  if (!response.ok || !body || !body.success) {
    const details = body?.errors && typeof body.errors === 'object' && !('file' in body.errors) ? body.errors : {}
    throw new ApiError(body?.message || `Request failed with status ${response.status}`, response.status, details)
  }
  return { data: body.data as T, message: body.message }
}

/** Authenticated JSON call to the Finance API with the stored Finance JWT. */
export async function apiCall<T>(method: string, path: string, body?: unknown): Promise<{ data: T; message: string }> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${storedToken()}`,
      ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })
  return parseEnvelope<T>(response, path)
}

export async function apiGet<T>(path: string): Promise<T> {
  return (await apiCall<T>('GET', path)).data
}

/** Download a file (CSV) the API streams, keeping the Authorization header. */
export async function apiDownload(path: string, fallbackName: string): Promise<void> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    credentials: 'include',
    headers: { Authorization: `Bearer ${storedToken()}` },
  })
  if (!response.ok) {
    await parseEnvelope(response, path)
    return
  }
  const disposition = response.headers.get('Content-Disposition') ?? ''
  const match = /filename="([^"]+)"/.exec(disposition)
  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = match?.[1] ?? fallbackName
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
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
