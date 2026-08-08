import { consoleLoginUrl } from '../lib/consoleAuth'

export function SignInPrompt({ error }: { error?: string }) {
  return (
    <main className="auth-screen">
      <div className="auth-card">
        <h1>APS Finance</h1>
        <p className="tagline">Aicountly</p>
        {error ? <p className="auth-error">{error}</p> : null}
        <p className="auth-copy">
          Finance is a Top Controller App — sign in with your Console account. There is no
          separate Finance password.
        </p>
        <a className="button" href={consoleLoginUrl()}>
          Sign in via Console
        </a>
      </div>
    </main>
  )
}
