import { Link, useParams } from 'react-router'
import { ErrorBanner, PageHeader, StatusBadge } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { dateOnly, inr } from '../lib/format'
import { payoutsApi } from '../lib/payoutsApi'

export function PayeePage() {
  const id = Number(useParams().id)
  const { data: p, error, loading } = useApiData(() => payoutsApi.payee(id), [id])

  if (loading && !p) return <p className="muted">Loading…</p>
  if (!p) return <ErrorBanner message={error || 'Payee not found.'} />

  return (
    <>
      <p className="crumbs">
        <Link to="/payees">Payees</Link> / {p.seller_code}
      </p>
      <PageHeader title={p.legal_name} subtitle={`${p.seller_code} · ${p.entity_type}`} />
      <dl className="meta">
        <div>
          <dt>PAN</dt>
          <dd className="mono">{p.pan_masked}</dd>
        </div>
        <div>
          <dt>GSTIN</dt>
          <dd className="mono">{p.gstin ?? '— (no GST on payouts)'}</dd>
        </div>
        <div>
          <dt>Bank</dt>
          <dd>
            {p.bank_holder_name} · <span className="mono">{p.bank_account_masked}</span> · {p.bank_ifsc}
          </dd>
        </div>
        <div>
          <dt>FY {p.fy} (194H)</dt>
          <dd>
            commission {inr(p.fy_cumulative)} · TDS deducted {inr(p.fy_tds_deducted)} · net paid {inr(p.fy_paid_net)}
          </dd>
        </div>
      </dl>
      <h2 className="section-title">Payout history</h2>
      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th>Batch</th>
              <th>Item</th>
              <th>Bank</th>
              <th className="num">Gross</th>
              <th className="num">GST</th>
              <th className="num">TDS</th>
              <th className="num">Adj.</th>
              <th className="num">Net</th>
              <th>Status</th>
              <th>UTR</th>
            </tr>
          </thead>
          <tbody>
            {p.items.map((i) => (
              <tr key={i.id}>
                <td>
                  <Link to={`/payouts/${i.batch_id}`}>{i.batch_ref}</Link>
                  <div className="muted small">
                    {dateOnly(i.period_start)} – {dateOnly(i.period_end)}
                  </div>
                </td>
                <td>{i.item_ref}</td>
                <td className="small">
                  <span className="mono">{i.bank_account_masked}</span> · {i.bank_ifsc}
                  {i.bank_changed ? <div className="flag">changed</div> : null}
                </td>
                <td className="num">{inr(i.gross_amount)}</td>
                <td className="num">{inr(i.gst_amount)}</td>
                <td className="num">{inr(i.tds_amount)}</td>
                <td className="num">{inr(i.adjustment_total)}</td>
                <td className="num">{inr(i.net_amount)}</td>
                <td>
                  <StatusBadge status={i.status} />
                </td>
                <td className="mono small">
                  {i.utr ?? '—'}
                  {i.paid_at ? <div>{dateOnly(i.paid_at)}</div> : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}
