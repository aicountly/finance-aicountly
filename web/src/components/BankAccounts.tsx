import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { useLocation } from 'react-router'
import { ErrorBanner, FormDialog, Notice, type DialogField } from './ui'
import { useApiData } from '../hooks/useApiData'
import { ApiError } from '../lib/financeApi'
import { dateTime } from '../lib/format'
import {
  payoutsApi,
  type BankAccountInput,
  type BankAccountsPayload,
  type CompanyAccountType,
  type CompanyBankAccount,
} from '../lib/payoutsApi'

// Same rules as CompanyBankAccountService::validate in server-php (the server re-checks everything).
export const IFSC_PATTERN = /^[A-Z]{4}0[A-Z0-9]{6}$/
export const ACCOUNT_NUMBER_PATTERN = /^[0-9]{6,18}$/

interface FormValues {
  label: string
  bank_name: string
  account_holder: string
  account_number: string
  account_number_confirm: string
  ifsc: string
  branch: string
  account_type: CompanyAccountType
  bank_file_format: string
  is_default: boolean
}

const digits = (v: string) => v.replace(/[\s-]+/g, '')

/** Field errors for the add / edit form; adding requires the account number, editing keeps it when left blank. */
export function validateBankAccount(v: FormValues, adding: boolean): Record<string, string> {
  const errors: Record<string, string> = {}
  const text = (key: keyof FormValues, label: string, max: number, required: boolean) => {
    const value = String(v[key]).trim()
    if (!value) {
      if (required) errors[key] = `${label} is required.`
    } else if (value.length > max) {
      errors[key] = `At most ${max} characters.`
    }
  }
  text('label', 'A label', 60, true)
  text('bank_name', 'The bank name', 80, true)
  text('account_holder', 'The account holder name', 100, true)
  text('branch', 'Branch', 80, false)
  const ifsc = v.ifsc.replace(/\s+/g, '').toUpperCase()
  if (!ifsc) errors.ifsc = 'The IFSC is required.'
  else if (!IFSC_PATTERN.test(ifsc)) errors.ifsc = 'An IFSC is 11 characters: 4 letters, 0, then 6 letters or digits (e.g. HDFC0001234).'
  const number = digits(v.account_number)
  if (adding || number) {
    if (!number) errors.account_number = 'The account number is required.'
    else if (!ACCOUNT_NUMBER_PATTERN.test(number)) errors.account_number = 'An account number is 6 to 18 digits.'
    else if (digits(v.account_number_confirm) !== number) errors.account_number_confirm = 'The two account numbers do not match.'
  }
  return errors
}

function initialValues(account: CompanyBankAccount | null, firstAccount: boolean): FormValues {
  return {
    label: account?.label ?? '',
    bank_name: account?.bank_name ?? '',
    account_holder: account?.account_holder ?? '',
    account_number: '',
    account_number_confirm: '',
    ifsc: account?.ifsc ?? '',
    branch: account?.branch ?? '',
    account_type: account?.account_type ?? 'current',
    bank_file_format: account?.bank_file_format ?? '',
    is_default: firstAccount,
  }
}

function BankAccountDialog({
  account,
  payload,
  onSaved,
  onClose,
}: {
  account: CompanyBankAccount | null
  payload: BankAccountsPayload
  onSaved: (message: string) => void
  onClose: () => void
}) {
  const adding = account === null
  const firstAccount = adding && !payload.accounts.some((a) => a.active)
  const [values, setValues] = useState<FormValues>(() => initialValues(account, firstAccount))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const defaultFormat = payload.options.bank_file_format[payload.default_bank_file_format]?.label ?? payload.default_bank_file_format

  const set = (k: keyof FormValues) => (e: { target: { value: string } }) => setValues({ ...values, [k]: e.target.value })

  async function submit(e: FormEvent) {
    e.preventDefault()
    const found = validateBankAccount(values, adding)
    setErrors(found)
    setError('')
    if (Object.keys(found).length > 0) return
    const body: BankAccountInput = {
      label: values.label.trim(),
      bank_name: values.bank_name.trim(),
      account_holder: values.account_holder.trim(),
      ifsc: values.ifsc.replace(/\s+/g, '').toUpperCase(),
      branch: values.branch.trim(),
      account_type: values.account_type,
      bank_file_format: values.bank_file_format,
    }
    if (digits(values.account_number)) {
      body.account_number = digits(values.account_number)
      body.account_number_confirm = digits(values.account_number_confirm)
    }
    if (adding) body.is_default = values.is_default
    setBusy(true)
    try {
      const r = adding ? await payoutsApi.createBankAccount(body) : await payoutsApi.updateBankAccount(account.id, body)
      onSaved(r.message || 'Saved.')
      onClose()
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, String(v)])))
      }
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  const field = (name: keyof FormValues, label: string, input: ReactNode, help?: ReactNode, required = false) => (
    <label className="field">
      <span className="field-label">
        {label}
        {required ? ' *' : ''}
      </span>
      {input}
      {errors[name] ? <span className="field-error">{errors[name]}</span> : help ? <span className="field-help">{help}</span> : null}
    </label>
  )

  return (
    <div className="dialog-backdrop" role="presentation" onClick={onClose}>
      <form
        className="dialog"
        role="dialog"
        aria-modal="true"
        aria-label={adding ? 'Add bank account' : `Edit ${account.label}`}
        onClick={(e) => e.stopPropagation()}
        onSubmit={submit}
        noValidate
      >
        <h2>{adding ? 'Add bank account' : `Edit ${account.label}`}</h2>
        {account?.incomplete ? (
          <Notice tone="warn">Moved here from the old debit account setting — add the bank name, IFSC and account holder.</Notice>
        ) : null}
        {field('label', 'Label', <input value={values.label} onChange={set('label')} placeholder="e.g. HDFC current — payouts" maxLength={60} />, 'How it appears in the bank file dialog and batch view.', true)}
        {field('bank_name', 'Bank name', <input value={values.bank_name} onChange={set('bank_name')} placeholder="HDFC Bank" maxLength={80} />, undefined, true)}
        {field('account_holder', 'Account holder', <input value={values.account_holder} onChange={set('account_holder')} placeholder="Company name as the bank has it" maxLength={100} />, undefined, true)}
        {field(
          'account_number',
          adding ? 'Account number' : 'New account number',
          <input
            value={values.account_number}
            onChange={set('account_number')}
            inputMode="numeric"
            autoComplete="off"
            placeholder={adding ? '6 to 18 digits' : `Leave blank to keep ${account.account_number_masked}`}
          />,
          adding ? '6 to 18 digits. Stored encrypted; only the last four digits are ever shown.' : `Currently ${account.account_number_masked}. Leave blank to keep it.`,
          adding,
        )}
        {adding || digits(values.account_number)
          ? field(
              'account_number_confirm',
              'Confirm account number',
              <input
                value={values.account_number_confirm}
                onChange={set('account_number_confirm')}
                inputMode="numeric"
                autoComplete="off"
                onPaste={(e) => e.preventDefault()}
              />,
              'Type it again (pasting is disabled).',
              true,
            )
          : null}
        {field('ifsc', 'IFSC', <input value={values.ifsc} onChange={(e) => setValues({ ...values, ifsc: e.target.value.toUpperCase() })} placeholder="HDFC0001234" maxLength={11} />, undefined, true)}
        {field('branch', 'Branch', <input value={values.branch} onChange={set('branch')} maxLength={80} />)}
        {field(
          'account_type',
          'Account type',
          <select value={values.account_type} onChange={set('account_type')}>
            {Object.entries(payload.options.account_type).map(([k, label]) => (
              <option key={k} value={k}>
                {label}
              </option>
            ))}
          </select>,
        )}
        {field(
          'bank_file_format',
          'Bank file format',
          <select value={values.bank_file_format} onChange={set('bank_file_format')}>
            <option value="">Settings default ({defaultFormat})</option>
            {Object.entries(payload.options.bank_file_format).map(([k, f]) => (
              <option key={k} value={k}>
                {f.label}
              </option>
            ))}
          </select>,
          'The layout this bank accepts. Preselected when a bank file is generated from this account.',
        )}
        {adding ? (
          <label className="field-inline field-check">
            <input
              type="checkbox"
              checked={values.is_default}
              disabled={firstAccount}
              onChange={(e) => setValues({ ...values, is_default: e.target.checked })}
            />
            <span>{firstAccount ? 'The first account is the default.' : 'Make this the default account'}</span>
          </label>
        ) : null}
        <ErrorBanner message={error} />
        <div className="dialog-actions">
          <button type="button" className="button button-outline" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="submit" className="button" disabled={busy}>
            {busy ? 'Saving…' : adding ? 'Add account' : 'Save'}
          </button>
        </div>
      </form>
    </div>
  )
}

interface ConfirmSpec {
  title: string
  description: ReactNode
  fields: DialogField[]
  submitLabel: string
  danger?: boolean
  run: (values: Record<string, string>) => Promise<{ message: string }>
}

/** Settings → Bank accounts: the company accounts seller payouts are debited from. */
export function BankAccountsSection() {
  const { data, error, reload } = useApiData(() => payoutsApi.bankAccounts(), [])
  const [editing, setEditing] = useState<CompanyBankAccount | 'new' | null>(null)
  const [confirm, setConfirm] = useState<ConfirmSpec | null>(null)
  const [flash, setFlash] = useState('')
  const [actionError, setActionError] = useState('')
  const sectionRef = useRef<HTMLElement>(null)
  const { hash } = useLocation()

  useEffect(() => {
    if (hash === '#bank-accounts' && data) sectionRef.current?.scrollIntoView({ block: 'start' })
  }, [hash, data])

  async function act(fn: () => Promise<{ message: string }>) {
    setActionError('')
    setFlash('')
    try {
      setFlash((await fn()).message)
      reload()
    } catch (e) {
      setActionError(e instanceof Error ? e.message : String(e))
    }
  }

  function deactivate(account: CompanyBankAccount, accounts: CompanyBankAccount[]) {
    const others = accounts.filter((a) => a.active && a.id !== account.id)
    const needsNewDefault = account.is_default && others.length > 0
    setConfirm({
      title: `Deactivate ${account.label}`,
      description: needsNewDefault ? (
        <p>This is the default account. Choose which account becomes the default.</p>
      ) : others.length === 0 ? (
        <Notice tone="warn">This is the only active account. Bank files cannot be generated until an account is active again.</Notice>
      ) : (
        <p>It will no longer be offered when a bank file is generated. Files already generated keep showing it.</p>
      ),
      fields: needsNewDefault
        ? [
            {
              name: 'new_default_id',
              label: 'New default account',
              type: 'select',
              options: others.map((a) => ({ value: String(a.id), label: `${a.label} · ${a.account_number_masked}` })),
            },
          ]
        : [],
      submitLabel: 'Deactivate',
      danger: true,
      run: (v) => payoutsApi.deactivateBankAccount(account.id, v.new_default_id ? Number(v.new_default_id) : undefined),
    })
  }

  const accounts = data?.accounts ?? []
  const anyActive = accounts.some((a) => a.active)
  const formatLabel = (a: CompanyBankAccount) =>
    a.bank_file_format_label ?? `Settings default (${data?.options.bank_file_format[data.default_bank_file_format]?.label ?? data?.default_bank_file_format})`

  return (
    <section className="card" id="bank-accounts" ref={sectionRef}>
      <div className="section-head">
        <h2 className="section-title section-title-tight">Bank accounts</h2>
        {data?.can_edit ? (
          <button type="button" className="button" onClick={() => setEditing('new')}>
            Add bank account
          </button>
        ) : null}
      </div>
      <p className="muted small">
        The company accounts seller payouts are paid from. A bank file debits the default account unless the approver picks
        another when generating it. Account numbers are stored encrypted; only the last four digits are ever shown.
        {data && !data.can_edit ? ' Only a Finance Administrator can add or change them.' : ''}
      </p>
      <ErrorBanner message={error || actionError} />
      {flash ? <Notice tone="ok">{flash}</Notice> : null}
      {data && !anyActive ? (
        <Notice tone="warn">
          No active bank account — bank files cannot be generated until {data.can_edit ? 'you add one' : 'a Finance Administrator adds one'}.
        </Notice>
      ) : null}
      {accounts.length > 0 ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Account</th>
                <th>Bank</th>
                <th>Number · IFSC</th>
                <th>Bank file format</th>
                <th>Updated</th>
                {data?.can_edit ? <th>Actions</th> : null}
              </tr>
            </thead>
            <tbody>
              {accounts.map((a) => (
                <tr key={a.id} className={a.active ? undefined : 'row-muted'}>
                  <td>
                    <span className="strong">{a.label}</span>{' '}
                    {a.is_default ? <span className="badge badge-paid">Default</span> : null}
                    {!a.active ? <span className="badge badge-cancelled">Inactive</span> : null}
                    <div className="muted small">{a.account_type_label}</div>
                    {a.incomplete ? <div className="flag">Details incomplete — add bank name, IFSC and holder</div> : null}
                  </td>
                  <td className="small">
                    {a.bank_name ?? <span className="muted">—</span>}
                    {a.branch ? <div className="muted">{a.branch}</div> : null}
                    {a.account_holder ? <div className="muted">{a.account_holder}</div> : null}
                  </td>
                  <td className="mono small nowrap">
                    {a.account_number_masked}
                    <br />
                    {a.ifsc ?? <span className="muted">no IFSC</span>}
                  </td>
                  <td className="small">{formatLabel(a)}</td>
                  <td className="small">
                    {a.updated_by ?? a.created_by}
                    <br />
                    {dateTime(a.updated_at)}
                  </td>
                  {data?.can_edit ? (
                    <td>
                      <div className="row-actions">
                        <button type="button" className="link-button" onClick={() => setEditing(a)}>
                          Edit
                        </button>
                        {a.active && !a.is_default ? (
                          <button type="button" className="link-button" onClick={() => act(() => payoutsApi.setDefaultBankAccount(a.id))}>
                            Make default
                          </button>
                        ) : null}
                        {a.active ? (
                          <button type="button" className="link-button link-danger" onClick={() => deactivate(a, accounts)}>
                            Deactivate
                          </button>
                        ) : (
                          <button type="button" className="link-button" onClick={() => act(() => payoutsApi.activateBankAccount(a.id))}>
                            Activate
                          </button>
                        )}
                      </div>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {editing && data ? (
        <BankAccountDialog
          account={editing === 'new' ? null : editing}
          payload={data}
          onSaved={(message) => {
            setActionError('')
            setFlash(message)
            reload()
          }}
          onClose={() => setEditing(null)}
        />
      ) : null}
      {confirm ? (
        <FormDialog
          title={confirm.title}
          description={confirm.description}
          fields={confirm.fields}
          submitLabel={confirm.submitLabel}
          danger={confirm.danger}
          onSubmit={async (v) => {
            const r = await confirm.run(v)
            setActionError('')
            setFlash(r.message)
            reload()
          }}
          onClose={() => setConfirm(null)}
        />
      ) : null}
    </section>
  )
}
