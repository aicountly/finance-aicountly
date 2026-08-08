import './App.css'

const appName = import.meta.env.VITE_APP_NAME ?? 'Finance'
const appEnv = import.meta.env.VITE_APP_ENV ?? 'local'
const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? '(not configured)'

function App() {
  return (
    <main className="app">
      <h1>{appName}</h1>
      <p className="tagline">Aicountly</p>
      <dl className="meta">
        <dt>Environment</dt>
        <dd>{appEnv}</dd>
        <dt>API</dt>
        <dd>{apiBaseUrl}</dd>
      </dl>
    </main>
  )
}

export default App
