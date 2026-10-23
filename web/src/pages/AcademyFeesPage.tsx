import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { ErrorBanner, Notice, PageHeader, StatusBadge, Tile } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { academyApi, type EngageEvent, type LinkInput, type LinkPreview, type Overview } from '../lib/academyApi'
import { ApiError } from '../lib/financeApi'
import { dateTime, inrPaise, statusLabel } from '../lib/format'

const TABS = [
  { key: 'links', label: 'Payment links' },
  { key: 'payments', label: 'Payments' },
  { key: 'webhooks', label: 'Webhook log' },
  { key: 'engage', label: 'Engage callbacks' },
] as const

const emptyLink = (provider: string): LinkInput => ({
  academy_ref: '',
  source_ref: '',
  stream: 'edge',
  product_code: '',
  description: '',
  payer: { name: '', email: '', mobile: '', state_code: '', address: '', pincode: '' },
  amount: '',
  fee: { sac: '999293', gst_rate: '18', price_basis: 'inclusive' },
  expire_at: '',
  provider,
})

/** Staff "create link" (maker). Someone else approves it before it exists at the provider. */
function CreateLinkDialog({ overview, onSaved, onClose }: { overview: Overview; onSaved: (msg: string, id: number) => void; onClose: () => void }) {
  const defaultProvider = overview.providers.find((p) => p.is_default)?.provider ?? overview.providers[0]?.provider ?? ''
  const [v, setV] = useState<LinkInput>(() => emptyLink(defaultProvider))
  const [preview, setPreview] = useState<LinkPreview | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, unknown>>({})

  const err = (k: string) => (typeof fieldErrors[k] === 'string' ? <span className="field-error">{String(fieldErrors[k])}</span> : null)
  const fail = (e: unknown) => {
    setError(e instanceof Error ? e.message : String(e))
    setFieldErrors(e instanceof ApiError ? e.errors : {})
  }
  const set = (patch: Partial<LinkInput>) => {
    setPreview(null)
    setV({ ...v, ...patch })
  }
  const setPayer = (k: keyof LinkInput['payer']) => (e: { target: { value: string } }) => set({ payer: { ...v.payer, [k]: e.target.value } })
  const setFee = (k: keyof LinkInput['fee']) => (e: { target: { value: string } }) => set({ fee: { ...v.fee, [k]: e.target.value } as LinkInput['fee'] })

  async function compute() {
    setBusy(true)
    setError('')
    setFieldErrors({})
    try {
      setPreview((await academyApi.preview(v)).data)
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
      const r = await academyApi.create(v)
      onSaved(r.message, r.data.id)
      onClose()
    } catch (e2) {
      fail(e2)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onClick={onClose}>
      <form className="dialog dialog-wide" role="dialog" aria-modal="true" aria-label="Raise a fee payment link" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <h2>Raise a fee payment link</h2>
        <div className="dialog-description">
          <p>
            A Razorpay / Cashfree payment link on Aicountly's own account for one Aicountly Academy fee. Someone else approves it before it is created; then share
            the link with the student. When the student pays, Finance verifies the payment with the provider and issues the GST invoice. What you enter cannot be
            edited later — cancel the link and raise a new one.
          </p>
        </div>

        <h3 className="section-title section-title-tight">Fee</h3>
        <div className="form-row">
          <label className="field">
            <span className="field-label">Course *</span>
            <select value={v.stream} onChange={(e) => set({ stream: e.target.value as LinkInput['stream'] })}>
              {Object.entries(overview.streams).map(([k, label]) => (
                <option key={k} value={k}>
                  {label}
                </option>
              ))}
            </select>
            {err('stream')}
          </label>
          <label className="field">
            <span className="field-label">Academy admission / fee ref *</span>
            <input required className="mono" value={v.academy_ref} onChange={(e) => set({ academy_ref: e.target.value })} placeholder="EDGE-ADM-0042-F1" />
            {err('academy_ref')}
          </label>
          <label className="field">
            <span className="field-label">Engage lead code</span>
            <input className="mono" value={v.source_ref} onChange={(e) => set({ source_ref: e.target.value })} placeholder="so Engage records the payment" />
            {err('source_ref')}
          </label>
        </div>
        <label className="field">
          <span className="field-label">Description (printed on the invoice) *</span>
          <input required value={v.description} onChange={(e) => set({ description: e.target.value })} placeholder="Aicountly Edge — fee instalment 1 of 2" />
          {err('description')}
        </label>
        <div className="form-row">
          <label className="field">
            <span className="field-label">Amount (₹) *</span>
            <input required inputMode="decimal" value={v.amount} onChange={(e) => set({ amount: e.target.value })} placeholder="11800.00" />
            {err('amount')}
          </label>
          <label className="field">
            <span className="field-label">Amount is</span>
            <select value={v.fee.price_basis} onChange={setFee('price_basis')}>
              <option value="inclusive">inclusive of GST</option>
              <option value="exclusive">exclusive (GST added)</option>
            </select>
          </label>
          <label className="field">
            <span className="field-label">SAC *</span>
            <input required className="mono" maxLength={6} value={v.fee.sac} onChange={setFee('sac')} />
            {err('fee.sac')}
          </label>
          <label className="field">
            <span className="field-label">GST rate (%) *</span>
            <input required inputMode="decimal" value={v.fee.gst_rate} onChange={setFee('gst_rate')} />
            {err('fee.gst_rate')}
          </label>
        </div>
        <p className="muted small">Use the fee, SAC and GST treatment defined for the course in academy.aicountly.org.</p>

        <h3 className="section-title section-title-tight">Student (as on the invoice)</h3>
        <div className="form-row">
          <label className="field">
            <span className="field-label">Name *</span>
            <input required value={v.payer.name} onChange={setPayer('name')} />
            {err('payer.name')}
          </label>
          <label className="field">
            <span className="field-label">Mobile *</span>
            <input required inputMode="tel" value={v.payer.mobile} onChange={setPayer('mobile')} placeholder="98765 43210" />
            {err('payer.mobile')}
          </label>
          <label className="field">
            <span className="field-label">Email</span>
            <input type="email" value={v.payer.email} onChange={setPayer('email')} />
            {err('payer.email')}
          </label>
        </div>
        <div className="form-row">
          <label className="field">
            <span className="field-label">State (place of supply) *</span>
            <select required value={v.payer.state_code} onChange={setPayer('state_code')}>
              <option value="">Choose…</option>
              {Object.entries(overview.states).map(([code, name]) => (
                <option key={code} value={code}>
                  {code} – {name}
                </option>
              ))}
            </select>
            {err('payer.state_code')}
          </label>
          <label className="field">
            <span className="field-label">PIN code</span>
            <input inputMode="numeric" maxLength={6} value={v.payer.pincode} onChange={setPayer('pincode')} />
            {err('payer.pincode')}
          </label>
        </div>
        <label className="field">
          <span className="field-label">Address</span>
          <textarea rows={2} value={v.payer.address} onChange={setPayer('address')} />
          <span className="field-help">Required (with the PIN) when the taxable value is ₹50,000 or more.</span>
          {err('payer.address')}
        </label>

        <h3 className="section-title section-title-tight">Link</h3>
        <div className="form-row">
          <label className="field">
            <span className="field-label">Gateway</span>
            <select value={v.provider} onChange={(e) => set({ provider: e.target.value })}>
              {overview.providers.map((p) => (
                <option key={p.id} value={p.provider}>
                  {p.label} ({p.mode}){p.is_default ? ' — default' : ''}
                </option>
              ))}
            </select>
          </label>
          <label className="field">
            <span className="field-label">Expires</span>
            <input type="datetime-local" value={v.expire_at} onChange={(e) => set({ expire_at: e.target.value })} />
            <span className="field-help">Default: 15 days.</span>
            {err('expire_at')}
          </label>
        </div>
        <p>
          <button type="button" className="button button-outline" onClick={compute} disabled={busy}>
            Compute GST
          </button>
        </p>
        {preview ? (
          <Notice tone="info">
            {preview.supply_type === 'intra_state' ? 'Intra-state' : 'Inter-state'} supply, place of supply {preview.place_of_supply} – {preview.place_of_supply_name}: taxable{' '}
            {inrPaise(preview.fee.taxable_paise)}
            {preview.supply_type === 'intra_state'
              ? ` + CGST ${inrPaise(preview.fee.cgst_paise)} + SGST ${inrPaise(preview.fee.sgst_paise)}`
              : ` + IGST ${inrPaise(preview.fee.igst_paise)}`}{' '}
            = the student pays <strong>{inrPaise(preview.amount_paise)}</strong>
          </Notice>
        ) : null}
        <ErrorBanner message={error} />
        <div className="dialog-actions">
          <button type="button" className="button button-outline" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="submit" className="button" disabled={busy}>
            {busy ? 'Working…' : 'Raise link for approval'}
          </button>
        </div>
      </form>
    </div>
  )
}

function LinksTab({ overview, reloadKey }: { overview: Overview; reloadKey: number }) {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? ''
  const stream = params.get('stream') ?? ''
  const [search, setSearch] = useState(params.get('q') ?? '')
  const q = params.get('q') ?? ''
  const { data, error } = useApiData(() => academyApi.links({ status, stream, q }), [status, stream, q, reloadKey])
  const setFilter = (k: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(k, value)
    else next.delete(k)
    setParams(next)
  }

  return (
    <>
      <div className="toolbar">
        <label className="field-inline">
          <span>Status</span>
          <select value={status} onChange={(e) => setFilter('status', e.target.value)}>
            <option value="">All</option>
            {['pending_approval', 'created', 'paid', 'failed', 'expired', 'cancelled', 'rejected'].map((s) => (
              <option key={s} value={s}>
                {statusLabel(s)}
              </option>
            ))}
          </select>
        </label>
        <label className="field-inline">
          <span>Course</span>
          <select value={stream} onChange={(e) => setFilter('stream', e.target.value)}>
            <option value="">Both</option>
            {Object.entries(overview.streams).map(([k, label]) => (
              <option key={k} value={k}>
                {label}
              </option>
            ))}
          </select>
        </label>
        <form
          className="field-inline"
          onSubmit={(e) => {
            e.preventDefault()
            setFilter('q', search.trim())
          }}
        >
          <input placeholder="Student, mobile, ref, lead" value={search} onChange={(e) => setSearch(e.target.value)} />
          <button className="button button-outline" type="submit">
            Search
          </button>
        </form>
      </div>
      <ErrorBanner message={error} />
      {data && data.rows.length === 0 ? <p className="muted">No payment links.</p> : null}
      {data && data.rows.length > 0 ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Student / fee</th>
                <th>Refs</th>
                <th className="num">Amount</th>
                <th>Status</th>
                <th>Gateway</th>
                <th>Raised</th>
              </tr>
            </thead>
            <tbody>
              {data.rows.map((l) => (
                <tr key={l.id}>
                  <td>
                    <Link to={`/academy/links/${l.id}`}>{l.payer_name}</Link>
                    <div className="muted small">{l.description}</div>
                  </td>
                  <td className="small mono">
                    {l.academy_ref}
                    <div className="muted">{l.source_ref ?? '—'}</div>
                  </td>
                  <td className="num">
                    {inrPaise(l.amount_paise)}
                    {l.amount_paid_paise > 0 && l.amount_paid_paise !== l.amount_paise ? <div className="muted small">paid {inrPaise(l.amount_paid_paise)}</div> : null}
                  </td>
                  <td>
                    <StatusBadge status={l.status} />
                  </td>
                  <td className="small">
                    {l.provider} {l.mode === 'test' ? <span className="badge badge-pending">test</span> : null}
                    <div className="muted">{l.source_app === 'engage' ? 'from Engage' : 'raised in Finance'}</div>
                  </td>
                  <td className="small">
                    {dateTime(l.created_at)}
                    <div className="muted">{l.created_by}</div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </>
  )
}

function PaymentsTab() {
  const [params, setParams] = useSearchParams()
  const verification = params.get('verification') ?? ''
  const { data, error, reload } = useApiData(() => academyApi.payments({ verification }), [verification])
  const [msg, setMsg] = useState('')
  const [actionError, setActionError] = useState('')
  return (
    <>
      <div className="toolbar">
        <label className="field-inline">
          <span>Verification</span>
          <select
            value={verification}
            onChange={(e) => {
              const next = new URLSearchParams(params)
              if (e.target.value) next.set('verification', e.target.value)
              else next.delete('verification')
              setParams(next)
            }}
          >
            <option value="">All</option>
            <option value="pending">Pending</option>
            <option value="verified">Verified</option>
            <option value="mismatch">Mismatch</option>
          </select>
        </label>
      </div>
      <ErrorBanner message={error || actionError} />
      {msg ? <Notice tone="ok">{msg}</Notice> : null}
      {data && data.rows.length === 0 ? <p className="muted">No gateway payments yet.</p> : null}
      {data && data.rows.length > 0 ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Paid</th>
                <th>Student</th>
                <th>Gateway payment</th>
                <th className="num">Amount</th>
                <th>Verified with provider</th>
                <th>Invoice</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data.rows.map((p) => (
                <tr key={p.id}>
                  <td className="small">{dateTime(p.paid_at)}</td>
                  <td>
                    <Link to={`/academy/links/${p.link_id}`}>{p.payer_name}</Link>
                    <div className="muted small mono">{p.academy_ref}</div>
                  </td>
                  <td className="small">
                    {p.provider} · {p.method ?? '—'}
                    <div className="mono">{p.gateway_payment_ref ?? p.provider_payment_id}</div>
                  </td>
                  <td className="num">
                    {inrPaise(p.amount_paise)}
                    {p.refunded_paise > 0 ? <div className="muted small">refunded {inrPaise(p.refunded_paise)}</div> : null}
                  </td>
                  <td>
                    <StatusBadge status={p.verification_status} />
                    {p.verification_error && p.verification_status !== 'verified' ? <div className="muted small">{p.verification_error}</div> : null}
                  </td>
                  <td>
                    {p.invoice ? <Link to={`/invoices/${p.invoice.id}`}>{p.invoice.doc_no}</Link> : <StatusBadge status={p.invoice_state} />}
                    {p.invoice_error && !p.invoice ? <div className="muted small">{p.invoice_error}</div> : null}
                  </td>
                  <td>
                    {p.can.retry_invoice ? (
                      <button
                        className="button button-outline"
                        onClick={() =>
                          academyApi
                            .retryInvoice(p.id)
                            .then((r) => {
                              setMsg(r.message)
                              reload()
                            })
                            .catch((e: Error) => setActionError(e.message))
                        }
                      >
                        Retry invoice
                      </button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </>
  )
}

function WebhooksTab() {
  const { data, error } = useApiData(() => academyApi.webhookEvents({}), [])
  const [open, setOpen] = useState<number | null>(null)
  return (
    <>
      <p className="muted small">
        Every signed delivery from Razorpay / Cashfree is stored once (replays are counted, not processed again); payloads are stored without personal or
        card / bank / UPI details. Rejected = the signature did not verify (check the webhook secret in Settings → Payment gateways).
      </p>
      <ErrorBanner message={error} />
      {data && data.rows.length === 0 ? <p className="muted">No webhook deliveries yet.</p> : null}
      {data && data.rows.length > 0 ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Received</th>
                <th>Provider / event</th>
                <th>Outcome</th>
                <th>Link</th>
                <th className="num">Deliveries</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data.rows.map((w) => (
                <tr key={w.id}>
                  <td className="small">{dateTime(w.received_at)}</td>
                  <td className="small">
                    {w.provider} · {w.event_type ?? '—'}
                    <div className="muted mono">{w.provider_event_id ?? ''}</div>
                  </td>
                  <td>
                    <StatusBadge status={w.outcome} />
                    {w.detail ? <div className="muted small">{w.detail}</div> : null}
                  </td>
                  <td className="small">{w.link_id ? <Link to={`/academy/links/${w.link_id}`}>{w.link_ref}</Link> : '—'}</td>
                  <td className="num">{w.deliveries}</td>
                  <td>
                    {w.payload ? (
                      <button className="link-button" onClick={() => setOpen(open === w.id ? null : w.id)}>
                        {open === w.id ? 'Hide' : 'Payload'}
                      </button>
                    ) : null}
                    {open === w.id ? <pre className="small mono">{JSON.stringify(w.payload, null, 2)}</pre> : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </>
  )
}

export function EngageEventsTable({ rows, canRequeue, onChanged }: { rows: EngageEvent[]; canRequeue: boolean; onChanged: () => void }) {
  const [actionError, setActionError] = useState('')
  if (rows.length === 0) return <p className="muted">No events.</p>
  return (
    <>
      <ErrorBanner message={actionError} />
      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th>Event</th>
              <th>Lead / fee</th>
              <th className="num">Amount</th>
              <th>State</th>
              <th className="num">Attempts</th>
              <th>Last error</th>
              <th>When</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {rows.map((o) => (
              <tr key={o.id}>
                <td className="small">
                  {o.event_type}
                  <div className="muted mono">{o.event_id}</div>
                </td>
                <td className="small mono">
                  {o.source_ref ?? '—'}
                  <div className="muted">
                    <Link to={`/academy/links/${o.link_id}`}>{o.academy_ref}</Link>
                  </div>
                </td>
                <td className="num">{inrPaise(o.amount_paise)}</td>
                <td>
                  <StatusBadge status={o.status} />
                </td>
                <td className="num">{o.attempts}</td>
                <td className="small">{o.last_error ?? '—'}</td>
                <td className="small">{o.status === 'delivered' ? dateTime(o.delivered_at) : `next ${dateTime(o.next_attempt_at)}`}</td>
                <td>
                  {o.status === 'dead' && canRequeue ? (
                    <button
                      className="button button-outline"
                      onClick={() =>
                        academyApi
                          .requeueEngageEvent(o.id)
                          .then(onChanged)
                          .catch((e: Error) => setActionError(e.message))
                      }
                    >
                      Retry
                    </button>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}

function EngageTab() {
  const { data, error, reload } = useApiData(() => academyApi.engageEvents(), [])
  return (
    <>
      <p className="muted small">
        payment.succeeded / payment.refunded sent to Engage (AIC-S2S signed) by <code>bin/academy-fees-cron.php</code> every 5 minutes with retry and back-off.
        Engage confirms the payment on the lead (seller commission) and tells Academy the fee is paid.
      </p>
      <ErrorBanner message={error} />
      {data ? (
        <>
          {!data.configured ? (
            <Notice tone="warn">Not configured (ENGAGE_API_BASE_URL / FINANCE_OUTBOUND_ENGAGE_S2S_KEY_ID / _SECRET): events are queued, nothing is sent.</Notice>
          ) : null}
          <p className="small">
            {data.counts.pending ?? 0} pending · {data.counts.delivered ?? 0} delivered · {data.counts.dead ?? 0} dead
          </p>
          <EngageEventsTable rows={data.recent} canRequeue={data.can_requeue} onChanged={reload} />
        </>
      ) : null}
    </>
  )
}

export function AcademyFeesPage() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') ?? 'links'
  const overview = useApiData(() => academyApi.overview(), [])
  const [creating, setCreating] = useState(false)
  const [done, setDone] = useState('')
  const [reloadKey, setReloadKey] = useState(0)
  const o = overview.data

  return (
    <>
      <PageHeader
        title="Academy fees"
        subtitle="Aicountly Edge and Aicountly Aspire fees collected through payment links on Aicountly's Razorpay / Cashfree accounts. Engage asks for links for its leads; staff can raise one here (someone else approves it). Paid links are verified with the provider, invoiced and reported to Engage."
        actions={
          o?.can.raise ? (
            <button className="button" disabled={o.providers.length === 0 || !o.readiness.seller_profile} onClick={() => setCreating(true)}>
              Raise payment link
            </button>
          ) : null
        }
      />
      <ErrorBanner message={overview.error} />
      {o && o.providers.length === 0 ? (
        <Notice tone="warn">
          No payment gateway is connected yet. A finance admin adds the Razorpay / Cashfree keys in <Link to="/settings#gateways">Settings → Payment gateways</Link>.
        </Notice>
      ) : null}
      {o && (!o.readiness.seller_profile || !o.readiness.invoice_series) ? (
        <Notice tone="warn">
          Invoicing is not set up for FY {o.readiness.fy} ({!o.readiness.seller_profile ? 'no seller profile' : 'no tax invoice series'}): paid fees wait for their invoice
          until a finance admin completes <Link to="/settings#invoicing">Settings → Invoicing</Link>.
        </Notice>
      ) : null}
      {done ? <Notice tone="ok">{done}</Notice> : null}
      {o ? (
        <div className="tiles tiles-compact">
          <Tile label="Awaiting approval" value={o.counts.awaiting_approval ?? 0} />
          <Tile label="Open links" value={o.counts.open ?? 0} />
          <Tile label="Paid" value={o.counts.paid ?? 0} />
          <Tile label="Failed to create" value={o.counts.failed ?? 0} />
          <Tile label="Verification pending / mismatch" value={`${o.counts.verification_pending ?? 0} / ${o.counts.verification_mismatch ?? 0}`} />
          <Tile label="Invoice blocked" value={o.counts.invoice_blocked ?? 0} />
          <Tile label="Engage callbacks dead" value={o.counts.engage_dead ?? 0} />
        </div>
      ) : null}
      <div className="tabs" role="tablist">
        {TABS.map((t) => (
          <button
            key={t.key}
            role="tab"
            aria-selected={tab === t.key}
            className={`tab${tab === t.key ? ' tab-active' : ''}`}
            onClick={() => setParams(t.key === 'links' ? {} : { tab: t.key })}
          >
            {t.label}
          </button>
        ))}
      </div>
      {o && tab === 'links' ? <LinksTab overview={o} reloadKey={reloadKey} /> : null}
      {tab === 'payments' ? <PaymentsTab /> : null}
      {tab === 'webhooks' ? <WebhooksTab /> : null}
      {tab === 'engage' ? <EngageTab /> : null}
      {creating && o ? (
        <CreateLinkDialog
          overview={o}
          onSaved={(msg) => {
            setDone(msg)
            setReloadKey((k) => k + 1)
            overview.reload()
          }}
          onClose={() => setCreating(false)}
        />
      ) : null}
    </>
  )
}
