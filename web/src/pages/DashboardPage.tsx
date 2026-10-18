import { Link } from 'react-router'
import { ErrorBanner, Notice, PageHeader, StatusBadge, Tile } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { dateOnly, dateTime, inr } from '../lib/format'
import { payoutsApi } from '../lib/payoutsApi'

export function DashboardPage() {
  const { data, error, loading } = useApiData(() => payoutsApi.dashboard(), [])

  return (
    <>
      <PageHeader
        title="Finance Dashboard"
        subtitle={data ? `Weekly seller payouts · FY ${data.fy}` : 'Weekly seller payouts'}
      />
      <ErrorBanner message={error} />
      {loading && !data ? <p className="muted">Loading…</p> : null}
      {data ? (
        <>
          <div className="tiles">
            <Tile
              label="Awaiting review"
              value={inr(data.awaiting_review.net_amount)}
              sub={`${data.awaiting_review.items} item(s) in draft / in-review batches`}
              to="/payouts?status=open"
            />
            <Tile
              label="Approved, awaiting payment"
              value={inr(data.awaiting_payment.net_amount)}
              sub={`${data.awaiting_payment.items} item(s) — upload the bank file, then record UTRs`}
              to="/payouts?status=open"
            />
            <Tile label="On hold" value={inr(data.on_hold.net_amount)} sub={`${data.on_hold.items} item(s)`} />
            <Tile
              label={`Paid in FY ${data.fy}`}
              value={inr(data.paid_this_fy.net_amount)}
              sub={`${data.paid_this_fy.items} payment(s) · TDS ${inr(data.paid_this_fy.tds_amount)} · GST ${inr(data.paid_this_fy.gst_amount)}`}
              to="/tds"
            />
            <Tile label="Failed / reversed this FY" value={data.failed_this_fy} />
            <Tile label="Payees" value={data.payees} sub={`Last batch received ${dateTime(data.last_batch_received_at)}`} to="/payees" />
          </div>

          {!data.engage_callbacks.configured ? (
            <Notice tone="warn">
              Status callbacks to Engage are not configured (ENGAGE_API_BASE_URL / ENGAGE_FINANCE_KEY).{' '}
              {data.engage_callbacks.pending} update(s) are waiting in the outbox; sellers will not see approvals or
              payments until it is set.
            </Notice>
          ) : data.engage_callbacks.dead > 0 ? (
            <Notice tone="warn">
              {data.engage_callbacks.dead} status update(s) to Engage failed permanently.{' '}
              <Link to="/settings">Review them in Settings</Link>.
            </Notice>
          ) : (
            <p className="muted small">
              Engage callbacks: {data.engage_callbacks.pending} pending, {data.engage_callbacks.delivered} delivered.
            </p>
          )}

          <h2 className="section-title">Open batches</h2>
          {data.open_batches.length === 0 ? (
            <p className="muted">
              No open batches. Engage sends a draft batch every Monday; nothing is paid until Finance approves it.
            </p>
          ) : (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th>Batch</th>
                    <th>Period</th>
                    <th>Status</th>
                    <th className="num">Items</th>
                    <th className="num">Net payable</th>
                  </tr>
                </thead>
                <tbody>
                  {data.open_batches.map((b) => (
                    <tr key={b.id}>
                      <td>
                        <Link to={`/payouts/${b.id}`}>{b.batch_ref}</Link>
                      </td>
                      <td>
                        {dateOnly(b.period_start)} – {dateOnly(b.period_end)}
                      </td>
                      <td>
                        <StatusBadge status={b.status} />
                      </td>
                      <td className="num">{b.item_count}</td>
                      <td className="num">{inr(b.net_amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      ) : null}
    </>
  )
}
