// Display helpers. Amounts stay strings end to end; formatting only inserts
// Indian digit grouping (12,34,567.50) — no float arithmetic on money.

export function inr(amount: string | null | undefined): string {
  if (amount === null || amount === undefined || amount === '') return '—'
  const negative = amount.startsWith('-')
  const [whole, frac = '00'] = (negative ? amount.slice(1) : amount).split('.')
  const last3 = whole.slice(-3)
  const rest = whole.slice(0, -3)
  const grouped = rest ? `${rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',')},${last3}` : last3
  return `${negative ? '−' : ''}₹${grouped}.${frac.padEnd(2, '0').slice(0, 2)}`
}

/** Integer paise → "1234.50" (exact integer arithmetic). */
export function paiseToRupees(paise: number): string {
  const negative = paise < 0
  const abs = Math.abs(paise)
  const rem = abs % 100
  return `${negative ? '-' : ''}${(abs - rem) / 100}.${String(rem).padStart(2, '0')}`
}

/** Integer paise → "₹12,34,567.89". */
export function inrPaise(paise: number | null | undefined): string {
  if (paise === null || paise === undefined) return '—'
  return inr(paiseToRupees(paise))
}

/** YYYY-MM-DD (or a timestamp) → dd/mm/yyyy, the Indian date format used on invoices. */
export function dmy(value: string | null | undefined): string {
  if (!value) return '—'
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : value
}

export function dateOnly(value: string | null | undefined): string {
  if (!value) return '—'
  return value.slice(0, 10)
}

export function dateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const d = new Date(value.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', dateStyle: 'medium', timeStyle: 'short' })
}

const LABELS: Record<string, string> = {
  draft: 'Draft',
  in_review: 'In review',
  approved: 'Approved',
  file_generated: 'Bank file ready',
  partially_paid: 'Partially paid',
  paid: 'Paid',
  cancelled: 'Cancelled',
  on_hold: 'On hold',
  failed: 'Failed',
  reversed: 'Reversed',
  pending: 'Pending',
  delivered: 'Delivered',
  dead: 'Dead',
  issued: 'Issued',
  partially_credited: 'Partially credited',
  credited: 'Credited',
  pending_approval: 'Awaiting approval',
  rejected: 'Rejected',
  recorded: 'Awaiting verification',
  verified: 'Verified',
  not_issued: 'Not issued',
  blocked_config: 'Blocked: setup missing',
  blocked_data: 'Blocked: data',
  creating: 'Creating…',
  created: 'Open',
  expired: 'Expired',
  mismatch: 'Mismatch',
  received: 'Received',
  processed: 'Processed',
  ignored: 'Ignored',
  error: 'Error',
}

export function statusLabel(status: string): string {
  return LABELS[status] ?? status
}

/** Financial year of today in IST, e.g. "2026-27". */
export function currentFy(now = new Date()): string {
  const ist = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }))
  const year = ist.getMonth() >= 3 ? ist.getFullYear() : ist.getFullYear() - 1
  return `${year}-${String((year + 1) % 100).padStart(2, '0')}`
}

export function currentQuarter(now = new Date()): string {
  const month = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Kolkata' })).getMonth() + 1
  if (month >= 4 && month <= 6) return 'Q1'
  if (month >= 7 && month <= 9) return 'Q2'
  if (month >= 10) return 'Q3'
  return 'Q4'
}

export function todayIst(): string {
  return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' })
}
