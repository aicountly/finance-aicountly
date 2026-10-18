import { useState } from 'react'
import { Link } from 'react-router'
import { ErrorBanner, PageHeader } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { dateOnly, inr } from '../lib/format'
import { payoutsApi } from '../lib/payoutsApi'

export function PayeesPage() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const { data, error, loading } = useApiData(() => payoutsApi.payees(query), [query])

  return (
    <>
      <PageHeader
        title="Payees"
        subtitle="Sellers as last synced from Engage payout batches. KYC is managed in Engage; PAN, GSTIN and bank account are stored encrypted and shown masked."
      />
      <form
        className="toolbar"
        onSubmit={(e) => {
          e.preventDefault()
          setQuery(search)
        }}
      >
        <input type="search" placeholder="Seller code or name" value={search} onChange={(e) => setSearch(e.target.value)} />
        <button className="button button-outline" type="submit">
          Search
        </button>
      </form>
      <ErrorBanner message={error} />
      {loading && !data ? <p className="muted">Loading…</p> : null}
      {data && data.length === 0 ? <p className="muted">No payees yet — they appear with the first Engage batch.</p> : null}
      {data && data.length > 0 ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Payee</th>
                <th>PAN</th>
                <th>GSTIN</th>
                <th>Bank</th>
                <th className="num">FY {data[0].fy} commission (194H base)</th>
                <th className="num">TDS deducted</th>
                <th className="num">Net paid</th>
                <th>Last paid</th>
              </tr>
            </thead>
            <tbody>
              {data.map((p) => (
                <tr key={p.id}>
                  <td>
                    <Link to={`/payees/${p.id}`}>{p.legal_name}</Link>
                    <div className="muted small">
                      {p.seller_code} · {p.entity_type}
                    </div>
                  </td>
                  <td className="mono small">{p.pan_masked}</td>
                  <td className="mono small">{p.gstin ?? '—'}</td>
                  <td className="small">
                    <span className="mono">{p.bank_account_masked}</span> · {p.bank_ifsc}
                  </td>
                  <td className="num">{inr(p.fy_cumulative)}</td>
                  <td className="num">{inr(p.fy_tds_deducted)}</td>
                  <td className="num">{inr(p.fy_paid_net)}</td>
                  <td>{dateOnly(p.last_paid_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </>
  )
}
