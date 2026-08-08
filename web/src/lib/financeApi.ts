// Client for Finance's own server-php API (not Console's API — see
// consoleAuth.ts for that). VITE_API_BASE_URL points at this app's backend.

const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/$/, '')

export interface FinanceUser {
  id: string | number | null
  name: string
  email: string
  role: string
  global_superadmin: boolean
}

export interface ConsoleSessionResult {
  active: boolean
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
  const body: ApiEnvelope<T> | null = text ? JSON.parse(text) : null

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
