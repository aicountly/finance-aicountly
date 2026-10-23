import { dmy, inrPaise } from '../lib/format'
import type { CreditNote, Invoice } from '../lib/invoicingApi'

function rate(r: string): string {
  return `${r.includes('.') ? r.replace(/\.?0+$/, '') : r}%`
}

/** The face of a tax invoice or credit note: seller, buyer, place of supply, lines with the tax split, totals. */
export function DocumentView({ doc }: { doc: Invoice | CreditNote }) {
  const intra = doc.supply_type === 'intra_state'
  const seller = doc.seller

  return (
    <>
      <div className="doc-parties">
        <div className="card doc-party">
          <h3>Seller</h3>
          {seller ? (
            <p>
              <strong>{seller.legal_name}</strong>
              {'\n'}
              {[seller.address_line1, seller.address_line2, `${seller.city} ${seller.pincode}`].filter(Boolean).join('\n')}
              {'\n'}State: {seller.state_name} ({seller.state_code}){'\n'}GSTIN <span className="mono">{seller.gstin}</span> · PAN{' '}
              <span className="mono">{seller.pan}</span>
            </p>
          ) : (
            <p className="muted">Assigned when the credit note is approved.</p>
          )}
        </div>
        <div className="card doc-party">
          <h3>{doc.doc_type === 'credit_note' ? 'Issued to' : 'Billed to'}</h3>
          <p>
            <strong>{doc.buyer.legal_name}</strong>
            {'\n'}
            {doc.buyer.address}
            {doc.buyer.pincode ? ` – ${doc.buyer.pincode}` : ''}
            {'\n'}State: {doc.buyer.state_name} ({doc.buyer.state_code}){'\n'}
            {doc.buyer.gstin ? (
              <>
                GSTIN <span className="mono">{doc.buyer.gstin}</span>
              </>
            ) : (
              'Unregistered (no GSTIN)'
            )}
            {doc.buyer.email ? `\n${doc.buyer.email}` : ''}
          </p>
        </div>
        <div className="card doc-party">
          <h3>Supply</h3>
          <p>
            Place of supply: {doc.place_of_supply.code} – {doc.place_of_supply.name}
            {'\n'}
            {intra ? 'Intra-state (CGST + SGST)' : 'Inter-state (IGST)'}
            {'\n'}Reverse charge: {doc.reverse_charge ? 'Yes' : 'No'}
            {'\n'}
            {doc.buyer_kind === 'academy_student' ? 'Aicountly Academy student (no company)' : `Company (cmp_id): ${doc.cmp_id}`}
          </p>
        </div>
      </div>

      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Description</th>
              <th>SAC</th>
              <th className="num">Rate</th>
              <th className="num">Taxable value</th>
              {intra ? (
                <>
                  <th className="num">CGST</th>
                  <th className="num">SGST</th>
                </>
              ) : (
                <th className="num">IGST</th>
              )}
              <th className="num">Total</th>
            </tr>
          </thead>
          <tbody>
            {doc.lines.map((l) => (
              <tr key={l.line_no}>
                <td>{l.line_no}</td>
                <td>
                  {l.description}
                  <div className="muted small">
                    {[l.item_code, l.item_kind].filter(Boolean).join(' · ')}
                    {l.period_start || l.period_end ? ` · ${dmy(l.period_start)} to ${dmy(l.period_end)}` : ''}
                    {l.quantity ? ` · qty ${l.quantity}` : ''}
                    {l.price_basis ? ` · price ${l.price_basis} of GST` : ''}
                  </div>
                  {l.discount_paise ? (
                    <div className="muted small">
                      List {inrPaise(l.list_amount_paise ?? 0)} − discount {inrPaise(l.discount_paise)} ({l.discount_ref}) — GST on the price after discount
                    </div>
                  ) : null}
                </td>
                <td className="mono">{l.sac}</td>
                <td className="num">{rate(l.gst_rate)}</td>
                <td className="num">{inrPaise(l.taxable_paise)}</td>
                {intra ? (
                  <>
                    <td className="num" title={`@ ${rate(l.cgst_rate)}`}>
                      {inrPaise(l.cgst_paise)}
                    </td>
                    <td className="num" title={`@ ${rate(l.sgst_rate)}`}>
                      {inrPaise(l.sgst_paise)}
                    </td>
                  </>
                ) : (
                  <td className="num">{inrPaise(l.igst_paise)}</td>
                )}
                <td className="num">{inrPaise(l.total_paise)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr>
              <td colSpan={4}>Total</td>
              <td className="num">{inrPaise(doc.totals.taxable_paise)}</td>
              {intra ? (
                <>
                  <td className="num">{inrPaise(doc.totals.cgst_paise)}</td>
                  <td className="num">{inrPaise(doc.totals.sgst_paise)}</td>
                </>
              ) : (
                <td className="num">{inrPaise(doc.totals.igst_paise)}</td>
              )}
              <td className="num">{inrPaise(doc.totals.total_paise)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <p className="small">
        <strong>Amount in words:</strong> {doc.amount_in_words}
      </p>
    </>
  )
}

/** Where the stored PDF stands (rendered once at issue, never re-rendered). */
export function RenderingNote({ rendering }: { rendering: { pdf_stored: boolean; renderer_configured: boolean; last_error: string | null; pdf_sha256: string | null } }) {
  if (rendering.pdf_stored) {
    return (
      <p className="muted small">
        PDF stored at issue · sha256 <span className="mono">{rendering.pdf_sha256?.slice(0, 16)}…</span>
      </p>
    )
  }
  return (
    <p className="muted small">
      {rendering.renderer_configured
        ? `PDF not rendered yet${rendering.last_error ? ` (${rendering.last_error})` : ''}: retried every 5 minutes from the HTML stored at issue.`
        : 'No PDF service is configured (GOTENBERG_URL): the document is stored as HTML and the PDF becomes available once it is.'}
    </p>
  )
}
