import { useState } from 'react'
import { ErrorBanner, PageHeader } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { dateTime } from '../lib/format'
import { payoutsApi } from '../lib/payoutsApi'

const ENTITY_TYPES = ['', 'payout_batch', 'payout_item', 'payee', 'settings', 'tds_register', 'outbox']

function Changes({ before, after }: { before: Record<string, unknown> | null; after: Record<string, unknown> | null }) {
  const keys = Array.from(new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})]))
  if (keys.length === 0) return <span className="muted">—</span>
  const show = (v: unknown) => (v === null || v === undefined ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v))
  return (
    <ul className="changes">
      {keys.map((k) => {
        const b = before?.[k]
        const a = after?.[k]
        if (before && after && JSON.stringify(a) === JSON.stringify(b)) return null
        return (
          <li key={k}>
            <span className="muted">{k}:</span> {before && k in before ? <s>{show(b)}</s> : null} {after && k in after ? show(a) : null}
          </li>
        )
      })}
    </ul>
  )
}

export function AuditLogPage() {
  const [entityType, setEntityType] = useState('')
  const [actor, setActor] = useState('')
  const [action, setAction] = useState('')
  const [page, setPage] = useState(1)
  const [filters, setFilters] = useState({ entity_type: '', actor: '', action: '' })
  const { data, error, loading } = useApiData(() => payoutsApi.auditLog({ ...filters, page }), [filters, page])
  const pages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1

  return (
    <>
      <PageHeader title="Audit log" subtitle="Every change: who, what, when, before and after. Append-only; PAN and account numbers appear masked." />
      <form
        className="toolbar"
        onSubmit={(e) => {
          e.preventDefault()
          setPage(1)
          setFilters({ entity_type: entityType, actor: actor.trim(), action: action.trim() })
        }}
      >
        <select value={entityType} onChange={(e) => setEntityType(e.target.value)}>
          {ENTITY_TYPES.map((t) => (
            <option key={t} value={t}>
              {t === '' ? 'All records' : t}
            </option>
          ))}
        </select>
        <input placeholder="Actor email" value={actor} onChange={(e) => setActor(e.target.value)} />
        <input placeholder="Action (e.g. item.)" value={action} onChange={(e) => setAction(e.target.value)} />
        <button className="button button-outline" type="submit">
          Filter
        </button>
      </form>
      <ErrorBanner message={error} />
      {loading && !data ? <p className="muted">Loading…</p> : null}
      {data ? (
        <>
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Who</th>
                  <th>Action</th>
                  <th>Record</th>
                  <th>Change</th>
                </tr>
              </thead>
              <tbody>
                {data.rows.map((r) => (
                  <tr key={r.id}>
                    <td className="small">{dateTime(r.occurred_at)}</td>
                    <td className="small">
                      {r.actor}
                      {r.ip ? <div className="muted">{r.ip}</div> : null}
                    </td>
                    <td className="mono small">{r.action}</td>
                    <td className="small">
                      {r.entity_type}
                      {r.entity_id ? ` #${r.entity_id}` : ''}
                    </td>
                    <td className="small">
                      <Changes before={r.before} after={r.after} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="toolbar">
            <button className="button button-outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Previous
            </button>
            <span className="small">
              Page {data.page} of {pages} · {data.total} entries
            </span>
            <button className="button button-outline" disabled={page >= pages} onClick={() => setPage(page + 1)}>
              Next
            </button>
          </div>
        </>
      ) : null}
    </>
  )
}
