// Shared controller portal auth helpers — mirrors console-react-app's
// portal-sso/consoleAuth.js.template (kept in TS here since this app is
// TypeScript). Console (console.aicountly.org) is the sole identity
// provider; Finance never shows its own login form.

export const CONSOLE_WEB_URL = (import.meta.env.VITE_CONSOLE_URL || 'https://console.aicountly.org').replace(/\/$/, '')
export const CONSOLE_API_URL = (import.meta.env.VITE_CONSOLE_API_URL || `${CONSOLE_WEB_URL}/api`).replace(/\/$/, '')

export function consoleLoginUrl(returnOrigin?: string, { signOut = false }: { signOut?: boolean } = {}): string {
  const params = new URLSearchParams()
  if (signOut) {
    params.set('signout', '1')
  } else {
    const origin = returnOrigin || window.location.origin
    if (origin) {
      params.set('return', `${origin}/`)
    }
  }
  const qs = params.toString()
  return `${CONSOLE_WEB_URL}/login${qs ? `?${qs}` : ''}`
}

export async function signOutViaConsole(): Promise<void> {
  try {
    await fetch(`${CONSOLE_API_URL}/auth/logout`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    })
  } catch {
    /* ignore — the local session is cleared regardless */
  }
}

export function redirectToConsoleLogin(): void {
  window.location.replace(consoleLoginUrl())
}

export async function redirectToConsoleLoginAfterSignOut(): Promise<void> {
  await signOutViaConsole()
  window.location.replace(consoleLoginUrl(undefined, { signOut: true }))
}
