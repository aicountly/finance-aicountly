import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { DocumentView, RenderingNote } from '../components/DocumentView'
import { ErrorBanner, FormDialog, Notice, PageHeader, StatusBadge } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { dateTime, dmy } from '../lib/format'
import { invoicingApi } from '../lib/invoicingApi'

export function CreditNotePage() {
  const id = Number(useParams().id)
  const { data, error, reload } = useApiData(() => invoicingApi.creditNote(id), [id])
  const [rejecting, setRejecting] = useState(false)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState('')
  const [done, setDone] = useState('')

  async function approve() {
    setBusy(true)
    setActionError('')
    try {
      const r = await invoicingApi.approveCreditNote(id)
      setDone(r.message)
      reload()
    } catch (e) {
      setActionError(e instanceof Error ? e.message : String(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <p className="crumbs">
        <Link to="/invoices">Invoices</Link> /{' '}
        {data?.original_invoice ? <Link to={`/invoices/${data.original_invoice.id}`}>{data.original_invoice.doc_no}</Link> : null} /
      </p>
      <ErrorBanner message={error || actionError} />
      {done ? <Notice tone="ok">{done}</Notice> : null}
      {data ? (
        <>
          <PageHeader
            title={data.doc_no ? `Credit note ${data.doc_no}` : 'Credit note (awaiting approval)'}
            subtitle={
              <>
                {data.doc_date ? `${dmy(data.doc_date)} · FY ${data.fy} · ` : ''}
                <StatusBadge status={data.status} /> · against {data.original_invoice?.doc_no} dated {dmy(data.original_invoice?.doc_date)}
              </>
            }
            actions={
              <>
                {data.status === 'issued' ? (
                  <button
                    className="button button-outline"
                    disabled={!data.rendering?.pdf_stored}
                    onClick={() => invoicingApi.creditNotePdf(data).catch((e: Error) => setActionError(e.message))}
                  >
                    Download PDF
                  </button>
                ) : null}
                {data.can.approve ? (
                  <button className="button" disabled={busy} onClick={approve}>
                    {busy ? 'Issuing…' : 'Approve and issue'}
                  </button>
                ) : null}
                {data.can.reject ? (
                  <button className="button button-outline button-danger-outline" onClick={() => setRejecting(true)}>
                    Reject
                  </button>
                ) : null}
              </>
            }
          />
          {data.can.blocked_reason ? <Notice tone="warn">{data.can.blocked_reason}</Notice> : null}
          <dl className="meta">
            <dt>Reason</dt>
            <dd>{data.reason}</dd>
            <dt>Kind</dt>
            <dd>{data.kind === 'full' ? 'Full (everything still creditable)' : 'Partial'}</dd>
            <dt>Requested</dt>
            <dd>
              {data.requested_by} · {dateTime(data.requested_at)}
            </dd>
            {data.approved_by ? (
              <>
                <dt>Approved</dt>
                <dd>
                  {data.approved_by} · {dateTime(data.approved_at)}
                </dd>
              </>
            ) : null}
            {data.rejected_by ? (
              <>
                <dt>Rejected</dt>
                <dd>
                  {data.rejected_by}: {data.reject_reason}
                </dd>
              </>
            ) : null}
            {data.status === 'pending_approval' && data.credit_deadline ? (
              <>
                <dt>Issue by</dt>
                <dd>{dmy(data.credit_deadline)} (s.34 CGST Act)</dd>
              </>
            ) : null}
          </dl>
          {data.rendering && data.status === 'issued' ? <RenderingNote rendering={data.rendering} /> : null}
          <DocumentView doc={data} />
          {rejecting ? (
            <FormDialog
              title="Reject this credit note"
              fields={[{ name: 'reason', label: 'Reason', type: 'textarea', required: true }]}
              submitLabel="Reject"
              danger
              onSubmit={async (v) => {
                await invoicingApi.rejectCreditNote(id, v.reason)
                reload()
              }}
              onClose={() => setRejecting(false)}
            />
          ) : null}
        </>
      ) : null}
    </>
  )
}
