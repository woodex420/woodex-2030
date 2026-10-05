import { forwardRef } from "react";
import { QuoteItem } from "@/contexts/QuoteContext";
import { formatPKR } from "@/data/products";
import woodexLogo from "@/assets/woodex-logo.png";

export interface ClientInfo {
  name: string;
  location: string;
  contactNumber: string;
  whatsapp: string;
}

interface PrintableInvoiceProps {
  items: QuoteItem[];
  totalPrice: number;
  totalItems: number;
  quoteNumber?: string;
  clientInfo: ClientInfo;
}

const PrintableInvoice = forwardRef<HTMLDivElement, PrintableInvoiceProps>(
  ({ items, totalPrice, totalItems, quoteNumber, clientInfo }, ref) => {
    const today = new Date();
    const dateStr = today.toLocaleDateString("en-PK", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
    });
    const qNumber = quoteNumber || `WDX-${10000 + Math.floor(Math.random() * 90000)}`;

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
          <span>📞 0322 4000768</span>
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
                <tr>
                  <td className="meta-label">Quote No.</td>
                  <td className="meta-value">{qNumber}</td>
                </tr>
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
              <tr key={item.id}>
                <td className="col-sr">{idx + 1}</td>
                <td className="col-item">{item.name}</td>
                <td className="col-desc">{item.category}{item.color ? ` — ${item.color}` : ""}</td>
                <td className="col-qty">{item.quantity}</td>
                <td className="col-unit">{formatPKR(item.price)}</td>
                <td className="col-total">{formatPKR(item.price * item.quantity)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        {/* Totals */}
        <div className="invoice-totals">
          <div className="invoice-totals-left">
            <p className="totals-note">Transportation: Outstation charges depend on city, quantity and load</p>
            <p className="totals-note">* Prices are in Pakistani Rupees (PKR). Final pricing may vary based on customization.</p>
          </div>
          <div className="invoice-totals-right">
            <table className="totals-table">
              <tbody>
                <tr>
                  <td>SUBTOTAL</td>
                  <td>{formatPKR(totalPrice)}</td>
                </tr>
                <tr>
                  <td>DISCOUNT</td>
                  <td>Rs: 0</td>
                </tr>
                <tr className="totals-highlight">
                  <td>TOTAL</td>
                  <td>{formatPKR(totalPrice)}</td>
                </tr>
                <tr>
                  <td>ADVANCE (75%)</td>
                  <td>{formatPKR(Math.round(totalPrice * 0.75))}</td>
                </tr>
                <tr className="totals-balance">
                  <td>BALANCE DUE</td>
                  <td>{formatPKR(Math.round(totalPrice * 0.25))}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        {/* Footer */}
        <div className="invoice-footer">
          <div className="invoice-footer-thanks">THANK YOU FOR CHOOSING WOODEX!</div>
          <div className="invoice-footer-contact">
            <span>🌐 www.woodex.pk</span>
            <span>•</span>
            <span>📞 0322 4000768</span>
            <span>•</span>
            <span>💬 WhatsApp: 0322 4000768</span>
            <span>•</span>
            <span>✉ info@woodex.pk</span>
          </div>
          <div className="invoice-footer-terms">
            <strong>Payment Terms:</strong> 75% advance with order confirmation, 25% balance before delivery.
            Warranty as per product category. This quotation is valid for 30 days from the date of issue.
          </div>
          <div className="invoice-footer-brand">
            WOODEX — Premium Office & Home Furniture | Made in Pakistan
          </div>
        </div>
      </div>
    );
  }
);

PrintableInvoice.displayName = "PrintableInvoice";

export default PrintableInvoice;
