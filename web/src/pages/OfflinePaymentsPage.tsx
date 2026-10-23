import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { ErrorBanner, FormDialog, Notice, PageHeader, StatusBadge } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { ApiError } from '../lib/financeApi'
import { dateTime, dmy, inrPaise, paiseToRupees, todayIst } from '../lib/format'
import { invoicingApi, type LineInput, type OfflinePayment, type OfflinePaymentInput, type Preview } from '../lib/invoicingApi'

const emptyLine = (): LineInput => ({
  item_code: '',
  item_kind: 'subscription',
  description: '',
  sac: '997331',
  gst_rate: '18',
  price_basis: 'inclusive',
  period_start: '',
  period_end: '',
  quantity: '1',
  amount: '',
  list_amount: '',
  discount: '',
  discount_ref: '',
})

function RecordDialog({ methods, states, onSaved, onClose }: { methods: Record<string, string>; states: Record<string, string>; onSaved: (msg: string) => void; onClose: () => void }) {
  const [v, setV] = useState<OfflinePaymentInput>({
    cmp_id: '',
    buyer_uuid: '',
    buyer: { legal_name: '', gstin: '', state_code: '', address: '', pincode: '', email: '' },
    lines: [emptyLine()],
    amount: '',
    method: Object.keys(methods)[0] ?? 'neft',
    utr: '',
    received_on: todayIst(),
    note: '',
  })
  const [preview, setPreview] = useState<Preview | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, unknown>>({})

  const setBuyer = (k: keyof OfflinePaymentInput['buyer']) => (e: { target: { value: string } }) => {
    setPreview(null)
    setV({ ...v, buyer: { ...v.buyer, [k]: e.target.value } })
  }
  const setLine = (i: number, k: keyof LineInput) => (e: { target: { value: string } }) => {
    setPreview(null)
    setV({ ...v, lines: v.lines.map((l, j) => (j === i ? { ...l, [k]: e.target.value } : l)) })
  }
  const err = (k: string) => (typeof fieldErrors[k] === 'string' ? <span className="field-error">{String(fieldErrors[k])}</span> : null)
  const fail = (e: unknown) => {
    setError(e instanceof Error ? e.message : String(e))
    setFieldErrors(e instanceof ApiError ? e.errors : {})
  }

  async function compute() {
    setBusy(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = await invoicingApi.previewOfflinePayment({ cmp_id: v.cmp_id, buyer_uuid: v.buyer_uuid, buyer: v.buyer, lines: v.lines, amount: v.amount })
      setPreview(data)
      if (!v.amount) setV({ ...v, amount: paiseToRupees(data.totals.total_paise) })
    } catch (e) {
      fail(e)
    } finally {
      setBusy(false)
    }
  }

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    setFieldErrors({})
    try {
      const r = await invoicingApi.recordOfflinePayment(v)
      onSaved(r.message)
      onClose()
    } catch (e2) {
      fail(e2)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onClick={onClose}>
      <form className="dialog dialog-wide" role="dialog" aria-modal="true" aria-label="Record an offline payment" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <h2>Record an offline payment</h2>
        <div className="dialog-description">
          <p>
            Money received by bank transfer, UPI or cheque. Someone else verifies it against the bank statement; the tax invoice is issued then. The
            amount received must equal the invoice total. What you record cannot be edited — a wrong record is rejected and recorded again.
          </p>
        </div>
        <h3 className="section-title section-title-tight">Buyer (as it will appear on the invoice)</h3>
        <div className="form-row">
          <label className="field">
            <span className="field-label">Manage company id (cmp_id) *</span>
            <input required inputMode="numeric" value={v.cmp_id} onChange={(e) => setV({ ...v, cmp_id: e.target.value })} />
            {err('cmp_id')}
          </label>
          <label className="field">
            <span className="field-label">Owner's my.aicountly id</span>
            <input value={v.buyer_uuid} onChange={(e) => setV({ ...v, buyer_uuid: e.target.value })} />
            {err('buyer_uuid')}
          </label>
        </div>
        <label className="field">
          <span className="field-label">Legal name *</span>
          <input required value={v.buyer.legal_name} onChange={setBuyer('legal_name')} />
          {err('buyer.legal_name')}
        </label>
        <div className="form-row">
          <label className="field">
            <span className="field-label">GSTIN (blank if unregistered)</span>
            <input className="mono" value={v.buyer.gstin} onChange={setBuyer('gstin')} maxLength={15} />
            {err('buyer.gstin')}
          </label>
          <label className="field">
            <span className="field-label">State *</span>
            <select required value={v.buyer.state_code} onChange={setBuyer('state_code')}>
              <option value="">Choose…</option>
              {Object.entries(states).map(([code, name]) => (
                <option key={code} value={code}>
                  {code} – {name}
                </option>
              ))}
            </select>
            {err('buyer.state_code')}
          </label>
        </div>
        <label className="field">
          <span className="field-label">Billing address *</span>
          <textarea required rows={2} value={v.buyer.address} onChange={setBuyer('address')} />
          {err('buyer.address')}
        </label>
        <div className="form-row">
          <label className="field">
            <span className="field-label">PIN code *</span>
            <input required inputMode="numeric" maxLength={6} value={v.buyer.pincode} onChange={setBuyer('pincode')} />
            {err('buyer.pincode')}
          </label>
          <label className="field">
            <span className="field-label">Email</span>
            <input type="email" value={v.buyer.email} onChange={setBuyer('email')} />
            {err('buyer.email')}
          </label>
        </div>

        <h3 className="section-title section-title-tight">What is billed</h3>
        {v.lines.map((l, i) => (
          <div key={i} className="line-card">
            <label className="field">
              <span className="field-label">Description *</span>
              <input required value={l.description} onChange={setLine(i, 'description')} placeholder="Smart Books — Business, annual" />
              {err(`lines[${i}].description`)}
            </label>
            <div className="form-row">
              <label className="field">
                <span className="field-label">Item code *</span>
                <input required value={l.item_code} onChange={setLine(i, 'item_code')} placeholder="books.business" />
                {err(`lines[${i}].item_code`)}
              </label>
              <label className="field">
                <span className="field-label">Item kind *</span>
                <input required value={l.item_kind} onChange={setLine(i, 'item_kind')} />
                {err(`lines[${i}].item_kind`)}
              </label>
              <label className="field">
                <span className="field-label">SAC *</span>
                <input required className="mono" value={l.sac} onChange={setLine(i, 'sac')} maxLength={6} />
                {err(`lines[${i}].sac`)}
              </label>
              <label className="field">
                <span className="field-label">GST rate (%) *</span>
                <input required inputMode="decimal" value={l.gst_rate} onChange={setLine(i, 'gst_rate')} />
                {err(`lines[${i}].gst_rate`)}
              </label>
            </div>
            <div className="form-row">
              <label className="field">
                <span className="field-label">Amount (₹){l.list_amount ? '' : ' *'}</span>
                <input required={!l.list_amount} inputMode="decimal" value={l.amount} onChange={setLine(i, 'amount')}
                  placeholder={l.list_amount ? 'list − discount' : '11800.00'} />
                {err(`lines[${i}].amount`)}
              </label>
              <label className="field">
                <span className="field-label">Price is</span>
                <select value={l.price_basis} onChange={setLine(i, 'price_basis')}>
                  <option value="inclusive">inclusive of GST</option>
                  <option value="exclusive">exclusive (GST added)</option>
                </select>
              </label>
              <label className="field">
                <span className="field-label">Period from</span>
                <input type="date" value={l.period_start} onChange={setLine(i, 'period_start')} />
                {err(`lines[${i}].period_start`)}
              </label>
              <label className="field">
                <span className="field-label">Period to</span>
                <input type="date" value={l.period_end} onChange={setLine(i, 'period_end')} />
                {err(`lines[${i}].period_end`)}
              </label>
            </div>
            <div className="form-row">
              <label className="field">
                <span className="field-label">List price (₹, optional)</span>
                <input inputMode="decimal" value={l.list_amount ?? ''} onChange={setLine(i, 'list_amount')} placeholder="before discount" />
                {err(`lines[${i}].list_amount`)}
              </label>
              <label className="field">
                <span className="field-label">Discount (₹)</span>
                <input inputMode="decimal" value={l.discount ?? ''} onChange={setLine(i, 'discount')} placeholder="from the Engage quote" />
                {err(`lines[${i}].discount`)}
              </label>
              <label className="field">
                <span className="field-label">Coupon code / quote ref</span>
                <input className="mono" value={l.discount_ref ?? ''} onChange={setLine(i, 'discount_ref')} placeholder="DIWALI26" maxLength={64} />
                {err(`lines[${i}].discount_ref`)}
              </label>
            </div>
            {l.list_amount ? (
              <p className="muted small">
                Discounted line: the amount billed is the list price less the discount, and GST is charged on that (leave Amount empty to have it
                computed). The invoice prints List price, Discount (code), Taxable value, GST and Total.
              </p>
            ) : null}
            {v.lines.length > 1 ? (
              <p>
                <button type="button" className="link-button link-danger" onClick={() => setV({ ...v, lines: v.lines.filter((_, j) => j !== i) })}>
                  Remove line
                </button>
              </p>
            ) : null}
          </div>
        ))}
        <p>
          <button type="button" className="link-button" onClick={() => setV({ ...v, lines: [...v.lines, emptyLine()] })}>
            Add a line
          </button>
          <button type="button" className="button button-outline" onClick={compute} disabled={busy}>
            Compute GST
          </button>
        </p>
        {preview ? (
          <Notice tone="info">
            {preview.supply_type === 'intra_state' ? 'Intra-state' : 'Inter-state'} supply, place of supply {preview.place_of_supply}: taxable{' '}
            {inrPaise(preview.totals.taxable_paise)}
            {preview.supply_type === 'intra_state'
              ? ` + CGST ${inrPaise(preview.totals.cgst_paise)} + SGST ${inrPaise(preview.totals.sgst_paise)}`
              : ` + IGST ${inrPaise(preview.totals.igst_paise)}`}{' '}
            = <strong>{inrPaise(preview.totals.total_paise)}</strong>
          </Notice>
        ) : null}

        <h3 className="section-title section-title-tight">Payment received</h3>
        <div className="form-row">
          <label className="field">
            <span className="field-label">Amount received (₹) *</span>
            <input required inputMode="decimal" value={v.amount} onChange={(e) => setV({ ...v, amount: e.target.value })} />
            {err('amount')}
          </label>
          <label className="field">
            <span className="field-label">Method *</span>
            <select value={v.method} onChange={(e) => setV({ ...v, method: e.target.value })}>
              {Object.entries(methods).map(([k, label]) => (
                <option key={k} value={k}>
                  {label}
                </option>
              ))}
            </select>
          </label>
          <label className="field">
            <span className="field-label">UTR / reference / cheque no. *</span>
            <input required className="mono" value={v.utr} onChange={(e) => setV({ ...v, utr: e.target.value })} />
            {err('utr')}
          </label>
          <label className="field">
            <span className="field-label">Received on *</span>
            <input required type="date" value={v.received_on} max={todayIst()} onChange={(e) => setV({ ...v, received_on: e.target.value })} />
            {err('received_on')}
          </label>
        </div>
        <label className="field">
          <span className="field-label">Note</span>
          <input value={v.note} onChange={(e) => setV({ ...v, note: e.target.value })} />
        </label>
        <ErrorBanner message={error} />
        <div className="dialog-actions">
          <button type="button" className="button button-outline" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="submit" className="button" disabled={busy}>
            {busy ? 'Working…' : 'Record payment'}
          </button>
        </div>
      </form>
    </div>
  )
}

export function OfflinePaymentsPage() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? ''
  const { data, error, reload } = useApiData(() => invoicingApi.offlinePayments(status), [status])
  const settings = useApiData(() => invoicingApi.settings(), [])
  const [recording, setRecording] = useState(false)
  const [rejecting, setRejecting] = useState<OfflinePayment | null>(null)
  const [actionError, setActionError] = useState('')
  const [done, setDone] = useState('')

  const act = (fn: () => Promise<{ message: string }>) => {
    setActionError('')
    setDone('')
    fn()
      .then((r) => {
        setDone(r.message)
        reload()
      })
      .catch((e: Error) => setActionError(e.message))
  }
  const ready = settings.data?.readiness

  return (
    <>
      <PageHeader
        title="Offline payments"
        subtitle="Bank transfer, UPI and cheque receipts. A maker records the payment; a different person verifies it against the bank statement, and the tax invoice is issued then."
        actions={
          data?.can.record ? (
            <button className="button" disabled={!ready?.seller_profile} title="The invoice is issued when someone else verifies it" onClick={() => setRecording(true)}>
              Record payment
            </button>
          ) : null
        }
      />
      {ready && (!ready.seller_profile || !ready.series.tax_invoice) ? (
        <Notice tone="warn">
          Invoicing is not set up yet for FY {ready.fy}: {!ready.seller_profile ? 'no seller profile' : ''}
          {!ready.seller_profile && !ready.series.tax_invoice ? ' and ' : ''}
          {!ready.series.tax_invoice ? 'no tax invoice series' : ''}. A finance admin adds them in <Link to="/settings#invoicing">Settings → Invoicing</Link>
          {ready.seller_profile ? '; verified payments wait until then and are invoiced automatically.' : '.'}
        </Notice>
      ) : null}
      <div className="toolbar">
        <label className="field-inline">
          <span>Status</span>
          <select value={status} onChange={(e) => setParams(e.target.value ? { status: e.target.value } : {})}>
            <option value="">All</option>
            <option value="recorded">Awaiting verification</option>
            <option value="verified">Verified</option>
            <option value="rejected">Rejected</option>
          </select>
        </label>
      </div>
      <ErrorBanner message={error || actionError || settings.error} />
      {done ? <Notice tone="ok">{done}</Notice> : null}
      {data && data.payments.length === 0 ? <p className="muted">No offline payments.</p> : null}
      {data && data.payments.length > 0 ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Received</th>
                <th>Buyer</th>
                <th className="num">Company</th>
                <th>Method / UTR</th>
                <th className="num">Amount</th>
                <th>Status</th>
                <th>Invoice</th>
                <th>Recorded / verified</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data.payments.map((p) => (
                <tr key={p.id}>
                  <td>{dmy(p.received_on)}</td>
                  <td>
                    {p.buyer.legal_name}
                    <div className="muted small mono">{p.buyer.gstin ?? 'Unregistered'}</div>
                    <div className="muted small">{p.lines.map((l) => l.description).join('; ')}</div>
                  </td>
                  <td className="num">{p.cmp_id}</td>
                  <td>
                    {p.method_label}
                    <div className="mono small">{p.utr}</div>
                  </td>
                  <td className="num">{inrPaise(p.amount_paise)}</td>
                  <td>
                    <StatusBadge status={p.status} />
                    {p.reject_reason ? <div className="muted small">{p.reject_reason}</div> : null}
                  </td>
                  <td>
                    {p.invoice ? (
                      <Link to={`/invoices/${p.invoice.id}`}>{p.invoice.doc_no}</Link>
                    ) : p.status === 'verified' ? (
                      <>
                        <StatusBadge status={p.invoice_state} />
                        {p.invoice_error ? <div className="muted small">{p.invoice_error}</div> : null}
                      </>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="small">
                    {p.recorded_by} · {dateTime(p.recorded_at)}
                    {p.verified_by ? <div className="muted">verified by {p.verified_by}</div> : null}
                    {p.rejected_by ? <div className="muted">rejected by {p.rejected_by}</div> : null}
                  </td>
                  <td>
                    <div className="row-actions">
                      {p.can.verify ? (
                        <button className="button button-outline" onClick={() => act(() => invoicingApi.verifyOfflinePayment(p.id))}>
                          Verify
                        </button>
                      ) : null}
                      {p.can.blocked_reason ? <span className="muted small">{p.can.blocked_reason}</span> : null}
                      {p.can.reject ? (
                        <button className="link-button link-danger" onClick={() => setRejecting(p)}>
                          Reject
                        </button>
                      ) : null}
                      {p.can.retry_invoice ? (
                        <button className="button button-outline" onClick={() => act(() => invoicingApi.retryInvoice(p.id))}>
                          Retry invoice
                        </button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {recording && data && settings.data ? (
        <RecordDialog
          methods={data.methods}
          states={settings.data.states}
          onSaved={(msg) => {
            setDone(msg)
            reload()
          }}
          onClose={() => setRecording(false)}
        />
      ) : null}
      {rejecting ? (
        <FormDialog
          title={`Reject the payment ${rejecting.utr}`}
          description="The record stays (rejected); the UTR can then be recorded again correctly."
          fields={[{ name: 'reason', label: 'Reason', type: 'textarea', required: true }]}
          submitLabel="Reject"
          danger
          onSubmit={async (v) => {
            const r = await invoicingApi.rejectOfflinePayment(rejecting.id, v.reason)
            setDone(r.message)
            reload()
          }}
          onClose={() => setRejecting(null)}
        />
      ) : null}
    </>
  )
}
