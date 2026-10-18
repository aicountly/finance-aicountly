import { useState, type FormEvent, type ReactNode } from 'react'
import { Link } from 'react-router'
import { statusLabel } from '../lib/format'

export function StatusBadge({ status }: { status: string }) {
  return <span className={`badge badge-${status}`}>{statusLabel(status)}</span>
}

export function ErrorBanner({ message }: { message: string }) {
  if (!message) return null
  return (
    <p className="banner banner-error" role="alert">
      {message}
    </p>
  )
}

export function Notice({ children, tone = 'info' }: { children: ReactNode; tone?: 'info' | 'warn' | 'ok' }) {
  return <div className={`banner banner-${tone}`}>{children}</div>
}

export function Tile({ label, value, sub, to }: { label: string; value: ReactNode; sub?: ReactNode; to?: string }) {
  const body = (
    <>
      <div className="tile-label">{label}</div>
      <div className="tile-value">{value}</div>
      {sub ? <div className="tile-sub">{sub}</div> : null}
    </>
  )
  return to ? (
    <Link className="tile tile-link" to={to}>
      {body}
    </Link>
  ) : (
    <div className="tile">{body}</div>
  )
}

export interface DialogField {
  name: string
  label: string
  type?: 'text' | 'textarea' | 'date' | 'select'
  options?: Array<{ value: string; label: string }>
  required?: boolean
  placeholder?: string
  initial?: string
  help?: string
}

/**
 * Modal form for every action that needs input (a reason, a UTR, an amount).
 * onSubmit rejects → the error stays in the dialog; resolves → it closes.
 */
export function FormDialog({
  title,
  description,
  fields,
  submitLabel,
  danger,
  onSubmit,
  onClose,
}: {
  title: string
  description?: ReactNode
  fields: DialogField[]
  submitLabel: string
  danger?: boolean
  onSubmit: (values: Record<string, string>) => Promise<void>
  onClose: () => void
}) {
  const [values, setValues] = useState<Record<string, string>>(() =>
    Object.fromEntries(fields.map((f) => [f.name, f.initial ?? (f.type === 'select' ? (f.options?.[0]?.value ?? '') : '')])),
  )
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      await onSubmit(values)
      onClose()
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onClick={onClose}>
      <form className="dialog" role="dialog" aria-modal="true" aria-label={title} onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <h2>{title}</h2>
        {description ? <div className="dialog-description">{description}</div> : null}
        {fields.map((f) => (
          <label key={f.name} className="field">
            <span className="field-label">
              {f.label}
              {f.required ? ' *' : ''}
            </span>
            {f.type === 'textarea' ? (
              <textarea
                value={values[f.name]}
                required={f.required}
                placeholder={f.placeholder}
                rows={3}
                onChange={(e) => setValues({ ...values, [f.name]: e.target.value })}
              />
            ) : f.type === 'select' ? (
              <select value={values[f.name]} onChange={(e) => setValues({ ...values, [f.name]: e.target.value })}>
                {f.options?.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            ) : (
              <input
                type={f.type ?? 'text'}
                value={values[f.name]}
                required={f.required}
                placeholder={f.placeholder}
                onChange={(e) => setValues({ ...values, [f.name]: e.target.value })}
              />
            )}
            {f.help ? <span className="field-help">{f.help}</span> : null}
          </label>
        ))}
        <ErrorBanner message={error} />
        <div className="dialog-actions">
          <button type="button" className="button button-outline" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="submit" className={`button${danger ? ' button-danger' : ''}`} disabled={busy}>
            {busy ? 'Working…' : submitLabel}
          </button>
        </div>
      </form>
    </div>
  )
}

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="page-header">
      <div>
        <h1>{title}</h1>
        {subtitle ? <p className="page-subtitle">{subtitle}</p> : null}
      </div>
      {actions ? <div className="page-actions">{actions}</div> : null}
    </div>
  )
}
