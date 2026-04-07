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
    <div className="flex justify-between items-center mb-10">
      <div>
        <h1 className="text-4xl font-black text-slate-900">Admin Dashboard</h1>
        <p className="text-slate-500 font-bold mt-2">Manage products and access codes</p>
      </div>
      <div className="flex items-center gap-3">
        <Link to="/admin/products" className="text-sm font-bold text-slate-600 hover:text-blue-600 bg-white px-4 py-2 rounded-xl shadow-sm">Products</Link>
        <Link to="/admin/invoices" className="text-sm font-bold text-slate-600 hover:text-blue-600 bg-white px-4 py-2 rounded-xl shadow-sm">Invoices</Link>
        <button onClick={handleLogout} className="text-sm font-bold text-slate-500 hover:text-red-600 bg-white px-6 py-3 rounded-2xl shadow-md">Logout</button>
      </div>
    </div>
  )
}

export default function AdminDashboard() {
  const [stats, setStats] = useState(null)
  const [codes, setCodes] = useState([])
  const [message, setMessage] = useState('')
  const [msgType, setMsgType] = useState('')
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    Promise.all([adminApi.getStats(), adminApi.getCodes()])
      .then(([s, c]) => {
        setStats(s.data || s)
        setCodes(c.data || [])
      })
      .finally(() => setLoading(false))
  }, [])

  async function generateCode() {
    try {
      const result = await adminApi.generateCode()
      if (result.status === 'success') {
        setMessage(`New access code generated: ${result.data.code}`)
        setMsgType('success')
        const c = await adminApi.getCodes()
        setCodes(c.data || [])
      } else {
        setMessage(result.message || 'Error generating code.')
        setMsgType('error')
      }
    } catch { setMessage('Error generating code.'); setMsgType('error') }
  }

  async function deactivateCode(id) {
    try {
      const result = await adminApi.deactivateCode(id)
      if (result.status === 'success') {
        setMessage('Access code deactivated'); setMsgType('success')
        const c = await adminApi.getCodes()
        setCodes(c.data || [])
      }
    } catch {}
  }

  if (loading) return (
    <div className="min-h-screen bg-slate-50 flex items-center justify-center">
      <div className="w-12 h-12 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>
  )

  const activeCodes = codes.filter(c => c.is_active)

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

        {/* Stats */}
        {stats && (
          <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
            <div className="bg-white p-8 rounded-[2rem] shadow-lg">
              <p className="text-slate-500 font-bold text-sm uppercase tracking-widest mb-2">Total Products</p>
              <p className="text-4xl font-black text-slate-900">{stats.total_products ?? 0}</p>
            </div>
            <div className="bg-white p-8 rounded-[2rem] shadow-lg">
              <p className="text-slate-500 font-bold text-sm uppercase tracking-widest mb-2">Total Invoices</p>
              <p className="text-4xl font-black text-blue-600">{stats.total_invoices ?? 0}</p>
            </div>
            <div className="bg-white p-8 rounded-[2rem] shadow-lg">
              <p className="text-slate-500 font-bold text-sm uppercase tracking-widest mb-2">Total Revenue</p>
              <p className="text-4xl font-black text-green-600">${Number(stats.total_revenue ?? 0).toFixed(2)}</p>
            </div>
            <div className="bg-white p-8 rounded-[2rem] shadow-lg">
              <p className="text-slate-500 font-bold text-sm uppercase tracking-widest mb-2">Active Codes</p>
              <p className="text-4xl font-black text-slate-900">{activeCodes.length}</p>
            </div>
            <div className="bg-white p-8 rounded-[2rem] shadow-lg md:col-span-2">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-slate-500 font-bold text-sm uppercase tracking-widest mb-2">Inventory</p>
                  <p className="text-3xl font-black text-slate-900">{stats.total_stock ?? 0} units</p>
                </div>
                <div className="text-right">
                  <p className="text-xs font-bold text-red-500 uppercase tracking-widest">Out of stock</p>
                  <p className="text-2xl font-black text-red-600">{stats.out_of_stock ?? 0}</p>
                </div>
              </div>
              <Link to="/admin/products" className="inline-flex mt-4 items-center gap-2 text-sm font-bold text-blue-600 hover:text-blue-800">Manage stock →</Link>
            </div>
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Access Codes Table */}
          <div className="lg:col-span-2 bg-white p-8 rounded-[2rem] shadow-xl">
            <h2 className="text-2xl font-black text-slate-900 mb-6">Access Codes</h2>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200">
                    {['Code', 'Status', 'Uses', 'Expires', 'Action'].map(h => (
                      <th key={h} className="text-left py-3 px-3 font-bold text-slate-500 uppercase text-xs tracking-widest">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {codes.map(code => (
                    <tr key={code.id} className="border-b border-slate-50 hover:bg-slate-50">
                      <td className="py-4 px-3"><code className="bg-slate-100 px-3 py-2 rounded-lg font-mono font-bold text-slate-900">{code.code}</code></td>
                      <td className="py-4 px-3">
                        <span className={`${code.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'} px-3 py-1 rounded-full text-xs font-bold`}>
                          {code.is_active ? '✓ Active' : '✗ Inactive'}
                        </span>
                      </td>
                      <td className="py-4 px-3 font-bold text-slate-900">{code.used_count}</td>
                      <td className="py-4 px-3 text-slate-600 text-xs">{code.expires_at ? new Date(code.expires_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'Never'}</td>
                      <td className="py-4 px-3">
                        {code.is_active && (
                          <button onClick={() => deactivateCode(code.id)} className="text-xs font-bold text-red-600 hover:bg-red-50 px-2 py-1 rounded">Revoke</button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Quick Actions */}
          <div className="bg-white p-8 rounded-[2rem] shadow-xl">
            <h2 className="text-2xl font-black text-slate-900 mb-8">Quick Actions</h2>
            <div className="space-y-4">
              <button onClick={generateCode} className="w-full bg-blue-600 text-white py-4 rounded-2xl font-black hover:bg-blue-700 transition-all active:scale-95">
                🔐 Generate New Code
              </button>
              <Link to="/admin/products" className="block w-full bg-slate-900 text-white py-4 rounded-2xl font-black hover:bg-slate-800 transition-all text-center">
                📦 Manage Products
              </Link>
              <Link to="/admin/invoices" className="block w-full bg-green-600 text-white py-4 rounded-2xl font-black hover:bg-green-700 transition-all text-center">
                🧾 View Invoices
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
