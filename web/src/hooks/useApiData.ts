import { useCallback, useEffect, useState } from 'react'

/**
 * Load data from the API, with a reload() for after an action. `deps` re-run
 * the loader (e.g. a filter); the previous answer stays on screen while the
 * next one loads.
 */
export function useApiData<T>(loader: () => Promise<T>, deps: unknown[]) {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [tick, setTick] = useState(0)

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const load = useCallback(loader, deps)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    load()
      .then((value) => {
        if (!cancelled) {
          setData(value)
          setError('')
        }
      })
      .catch((e: unknown) => {
        if (!cancelled) setError(e instanceof Error ? e.message : String(e))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [load, tick])

  const reload = useCallback(() => setTick((t) => t + 1), [])

  return { data, error, loading, reload, setData }
}
