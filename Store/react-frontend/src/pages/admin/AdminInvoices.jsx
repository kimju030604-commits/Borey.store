import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { adminApi } from '../../services/api'

function AdminNav() {
  const navigate = useNavigate()
  async function handleLogout() {
    try { await adminApi.logout() } catch {}
    sessionStorage.removeItem('admin_logged_in')
    navigate('/admin/login', { replace: true })
  }
  return (
    <div className="flex justify-between items-center mb-8">
      <div className="flex items-center gap-4">
        <Link to="/admin/dashboard" className="text-slate-500 hover:text-slate-700">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </Link>
        <h1 className="text-3xl font-black text-slate-900">Invoices</h1>
      </div>
      <button onClick={handleLogout} className="text-sm font-bold text-slate-500 hover:text-red-600 bg-white px-6 py-3 rounded-2xl shadow-md">Logout</button>
    </div>
  )
}

export default function AdminInvoices() {
  const [invoices, setInvoices] = useState([])
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [msgType, setMsgType] = useState('')
  const [page, setPage] = useState(1)
  const [totalPages, setTotalPages] = useState(1)
  const [filterDate, setFilterDate] = useState('')
  const [stats, setStats] = useState({ total: 0, revenue: 0 })

  const load = (p = 1, date = '') => {
    setLoading(true)
    const params = { page: p }
    if (date) params.date = date
    adminApi.getInvoices(params).then(r => {
      setInvoices(r.data || [])
      setTotalPages(r.total_pages || 1)
      setStats({ total: r.total || 0, revenue: r.revenue || 0 })
    }).finally(() => setLoading(false))
  }

  useEffect(() => { load(1) }, [])

  function handleDateFilter(e) {
    e.preventDefault()
    setPage(1)
    load(1, filterDate)
  }

  async function handleDelete(id) {
    if (!confirm('Delete this invoice?')) return
    try {
      const r = await adminApi.deleteInvoice(id)
      if (r.status === 'success') { setMessage('Invoice deleted.'); setMsgType('success'); load(page, filterDate) }
      else { setMessage(r.message || 'Error.'); setMsgType('error') }
    } catch { setMessage('Error deleting.'); setMsgType('error') }
  }

  async function handleRegenerate(id) {
    try {
      const r = await adminApi.regeneratePdf(id)
      if (r.status === 'success') { setMessage('PDF regenerated.'); setMsgType('success') }
      else { setMessage(r.message || 'Error.'); setMsgType('error') }
    } catch { setMessage('Error regenerating.'); setMsgType('error') }
  }

  return (
    <div className="bg-slate-50 min-h-screen p-6" style={{ fontFamily: "'Plus Jakarta Sans', sans-serif" }}>
      <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
      <div className="max-w-7xl mx-auto">
        <AdminNav />

        {message && (
          <div className={`p-4 rounded-2xl mb-6 font-bold ${msgType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
            {message}
          </div>
        )}

        {/* Stats + filter */}
        <div className="flex flex-wrap items-center justify-between gap-4 mb-8">
          <div className="flex gap-4 flex-wrap">
            <div className="bg-white px-6 py-4 rounded-2xl shadow-sm">
              <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">Total</p>
              <p className="text-2xl font-black text-blue-600">{stats.total}</p>
            </div>
            <div className="bg-white px-6 py-4 rounded-2xl shadow-sm">
              <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">Revenue</p>
              <p className="text-2xl font-black text-green-600">${Number(stats.revenue).toFixed(2)}</p>
            </div>
          </div>
          <form onSubmit={handleDateFilter} className="flex gap-2 items-center">
            <input type="date" value={filterDate} onChange={e => setFilterDate(e.target.value)} className="p-3 rounded-xl border border-slate-200 text-sm" />
            <button type="submit" className="bg-blue-600 text-white px-4 py-3 rounded-xl font-bold text-sm hover:bg-blue-700">Filter</button>
            {filterDate && <button type="button" onClick={() => { setFilterDate(''); load(1, '') }} className="bg-slate-200 text-slate-700 px-4 py-3 rounded-xl font-bold text-sm hover:bg-slate-300">Clear</button>}
          </form>
        </div>

        <div className="bg-white p-8 rounded-[2rem] shadow-xl">
          {loading ? (
            <div className="flex justify-center py-10"><div className="w-10 h-10 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" /></div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-200">
                      {['Invoice #', 'Customer', 'Items', 'Total', 'Method', 'Date', 'Actions'].map(h => (
                        <th key={h} className="text-left py-3 px-3 font-bold text-slate-500 uppercase text-xs tracking-widest">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {invoices.map(inv => (
                      <tr key={inv.id} className="border-b border-slate-50 hover:bg-slate-50">
                        <td className="py-3 px-3">
                          <a href={`#/invoice?id=${encodeURIComponent(inv.invoice_number)}`} target="_blank" rel="noreferrer"
                            className="font-mono font-bold text-blue-600 hover:underline text-xs">{inv.invoice_number}</a>
                        </td>
                        <td className="py-3 px-3">
                          <p className="font-bold text-slate-900 text-xs">{inv.customer_name}</p>
                          <p className="text-slate-400 text-[11px]">{inv.customer_phone}</p>
                        </td>
                        <td className="py-3 px-3 text-slate-700">{Array.isArray(inv.items) ? inv.items.length : '—'}</td>
                        <td className="py-3 px-3 font-black text-slate-900">${Number(inv.total_usd).toFixed(2)}</td>
                        <td className="py-3 px-3"><span className="bg-purple-100 text-purple-700 px-2 py-1 rounded-full text-[11px] font-bold">{inv.payment_bank || inv.payment_method}</span></td>
                        <td className="py-3 px-3 text-slate-500 text-xs">{new Date(inv.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</td>
                        <td className="py-3 px-3">
                          <div className="flex gap-2 flex-wrap">
                            <button onClick={() => handleRegenerate(inv.id)} className="text-xs font-bold text-blue-600 hover:bg-blue-50 px-2 py-1 rounded">PDF</button>
                            <button onClick={() => handleDelete(inv.id)} className="text-xs font-bold text-red-600 hover:bg-red-50 px-2 py-1 rounded">Delete</button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              {totalPages > 1 && (
                <div className="flex justify-center gap-2 mt-6">
                  <button disabled={page <= 1} onClick={() => { const p = page - 1; setPage(p); load(p, filterDate) }}
                    className="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold text-sm disabled:opacity-40">← Prev</button>
                  <span className="px-4 py-2 font-bold text-sm text-slate-600">{page} / {totalPages}</span>
                  <button disabled={page >= totalPages} onClick={() => { const p = page + 1; setPage(p); load(p, filterDate) }}
                    className="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold text-sm disabled:opacity-40">Next →</button>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  )
}
