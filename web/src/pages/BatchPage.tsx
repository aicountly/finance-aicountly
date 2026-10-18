import { useState, type ChangeEvent, type FormEvent, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { ErrorBanner, FormDialog, Notice, PageHeader, StatusBadge, type DialogField } from '../components/ui'
import { useApiData } from '../hooks/useApiData'
import { dateOnly, dateTime, inr, statusLabel, todayIst } from '../lib/format'
import { payoutsApi, type BankResponseResult, type BatchDetail, type PayoutItem } from '../lib/payoutsApi'

interface DialogSpec {
  title: string
  description?: ReactNode
  fields: DialogField[]
  submitLabel: string
  danger?: boolean
  run: (values: Record<string, string>) => Promise<unknown>
}

const reasonField = (label = 'Reason', placeholder?: string): DialogField => ({
  name: 'reason',
  label,
  type: 'textarea',
  required: true,
  placeholder,
})

export function BatchPage() {
  const id = Number(useParams().id)
  const { data: batch, error, loading, reload } = useApiData(() => payoutsApi.batch(id), [id])
  const [dialog, setDialog] = useState<DialogSpec | null>(null)
  const [generating, setGenerating] = useState(false)
  const [flash, setFlash] = useState('')
  const [actionError, setActionError] = useState('')

  async function act(fn: () => Promise<{ message?: string } | unknown>) {
    setActionError('')
    setFlash('')
    try {
      const r = (await fn()) as { message?: string } | undefined
      if (r && typeof r === 'object' && 'message' in r && r.message) setFlash(r.message)
      reload()
    } catch (e) {
      setActionError(e instanceof Error ? e.message : String(e))
    }
  }

  function open(spec: DialogSpec) {
    setDialog({
      ...spec,
      run: async (values) => {
        const r = (await spec.run(values)) as { message?: string } | undefined
        setFlash(r?.message ?? 'Done.')
        setActionError('')
        reload()
      },
    })
  }

  if (loading && !batch) return <p className="muted">Loading…</p>
  if (!batch) return <ErrorBanner message={error || 'Batch not found.'} />

  const can = batch.can
  const activeFiles = batch.bank_files.filter((f) => f.kind === 'payment' && !f.superseded)
  const approvable = batch.items.filter((i) => i.status === 'draft')

  return (
    <>
      <p className="crumbs">
        <Link to="/payouts">Payouts</Link> / {batch.batch_ref}
      </p>
      <PageHeader
        title={batch.batch_ref}
        subtitle={
          <>
            Week {dateOnly(batch.period_start)} – {dateOnly(batch.period_end)} · FY {batch.fy} · received {dateTime(batch.received_at)}{' '}
            <StatusBadge status={batch.status} />
          </>
        }
      />

      <div className="tiles tiles-compact">
        <div className="tile">
          <div className="tile-label">Gross commission</div>
          <div className="tile-value">{inr(batch.gross_amount)}</div>
        </div>
        <div className="tile">
          <div className="tile-label">GST</div>
          <div className="tile-value">{inr(batch.gst_amount)}</div>
        </div>
        <div className="tile">
          <div className="tile-label">TDS (194H)</div>
          <div className="tile-value">{inr(batch.tds_amount)}</div>
        </div>
        <div className="tile">
          <div className="tile-label">Adjustments</div>
          <div className="tile-value">{inr(batch.adjustment_total)}</div>
        </div>
        <div className="tile">
          <div className="tile-label">Net payable</div>
          <div className="tile-value">{inr(batch.payable_amount)}</div>
          <div className="tile-sub">excludes held / failed items</div>
        </div>
        <div className="tile">
          <div className="tile-label">Paid</div>
          <div className="tile-value">{inr(batch.paid_amount)}</div>
          <div className="tile-sub">
            {batch.paid_count} of {batch.item_count} item(s)
          </div>
        </div>
      </div>

      <dl className="meta">
        <div>
          <dt>Submitted for review</dt>
          <dd>{batch.prepared_by ? `${batch.prepared_by} · ${dateTime(batch.prepared_at)}` : '—'}</dd>
        </div>
        <div>
          <dt>Last edited</dt>
          <dd>{batch.last_edited_by ? `${batch.last_edited_by} · ${dateTime(batch.last_edited_at)}` : '—'}</dd>
        </div>
        <div>
          <dt>Approved</dt>
          <dd>{batch.approved_by ? `${batch.approved_by} · ${dateTime(batch.approved_at)}` : '—'}</dd>
        </div>
        {batch.status === 'cancelled' ? (
          <div>
            <dt>Cancelled</dt>
            <dd>
              {batch.cancelled_by} · {dateTime(batch.cancelled_at)} — {batch.cancel_reason}
            </dd>
          </div>
        ) : null}
      </dl>

      {batch.bank_changed_count > 0 ? (
        <Notice tone="warn">
          {batch.bank_changed_count} payee(s) have bank details that differ from their previous payout. Confirm the change
          with the seller before approving.
        </Notice>
      ) : null}
      {batch.status === 'in_review' && batch.four_eyes.blocked_reason ? <Notice tone="info">{batch.four_eyes.blocked_reason}</Notice> : null}

      <BatchActions batch={batch} approvable={approvable.length} act={act} open={open} onGenerate={() => setGenerating(true)} />

      {flash ? <Notice tone="ok">{flash}</Notice> : null}
      <ErrorBanner message={actionError} />

      <h2 className="section-title">Items</h2>
      <ItemsTable batch={batch} open={open} />

      {(activeFiles.length > 0 || batch.bank_files.length > 0) && (
        <>
          <h2 className="section-title">Bank files</h2>
          <BankFiles batch={batch} />
        </>
      )}

      {can.record_payments ? <BankResponseUpload batch={batch} onApplied={reload} /> : null}

      {generating ? (
        <GenerateFileDialog
          batch={batch}
          onDone={(message) => {
            setFlash(message)
            setActionError('')
            reload()
          }}
          onClose={() => setGenerating(false)}
        />
      ) : null}

      {dialog ? (
        <FormDialog
          title={dialog.title}
          description={dialog.description}
          fields={dialog.fields}
          submitLabel={dialog.submitLabel}
          danger={dialog.danger}
          onSubmit={async (v) => {
            await dialog.run(v)
          }}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </>
  )
}

function BatchActions({
  batch,
  approvable,
  act,
  open,
  onGenerate,
}: {
  batch: BatchDetail
  approvable: number
  act: (fn: () => Promise<unknown>) => Promise<void>
  open: (spec: DialogSpec) => void
  onGenerate: () => void
}) {
  const can = batch.can
  const regenerate = batch.status === 'file_generated'
  const buttons = []

  if (can.submit) {
    buttons.push(
      <button key="submit" className="button" onClick={() => act(() => payoutsApi.submit(batch.id))}>
        Submit for review
      </button>,
    )
  }
  if (can.edit) {
    buttons.push(
      <button key="recalc" className="button button-outline" onClick={() => act(() => payoutsApi.recalculate(batch.id))}>
        Recalculate GST / TDS
      </button>,
    )
  }
  if (can.return_to_draft) {
    buttons.push(
      <button
        key="return"
        className="button button-outline"
        onClick={() =>
          open({
            title: 'Return to draft',
            fields: [reasonField('What needs changing?')],
            submitLabel: 'Return to draft',
            run: (v) => payoutsApi.returnToDraft(batch.id, v.reason),
          })
        }
      >
        Return to draft
      </button>,
    )
  }
  if (can.approve) {
    buttons.push(
      <button
        key="approve"
        className="button"
        onClick={() =>
          open({
            title: 'Approve batch',
            description: (
              <>
                <p>
                  Approve {approvable} item(s), net {inr(batch.payable_amount)}. Held items stay on hold and are left out of
                  the bank file. Engage is told each item is approved.
                </p>
                <p className="muted small">Approving does not move money — the next step generates the bank upload file.</p>
              </>
            ),
            fields: [],
            submitLabel: 'Approve',
            run: () => payoutsApi.approve(batch.id, batch.version),
          })
        }
      >
        Approve batch
      </button>,
    )
  }
  if (can.generate_file) {
    buttons.push(
      <button
        key="file"
        className="button"
        disabled={!batch.debit_account_configured}
        title={batch.debit_account_configured ? undefined : 'Add the company bank account in Settings → Bank accounts first'}
        onClick={onGenerate}
      >
        {regenerate ? 'Regenerate bank file' : 'Generate bank file'}
      </button>,
    )
  }
  if (can.cancel) {
    buttons.push(
      <button
        key="cancel"
        className="button button-outline button-danger-outline"
        onClick={() =>
          open({
            title: 'Cancel batch',
            description: 'Every item is reported to Engage as failed, so its commission returns to the next weekly run.',
            fields: [reasonField('Cancellation reason')],
            submitLabel: 'Cancel batch',
            danger: true,
            run: (v) => payoutsApi.cancel(batch.id, v.reason),
          })
        }
      >
        Cancel batch
      </button>,
    )
  }
  if (buttons.length === 0) return null
  return (
    <div className="action-bar">
      {buttons}
      {can.generate_file && !batch.debit_account_configured ? (
        <span className="muted small">
          Add the company bank account in <Link to="/settings#bank-accounts">Settings → Bank accounts</Link> to generate the bank
          file.
        </span>
      ) : null}
    </div>
  )
}

function ItemsTable({ batch, open }: { batch: BatchDetail; open: (spec: DialogSpec) => void }) {
  const can = batch.can
  const actionsFor = (item: PayoutItem) => {
    const out = []
    if (can.edit && item.status === 'draft') {
      out.push(
        <button
          key="hold"
          className="link-button"
          onClick={() =>
            open({
              title: `Hold ${item.item_ref}`,
              fields: [reasonField('Hold reason', 'e.g. KYC document expired')],
              submitLabel: 'Put on hold',
              run: (v) => payoutsApi.hold(item.id, v.reason),
            })
          }
        >
          Hold
        </button>,
      )
    }
    if (can.edit && item.status === 'on_hold') {
      out.push(
        <button
          key="unhold"
          className="link-button"
          onClick={() =>
            open({
              title: `Release hold on ${item.item_ref}`,
              description: `Held because: ${item.hold_reason}`,
              fields: [reasonField('Why is it OK to pay now?')],
              submitLabel: 'Release hold',
              run: (v) => payoutsApi.unhold(item.id, v.reason),
            })
          }
        >
          Release hold
        </button>,
      )
    }
    if (can.edit && (item.status === 'draft' || item.status === 'on_hold')) {
      out.push(
        <button
          key="adjust"
          className="link-button"
          onClick={() =>
            open({
              title: `Adjust ${item.item_ref}`,
              description: `Net now ${inr(item.net_amount)}. An adjustment changes the amount payable only; GST and TDS stay on the commission Engage sent.`,
              fields: [
                { name: 'amount', label: 'Amount (₹, negative to deduct)', required: true, placeholder: '-250.00' },
                reasonField('Reason'),
              ],
              submitLabel: 'Add adjustment',
              run: (v) => payoutsApi.adjust(item.id, v.amount, v.reason),
            })
          }
        >
          Adjust
        </button>,
      )
    }
    if (can.record_payments && item.status === 'approved') {
      out.push(
        <button
          key="pay"
          className="link-button"
          onClick={() =>
            open({
              title: `Record payment for ${item.item_ref}`,
              description: `${item.bank_holder_name} · ${item.bank_account_masked} · ${item.bank_ifsc} · ${inr(item.net_amount)}. Enter the UTR from the bank — an item is never marked paid without one.`,
              fields: [
                { name: 'utr', label: 'UTR / bank reference', required: true, placeholder: 'HDFCN52026101912345' },
                { name: 'paid_at', label: 'Payment date', type: 'date', initial: todayIst() },
              ],
              submitLabel: 'Mark paid',
              run: (v) => payoutsApi.pay(item.id, v.utr, v.paid_at),
            })
          }
        >
          Record UTR
        </button>,
        <button
          key="fail"
          className="link-button link-danger"
          onClick={() =>
            open({
              title: `Mark ${item.item_ref} failed`,
              description: 'Use when the bank rejected or returned the transfer before credit. Engage returns the commission to the next run.',
              fields: [reasonField('Failure reason', 'e.g. Account closed')],
              submitLabel: 'Mark failed',
              danger: true,
              run: (v) => payoutsApi.fail(item.id, v.reason),
            })
          }
        >
          Failed
        </button>,
      )
    }
    if (can.release_held && item.status === 'on_hold') {
      out.push(
        <button
          key="release"
          className="link-button"
          onClick={() =>
            open({
              title: `Release ${item.item_ref} back to Engage`,
              description: 'The item is not paid in this batch. Engage returns its commission entries to the next weekly run.',
              fields: [reasonField()],
              submitLabel: 'Release to Engage',
              run: (v) => payoutsApi.fail(item.id, v.reason),
            })
          }
        >
          Release to Engage
        </button>,
      )
    }
    if (can.record_payments && item.status === 'paid') {
      out.push(
        <button
          key="reverse"
          className="link-button link-danger"
          onClick={() =>
            open({
              title: `Reverse ${item.item_ref}`,
              description: `Paid with UTR ${item.utr}. Use when the bank returned a credited payment.`,
              fields: [reasonField('Reversal reason')],
              submitLabel: 'Mark reversed',
              danger: true,
              run: (v) => payoutsApi.reverse(item.id, v.reason),
            })
          }
        >
          Reversed
        </button>,
      )
    }
    return out
  }

  return (
    <div className="table-wrap">
      <table className="table table-items">
        <thead>
          <tr>
            <th>Item / payee</th>
            <th>PAN · GSTIN</th>
            <th>Bank</th>
            <th className="num">Gross</th>
            <th className="num">GST</th>
            <th className="num">TDS</th>
            <th className="num">Adj.</th>
            <th className="num">Net</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {batch.items.map((item) => (
            <tr key={item.id}>
              <td>
                <div className="strong">{item.legal_name}</div>
                <div className="muted small nowrap">
                  <Link to={`/payees/${item.payee_id}`}>{item.seller_code}</Link> · {item.entity_type}
                </div>
                <div className="muted small nowrap">{item.item_ref}</div>
              </td>
              <td className="mono small">
                {item.pan_masked}
                <br />
                {item.gstin ?? <span className="muted">no GSTIN</span>}
              </td>
              <td className="small">
                {item.bank_holder_name}
                <br />
                <span className="mono nowrap">{item.bank_account_masked}</span>
                <br />
                <span className="mono nowrap">{item.bank_ifsc}</span>
                {item.bank_changed ? <div className="flag">bank changed since last payout</div> : null}
              </td>
              <td className="num">{inr(item.gross_amount)}</td>
              <td className="num" title={item.gst_mode === 'none' ? 'No GST' : `${item.gst_mode} @ ${item.gst_rate}%`}>
                {inr(item.gst_amount)}
              </td>
              <td className="num" title={item.tds_note ?? undefined}>
                {inr(item.tds_amount)}
                <div className="muted small">{item.tds_rate}% · {item.tds_section}</div>
              </td>
              <td className="num">
                {inr(item.adjustment_total)}
                {item.adjustments.map((a) => (
                  <div key={a.id} className="muted small adj-note" title={`${a.created_by} · ${dateTime(a.created_at)}`}>
                    {inr(a.amount)}: {a.reason}
                  </div>
                ))}
              </td>
              <td className="num strong">{inr(item.net_amount)}</td>
              <td>
                <StatusBadge status={item.status} />
                {item.hold_reason ? <div className="muted small">{item.hold_reason}</div> : null}
                {item.failure_reason ? <div className="muted small">{item.failure_reason}</div> : null}
                {item.utr ? (
                  <div className="mono small">
                    UTR {item.utr}
                    <br />
                    {dateOnly(item.paid_at)}
                  </div>
                ) : null}
                {item.engage_sync ? <div className="muted small">Engage: {statusLabel(item.engage_sync)}</div> : null}
              </td>
              <td>
                <div className="row-actions">{actionsFor(item)}</div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** Generate / regenerate the bank file: which company account it debits (default preselected), format, value date. */
function GenerateFileDialog({ batch, onDone, onClose }: { batch: BatchDetail; onDone: (message: string) => void; onClose: () => void }) {
  const regenerate = batch.status === 'file_generated'
  const accounts = batch.bank_accounts
  const initial = accounts.find((a) => a.is_default) ?? accounts[0]
  const formatFor = (id: number | undefined) => accounts.find((a) => a.id === id)?.bank_file_format ?? batch.default_bank_file_format
  const [accountId, setAccountId] = useState<number | undefined>(initial?.id)
  const [format, setFormat] = useState(() => formatFor(initial?.id))
  const [valueDate, setValueDate] = useState(todayIst())
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const account = accounts.find((a) => a.id === accountId)
  const chosenFormat = batch.bank_file_formats[format]

  function pickAccount(id: number) {
    setAccountId(id)
    setFormat(formatFor(id)) // the account's own layout, else the Settings default
  }

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (!account) {
      setError('Pick the account to debit.')
      return
    }
    setBusy(true)
    setError('')
    try {
      const r = await payoutsApi.generateFile(batch.id, { bank_account_id: account.id, format, value_date: valueDate, reason: regenerate ? reason : undefined })
      onDone(r.message || 'Bank file generated.')
      onClose()
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onClick={onClose}>
      <form
        className="dialog"
        role="dialog"
        aria-modal="true"
        aria-label={regenerate ? 'Regenerate bank file' : 'Generate bank file'}
        onClick={(e) => e.stopPropagation()}
        onSubmit={submit}
      >
        <h2>{regenerate ? 'Regenerate bank file' : 'Generate bank file'}</h2>
        <div className="dialog-description">
          {regenerate ? (
            <Notice tone="warn">The current file will be marked superseded. Upload only the new file — uploading both pays twice.</Notice>
          ) : (
            <p>Creates the bulk-upload CSV for the approved items. Download it and upload it to your bank's portal.</p>
          )}
        </div>
        <label className="field">
          <span className="field-label">Debit account *</span>
          <select value={accountId ?? ''} onChange={(e) => pickAccount(Number(e.target.value))} required>
            {accounts.map((a) => (
              <option key={a.id} value={a.id}>
                {a.label} · {a.account_number_masked}
                {a.is_default ? ' (default)' : ''}
              </option>
            ))}
          </select>
          {account ? (
            <span className="field-help">
              {[account.bank_name, account.branch, account.ifsc, account.account_type_label].filter(Boolean).join(' · ')}
              {account.incomplete ? ' — details incomplete in Settings → Bank accounts' : ''}
            </span>
          ) : null}
        </label>
        <label className="field">
          <span className="field-label">Format</span>
          <select value={format} onChange={(e) => setFormat(e.target.value)}>
            {Object.entries(batch.bank_file_formats).map(([value, f]) => (
              <option key={value} value={value}>
                {f.label}
              </option>
            ))}
          </select>
          <span className="field-help">
            {account?.bank_file_format ? "Preselected: this account's format." : 'Preselected: the Settings default.'}
          </span>
          {chosenFormat?.confirm_format ? <span className="flag">Confirm this format with your bank before uploading.</span> : null}
        </label>
        <label className="field">
          <span className="field-label">Value date *</span>
          <input type="date" value={valueDate} min={todayIst()} required onChange={(e) => setValueDate(e.target.value)} />
        </label>
        {regenerate ? (
          <label className="field">
            <span className="field-label">Why regenerate? *</span>
            <textarea value={reason} required rows={3} onChange={(e) => setReason(e.target.value)} />
          </label>
        ) : null}
        <ErrorBanner message={error} />
        <div className="dialog-actions">
          <button type="button" className="button button-outline" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="submit" className="button" disabled={busy || !account}>
            {busy ? 'Working…' : regenerate ? 'Regenerate' : 'Generate'}
          </button>
        </div>
      </form>
    </div>
  )
}

function BankFiles({ batch }: { batch: BatchDetail }) {
  const [error, setError] = useState('')
  return (
    <>
      <ErrorBanner message={error} />
      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th>File</th>
              <th>Kind</th>
              <th className="num">Lines</th>
              <th className="num">Total</th>
              <th>SHA-256</th>
              <th>Created</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {batch.bank_files.map((f) => (
              <tr key={f.id} className={f.superseded ? 'row-muted' : undefined}>
                <td>
                  {f.filename}
                  <div className="muted small">{f.format_label}</div>
                  {f.confirm_format && f.kind === 'payment' ? <div className="flag">Confirm this format with your bank before uploading.</div> : null}
                  {f.debit_account ? (
                    <div className="small">
                      Debits <span className="strong">{f.debit_account.label}</span>{' '}
                      <span className="mono nowrap">{f.debit_account.account_number_masked}</span>
                    </div>
                  ) : null}
                  {f.superseded ? <div className="flag">Superseded — do not upload</div> : null}
                  {f.note ? <div className="muted small">{f.note}</div> : null}
                </td>
                <td>{f.kind === 'payment' ? 'Payment upload' : 'Bank response'}</td>
                <td className="num">{f.item_count}</td>
                <td className="num">{f.kind === 'payment' ? inr(f.total_amount) : '—'}</td>
                <td className="mono small" title={f.sha256}>
                  {f.sha256.slice(0, 16)}…
                </td>
                <td className="small">
                  {f.created_by}
                  <br />
                  {dateTime(f.created_at)}
                </td>
                <td>
                  {batch.can.download_file ? (
                    <button
                      className="button button-outline"
                      onClick={() => payoutsApi.downloadFile(batch.id, f).catch((e: Error) => setError(e.message))}
                    >
                      Download
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

function BankResponseUpload({ batch, onApplied }: { batch: BatchDetail; onApplied: () => void }) {
  const [csv, setCsv] = useState('')
  const [fileName, setFileName] = useState('')
  const [preview, setPreview] = useState<BankResponseResult | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState('')

  async function pick(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    setPreview(null)
    setDone('')
    setError('')
    if (!file) return
    if (file.size > 2_000_000) {
      setError('The file is larger than 2 MB.')
      return
    }
    setFileName(file.name)
    setCsv(await file.text())
  }

  async function run(dryRun: boolean) {
    setBusy(true)
    setError('')
    try {
      const { data, message } = await payoutsApi.bankResponse(batch.id, csv, dryRun)
      setPreview(data)
      if (!dryRun) {
        setDone(`${message} ${data.counts.paid} paid, ${data.counts.failed} failed, ${data.counts.skipped} skipped.`)
        setCsv('')
        onApplied()
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="card">
      <h2 className="section-title section-title-tight">Upload the bank's response file</h2>
      <p className="muted small">
        CSV with a header row: a reference column (the Customer Reference / REF_NO from the upload file), a UTR column and
        optionally status, date and remarks. Preview first — nothing changes until you apply. Lines that report success
        without a UTR are never marked paid.
      </p>
      <div className="toolbar">
        <input type="file" accept=".csv,text/csv" onChange={pick} />
        <button className="button button-outline" disabled={!csv || busy} onClick={() => run(true)}>
          Preview
        </button>
        <button className="button" disabled={!csv || busy || !preview || preview.counts.paid + preview.counts.failed === 0} onClick={() => run(false)}>
          Apply
        </button>
        {fileName ? <span className="muted small">{fileName}</span> : null}
      </div>
      <ErrorBanner message={error} />
      {done ? <Notice tone="ok">{done}</Notice> : null}
      {preview ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th className="num">Line</th>
                <th>Reference</th>
                <th>UTR</th>
                <th>Date</th>
                <th>{preview.dry_run ? 'Will do' : 'Result'}</th>
              </tr>
            </thead>
            <tbody>
              {preview.lines.map((l) => (
                <tr key={l.line}>
                  <td className="num">{l.line}</td>
                  <td>{l.reference || '—'}</td>
                  <td className="mono small">{l.utr ?? '—'}</td>
                  <td>{l.paid_at ?? '—'}</td>
                  <td>
                    <StatusBadge status={l.action === 'skip' ? 'skipped' : l.action} /> <span className="small">{l.message}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </section>
  )
}
