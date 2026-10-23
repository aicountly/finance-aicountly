// Typed client for Finance's Aicountly Academy fee endpoints (server-php app/Config/Routes.php, docs/ACADEMY_PAYMENTS.md).
// Amounts from the server are integer paise; amounts typed by staff are rupee strings ("11800.00") the server parses.
// Gateway secrets are write-only: the server never returns them, and this client never reads them back.

import { apiCall, apiGet } from './financeApi'

export type LinkStatus = 'pending_approval' | 'creating' | 'created' | 'partially_paid' | 'paid' | 'expired' | 'cancelled' | 'failed' | 'rejected'
export type Provider = 'razorpay' | 'cashfree'
export type Stream = 'edge' | 'aspire'

export interface Fee {
  taxable_paise: number
  cgst_paise: number
  sgst_paise: number
  igst_paise: number
  sac: string
  gst_rate: string
  price_basis: 'inclusive' | 'exclusive'
  /** A scholarship / coupon Academy or Engage gave: list price less discount, on the fee's price basis. */
  discount?: { list_amount_paise: number; discount_paise: number; discount_ref: string | null } | null
}

export interface GatewayPayment {
  id: number
  payment_ref: string
  provider: Provider
  mode: 'test' | 'live'
  provider_payment_id: string
  gateway_payment_ref: string | null
  provider_txn_ref: string | null
  amount_paise: number
  method: string | null
  paid_at: string | null
  verification_status: 'pending' | 'verified' | 'mismatch'
  verification_error: string | null
  verified_at: string | null
  refunded_paise: number
  invoice_state: 'not_issued' | 'issued' | 'blocked_config' | 'blocked_data'
  invoice_error: string | null
  invoice: { id: number; document_id: string; doc_no: string } | null
  created_at: string | null
}

export interface PaymentRow extends GatewayPayment {
  link_id: number
  link_ref: string
  academy_ref: string
  source_ref: string | null
  payer_name: string
  stream: Stream
  can: { retry_invoice: boolean }
}

export interface LinkRow {
  id: number
  link_id: string
  status: LinkStatus
  source_app: 'engage' | 'finance'
  source_ref: string | null
  academy_ref: string
  stream: Stream
  payer_name: string
  description: string
  amount_paise: number
  amount_paid_paise: number
  provider: Provider
  mode: 'test' | 'live'
  url: string | null
  expire_at: string | null
  created_by: string
  created_at: string | null
  paid_at: string | null
}

export interface LinkPage {
  rows: LinkRow[]
  total: number
  page: number
  per_page: number
  counts: Partial<Record<LinkStatus, number>>
}

export interface WebhookEvent {
  id: number
  provider: Provider
  event_type: string | null
  provider_event_id: string | null
  signature_valid: boolean
  outcome: 'received' | 'processed' | 'ignored' | 'rejected' | 'error'
  detail: string | null
  deliveries: number
  received_at: string
  processed_at: string | null
  link_id: number | null
  link_ref: string | null
  gateway_payment_id: number | null
  payload: unknown
}

export interface EngageEvent {
  id: number
  event_id: string
  event_type: 'payment.succeeded' | 'payment.refunded'
  status: 'pending' | 'delivered' | 'dead'
  attempts: number
  next_attempt_at: string
  last_http_status: number | null
  last_error: string | null
  created_at: string
  delivered_at: string | null
  link_id: number
  link_ref: string
  source_ref: string | null
  academy_ref: string
  amount_paise: number | null
}

export interface LinkDetail extends Omit<LinkRow, 'payer_name'> {
  currency: 'INR'
  product_code: string
  seller_code: string | null
  fee: Fee
  cancelled_at: string | null
  last_error: string | null
  payer: { name: string; email: string | null; mobile: string; state_code: string; state_name: string; address: string | null; pincode: string | null }
  place_of_supply: string
  supply_type: 'intra_state' | 'inter_state'
  provider_link_id: string | null
  provider_status: string | null
  approved_by: string | null
  approved_at: string | null
  rejected_by: string | null
  reject_reason: string | null
  cancelled_by: string | null
  cancel_reason: string | null
  last_synced_at: string | null
  create_attempts: number
  payments: GatewayPayment[]
  webhooks: WebhookEvent[]
  engage_events: EngageEvent[]
  can: { approve: boolean; reject: boolean; cancel: boolean; retry: boolean; refresh: boolean; blocked_reason: string | null }
}

export interface Overview {
  providers: Array<{ id: number; provider: Provider; mode: 'test' | 'live'; label: string; is_default: boolean }>
  readiness: { gateway: boolean; seller_profile: boolean; invoice_series: boolean; engage_inbound: boolean; engage_events: boolean; fy: string }
  counts: Record<string, number>
  streams: Record<Stream, string>
  states: Record<string, string>
  can: { raise: boolean; approve: boolean; admin: boolean }
}

export interface LinkInput {
  academy_ref: string
  source_ref: string
  stream: Stream
  product_code: string
  description: string
  payer: { name: string; email: string; mobile: string; state_code: string; address: string; pincode: string }
  amount: string
  fee: { sac: string; gst_rate: string; price_basis: 'inclusive' | 'exclusive' }
  expire_at: string
  provider: string
}

export interface LinkPreview {
  amount_paise: number
  fee: Fee
  place_of_supply: string
  place_of_supply_name: string
  seller_state: string
  supply_type: 'intra_state' | 'inter_state'
  expire_at: string
}

export interface GatewayConnection {
  id: number
  provider: Provider
  provider_label: string
  mode: 'test' | 'live'
  label: string
  key_id: string
  key_secret: string
  webhook_secret_set: boolean
  webhook_secret_note: string | null
  enabled: boolean
  is_default: boolean
  last_test_at: string | null
  last_test_ok: boolean | null
  last_test_message: string | null
  created_by: string
  created_at: string
  updated_by: string | null
  updated_at: string
}

export interface ConnectionsPayload {
  connections: GatewayConnection[]
  providers: Record<Provider, string>
  modes: Record<'test' | 'live', string>
  webhook_urls: Record<Provider, string>
}

export interface ConnectionInput {
  provider?: Provider
  mode?: 'test' | 'live'
  label?: string
  key_id?: string
  key_secret?: string
  webhook_secret?: string
  clear_webhook_secret?: boolean
}

const q = (params: Record<string, string | number | undefined>) => {
  const s = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '') s.set(k, String(v))
  }
  const str = s.toString()
  return str ? `?${str}` : ''
}

/** The form as the server wants it: blank optional fields left out. */
function linkBody(v: LinkInput) {
  return {
    academy_ref: v.academy_ref.trim(),
    source_ref: v.source_ref.trim() || undefined,
    stream: v.stream,
    product_code: v.product_code.trim() || undefined,
    description: v.description,
    payer: {
      name: v.payer.name,
      email: v.payer.email || undefined,
      mobile: v.payer.mobile,
      state_code: v.payer.state_code,
      address: v.payer.address || undefined,
      pincode: v.payer.pincode || undefined,
    },
    amount: v.amount,
    fee: v.fee,
    expire_at: v.expire_at ? new Date(v.expire_at).toISOString() : undefined,
    provider: v.provider || undefined,
  }
}

export const academyApi = {
  overview: () => apiGet<Overview>('/v1/academy/overview'),
  links: (f: { status?: string; stream?: string; q?: string; page?: number }) => apiGet<LinkPage>(`/v1/academy/payment-links${q(f)}`),
  link: (id: number) => apiGet<LinkDetail>(`/v1/academy/payment-links/${id}`),
  preview: (v: LinkInput) => apiCall<LinkPreview>('POST', '/v1/academy/payment-links/preview', linkBody(v)),
  create: (v: LinkInput) => apiCall<LinkDetail>('POST', '/v1/academy/payment-links', linkBody(v)),
  approve: (id: number) => apiCall<LinkDetail>('POST', `/v1/academy/payment-links/${id}/approve`, {}),
  reject: (id: number, reason: string) => apiCall<LinkDetail>('POST', `/v1/academy/payment-links/${id}/reject`, { reason }),
  cancel: (id: number, reason: string) => apiCall<LinkDetail>('POST', `/v1/academy/payment-links/${id}/cancel`, { reason }),
  retry: (id: number) => apiCall<LinkDetail>('POST', `/v1/academy/payment-links/${id}/retry`, {}),
  refresh: (id: number) => apiCall<LinkDetail>('POST', `/v1/academy/payment-links/${id}/refresh`, {}),

  payments: (f: { verification?: string; invoice?: string; page?: number }) =>
    apiGet<{ rows: PaymentRow[]; total: number; page: number; per_page: number }>(`/v1/academy/payments${q(f)}`),
  retryInvoice: (id: number) => apiCall<{ state: string }>('POST', `/v1/academy/payments/${id}/retry-invoice`, {}),

  webhookEvents: (f: { provider?: string; outcome?: string; page?: number }) =>
    apiGet<{ rows: WebhookEvent[]; total: number; page: number; per_page: number }>(`/v1/academy/webhook-events${q(f)}`),
  engageEvents: () =>
    apiGet<{ counts: Record<string, number>; recent: EngageEvent[]; configured: boolean; can_requeue: boolean }>('/v1/academy/engage-events'),
  requeueEngageEvent: (id: number) => apiCall<{ id: number }>('POST', `/v1/academy/engage-events/${id}/requeue`, {}),

  connections: () => apiGet<ConnectionsPayload>('/v1/gateway-connections'),
  createConnection: (body: ConnectionInput) => apiCall<GatewayConnection>('POST', '/v1/gateway-connections', body),
  updateConnection: (id: number, body: ConnectionInput) => apiCall<GatewayConnection>('PUT', `/v1/gateway-connections/${id}`, body),
  testConnection: (id: number) => apiCall<{ ok: boolean; message: string; connection: GatewayConnection }>('POST', `/v1/gateway-connections/${id}/test`, {}),
  enableConnection: (id: number) => apiCall<GatewayConnection>('POST', `/v1/gateway-connections/${id}/enable`, {}),
  disableConnection: (id: number) => apiCall<GatewayConnection>('POST', `/v1/gateway-connections/${id}/disable`, {}),
  defaultConnection: (id: number) => apiCall<GatewayConnection>('POST', `/v1/gateway-connections/${id}/default`, {}),
}
