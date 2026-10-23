import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { ErrorBanner, FormDialog, Notice, PageHeader, StatusBadge } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { academyApi } from '../lib/academyApi'
import { dateTime, inrPaise } from '../lib/format'
import { useFinanceAccess } from '../lib/financeRoles'
import { EngageEventsTable } from './AcademyFeesPage'

export function AcademyLinkPage() {
  const id = Number(useParams().id)
  const { can } = useFinanceAccess()
  const { data, error, reload } = useApiData(() => academyApi.link(id), [id])
  const [dialog, setDialog] = useState<'reject' | 'cancel' | null>(null)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState('')
  const [actionError, setActionError] = useState('')
  const [copied, setCopied] = useState(false)

  const act = (fn: () => Promise<{ message: string }>) => {
    setBusy(true)
    setActionError('')
    setDone('')
    fn()
      .then((r) => {
        setDone(r.message)
        reload()
      })
      .catch((e: Error) => setActionError(e.message))
      .finally(() => setBusy(false))
  }

  if (!data) {
    return (
      <>
        <PageHeader title="Payment link" />
        <ErrorBanner message={error} />
      </>
    )
  }
  const l = data
  const intra = l.supply_type === 'intra_state'

  return (
    <>
      <PageHeader
        title={`${l.payer.name} — ${inrPaise(l.amount_paise)}`}
        subtitle={
          <>
            <Link to="/academy">Academy fees</Link> · <span className="mono">{l.link_id}</span> · <StatusBadge status={l.status} />{' '}
            {l.mode === 'test' ? <span className="badge badge-pending">test mode</span> : null}
          </>
        }
        actions={
          <div className="row-actions">
            {l.can.approve ? (
              <button className="button" disabled={busy} onClick={() => act(() => academyApi.approve(l.id))}>
                Approve and create link
              </button>
            ) : null}
            {l.can.blocked_reason ? <span className="muted small">{l.can.blocked_reason}</span> : null}
            {l.can.reject ? (
              <button className="button button-outline" disabled={busy} onClick={() => setDialog('reject')}>
                Reject
              </button>
            ) : null}
            {l.can.retry ? (
              <button className="button" disabled={busy} onClick={() => act(() => academyApi.retry(l.id))}>
                Retry creating at {l.provider}
              </button>
            ) : null}
            {l.can.refresh ? (
              <button className="button button-outline" disabled={busy} onClick={() => act(() => academyApi.refresh(l.id))}>
                Re-check with {l.provider}
              </button>
            ) : null}
            {l.can.cancel ? (
              <button className="link-button link-danger" disabled={busy} onClick={() => setDialog('cancel')}>
                Cancel link
              </button>
            ) : null}
          </div>
        }
      />
      <ErrorBanner message={error || actionError} />
      {done ? <Notice tone="ok">{done}</Notice> : null}
      {l.status === 'failed' && l.last_error ? <Notice tone="warn">Creating the link at {l.provider} failed: {l.last_error}</Notice> : null}
      {l.url && ['created', 'partially_paid'].includes(l.status) ? (
        <Notice tone="info">
          Share this link with the student:{' '}
          <a href={l.url} target="_blank" rel="noreferrer" className="mono">
            {l.url}
          </a>{' '}
          <button
            className="link-button"
            onClick={() => {
              void navigator.clipboard?.writeText(l.url ?? '').then(() => setCopied(true))
            }}
          >
            {copied ? 'Copied' : 'Copy'}
          </button>{' '}
          · expires {dateTime(l.expire_at)}
        </Notice>
      ) : null}

      <div className="doc-parties">
        <div className="card doc-party">
          <h3>Student</h3>
          <p>
            {l.payer.name}
            {'\n'}
            {l.payer.mobile}
            {l.payer.email ? `\n${l.payer.email}` : ''}
            {l.payer.address ? `\n${l.payer.address}` : ''}
            {l.payer.pincode ? ` ${l.payer.pincode}` : ''}
            {'\n'}
            {l.payer.state_code} – {l.payer.state_name}
          </p>
        </div>
        <div className="card doc-party">
          <h3>Fee</h3>
          <p>
            {l.description}
            {'\n'}SAC {l.fee.sac} · GST {l.fee.gst_rate}% ({l.fee.price_basis})
            {'\n'}Taxable {inrPaise(l.fee.taxable_paise)}
            {intra ? ` + CGST ${inrPaise(l.fee.cgst_paise)} + SGST ${inrPaise(l.fee.sgst_paise)}` : ` + IGST ${inrPaise(l.fee.igst_paise)}`}
            {'\n'}
            {intra ? 'Intra-state' : 'Inter-state'}, place of supply {l.place_of_supply}
          </p>
        </div>
        <div className="card doc-party">
          <h3>References</h3>
          <p>
            Academy: <span className="mono">{l.academy_ref}</span>
            {'\n'}Engage lead: <span className="mono">{l.source_ref ?? '—'}</span>
            {'\n'}Course: {l.stream === 'edge' ? 'Aicountly Edge' : 'Aicountly Aspire'} ({l.product_code})
            {l.seller_code ? `\nSeller: ${l.seller_code}` : ''}
            {'\n'}
            {l.provider} link: <span className="mono">{l.provider_link_id ?? '—'}</span> ({l.provider_status ?? '—'})
          </p>
        </div>
        <div className="card doc-party">
          <h3>History</h3>
          <p>
            {l.source_app === 'engage' ? 'Requested by Engage' : `Raised by ${l.created_by}`} · {dateTime(l.created_at)}
            {l.approved_by ? `\nApproved by ${l.approved_by} · ${dateTime(l.approved_at)}` : ''}
            {l.rejected_by ? `\nRejected by ${l.rejected_by}: ${l.reject_reason ?? ''}` : ''}
            {l.cancelled_by ? `\nCancelled by ${l.cancelled_by}${l.cancel_reason ? `: ${l.cancel_reason}` : ''}` : ''}
            {l.paid_at ? `\nPaid ${dateTime(l.paid_at)}` : ''}
            {l.last_synced_at ? `\nLast checked with ${l.provider}: ${dateTime(l.last_synced_at)}` : ''}
          </p>
        </div>
      </div>

      <h2 className="section-title">Payments</h2>
      {l.payments.length === 0 ? <p className="muted">No payment yet.</p> : null}
      {l.payments.length > 0 ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Paid</th>
                <th>Gateway payment</th>
                <th className="num">Amount</th>
                <th>Verified with provider</th>
                <th>Invoice</th>
              </tr>
            </thead>
            <tbody>
              {l.payments.map((p) => (
                <tr key={p.id}>
                  <td className="small">{dateTime(p.paid_at)}</td>
                  <td className="small">
                    <span className="mono">{p.gateway_payment_ref ?? p.provider_payment_id}</span> · {p.method ?? '—'}
                    <div className="muted mono">{p.payment_ref}</div>
                  </td>
                  <td className="num">
                    {inrPaise(p.amount_paise)}
                    {p.refunded_paise > 0 ? <div className="muted small">refunded {inrPaise(p.refunded_paise)} — raise the credit note on the invoice</div> : null}
                  </td>
                  <td>
                    <StatusBadge status={p.verification_status} />
                    {p.verification_error && p.verification_status !== 'verified' ? <div className="muted small">{p.verification_error}</div> : null}
                  </td>
                  <td>
                    {p.invoice ? <Link to={`/invoices/${p.invoice.id}`}>{p.invoice.doc_no}</Link> : <StatusBadge status={p.invoice_state} />}
                    {p.invoice_error && !p.invoice ? <div className="muted small">{p.invoice_error}</div> : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      <h2 className="section-title">Engage callbacks</h2>
      {l.source_ref ? (
        <EngageEventsTable rows={l.engage_events} canRequeue={can('settings')} onChanged={reload} />
      ) : (
        <p className="muted">No Engage lead code on this link: the payment is not reported to Engage. Confirm it on the lead in Engage by hand.</p>
      )}

      <h2 className="section-title">Webhook deliveries</h2>
      {l.webhooks.length === 0 ? <p className="muted">None.</p> : null}
      {l.webhooks.length > 0 ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Received</th>
                <th>Event</th>
                <th>Outcome</th>
                <th className="num">Deliveries</th>
              </tr>
            </thead>
            <tbody>
              {l.webhooks.map((w) => (
                <tr key={w.id}>
                  <td className="small">{dateTime(w.received_at)}</td>
                  <td className="small">{w.event_type}</td>
                  <td>
                    <StatusBadge status={w.outcome} />
                    {w.detail ? <div className="muted small">{w.detail}</div> : null}
                  </td>
                  <td className="num">{w.deliveries}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {dialog === 'reject' ? (
        <FormDialog
          title="Reject the payment link"
          description="The link is never created at the provider. Raise a corrected one if needed."
          fields={[{ name: 'reason', label: 'Reason', type: 'textarea', required: true }]}
          submitLabel="Reject"
          danger
          onSubmit={async (v) => {
            const r = await academyApi.reject(l.id, v.reason)
            setDone(r.message)
            reload()
          }}
          onClose={() => setDialog(null)}
        />
      ) : null}
      {dialog === 'cancel' ? (
        <FormDialog
          title="Cancel the payment link"
          description={`The student can no longer pay it. ${l.provider_link_id ? `It is cancelled at ${l.provider} too.` : ''}`}
          fields={[{ name: 'reason', label: 'Reason', type: 'textarea' }]}
          submitLabel="Cancel link"
          danger
          onSubmit={async (v) => {
            const r = await academyApi.cancel(l.id, v.reason)
            setDone(r.message)
            reload()
          }}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </>
  )
}
