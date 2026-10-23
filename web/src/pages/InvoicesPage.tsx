import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { ErrorBanner, Notice, PageHeader, StatusBadge } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { currentFy, dmy, inrPaise } from '../lib/format'
import { useFinanceAccess } from '../lib/financeRoles'
import { invoicingApi, type RegisterFilters } from '../lib/invoicingApi'

function fyOptions(): string[] {
  const start = Number(currentFy().slice(0, 4))
  return [0, 1, 2].map((n) => `${start - n}-${String((start - n + 1) % 100).padStart(2, '0')}`)
}

const STATUSES: Array<[string, string]> = [
  ['', 'All issued'],
  ['issued', 'Issued (not credited)'],
  ['partially_credited', 'Partially credited'],
  ['credited', 'Credited'],
  ['pending_approval', 'Credit notes awaiting approval'],
  ['rejected', 'Rejected credit notes'],
]

export function InvoicesPage() {
  const { can } = useFinanceAccess()
  const [params, setParams] = useSearchParams()
  const filters: RegisterFilters = {
    fy: params.get('fy') ?? currentFy(),
    doc_type: params.get('doc_type') ?? '',
    status: params.get('status') ?? '',
    cmp_id: params.get('cmp_id') ?? '',
    buyer_kind: params.get('buyer_kind') ?? '',
    q: params.get('q') ?? '',
    page: Number(params.get('page') ?? '1') || 1,
  }
  const [company, setCompany] = useState(filters.cmp_id ?? '')
  const [search, setSearch] = useState(filters.q ?? '')
  const [exportError, setExportError] = useState('')
  const key = params.toString()
  const { data, error, loading } = useApiData(() => invoicingApi.register(filters), [key])
  const pending = useApiData(() => invoicingApi.creditNotes('pending_approval'), [])

  const set = (patch: Partial<Record<keyof RegisterFilters, string>>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(patch)) {
      if (v) next.set(k, v)
      else next.delete(k)
    }
    if (!('page' in patch)) next.delete('page')
    setParams(next)
  }
  const pages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1

  return (
    <>
      <PageHeader
        title="Invoices"
        subtitle="GST tax invoices and credit notes for Aicountly subscriptions. Issued only for payments Finance has verified; never edited — corrections are credit notes."
        actions={
          <>
            <Link className="button button-outline" to="/offline-payments">
              Offline payments
            </Link>
            {can('edit') ? (
              <button className="button button-outline" onClick={() => invoicingApi.exportCsv(filters).catch((e: Error) => setExportError(e.message))}>
                Export CSV (GSTR-1)
              </button>
            ) : null}
          </>
        }
      />
      {pending.data && pending.data.length > 0 ? (
        <Notice tone="warn">
          {pending.data.length} credit note{pending.data.length === 1 ? '' : 's'} awaiting approval:{' '}
          {pending.data.map((c, i) => (
            <span key={c.id}>
              {i > 0 ? ', ' : ''}
              <Link to={`/credit-notes/${c.id}`}>
                {inrPaise(c.total_paise)} against {c.original_invoice?.doc_no ?? 'invoice'}
              </Link>
            </span>
          ))}
          {can('approve') ? ' — someone other than the requester approves.' : '.'}
        </Notice>
      ) : null}
      <div className="toolbar">
        <label className="field-inline">
          <span>FY</span>
          <select value={filters.fy} onChange={(e) => set({ fy: e.target.value })}>
            {fyOptions().map((o) => (
              <option key={o}>{o}</option>
            ))}
          </select>
        </label>
        <label className="field-inline">
          <span>Type</span>
          <select value={filters.doc_type} onChange={(e) => set({ doc_type: e.target.value })}>
            <option value="">Invoices and credit notes</option>
            <option value="tax_invoice">Tax invoices</option>
            <option value="credit_note">Credit notes</option>
          </select>
        </label>
        <label className="field-inline">
          <span>Buyer</span>
          <select value={filters.buyer_kind} onChange={(e) => set({ buyer_kind: e.target.value })}>
            <option value="">All buyers</option>
            <option value="company">Companies (subscriptions)</option>
            <option value="academy_student">Aicountly Academy students</option>
          </select>
        </label>
        <label className="field-inline">
          <span>Status</span>
          <select value={filters.status} onChange={(e) => set({ status: e.target.value })}>
            {STATUSES.map(([v, l]) => (
              <option key={v || 'all'} value={v}>
                {l}
              </option>
            ))}
          </select>
        </label>
        <form
          className="field-inline"
          onSubmit={(e) => {
            e.preventDefault()
            set({ cmp_id: company.trim(), q: search.trim() })
          }}
        >
          <input placeholder="Company id" value={company} onChange={(e) => setCompany(e.target.value)} size={9} inputMode="numeric" />
          <input placeholder="Number, buyer or GSTIN" value={search} onChange={(e) => setSearch(e.target.value)} />
          <button className="button button-outline" type="submit">
            Filter
          </button>
        </form>
      </div>
      <ErrorBanner message={error || exportError || pending.error} />
      {loading && !data ? <p className="muted">Loading…</p> : null}
      {data ? (
        <>
          <p className="small">
            {data.total} document(s) · invoiced <strong>{inrPaise(data.totals.invoiced_paise)}</strong> · credited{' '}
            <strong>{inrPaise(data.totals.credited_paise)}</strong>
          </p>
          {data.rows.length === 0 ? (
            <p className="muted">No documents match.</p>
          ) : (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th>Number</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Buyer</th>
                    <th>GSTIN</th>
                    <th>POS</th>
                    <th className="num">Company</th>
                    <th className="num">Taxable</th>
                    <th className="num">GST</th>
                    <th className="num">Total</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.map((r) => (
                    <tr key={`${r.doc_type}-${r.id}`}>
                      <td className="nowrap">
                        <Link to={r.doc_type === 'credit_note' ? `/credit-notes/${r.id}` : `/invoices/${r.id}`}>{r.doc_no ?? '(not numbered yet)'}</Link>
                      </td>
                      <td>{dmy(r.doc_date)}</td>
                      <td>{r.doc_type === 'credit_note' ? 'Credit note' : 'Tax invoice'}</td>
                      <td>{r.buyer_legal_name}</td>
                      <td className="mono small">{r.buyer_gstin ?? 'B2C'}</td>
                      <td>{r.place_of_supply}</td>
                      <td className="num">{r.buyer_kind === 'academy_student' ? 'Academy' : r.cmp_id}</td>
                      <td className="num">{inrPaise(r.taxable_paise)}</td>
                      <td className="num">{inrPaise(r.tax_paise)}</td>
                      <td className="num">{inrPaise(r.total_paise)}</td>
                      <td>
                        <StatusBadge status={r.status} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {pages > 1 ? (
            <div className="toolbar">
              <button className="button button-outline" disabled={data.page <= 1} onClick={() => set({ page: String(data.page - 1) })}>
                Previous
              </button>
              <span className="small">
                Page {data.page} of {pages}
              </span>
              <button className="button button-outline" disabled={data.page >= pages} onClick={() => set({ page: String(data.page + 1) })}>
                Next
              </button>
            </div>
          ) : null}
        </>
      ) : null}
    </>
  )
}
