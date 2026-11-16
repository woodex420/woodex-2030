import { forwardRef } from "react";
import { brand } from "@/config/brand";
import { QuoteItem, estimateOf } from "@/contexts/QuoteContext";
import { formatPKR, categoryLabel } from "@/data/products";
import { keyOf } from "@/contexts/lineKey";
import woodexLogo from "@/assets/woodex-logo.png";

export interface ClientInfo {
  name: string;
  location: string;
  contactNumber: string;
  whatsapp: string;
}

interface PrintableInvoiceProps {
  items: QuoteItem[];
  totalItems: number;
  /** The reference the request was saved under. Printed only when there is one. */
  quoteNumber?: string;
  clientInfo: ClientInfo;
}

const PrintableInvoice = forwardRef<HTMLDivElement, PrintableInvoiceProps>(
  ({ items, totalItems, quoteNumber, clientInfo }, ref) => {
    const today = new Date();
    const dateStr = today.toLocaleDateString("en-PK", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
    });
    /* A reference is printed only when the request has really been saved under
       one. This used to fall back to "WDX-" plus a random number — a quote
       number that existed nowhere and changed on every render. */
    const qNumber = quoteNumber?.trim() || "";
    const onRequest = items.filter((item) => item.priceOnRequest).length;

    return (
      <div ref={ref} className="print-invoice hidden print:block">
        {/* Letterhead with Logo */}
        <div className="invoice-header">
          <div className="invoice-brand">
            <img src={woodexLogo} alt="WoodEx Furniture" className="invoice-logo-img" />
          </div>
          <div className="invoice-title-block">
            <h2 className="invoice-title">E-Quotation</h2>
            <p className="invoice-title-sub">Professional Furniture Solutions</p>
          </div>
        </div>

        {/* Company Info Bar */}
        <div className="invoice-info-bar">
          <span>📍 LG 89 Zainab Tower, Model Town, Link Road, Lahore</span>
          <span>📞 {brand.phone}</span>
          <span>✉ info@woodex.pk</span>
          <span>🌐 www.woodex.pk</span>
        </div>

        {/* Client & Quote Meta */}
        <div className="invoice-meta">
          <div className="invoice-meta-left">
            <p className="invoice-meta-label">Quotation For</p>
            <p className="invoice-meta-value">{clientInfo.name}</p>
            <p className="invoice-meta-detail">📍 {clientInfo.location}</p>
            <p className="invoice-meta-detail">📞 {clientInfo.contactNumber}</p>
            <p className="invoice-meta-detail">💬 WhatsApp: {clientInfo.whatsapp}</p>
          </div>
          <div className="invoice-meta-right">
            <table className="invoice-meta-table">
              <tbody>
                {qNumber && (
                  <tr>
                    <td className="meta-label">Reference</td>
                    <td className="meta-value">{qNumber}</td>
                  </tr>
                )}
                <tr>
                  <td className="meta-label">Date</td>
                  <td className="meta-value">{dateStr}</td>
                </tr>
                <tr>
                  <td className="meta-label">Valid Until</td>
                  <td className="meta-value">
                    {new Date(today.getTime() + 30 * 86400000).toLocaleDateString("en-PK", {
                      day: "2-digit",
                      month: "2-digit",
                      year: "numeric",
                    })}
                  </td>
                </tr>
                <tr>
                  <td className="meta-label">Total Items</td>
                  <td className="meta-value">{totalItems}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        {/* Items Table */}
        <table className="invoice-table">
          <thead>
            <tr>
              <th className="col-sr">Sr</th>
              <th className="col-item">Item</th>
              <th className="col-desc">Description</th>
              <th className="col-qty">QTY</th>
              <th className="col-unit">Unit Price</th>
              <th className="col-total">Total</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item, idx) => (
              <tr key={keyOf(item)}>
                <td className="col-sr">{idx + 1}</td>
                <td className="col-item">{item.name}{item.sku ? ` (${item.sku})` : ""}</td>
                <td className="col-desc">{[categoryLabel(item.category), item.variant, item.color].filter(Boolean).join(" — ")}</td>
                <td className="col-qty">{item.quantity}</td>
                {/* No price yet is printed as words. "PKR 0" on paper reads as free. */}
                <td className="col-unit">{item.priceOnRequest ? "On request" : formatPKR(item.price)}</td>
                <td className="col-total">{item.priceOnRequest ? "On request" : formatPKR(item.price * item.quantity)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        {/* Totals */}
        <div className="invoice-totals">
          <div className="invoice-totals-left">
            <p className="totals-note">Transportation and delivery terms will be confirmed in the formal quotation.</p>
            <p className="totals-note">* This enquiry estimate is shown in Pakistani Rupees (PKR).</p>
            {onRequest > 0 && (
              <p className="totals-note">
                * {onRequest === 1 ? "One item is" : `${onRequest} items are`} priced on request and not included in the estimated total.
              </p>
            )}
          </div>
          <div className="invoice-totals-right">
            <table className="totals-table">
              <tbody>
                <tr className="totals-highlight">
                  <td>ESTIMATED TOTAL</td>
                  <td>{estimateOf(items)}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        {/* Footer */}
        <div className="invoice-footer">
          <div className="invoice-footer-thanks">THANK YOU FOR CHOOSING {brand.name.toUpperCase()}!</div>
          <div className="invoice-footer-contact">
            <span>🌐 www.woodex.pk</span>
            <span>•</span>
            <span>📞 {brand.phone}</span>
            <span>•</span>
            <span>💬 WhatsApp: {brand.phone}</span>
            <span>•</span>
            <span>✉ info@woodex.pk</span>
          </div>
          <div className="invoice-footer-terms">
            This is an enquiry estimate, not a formal quotation. Final payment terms will be included in formal quotation; final pricing, validity, delivery and warranty terms will also be provided there.
          </div>
          <div className="invoice-footer-brand">
            {brand.name} — Premium Office & Home Furniture | Made in Pakistan
          </div>
        </div>
      </div>
    );
  }
);

PrintableInvoice.displayName = "PrintableInvoice";

export default PrintableInvoice;
