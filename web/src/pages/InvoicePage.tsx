import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { DocumentView, RenderingNote } from '../components/DocumentView'
import { ErrorBanner, Notice, PageHeader, StatusBadge } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { dateTime, dmy, inrPaise, paiseToRupees } from '../lib/format'
import { invoicingApi, type Invoice } from '../lib/invoicingApi'

function CreditNoteDialog({ invoice, onClose }: { invoice: Invoice; onClose: () => void }) {
  const navigate = useNavigate()
  const [kind, setKind] = useState<'full' | 'partial'>('partial')
  const [reason, setReason] = useState('')
  const [amounts, setAmounts] = useState<Record<number, string>>({})
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const left = (lineId: number) => invoice.remaining_paise[String(lineId)]?.total ?? 0
  const leftTotal = invoice.lines.reduce((s, l) => s + left(l.id), 0)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      const lines =
        kind === 'partial'
          ? Object.entries(amounts)
              .filter(([, v]) => v.trim() !== '')
              .map(([id, amount]) => ({ invoice_line_id: Number(id), amount: amount.trim() }))
          : undefined
      const { data } = await invoicingApi.requestCreditNote(invoice.id, { kind, reason, lines })
      navigate(`/credit-notes/${data.id}`)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onClick={onClose}>
      <form className="dialog dialog-wide" role="dialog" aria-modal="true" aria-label="Request a credit note" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <h2>Request a credit note against {invoice.doc_no}</h2>
        <div className="dialog-description">
          <p>
            Four-eyes: the credit note is numbered and issued only when someone else approves it. Still creditable: <strong>{inrPaise(leftTotal)}</strong>{' '}
            (including GST). Deadline: {dmy(invoice.credit_deadline)} (s.34 CGST Act).
          </p>
        </div>
        <label className="field">
          <span className="field-label">Credit</span>
          <select value={kind} onChange={(e) => setKind(e.target.value as 'full' | 'partial')}>
            <option value="partial">Part of the invoice (amount per line, including GST)</option>
            <option value="full">Everything still creditable ({inrPaise(leftTotal)})</option>
          </select>
        </label>
        {kind === 'partial'
          ? invoice.lines.map((l) => (
              <label key={l.id} className="field">
                <span className="field-label">
                  Line {l.line_no}: {l.description} — up to {inrPaise(left(l.id))} incl. {l.gst_rate}% GST
                </span>
                <input
                  inputMode="decimal"
                  placeholder={paiseToRupees(left(l.id))}
                  value={amounts[l.id] ?? ''}
                  onChange={(e) => setAmounts({ ...amounts, [l.id]: e.target.value })}
                  disabled={left(l.id) === 0}
                />
              </label>
            ))
          : null}
        <label className="field">
          <span className="field-label">Reason *</span>
          <textarea rows={3} required value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Refund on cancellation, price correction" />
        </label>
        <ErrorBanner message={error} />
        <div className="dialog-actions">
          <button type="button" className="button button-outline" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="submit" className="button" disabled={busy}>
            {busy ? 'Working…' : 'Request credit note'}
          </button>
        </div>
      </form>
    </div>
  )
}

export function InvoicePage() {
  const id = Number(useParams().id)
  const { data, error } = useApiData(() => invoicingApi.invoice(id), [id])
  const [dialog, setDialog] = useState(false)
  const [actionError, setActionError] = useState('')

  return (
    <>
      <p className="crumbs">
        <Link to="/invoices">Invoices</Link> /
      </p>
      <ErrorBanner message={error || actionError} />
      {data ? (
        <>
          <PageHeader
            title={`Tax invoice ${data.doc_no}`}
            subtitle={
              <>
                {dmy(data.doc_date)} · FY {data.fy} · <StatusBadge status={data.status} /> · issued by {data.issued_by} {dateTime(data.issued_at)}
              </>
            }
            actions={
              <>
                <button
                  className="button button-outline"
                  disabled={!data.rendering.pdf_stored}
                  onClick={() => invoicingApi.invoicePdf(data).catch((e: Error) => setActionError(e.message))}
                >
                  Download PDF
                </button>
                {data.can.request_credit_note ? (
                  <button className="button button-outline" onClick={() => setDialog(true)}>
                    Request credit note
                  </button>
                ) : null}
              </>
            }
          />
          <RenderingNote rendering={data.rendering} />
          <DocumentView doc={data} />
          <section className="card">
            <h2 className="section-title section-title-tight">Payment</h2>
            <p className="small">
              <strong>Paid</strong> {inrPaise(data.payment.amount_paise)} on {dmy(data.payment.paid_on)} by{' '}
              {data.payment.gateway ? `${data.payment.gateway} (${data.payment.method_label})` : data.payment.method_label}, reference{' '}
              <span className="mono">{data.payment.reference}</span>
              {data.source.offline_payment_id ? (
                <>
                  {' '}
                  · <Link to="/offline-payments">offline payment #{data.source.offline_payment_id}</Link>
                </>
              ) : null}
            </p>
          </section>
          <section className="card">
            <h2 className="section-title section-title-tight">Credit notes</h2>
            {data.credited_paise > 0 ? (
              <p className="small">
                Credited {inrPaise(data.credited_paise)} of {inrPaise(data.total_paise)}.
              </p>
            ) : null}
            {data.credit_notes.length === 0 ? (
              <p className="muted small">None.</p>
            ) : (
              <div className="table-wrap">
                <table className="table">
                  <thead>
                    <tr>
                      <th>Number</th>
                      <th>Date</th>
                      <th>Kind</th>
                      <th className="num">Amount</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.credit_notes.map((c) => (
                      <tr key={c.id}>
                        <td>
                          <Link to={`/credit-notes/${c.id}`}>{c.doc_no ?? '(awaiting approval)'}</Link>
                        </td>
                        <td>{dmy(c.doc_date)}</td>
                        <td>{c.kind === 'full' ? 'Full' : 'Partial'}</td>
                        <td className="num">{inrPaise(c.total_paise)}</td>
                        <td>
                          <StatusBadge status={c.status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {data.status === 'credited' ? <Notice tone="info">Fully credited: nothing is left to credit on this invoice.</Notice> : null}
          </section>
          {dialog ? <CreditNoteDialog invoice={data} onClose={() => setDialog(false)} /> : null}
        </>
      ) : null}
    </>
  )
}
