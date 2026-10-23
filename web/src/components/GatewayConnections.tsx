import { useState } from 'react'
import { ErrorBanner, FormDialog, Notice, type DialogField } from './ui'
import { useApiData } from '../hooks/useApiData'
import { academyApi, type GatewayConnection } from '../lib/academyApi'
import { dateTime } from '../lib/format'
import { useFinanceAccess } from '../lib/financeRoles'

/**
 * Settings → Payment gateways (finance_admin only): Aicountly's own Razorpay / Cashfree merchant accounts that Academy
 * fee links are created on. Secrets are write-only — the server stores them encrypted and never sends them back; this
 * screen shows only "set (…last4)".
 */
export function GatewayConnectionsSection() {
  const { isAdmin } = useFinanceAccess()
  if (!isAdmin) {
    return (
      <section className="card" id="gateways">
        <h2 className="section-title section-title-tight">Payment gateways</h2>
        <p className="muted small">Razorpay / Cashfree connections for Academy fee links are managed by a Finance Administrator.</p>
      </section>
    )
  }
  return <GatewayConnectionsAdmin />
}

function GatewayConnectionsAdmin() {
  const { data, error, reload } = useApiData(() => academyApi.connections(), [])
  const [adding, setAdding] = useState(false)
  const [editing, setEditing] = useState<GatewayConnection | null>(null)
  const [actionError, setActionError] = useState('')
  const [done, setDone] = useState('')

  const act = (fn: () => Promise<{ message: string }>) => {
    setActionError('')
    setDone('')
    fn()
      .then((r) => {
        setDone(r.message)
        reload()
      })
      .catch((e: Error) => setActionError(e.message))
  }

  const secretHelp = 'Pasted values are stored encrypted and never shown again. Leave blank to keep the stored one.'
  const addFields: DialogField[] = [
    {
      name: 'provider',
      label: 'Provider',
      type: 'select',
      options: [
        { value: 'razorpay', label: 'Razorpay' },
        { value: 'cashfree', label: 'Cashfree' },
      ],
    },
    {
      name: 'mode',
      label: 'Mode',
      type: 'select',
      options: [
        { value: 'test', label: 'Test (sandbox)' },
        { value: 'live', label: 'Live' },
      ],
    },
    { name: 'label', label: 'Label', placeholder: 'Razorpay — Aicountly (live)' },
    { name: 'key_id', label: 'Key id / App id', required: true, help: 'Razorpay: rzp_test_… / rzp_live_… · Cashfree: the App ID (x-client-id).' },
    { name: 'key_secret', label: 'Key secret / Secret key', type: 'password', required: true, help: 'Stored encrypted; never shown again.' },
    {
      name: 'webhook_secret',
      label: 'Webhook secret',
      type: 'password',
      help: 'Razorpay: the secret you set on the webhook (required for webhooks). Cashfree signs webhooks with the secret key — leave blank.',
    },
  ]

  return (
    <section className="card" id="gateways">
      <div className="section-head">
        <h2 className="section-title section-title-tight">Payment gateways</h2>
        <button className="button" onClick={() => setAdding(true)}>
          Add connection
        </button>
      </div>
      <p className="muted small">
        Aicountly's own Razorpay / Cashfree accounts for Aicountly Academy fee links. Add a connection, test it, then enable it; the default one is used when
        Engage or staff do not choose a provider. Point the provider's webhook at the URL below.
      </p>
      <ErrorBanner message={error || actionError} />
      {done ? <Notice tone="ok">{done}</Notice> : null}
      {data ? (
        <>
          <p className="small">
            Webhook URLs — Razorpay: <span className="mono">{data.webhook_urls.razorpay}</span> · Cashfree: <span className="mono">{data.webhook_urls.cashfree}</span>
          </p>
          {data.connections.length === 0 ? <p className="muted">No gateway connected.</p> : null}
          {data.connections.length > 0 ? (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th>Connection</th>
                    <th>Key id</th>
                    <th>Secrets</th>
                    <th>Last test</th>
                    <th>State</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {data.connections.map((c) => (
                    <tr key={c.id}>
                      <td>
                        {c.label}
                        <div className="muted small">
                          {c.provider_label} · {c.mode === 'live' ? 'Live' : 'Test'}
                        </div>
                      </td>
                      <td className="mono small">{c.key_id}</td>
                      <td className="small">
                        API secret {c.key_secret}
                        <div className="muted">Webhook secret: {c.webhook_secret_set ? 'set' : (c.webhook_secret_note ?? 'not set — webhooks are refused')}</div>
                      </td>
                      <td className="small">
                        {c.last_test_at ? (
                          <>
                            <span className={`badge badge-${c.last_test_ok ? 'verified' : 'failed'}`}>{c.last_test_ok ? 'OK' : 'Failed'}</span> {dateTime(c.last_test_at)}
                            <div className="muted">{c.last_test_message}</div>
                          </>
                        ) : (
                          'Not tested'
                        )}
                      </td>
                      <td>
                        {c.enabled ? <span className="badge badge-verified">Enabled</span> : <span className="badge">Disabled</span>}{' '}
                        {c.is_default ? <span className="badge badge-pending">Default</span> : null}
                      </td>
                      <td>
                        <div className="row-actions">
                          <button className="button button-outline" onClick={() => act(() => academyApi.testConnection(c.id))}>
                            Test connection
                          </button>
                          {c.enabled ? (
                            <button className="link-button link-danger" onClick={() => act(() => academyApi.disableConnection(c.id))}>
                              Disable
                            </button>
                          ) : (
                            <button className="link-button" onClick={() => act(() => academyApi.enableConnection(c.id))}>
                              Enable
                            </button>
                          )}
                          {c.enabled && !c.is_default ? (
                            <button className="link-button" onClick={() => act(() => academyApi.defaultConnection(c.id))}>
                              Make default
                            </button>
                          ) : null}
                          <button className="link-button" onClick={() => setEditing(c)}>
                            Edit
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
        </>
      ) : null}
      {adding ? (
        <FormDialog
          title="Add a payment gateway connection"
          description="Saved disabled. Test it, then enable it."
          fields={addFields}
          submitLabel="Save"
          onSubmit={async (v) => {
            const r = await academyApi.createConnection({
              provider: v.provider as 'razorpay' | 'cashfree',
              mode: v.mode as 'test' | 'live',
              label: v.label,
              key_id: v.key_id,
              key_secret: v.key_secret,
              webhook_secret: v.webhook_secret || undefined,
            })
            setDone(r.message)
            reload()
          }}
          onClose={() => setAdding(false)}
        />
      ) : null}
      {editing ? (
        <FormDialog
          title={`Edit ${editing.label}`}
          description={secretHelp}
          fields={[
            { name: 'label', label: 'Label', initial: editing.label, required: true },
            { name: 'key_id', label: 'Key id / App id', initial: editing.key_id, required: true },
            { name: 'key_secret', label: 'New key secret', type: 'password', help: secretHelp },
            { name: 'webhook_secret', label: 'New webhook secret', type: 'password', help: secretHelp },
          ]}
          submitLabel="Save"
          onSubmit={async (v) => {
            const r = await academyApi.updateConnection(editing.id, {
              label: v.label,
              key_id: v.key_id,
              key_secret: v.key_secret || undefined,
              webhook_secret: v.webhook_secret || undefined,
            })
            setDone(r.message)
            reload()
          }}
          onClose={() => setEditing(null)}
        />
      ) : null}
    </section>
  )
}
