// Typed client for Finance's payout endpoints (server-php app/Config/Routes.php).
// Money is always an INR string with two decimals ("1234.50") — never a float.

import { apiCall, apiDownload, apiGet } from './financeApi'

export type BatchStatus = 'draft' | 'in_review' | 'approved' | 'file_generated' | 'partially_paid' | 'paid' | 'cancelled'
export type ItemStatus = 'draft' | 'on_hold' | 'approved' | 'paid' | 'failed' | 'reversed'

export interface BatchSummary {
  id: number
  batch_ref: string
  period_start: string
  period_end: string
  fy: string
  status: BatchStatus
  version: number
  received_at: string
  prepared_by: string | null
  approved_by: string | null
  approved_at: string | null
  item_count: number
  held_count: number
  paid_count: number
  failed_count: number
  bank_changed_count: number
  gross_amount: string
  gst_amount: string
  tds_amount: string
  net_amount: string
  payable_amount: string
  paid_amount: string
}

export interface Adjustment {
  id: number
  amount: string
  reason: string
  created_by: string
  created_at: string
}

export interface PayoutItem {
  id: number
  item_ref: string
  payee_id: number
  seller_code: string
  legal_name: string
  entity_type: string
  pan_masked: string
  gstin: string | null
  bank_holder_name: string
  bank_account_masked: string
  bank_ifsc: string
  bank_changed: boolean
  entry_ids: Array<string | number>
  gross_amount: string
  taxable_amount: string
  gst_mode: string
  gst_rate: string
  gst_amount: string
  tds_section: string
  tds_rate: string
  tds_amount: string
  tds_note: string | null
  adjustment_total: string
  net_amount: string
  status: ItemStatus
  hold_reason: string | null
  failure_reason: string | null
  utr: string | null
  paid_at: string | null
  payment_recorded_by: string | null
  engage_sync: 'pending' | 'delivered' | 'dead' | null
  adjustments: Adjustment[]
}

export interface BankFileFormat {
  label: string
  confirm_format: boolean
}

export interface BankFile {
  id: number
  kind: 'payment' | 'response'
  format: string
  format_label: string
  confirm_format: boolean
  filename: string
  sha256: string
  item_count: number
  total_amount: string
  superseded: boolean
  note: string | null
  created_by: string
  created_at: string
}

export interface BatchDetail extends BatchSummary {
  last_edited_by: string | null
  last_edited_at: string | null
  prepared_at: string | null
  cancelled_by: string | null
  cancelled_at: string | null
  cancel_reason: string | null
  adjustment_total: string
  four_eyes: { enabled: boolean; blocked_reason: string | null }
  can: {
    edit: boolean
    submit: boolean
    return_to_draft: boolean
    approve: boolean
    cancel: boolean
    generate_file: boolean
    download_file: boolean
    record_payments: boolean
    release_held: boolean
  }
  items: PayoutItem[]
  bank_files: BankFile[]
  bank_file_formats: Record<string, BankFileFormat>
  debit_account_configured: boolean
}

export interface BankResponseLine {
  line: number
  reference: string
  outcome: 'paid' | 'failed' | 'skip'
  action: 'paid' | 'failed' | 'skip'
  utr: string | null
  paid_at: string | null
  reason: string | null
  item_id: number | null
  message: string
}

export interface BankResponseResult {
  dry_run: boolean
  columns: Record<string, string | null>
  counts: { paid: number; failed: number; skipped: number }
  lines: BankResponseLine[]
}

export interface Payee {
  id: number
  seller_code: string
  legal_name: string
  entity_type: string
  pan_masked: string
  gstin: string | null
  bank_holder_name: string
  bank_account_masked: string
  bank_ifsc: string
  created_at: string
  updated_at: string
  last_paid_at: string | null
  fy: string
  fy_paid_net: string
  fy_cumulative: string
  fy_tds_deducted: string
}

export interface PayeeDetail extends Payee {
  items: Array<{
    id: number
    item_ref: string
    status: ItemStatus
    gross_amount: string
    gst_amount: string
    tds_amount: string
    adjustment_total: string
    net_amount: string
    utr: string | null
    paid_at: string | null
    bank_changed: boolean
    bank_account_masked: string
    bank_ifsc: string
    batch_id: number
    batch_ref: string
    period_start: string
    period_end: string
    batch_status: BatchStatus
  }>
}

export interface Dashboard {
  fy: string
  batches_by_status: Record<BatchStatus, number>
  awaiting_review: { items: number; net_amount: string }
  awaiting_payment: { items: number; net_amount: string }
  on_hold: { items: number; net_amount: string }
  paid_this_fy: { items: number; gross_amount: string; gst_amount: string; tds_amount: string; net_amount: string }
  failed_this_fy: number
  payees: number
  last_batch_received_at: string | null
  engage_callbacks: { pending: number; delivered: number; dead: number; configured: boolean }
  open_batches: BatchSummary[]
}

export interface TdsRow {
  deductee_pan: string
  deductee_name: string
  deductee_code: string
  entity_type: string
  seller_code: string
  section: string
  amount_paid: string
  tds_rate: string
  tds_amount: string
  date_of_payment: string
  date_of_deduction: string
  gst_amount: string
  net_paid: string
  utr: string | null
  item_ref: string
  batch_ref: string
  note: string | null
}

export interface TdsRegister {
  fy: string
  quarter: string
  from: string
  to: string
  rows: TdsRow[]
  totals: { rows: number; rows_with_tds: number; amount_paid: string; tds_amount: string }
}

export interface SettingsValues {
  tds_rate_percent: string
  tds_threshold_per_fy: string
  gst_mode: 'none' | 'exclusive' | 'inclusive'
  gst_rate_percent: string
  minimum_payout: string
  bank_file_format: string
  payment_mode: 'NEFT' | 'IMPS' | 'RTGS'
  debit_account_number: string
  narration_prefix: string
  four_eyes_enabled: 'true' | 'false'
}

export interface SettingsPayload {
  values: SettingsValues
  can_edit: boolean
  options: { gst_mode: string[]; payment_mode: string[]; bank_file_format: Record<string, BankFileFormat> }
}

export interface AuditRow {
  id: number
  occurred_at: string
  actor: string
  actor_name: string | null
  action: string
  entity_type: string
  entity_id: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  ip: string | null
}

export interface AuditPage {
  rows: AuditRow[]
  total: number
  page: number
  per_page: number
}

export interface OutboxOverview {
  counts: { pending: number; delivered: number; dead: number }
  configured: boolean
  recent: Array<{
    id: number
    event_key: string
    status: 'pending' | 'delivered' | 'dead'
    attempts: number
    next_attempt_at: string
    last_http_status: number | null
    last_error: string | null
    created_at: string
    delivered_at: string | null
    batch_ref: string
    item_ref: string
    event_status: string
  }>
}

const q = (params: Record<string, string | number | undefined>) => {
  const s = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '') s.set(k, String(v))
  }
  const str = s.toString()
  return str ? `?${str}` : ''
}

export const payoutsApi = {
  dashboard: () => apiGet<Dashboard>('/v1/dashboard'),
  batches: (status?: string) => apiGet<BatchSummary[]>(`/v1/payout-batches${q({ status })}`),
  batch: (id: number) => apiGet<BatchDetail>(`/v1/payout-batches/${id}`),
  submit: (id: number) => apiCall<BatchDetail>('POST', `/v1/payout-batches/${id}/submit`, {}),
  returnToDraft: (id: number, reason: string) => apiCall<BatchDetail>('POST', `/v1/payout-batches/${id}/return`, { reason }),
  recalculate: (id: number) => apiCall<BatchDetail>('POST', `/v1/payout-batches/${id}/recalculate`, {}),
  approve: (id: number, version: number) => apiCall<BatchDetail>('POST', `/v1/payout-batches/${id}/approve`, { version }),
  cancel: (id: number, reason: string) => apiCall<BatchDetail>('POST', `/v1/payout-batches/${id}/cancel`, { reason }),
  generateFile: (id: number, body: { format?: string; value_date?: string; reason?: string }) =>
    apiCall<BankFile>('POST', `/v1/payout-batches/${id}/bank-file`, body),
  downloadFile: (id: number, file: BankFile) => apiDownload(`/v1/payout-batches/${id}/bank-files/${file.id}`, file.filename),
  bankResponse: (id: number, csv: string, dryRun: boolean) =>
    apiCall<BankResponseResult>('POST', `/v1/payout-batches/${id}/bank-response`, { csv, dry_run: dryRun }),

  hold: (itemId: number, reason: string) => apiCall('POST', `/v1/payout-items/${itemId}/hold`, { reason }),
  unhold: (itemId: number, reason: string) => apiCall('POST', `/v1/payout-items/${itemId}/unhold`, { reason }),
  adjust: (itemId: number, amount: string, reason: string) => apiCall('POST', `/v1/payout-items/${itemId}/adjustments`, { amount, reason }),
  pay: (itemId: number, utr: string, paidAt?: string) => apiCall('POST', `/v1/payout-items/${itemId}/payment`, { utr, paid_at: paidAt || undefined }),
  fail: (itemId: number, reason: string) => apiCall('POST', `/v1/payout-items/${itemId}/fail`, { reason }),
  reverse: (itemId: number, reason: string) => apiCall('POST', `/v1/payout-items/${itemId}/reverse`, { reason }),

  payees: (search?: string) => apiGet<Payee[]>(`/v1/payees${q({ q: search })}`),
  payee: (id: number) => apiGet<PayeeDetail>(`/v1/payees/${id}`),
  tdsRegister: (fy: string, quarter: string) => apiGet<TdsRegister>(`/v1/tds-register${q({ fy, quarter })}`),
  tdsExport: (fy: string, quarter: string) => apiDownload(`/v1/tds-register/export${q({ fy, quarter })}`, `tds-194H-${fy}-${quarter}.csv`),
  settings: () => apiGet<SettingsPayload>('/v1/settings'),
  saveSettings: (values: Partial<SettingsValues>) => apiCall<SettingsPayload>('PUT', '/v1/settings', values),
  auditLog: (filters: { entity_type?: string; entity_id?: string; actor?: string; action?: string; page?: number }) =>
    apiGet<AuditPage>(`/v1/audit-log${q(filters)}`),
  outbox: () => apiGet<OutboxOverview>('/v1/outbox'),
  requeue: (id: number) => apiCall('POST', `/v1/outbox/${id}/requeue`, {}),
}
