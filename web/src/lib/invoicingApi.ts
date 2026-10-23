// Typed client for Finance's subscription invoicing endpoints (server-php app/Config/Routes.php, docs/INVOICING.md).
// Document amounts are integer paise (contract field names end in _paise); amounts typed by staff are rupee strings
// ("11800.00") that the server parses — never floats.

import { apiCall, apiDownload, apiGet } from './financeApi'

export type DocType = 'tax_invoice' | 'credit_note'
export type InvoiceStatus = 'issued' | 'partially_credited' | 'credited'
export type CreditNoteStatus = 'pending_approval' | 'issued' | 'rejected'

export interface PartyBlock {
  legal_name: string
  gstin: string | null
  state_code: string
  state_name: string
  address: string
  pincode: string | null
  country: string
  email: string | null
}

export interface SellerSnapshot {
  legal_name: string
  trade_name: string | null
  gstin: string
  pan: string
  address_line1: string
  address_line2: string | null
  city: string
  pincode: string
  state_code: string
  state_name: string
  email: string | null
  phone: string | null
  signatory_name: string
  signatory_designation: string | null
}

export interface DocumentLine {
  id: number
  line_no: number
  item_code: string | null
  item_kind: string | null
  description: string
  sac: string
  gst_rate: string
  price_basis: string | null
  period_start: string | null
  period_end: string | null
  quantity: string | null
  amount_paise: number | null
  taxable_paise: number
  cgst_rate: string
  cgst_paise: number
  sgst_rate: string
  sgst_paise: number
  igst_rate: string
  igst_paise: number
  total_paise: number
  /** Discounted line (docs/INVOICING.md "Discounted lines"); null on an ordinary line. */
  list_amount_paise?: number | null
  discount_paise?: number | null
  discount_ref?: string | null
  invoice_line_id: number | null
}

export interface Totals {
  taxable_paise: number
  cgst_paise: number
  sgst_paise: number
  igst_paise: number
  tax_paise: number
  total_paise: number
}

export interface Rendering {
  pdf_stored: boolean
  pdf_sha256: string | null
  html_sha256: string | null
  rendered_at: string | null
  renderer_configured: boolean
  last_error: string | null
}

interface DocumentBase {
  id: number
  document_id: string
  doc_no: string | null
  doc_date: string | null
  fy: string | null
  cmp_id: number | null
  buyer_kind: 'company' | 'academy_student'
  seller: SellerSnapshot | null
  buyer: PartyBlock
  place_of_supply: { code: string; name: string }
  supply_type: 'intra_state' | 'inter_state'
  reverse_charge: boolean
  lines: DocumentLine[]
  totals: Totals
  total_paise: number
  amount_in_words: string
  summary: string
  period_start: string | null
  period_end: string | null
  pdf_sha256: string | null
}

export interface CreditNoteRef {
  id: number
  document_id: string
  doc_no: string | null
  doc_date: string | null
  total_paise: number
  status: CreditNoteStatus
  kind: 'full' | 'partial'
}

export interface Invoice extends DocumentBase {
  doc_type: 'tax_invoice'
  status: InvoiceStatus
  credited_paise: number
  payment: { method: string; method_label: string; gateway: string | null; reference: string; paid_on: string; amount_paise: number }
  source: { kind: 'offline_payment' | 'payment'; offline_payment_id: number | null; payment_id: number | null }
  issued_by: string
  issued_at: string
  credit_notes: CreditNoteRef[]
  remaining_paise: Record<string, { taxable: number; cgst: number; sgst: number; igst: number; total: number }>
  rendering: Rendering
  credit_deadline: string
  can: { request_credit_note: boolean; approve_credit_note: boolean }
}

export interface CreditNote extends DocumentBase {
  doc_type: 'credit_note'
  status: CreditNoteStatus
  kind: 'full' | 'partial'
  reason: string
  original_invoice: { id: number; document_id: string; doc_no: string; doc_date: string; fy: string; total_paise: number } | null
  requested_by: string
  requested_at: string
  approved_by: string | null
  approved_at: string | null
  rejected_by: string | null
  reject_reason: string | null
  credit_deadline: string | null
  rendering?: Rendering
  can: { approve: boolean; reject: boolean; blocked_reason: string | null }
}

export interface RegisterRow {
  id: number
  doc_type: DocType
  document_id: string
  doc_no: string | null
  doc_date: string | null
  fy: string | null
  cmp_id: number | null
  buyer_kind: 'company' | 'academy_student'
  buyer_legal_name: string
  buyer_gstin: string | null
  place_of_supply: string
  taxable_paise: number
  tax_paise: number
  total_paise: number
  status: string
  invoice_id: number | null
}

export interface RegisterPage {
  rows: RegisterRow[]
  total: number
  page: number
  per_page: number
  totals: { invoiced_paise: number; credited_paise: number }
}

export interface RegisterFilters {
  fy?: string
  doc_type?: string
  status?: string
  cmp_id?: string
  buyer_kind?: string
  q?: string
  page?: number
}

export interface BuyerInput {
  legal_name: string
  gstin: string
  state_code: string
  address: string
  pincode: string
  email: string
}

export interface LineInput {
  item_code: string
  item_kind: string
  description: string
  sac: string
  gst_rate: string
  price_basis: 'inclusive' | 'exclusive'
  period_start: string
  period_end: string
  quantity: string
  amount: string
  /** Optional discount agreed before supply: list price and discount (₹, on the line's price basis) and the coupon code / quote ref. */
  list_amount?: string
  discount?: string
  discount_ref?: string
}

export interface OfflinePaymentInput {
  cmp_id: string
  buyer_uuid: string
  buyer: BuyerInput
  lines: LineInput[]
  amount: string
  method: string
  utr: string
  received_on: string
  note: string
}

export interface Preview {
  place_of_supply: string
  supply_type: 'intra_state' | 'inter_state'
  seller_state: string
  lines: Array<{ description: string; taxable_paise: number; cgst_paise: number; sgst_paise: number; igst_paise: number; total_paise: number
    amount_paise?: number; list_amount_paise?: number; discount_paise?: number; discount_ref?: string | null }>
  totals: { taxable_paise: number; cgst_paise: number; sgst_paise: number; igst_paise: number; total_paise: number }
}

export interface OfflinePayment {
  id: number
  cmp_id: number
  buyer_uuid: string | null
  buyer: { legal_name: string; gstin: string | null; state_code: string; address: string; pincode: string; country: string; email: string | null }
  lines: Array<{ description: string; sac: string; gst_rate: string; price_basis: string; amount_paise: number; period_start: string | null; period_end: string | null }>
  amount_paise: number
  method: string
  method_label: string
  utr: string
  received_on: string
  note: string | null
  status: 'recorded' | 'verified' | 'rejected'
  invoice_state: 'not_issued' | 'issued' | 'blocked_config' | 'blocked_data'
  invoice_error: string | null
  recorded_by: string
  recorded_at: string
  verified_by: string | null
  verified_at: string | null
  rejected_by: string | null
  rejected_at: string | null
  reject_reason: string | null
  invoice: { id: number; document_id: string; doc_no: string } | null
  can: { verify: boolean; reject: boolean; retry_invoice: boolean; blocked_reason: string | null }
}

export interface OfflinePaymentsPayload {
  payments: OfflinePayment[]
  methods: Record<string, string>
  can: { record: boolean; verify: boolean }
}

export interface SellerProfile extends SellerSnapshot {
  id: number
  effective_from: string
  active: boolean
  created_by: string
  created_at: string
  retired_at: string | null
}

export interface Series {
  id: number
  fy: string
  doc_type: DocType
  prefix: string
  width: number
  next_no: number
  next_doc_no: string | null
  used: number
  editable: boolean
  updated_at: string
}

export interface InvoicingSettings {
  profile: SellerProfile | null
  history: SellerProfile[]
  series: Series[]
  readiness: {
    seller_profile: boolean
    series: { tax_invoice: boolean; credit_note: boolean }
    fy: string
    pdf_renderer: boolean
    manage_inbound: boolean
  }
  states: Record<string, string>
  current_fy: string
  can_edit: boolean
}

export type SellerProfileInput = Omit<SellerSnapshot, 'state_code' | 'state_name' | 'pan'> & { pan: string; effective_from: string }

const q = (params: Record<string, string | number | undefined>) => {
  const s = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '') s.set(k, String(v))
  }
  const str = s.toString()
  return str ? `?${str}` : ''
}

const pdfName = (docNo: string | null) => `${(docNo ?? 'document').replace(/[^A-Za-z0-9-]/g, '-')}.pdf`

export const invoicingApi = {
  register: (f: RegisterFilters) => apiGet<RegisterPage>(`/v1/invoices${q({ ...f })}`),
  exportCsv: (f: RegisterFilters) => apiDownload(`/v1/invoices/export${q({ ...f, page: undefined })}`, `gstr1-${f.fy || 'all'}.csv`),
  invoice: (id: number) => apiGet<Invoice>(`/v1/invoices/${id}`),
  invoicePdf: (inv: { id: number; doc_no: string | null }) => apiDownload(`/v1/invoices/${inv.id}/pdf`, pdfName(inv.doc_no)),
  requestCreditNote: (invoiceId: number, body: { kind: 'full' | 'partial'; reason: string; lines?: Array<{ invoice_line_id: number; amount: string }> }) =>
    apiCall<CreditNote>('POST', `/v1/invoices/${invoiceId}/credit-notes`, body),

  creditNotes: (status = 'pending_approval') => apiGet<CreditNote[]>(`/v1/credit-notes${q({ status })}`),
  creditNote: (id: number) => apiGet<CreditNote>(`/v1/credit-notes/${id}`),
  creditNotePdf: (cn: { id: number; doc_no: string | null }) => apiDownload(`/v1/credit-notes/${cn.id}/pdf`, pdfName(cn.doc_no)),
  approveCreditNote: (id: number) => apiCall<CreditNote>('POST', `/v1/credit-notes/${id}/approve`, {}),
  rejectCreditNote: (id: number, reason: string) => apiCall<CreditNote>('POST', `/v1/credit-notes/${id}/reject`, { reason }),

  offlinePayments: (status?: string) => apiGet<OfflinePaymentsPayload>(`/v1/offline-payments${q({ status })}`),
  previewOfflinePayment: (body: Omit<OfflinePaymentInput, 'utr' | 'method' | 'received_on' | 'note'>) =>
    apiCall<Preview>('POST', '/v1/offline-payments/preview', body),
  recordOfflinePayment: (body: OfflinePaymentInput) => apiCall<OfflinePayment>('POST', '/v1/offline-payments', body),
  verifyOfflinePayment: (id: number) => apiCall<OfflinePayment>('POST', `/v1/offline-payments/${id}/verify`, {}),
  rejectOfflinePayment: (id: number, reason: string) => apiCall<OfflinePayment>('POST', `/v1/offline-payments/${id}/reject`, { reason }),
  retryInvoice: (id: number) => apiCall<OfflinePayment>('POST', `/v1/offline-payments/${id}/retry-invoice`, {}),

  settings: () => apiGet<InvoicingSettings>('/v1/invoicing/settings'),
  saveSellerProfile: (body: SellerProfileInput) => apiCall<SellerProfile>('PUT', '/v1/invoicing/seller-profile', body),
  createSeries: (body: { fy: string; doc_type: DocType; prefix: string; width: number; next_no: number }) =>
    apiCall<Series>('POST', '/v1/invoicing/series', body),
  updateSeries: (id: number, body: { prefix: string; width: number; next_no: number }) => apiCall<Series>('PUT', `/v1/invoicing/series/${id}`, body),
}
