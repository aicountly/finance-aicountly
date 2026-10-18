import { useState } from 'react'
import { ErrorBanner, Notice, PageHeader } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { currentFy, currentQuarter, inr } from '../lib/format'
import { useFinanceAccess } from '../lib/financeRoles'
import { payoutsApi } from '../lib/payoutsApi'

function fyOptions(): string[] {
  const start = Number(currentFy().slice(0, 4))
  return [0, 1, 2].map((n) => `${start - n}-${String((start - n + 1) % 100).padStart(2, '0')}`)
}

export function TdsRegisterPage() {
  const { can } = useFinanceAccess()
  const [fy, setFy] = useState(currentFy())
  const [quarter, setQuarter] = useState(currentQuarter())
  const [exportError, setExportError] = useState('')
  const { data, error, loading } = useApiData(() => payoutsApi.tdsRegister(fy, quarter), [fy, quarter])

  return (
    <>
      <PageHeader
        title="TDS register — section 194H"
        subtitle="Commission paid to sellers, by quarter of payment: the deductee data for Form 26Q."
        actions={
          can('edit') ? (
            <button className="button button-outline" onClick={() => payoutsApi.tdsExport(fy, quarter).catch((e: Error) => setExportError(e.message))}>
              Export CSV (full PAN)
            </button>
          ) : null
        }
      />
      <div className="toolbar">
        <label className="field-inline">
          <span>FY</span>
          <select value={fy} onChange={(e) => setFy(e.target.value)}>
            {fyOptions().map((o) => (
              <option key={o}>{o}</option>
            ))}
          </select>
        </label>
        <label className="field-inline">
          <span>Quarter</span>
          <select value={quarter} onChange={(e) => setQuarter(e.target.value)}>
            <option value="Q1">Q1 (Apr–Jun)</option>
            <option value="Q2">Q2 (Jul–Sep)</option>
            <option value="Q3">Q3 (Oct–Dec)</option>
            <option value="Q4">Q4 (Jan–Mar)</option>
          </select>
        </label>
      </div>
      <Notice tone="info">
        Rows are payouts marked paid (with a UTR) in the quarter; the amount is the commission excluding GST. Rate and
        annual threshold come from Settings — confirm them, and the deductee codes, with your CA before filing. Challan
        (BSR code, challan serial) details are added in your TDS filing software.
      </Notice>
      <ErrorBanner message={error || exportError} />
      {loading && !data ? <p className="muted">Loading…</p> : null}
      {data ? (
        <>
          <p className="small">
            {data.from} – {data.to}: {data.totals.rows} payment(s), {data.totals.rows_with_tds} with TDS · amount paid{' '}
            <strong>{inr(data.totals.amount_paid)}</strong> · TDS <strong>{inr(data.totals.tds_amount)}</strong>
          </p>
          {data.rows.length === 0 ? (
            <p className="muted">No payments in this quarter.</p>
          ) : (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th>Deductee PAN</th>
                    <th>Name</th>
                    <th>Code</th>
                    <th>Section</th>
                    <th className="num">Amount paid</th>
                    <th className="num">Rate</th>
                    <th className="num">TDS</th>
                    <th>Date of payment / deduction</th>
                    <th>UTR</th>
                    <th>Ref</th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.map((r) => (
                    <tr key={r.item_ref}>
                      <td className="mono small">{r.deductee_pan}</td>
                      <td>
                        {r.deductee_name}
                        <div className="muted small">{r.seller_code}</div>
                      </td>
                      <td title={r.deductee_code === '01' ? 'Company' : 'Other than company'}>{r.deductee_code}</td>
                      <td>{r.section}</td>
                      <td className="num">{inr(r.amount_paid)}</td>
                      <td className="num">{r.tds_rate}%</td>
                      <td className="num" title={r.note ?? undefined}>
                        {inr(r.tds_amount)}
                      </td>
                      <td>{r.date_of_payment}</td>
                      <td className="mono small">{r.utr}</td>
                      <td className="small">
                        {r.item_ref}
                        <div className="muted">{r.batch_ref}</div>
                      </td>
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
