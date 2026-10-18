import { useEffect, useState, type FormEvent } from 'react'
import { ErrorBanner, FormDialog, Notice, type DialogField } from './ui'
import { useApiData } from '../hooks/useApiData'
import { ApiError } from '../lib/financeApi'
import { dateTime, dmy, todayIst } from '../lib/format'
import { invoicingApi, type InvoicingSettings, type Series, type SellerProfileInput } from '../lib/invoicingApi'

const EMPTY: SellerProfileInput = {
  legal_name: '',
  trade_name: '',
  gstin: '',
  pan: '',
  address_line1: '',
  address_line2: '',
  city: '',
  pincode: '',
  email: '',
  phone: '',
  signatory_name: '',
  signatory_designation: '',
  effective_from: todayIst(),
}

function fyLabel(fy: string): string {
  return fy.slice(2, 4) + '-' + fy.slice(5, 7)
}

function SellerProfileForm({ settings, onSaved }: { settings: InvoicingSettings; onSaved: () => void }) {
  const p = settings.profile
  const [v, setV] = useState<SellerProfileInput>(EMPTY)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, unknown>>({})
  const [saved, setSaved] = useState('')
  const editable = settings.can_edit

  useEffect(() => {
    if (p) {
      setV({
        legal_name: p.legal_name,
        trade_name: p.trade_name ?? '',
        gstin: p.gstin,
        pan: p.pan,
        address_line1: p.address_line1,
        address_line2: p.address_line2 ?? '',
        city: p.city,
        pincode: p.pincode,
        email: p.email ?? '',
        phone: p.phone ?? '',
        signatory_name: p.signatory_name,
        signatory_designation: p.signatory_designation ?? '',
        effective_from: todayIst(),
      })
    }
  }, [p])

  const set = (k: keyof SellerProfileInput) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })
  const err = (k: string) => (typeof fieldErrors[k] === 'string' ? <span className="field-error">{String(fieldErrors[k])}</span> : null)
  const field = (k: keyof SellerProfileInput, label: string, opts: { required?: boolean; help?: string; type?: string; mono?: boolean } = {}) => (
    <label className="field">
      <span className="field-label">
        {label}
        {opts.required ? ' *' : ''}
      </span>
      <input type={opts.type ?? 'text'} className={opts.mono ? 'mono' : undefined} value={String(v[k] ?? '')} onChange={set(k)} disabled={!editable} required={opts.required} />
      {opts.help ? <span className="field-help">{opts.help}</span> : null}
      {err(k)}
    </label>
  )

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    setSaved('')
    setFieldErrors({})
    try {
      const r = await invoicingApi.saveSellerProfile(v)
      setSaved(r.message)
      onSaved()
    } catch (e2) {
      setError(e2 instanceof Error ? e2.message : String(e2))
      setFieldErrors(e2 instanceof ApiError ? e2.errors : {})
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="form-grid" onSubmit={submit}>
      <h3 className="section-title section-title-tight">Seller profile (printed on every invoice and credit note)</h3>
      {p ? (
        <p className="muted small">
          In use since {dmy(p.effective_from)} · saved by {p.created_by} {dateTime(p.created_at)}. Saving keeps the old profile in the history; documents
          already issued keep the details they were issued with.
        </p>
      ) : (
        <Notice tone="warn">No seller profile yet: nothing can be invoiced until a finance admin saves it (legal name, GSTIN, address, signatory).</Notice>
      )}
      {field('legal_name', 'Legal name', { required: true })}
      {field('trade_name', 'Trade name')}
      <div className="form-row">
        {field('gstin', 'GSTIN', { required: true, mono: true, help: 'The state of registration and the PAN are taken from it.' })}
        {field('pan', 'PAN', { mono: true })}
      </div>
      {field('address_line1', 'Address', { required: true })}
      {field('address_line2', 'Address (line 2)')}
      <div className="form-row">
        {field('city', 'City', { required: true })}
        {field('pincode', 'PIN code', { required: true })}
      </div>
      <div className="form-row">
        {field('email', 'Billing email', { type: 'email' })}
        {field('phone', 'Phone')}
      </div>
      <div className="form-row">
        {field('signatory_name', 'Authorised signatory', { required: true })}
        {field('signatory_designation', 'Designation')}
      </div>
      {field('effective_from', 'Effective from', { type: 'date' })}
      <ErrorBanner message={error} />
      {saved ? <Notice tone="ok">{saved}</Notice> : null}
      {editable ? (
        <p>
          <button className="button" type="submit" disabled={busy}>
            {busy ? 'Saving…' : 'Save seller profile'}
          </button>
        </p>
      ) : null}
    </form>
  )
}

function SeriesTable({ settings, onSaved }: { settings: InvoicingSettings; onSaved: () => void }) {
  const [dialog, setDialog] = useState<{ series: Series | null } | null>(null)
  const fy = settings.current_fy
  const yy = fyLabel(fy)
  const missing = (['tax_invoice', 'credit_note'] as const).filter((t) => !settings.series.some((s) => s.fy === fy && s.doc_type === t))
  const fields = (s: Series | null): DialogField[] => [
    ...(s
      ? []
      : [
          { name: 'fy', label: 'Financial year', initial: fy, required: true },
          {
            name: 'doc_type',
            label: 'Document',
            type: 'select' as const,
            options: [
              { value: 'tax_invoice', label: 'Tax invoice' },
              { value: 'credit_note', label: 'Credit note' },
            ],
            initial: missing[0] ?? 'tax_invoice',
          },
        ]),
    { name: 'prefix', label: 'Prefix', required: true, initial: s?.prefix ?? `AIC/${yy}/`, help: 'Letters, digits, "-" and "/", not ending in a digit. Prefix + digits ≤ 16 characters (Rule 46(b)).' },
    { name: 'width', label: 'Digits', required: true, initial: String(s?.width ?? 6) },
    { name: 'next_no', label: 'Next number', required: true, initial: String(s?.next_no ?? 1), help: 'Usually 1; higher to continue an existing manual series.' },
  ]

  return (
    <>
      <h3 className="section-title section-title-tight">Document series (gapless, one per FY and document type)</h3>
      {missing.length > 0 ? (
        <Notice tone="warn">
          FY {fy} has no {missing.map((m) => (m === 'tax_invoice' ? 'tax invoice' : 'credit note')).join(' or ')} series yet.
        </Notice>
      ) : null}
      {settings.series.length > 0 ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>FY</th>
                <th>Document</th>
                <th>Prefix</th>
                <th className="num">Digits</th>
                <th>Next number</th>
                <th className="num">Issued</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {settings.series.map((s) => (
                <tr key={s.id}>
                  <td>{s.fy}</td>
                  <td>{s.doc_type === 'tax_invoice' ? 'Tax invoice' : 'Credit note'}</td>
                  <td className="mono">{s.prefix}</td>
                  <td className="num">{s.width}</td>
                  <td className="mono">{s.next_doc_no ?? '(exhausted)'}</td>
                  <td className="num">{s.used}</td>
                  <td>
                    {settings.can_edit && s.editable ? (
                      <button className="link-button" onClick={() => setDialog({ series: s })}>
                        Edit
                      </button>
                    ) : (
                      <span className="muted small">{s.editable ? '' : 'in use'}</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {settings.can_edit ? (
        <p>
          <button className="button button-outline" onClick={() => setDialog({ series: null })}>
            Add a series
          </button>
        </p>
      ) : null}
      {dialog ? (
        <FormDialog
          title={dialog.series ? `Edit the ${dialog.series.fy} ${dialog.series.doc_type === 'tax_invoice' ? 'tax invoice' : 'credit note'} series` : 'Add a document series'}
          description="A series can change only until its first document is numbered: numbers must stay consecutive."
          fields={fields(dialog.series)}
          submitLabel="Save series"
          onSubmit={async (f) => {
            const body = { prefix: f.prefix, width: Number(f.width), next_no: Number(f.next_no) }
            if (dialog.series) await invoicingApi.updateSeries(dialog.series.id, body)
            else await invoicingApi.createSeries({ ...body, fy: f.fy, doc_type: f.doc_type as Series['doc_type'] })
            onSaved()
          }}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </>
  )
}

/** Settings → Invoicing: seller profile and document series (finance_admin edits; everyone reads). */
export function InvoicingSettingsSection() {
  const { data, error, reload } = useApiData(() => invoicingApi.settings(), [])

  return (
    <section className="card" id="invoicing">
      <h2 className="section-title section-title-tight">Invoicing</h2>
      <p className="muted small">
        Subscription tax invoices and credit notes. Nothing is issued until the seller profile and the FY's series exist.
      </p>
      <ErrorBanner message={error} />
      {data ? (
        <>
          <ul className="small">
            <li>PDF service (GOTENBERG_URL): {data.readiness.pdf_renderer ? 'configured' : 'not configured — documents are stored as HTML until it is'}</li>
            <li>Manage read access (AIC-S2S key): {data.readiness.manage_inbound ? 'configured' : 'not configured — customers cannot see invoices in Manage yet'}</li>
          </ul>
          <SellerProfileForm settings={data} onSaved={reload} />
          <SeriesTable settings={data} onSaved={reload} />
        </>
      ) : null}
    </section>
  )
}
