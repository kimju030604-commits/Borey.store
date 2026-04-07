import { useEffect, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { invoiceApi } from '../services/api'

function parseQuery(search) {
  return Object.fromEntries(new URLSearchParams(search))
}

export default function InvoicePage() {
  const location = useLocation()
  const [invoice, setInvoice] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    // Support both ?id=X and ?order=X query params from hash-based URL
    // e.g. #/invoice?id=Borey.0001
    const hash = window.location.hash // e.g. #/invoice?id=Borey.0001
    const queryStr = hash.includes('?') ? hash.slice(hash.indexOf('?')) : location.search
    const params = parseQuery(queryStr)
    const id = params.id || ''
    const order = params.order || ''

    if (!id && !order) { setError('No invoice specified.'); setLoading(false); return }

    const fetcher = id ? invoiceApi.getByNumber(id) : invoiceApi.getByOrder(order)
    fetcher
      .then(res => {
        if (res.status === 'success' && res.data) setInvoice(res.data)
        else setError('Invoice not found.')
      })
      .catch(() => setError('Failed to load invoice.'))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return (
    <div className="min-h-screen bg-slate-100 flex items-center justify-center">
      <div className="w-12 h-12 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>
  )

  if (error) return (
    <div className="min-h-screen bg-slate-100 flex items-center justify-center">
      <div className="text-center">
        <p className="text-xl font-black text-slate-700 mb-4">{error}</p>
        <a href="#/" className="text-blue-600 font-bold hover:underline">← Back to Store</a>
      </div>
    </div>
  )

  if (!invoice) return null

  const items = Array.isArray(invoice.items) ? invoice.items : (JSON.parse(invoice.items || '[]'))

  return (
    <div className="bg-slate-100 min-h-screen py-8 px-4" style={{ fontFamily: "'Plus Jakarta Sans', sans-serif" }}>
      <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />

      {/* Action Buttons */}
      <div className="no-print max-w-3xl mx-auto mb-6 flex gap-4 justify-end flex-wrap">
        <a href={invoiceApi.downloadUrl(invoice.invoice_number)}
          className="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors shadow-lg">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
          Download PDF
        </a>
        <button onClick={() => window.print()}
          className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors shadow-lg">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
          Print Invoice
        </button>
        <a href="#/" className="bg-slate-200 hover:bg-slate-300 text-slate-700 px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="m15 18-6-6 6-6"/></svg>
          Back to Store
        </a>
      </div>

      {/* Invoice Container */}
      <div className="max-w-3xl mx-auto bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden">
        {/* Header */}
        <div className="bg-gradient-to-r from-slate-900 to-blue-900 text-white p-8">
          <div className="flex justify-between items-start">
            <div>
              <h1 className="text-3xl font-black tracking-tight mb-2">BOREY<span className="text-blue-400">.STORE</span></h1>
              <p className="text-slate-300 text-sm">Premium Local Marketplace</p>
            </div>
            <div className="text-right">
              <div className="inline-block bg-green-500 text-white px-4 py-2 rounded-xl text-sm font-bold mb-3">
                {(invoice.payment_status || 'PAID').toUpperCase()}
              </div>
              <p className="text-2xl font-black">INVOICE</p>
            </div>
          </div>
        </div>

        {/* Details */}
        <div className="p-8">
          <div className="grid grid-cols-2 gap-8 mb-8">
            <div>
              <h3 className="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Invoice To</h3>
              <p className="font-bold text-lg text-slate-900">{invoice.customer_name}</p>
              <p className="text-slate-600">{invoice.customer_phone}</p>
              <p className="text-slate-600">{invoice.customer_location}</p>
            </div>
            <div>
              <h3 className="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3 text-center">Invoice Details</h3>
              <p className="text-slate-600"><span className="font-bold text-slate-900">Invoice #:</span> {invoice.invoice_number}</p>
              <p className="text-slate-600"><span className="font-bold text-slate-900">Order #:</span> {invoice.order_number || invoice.order_id}</p>
              <p className="text-slate-600"><span className="font-bold text-slate-900">Date:</span> {new Date(invoice.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</p>
              <p className="text-slate-600"><span className="font-bold text-slate-900">Payment:</span> {invoice.payment_bank || invoice.payment_method}</p>
              {invoice.bakong_hash && <p className="text-slate-600"><span className="font-bold text-slate-900">Bakong Hash:</span> <span className="font-mono text-slate-600">{invoice.bakong_hash.slice(0, 8)}</span></p>}
              {invoice.payment_time && <p className="text-slate-600"><span className="font-bold text-green-600">Paid:</span> {new Date(invoice.payment_time).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</p>}
            </div>
          </div>

          {/* Items Table */}
          <div className="border border-slate-200 rounded-2xl overflow-hidden mb-8">
            <table className="w-full">
              <thead className="bg-slate-50">
                <tr>
                  <th className="text-left py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Item</th>
                  <th className="text-center py-4 px-4 text-xs font-bold text-slate-500 uppercase tracking-widest">Qty</th>
                  <th className="text-right py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Price</th>
                  <th className="text-right py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {items.map((item, i) => (
                  <tr key={i}>
                    <td className="py-4 px-6">
                      <div className="flex items-center gap-3">
                        {item.image && <img src={item.image} className="w-12 h-12 rounded-lg object-cover" alt={item.name} />}
                        <div>
                          <p className="font-bold text-slate-900">{item.name}</p>
                          {item.category && <p className="text-xs text-slate-400">{item.category}</p>}
                        </div>
                      </div>
                    </td>
                    <td className="py-4 px-4 text-center font-bold text-slate-700">{item.qty || item.quantity}</td>
                    <td className="py-4 px-6 text-right text-slate-700">${Number(item.price).toFixed(2)}</td>
                    <td className="py-4 px-6 text-right font-bold text-slate-900">${(Number(item.price) * (item.qty || item.quantity)).toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Totals */}
          <div className="flex justify-end">
            <div className="w-72 space-y-3">
              <div className="flex justify-between text-slate-600"><span>Subtotal</span><span>${Number(invoice.subtotal).toFixed(2)}</span></div>
              <div className="flex justify-between text-slate-600">
                <span>Delivery</span>
                <span className={Number(invoice.delivery_fee) > 0 ? '' : 'text-green-600 font-bold'}>
                  {Number(invoice.delivery_fee) > 0 ? `$${Number(invoice.delivery_fee).toFixed(2)}` : 'FREE'}
                </span>
              </div>
              <div className="border-t border-slate-200 pt-3 flex justify-between text-xl font-black">
                <span>Total (USD)</span><span className="text-blue-600">${Number(invoice.total_usd).toFixed(2)}</span>
              </div>
              <div className="flex justify-between text-lg font-bold text-slate-500">
                <span>Total (KHR)</span><span>{Number(invoice.total_khr).toLocaleString()} ៛</span>
              </div>
            </div>
          </div>

          {/* Footer Notes */}
          <div className="mt-12 pt-8 border-t border-slate-100">
            <div className="grid grid-cols-2 gap-8">
              <div>
                <h4 className="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Payment Information</h4>
                <p className="text-sm text-slate-600">Paid via {invoice.payment_bank || invoice.payment_method}</p>
                <p className="text-sm text-slate-600">{invoice.payer_account_id ? `Payer ID: ${invoice.payer_account_id}` : 'Bakong KHQR - Khem Sovanny'}</p>
                {invoice.verified_sender && <p className="text-sm text-slate-700 mt-2"><span className="font-bold text-green-600">Verified Sender:</span> {invoice.verified_sender}</p>}
              </div>
              <div className="text-right">
                <h4 className="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Contact Support</h4>
                <p className="text-sm text-slate-600">Telegram: @monkey_Dluffy012</p>
                <p className="text-sm text-slate-600">Phnom Penh, Cambodia</p>
              </div>
            </div>
            <div className="mt-8 text-center">
              <p className="text-sm text-slate-400 font-bold">Thank you for shopping with Borey.Store!</p>
              <p className="text-xs text-slate-300 mt-1">© {new Date().getFullYear()} Borey Store Co. Ltd</p>
            </div>
          </div>
        </div>
      </div>

      {/* Receipt image if available */}
      {invoice.receipt_path && (
        <div className="max-w-3xl mx-auto bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden mt-8">
          <div className="bg-gradient-to-r from-amber-500 to-orange-600 text-white p-6">
            <h2 className="text-xl font-black flex items-center gap-3">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
              Payment Proof - Bank Transaction
            </h2>
            <p className="text-amber-100 text-sm mt-1">Uploaded by customer as proof of payment</p>
          </div>
          <div className="p-8">
            <div className="bg-slate-50 rounded-2xl p-4 border border-slate-200">
              <img src={invoice.receipt_path} alt="Bank Transaction Receipt" className="max-w-full h-auto mx-auto rounded-lg shadow-lg" style={{ maxHeight: 600, objectFit: 'contain' }} />
            </div>
            <p className="text-center text-xs text-slate-400 mt-4">
              Receipt uploaded by customer for Order #{invoice.order_id}
            </p>
          </div>
        </div>
      )}

      <style>{`
        @media print {
          .no-print { display: none !important; }
          body { background: white !important; }
        }
      `}</style>
    </div>
  )
}
