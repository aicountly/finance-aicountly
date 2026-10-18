import { Link, useSearchParams } from 'react-router'
import { ErrorBanner, PageHeader, StatusBadge } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { dateOnly, dateTime, inr, statusLabel } from '../lib/format'
import { payoutsApi } from '../lib/payoutsApi'

const FILTERS = ['open', 'draft', 'in_review', 'approved', 'file_generated', 'partially_paid', 'paid', 'cancelled', '']

export function PayoutsPage() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? 'open'
  const { data, error, loading } = useApiData(() => payoutsApi.batches(status), [status])

  return (
    <>
      <PageHeader
        title="Payouts"
        subtitle="Weekly batches from Engage arrive as drafts. Review, approve, upload the bank file, then record each UTR."
      />
      <div className="toolbar">
        <label className="field-inline">
          <span>Status</span>
          <select value={status} onChange={(e) => setParams(e.target.value === 'open' ? {} : { status: e.target.value })}>
            {FILTERS.map((f) => (
              <option key={f || 'all'} value={f}>
                {f === '' ? 'All' : f === 'open' ? 'Open (not paid / cancelled)' : statusLabel(f)}
              </option>
            ))}
          </select>
        </label>
      </div>
      <ErrorBanner message={error} />
      {loading && !data ? <p className="muted">Loading…</p> : null}
      {data && data.length === 0 ? <p className="muted">No batches.</p> : null}
      {data && data.length > 0 ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Batch</th>
                <th>Period</th>
                <th>Received</th>
                <th>Status</th>
                <th className="num">Items</th>
                <th className="num">Gross</th>
                <th className="num">GST</th>
                <th className="num">TDS</th>
                <th className="num">Net</th>
                <th className="num">Paid</th>
              </tr>
            </thead>
            <tbody>
              {data.map((b) => (
                <tr key={b.id}>
                  <td>
                    <Link to={`/payouts/${b.id}`}>{b.batch_ref}</Link>
                    {b.bank_changed_count > 0 ? <span className="flag" title="Bank details changed since the payee's previous payout"> bank changed</span> : null}
                  </td>
                  <td>
                    {dateOnly(b.period_start)} – {dateOnly(b.period_end)}
                  </td>
                  <td>{dateTime(b.received_at)}</td>
                  <td>
                    <StatusBadge status={b.status} />
                  </td>
                  <td className="num">
                    {b.item_count}
                    {b.held_count > 0 ? <span className="muted"> ({b.held_count} held)</span> : null}
                  </td>
                  <td className="num">{inr(b.gross_amount)}</td>
                  <td className="num">{inr(b.gst_amount)}</td>
                  <td className="num">{inr(b.tds_amount)}</td>
                  <td className="num">{inr(b.net_amount)}</td>
                  <td className="num">{inr(b.paid_amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </>
  )
}
