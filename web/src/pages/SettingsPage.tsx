import { useEffect, useState, type FormEvent } from 'react'
import { BankAccountsSection } from '../components/BankAccounts'
import { ErrorBanner, Notice, PageHeader, StatusBadge } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { dateTime } from '../lib/format'
import { useFinanceAccess } from '../lib/financeRoles'
import { payoutsApi, type SettingsValues } from '../lib/payoutsApi'

const GST_LABELS: Record<string, string> = {
  none: 'None — never add GST',
  exclusive: 'Exclusive — add GST on top of the commission (payees with a GSTIN)',
  inclusive: 'Inclusive — the commission already includes GST',
}

export function SettingsPage() {
  const { data, error, reload } = useApiData(() => payoutsApi.settings(), [])
  const [values, setValues] = useState<SettingsValues | null>(null)
  const [saveError, setSaveError] = useState('')
  const [saved, setSaved] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (data) setValues(data.values)
  }, [data])

  async function save(e: FormEvent) {
    e.preventDefault()
    if (!values || !data) return
    setBusy(true)
    setSaveError('')
    setSaved('')
    // debit_account_number is read-only now (Bank accounts below): never sent back.
    const changed = Object.fromEntries(
      Object.entries(values).filter(([k, v]) => k !== 'debit_account_number' && data.values[k as keyof SettingsValues] !== v),
    ) as Partial<SettingsValues>
    try {
      if (Object.keys(changed).length === 0) {
        setSaved('Nothing changed.')
      } else {
        await payoutsApi.saveSettings(changed)
        setSaved('Settings saved. New batches use them; existing drafts change only when someone presses Recalculate.')
        reload()
      }
    } catch (err) {
      setSaveError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  const editable = data?.can_edit ?? false
  const set = (k: keyof SettingsValues) => (e: { target: { value: string } }) => values && setValues({ ...values, [k]: e.target.value })

  return (
    <>
      <PageHeader title="Settings" subtitle={editable ? 'Payout and tax settings.' : 'Payout and tax settings (only a Finance Administrator can change them).'} />
      <ErrorBanner message={error} />
      {values && data ? (
        <form className="card form-grid" onSubmit={save}>
          <h2 className="section-title section-title-tight">Tax</h2>
          <Notice tone="warn">
            Defaults: TDS 2.00% under section 194H with a ₹20,000 per-FY threshold per PAN, and GST 18% added on top for
            payees with a GSTIN. Confirm the current rates, the threshold and the GST treatment of commission with your CA
            before the first payout.
          </Notice>
          <label className="field">
            <span className="field-label">TDS rate (%) — section 194H</span>
            <input value={values.tds_rate_percent} onChange={set('tds_rate_percent')} disabled={!editable} />
          </label>
          <label className="field">
            <span className="field-label">TDS threshold per FY per PAN (₹)</span>
            <input value={values.tds_threshold_per_fy} onChange={set('tds_threshold_per_fy')} disabled={!editable} />
            <span className="field-help">No TDS until a payee's FY total exceeds this; the payment that crosses it bears TDS on the whole FY total.</span>
          </label>
          <label className="field">
            <span className="field-label">GST on commission</span>
            <select value={values.gst_mode} onChange={set('gst_mode')} disabled={!editable}>
              {data.options.gst_mode.map((m) => (
                <option key={m} value={m}>
                  {GST_LABELS[m] ?? m}
                </option>
              ))}
            </select>
          </label>
          <label className="field">
            <span className="field-label">GST rate (%)</span>
            <input value={values.gst_rate_percent} onChange={set('gst_rate_percent')} disabled={!editable} />
          </label>

          <h2 className="section-title section-title-tight">Payouts</h2>
          <label className="field">
            <span className="field-label">Minimum payout (₹)</span>
            <input value={values.minimum_payout} onChange={set('minimum_payout')} disabled={!editable} />
            <span className="field-help">Items whose net is below this arrive on hold. 0 = off (Engage already rolls small amounts over).</span>
          </label>
          <label className="field">
            <span className="field-label">Four-eyes approval</span>
            <select value={values.four_eyes_enabled} onChange={set('four_eyes_enabled')} disabled={!editable}>
              <option value="true">On — the approver must not have submitted or last edited the batch</option>
              <option value="false">Off</option>
            </select>
          </label>

          <h2 className="section-title section-title-tight">Bank file</h2>
          <p className="muted small">
            The company account the bank debits is chosen from <a href="#bank-accounts">Bank accounts</a> below.
          </p>
          <label className="field">
            <span className="field-label">Default format</span>
            <select value={values.bank_file_format} onChange={set('bank_file_format')} disabled={!editable}>
              {Object.entries(data.options.bank_file_format).map(([k, f]) => (
                <option key={k} value={k}>
                  {f.label}
                </option>
              ))}
            </select>
            <span className="field-help">Used for bank accounts that do not set their own format.</span>
          </label>
          <label className="field">
            <span className="field-label">Payment mode</span>
            <select value={values.payment_mode} onChange={set('payment_mode')} disabled={!editable}>
              {data.options.payment_mode.map((m) => (
                <option key={m}>{m}</option>
              ))}
            </select>
          </label>
          <label className="field">
            <span className="field-label">Narration prefix</span>
            <input value={values.narration_prefix} onChange={set('narration_prefix')} disabled={!editable} />
          </label>
          <ErrorBanner message={saveError} />
          {saved ? <Notice tone="ok">{saved}</Notice> : null}
          {editable ? (
            <div>
              <button className="button" type="submit" disabled={busy}>
                {busy ? 'Saving…' : 'Save settings'}
              </button>
            </div>
          ) : null}
        </form>
      ) : null}
      <BankAccountsSection />
      <EngageCallbacks />
    </>
  )
}

function EngageCallbacks() {
  const { can } = useFinanceAccess()
  const { data, error, reload } = useApiData(() => payoutsApi.outbox(), [])
  const [actionError, setActionError] = useState('')

  return (
    <section className="card">
      <h2 className="section-title section-title-tight">Engage status callbacks</h2>
      <p className="muted small">
        Approved / on hold / paid / failed / reversed updates for each item, delivered to Engage every 5 minutes by{' '}
        <code>bin/outbox-deliver.php</code> with retry and back-off.
      </p>
      <ErrorBanner message={error || actionError} />
      {data ? (
        <>
          {!data.configured ? (
            <Notice tone="warn">Not configured (ENGAGE_API_BASE_URL / ENGAGE_FINANCE_KEY): updates are queued, nothing is sent.</Notice>
          ) : null}
          <p className="small">
            {data.counts.pending} pending · {data.counts.delivered} delivered · {data.counts.dead} dead
          </p>
          {data.recent.length > 0 ? (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Update</th>
                    <th>State</th>
                    <th className="num">Attempts</th>
                    <th>Last error</th>
                    <th>When</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {data.recent.map((o) => (
                    <tr key={o.id}>
                      <td className="small">
                        {o.item_ref}
                        <div className="muted">{o.batch_ref}</div>
                      </td>
                      <td>{o.event_status}</td>
                      <td>
                        <StatusBadge status={o.status} />
                      </td>
                      <td className="num">{o.attempts}</td>
                      <td className="small">{o.last_error ?? '—'}</td>
                      <td className="small">{o.status === 'delivered' ? dateTime(o.delivered_at) : `next ${dateTime(o.next_attempt_at)}`}</td>
                      <td>
                        {o.status === 'dead' && can('settings') ? (
                          <button
                            className="button button-outline"
                            onClick={() =>
                              payoutsApi
                                .requeue(o.id)
                                .then(reload)
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
          ) : null}
        </>
      ) : null}
    </section>
  )
}
