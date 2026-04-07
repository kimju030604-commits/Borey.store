import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { adminApi } from '../../services/api'

function AdminNav({ title }) {
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
        <h1 className="text-3xl font-black text-slate-900">{title}</h1>
      </div>
      <button onClick={handleLogout} className="text-sm font-bold text-slate-500 hover:text-red-600 bg-white px-6 py-3 rounded-2xl shadow-md">Logout</button>
    </div>
  )
}

const CATEGORIES = ['Drinks', 'Water', 'Achoholic', 'Snacks']

export default function AdminProducts() {
  const [products, setProducts] = useState([])
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [msgType, setMsgType] = useState('')
  const [editingStock, setEditingStock] = useState({})
  const fileRef = useRef(null)
  const [form, setForm] = useState({ name: '', name_en: '', price: '', category: 'Drinks', rating: '5', stock: '0' })
  const [submitting, setSubmitting] = useState(false)

  const load = () => adminApi.getProducts().then(r => setProducts(r.data || [])).finally(() => setLoading(false))
  useEffect(() => { load() }, [])

  async function handleAdd(e) {
    e.preventDefault()
    if (!fileRef.current?.files[0]) { setMessage('Please select a product image.'); setMsgType('error'); return }
    setSubmitting(true)
    const fd = new FormData()
    Object.entries(form).forEach(([k, v]) => fd.append(k, v))
    fd.append('image', fileRef.current.files[0])
    try {
      const result = await adminApi.addProduct(fd)
      if (result.status === 'success') {
        setMessage('Product added successfully!'); setMsgType('success')
        setForm({ name: '', name_en: '', price: '', category: 'Drinks', rating: '5', stock: '0' })
        if (fileRef.current) fileRef.current.value = ''
        load()
      } else {
        setMessage(result.message || 'Error adding product.'); setMsgType('error')
      }
    } catch { setMessage('Error adding product.'); setMsgType('error') }
    setSubmitting(false)
  }

  async function handleDelete(id) {
    if (!confirm('Delete this product?')) return
    try {
      await adminApi.deleteProduct(id)
      setMessage('Product deleted.'); setMsgType('success')
      load()
    } catch { setMessage('Error deleting product.'); setMsgType('error') }
  }

  async function saveStock(id) {
    const stock = editingStock[id]
    if (stock === undefined) return
    try {
      await adminApi.updateStock(id, parseInt(stock))
      setMessage('Stock updated.'); setMsgType('success')
      setEditingStock(prev => { const n = { ...prev }; delete n[id]; return n })
      load()
    } catch { setMessage('Error updating stock.'); setMsgType('error') }
  }

  return (
    <div className="bg-slate-50 min-h-screen p-6" style={{ fontFamily: "'Plus Jakarta Sans', sans-serif" }}>
      <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
      <div className="max-w-7xl mx-auto">
        <AdminNav title="Products" />

        {message && (
          <div className={`p-4 rounded-2xl mb-6 font-bold ${msgType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
            {message}
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Add Product Form */}
          <div className="lg:col-span-1">
            <div className="bg-white p-8 rounded-[2rem] shadow-xl">
              <h2 className="text-2xl font-black text-slate-900 mb-6">Add Product</h2>
              <form onSubmit={handleAdd} className="space-y-4">
                <div>
                  <label className="text-xs font-black text-slate-400 uppercase tracking-widest mb-1 block">Name (Khmer)</label>
                  <input type="text" required value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} className="w-full p-3 bg-slate-100 rounded-xl border border-slate-200 focus:outline-none focus:border-blue-400" />
                </div>
                <div>
                  <label className="text-xs font-black text-slate-400 uppercase tracking-widest mb-1 block">Name (English)</label>
                  <input type="text" value={form.name_en} onChange={e => setForm(f => ({ ...f, name_en: e.target.value }))} className="w-full p-3 bg-slate-100 rounded-xl border border-slate-200 focus:outline-none focus:border-blue-400" />
                </div>
                <div>
                  <label className="text-xs font-black text-slate-400 uppercase tracking-widest mb-1 block">Price (USD)</label>
                  <input type="number" step="0.01" min="0" required value={form.price} onChange={e => setForm(f => ({ ...f, price: e.target.value }))} className="w-full p-3 bg-slate-100 rounded-xl border border-slate-200 focus:outline-none focus:border-blue-400" />
                </div>
                <div>
                  <label className="text-xs font-black text-slate-400 uppercase tracking-widest mb-1 block">Category</label>
                  <select value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))} className="w-full p-3 bg-slate-100 rounded-xl border border-slate-200 focus:outline-none focus:border-blue-400">
                    {CATEGORIES.map(c => <option key={c}>{c}</option>)}
                  </select>
                </div>
                <div>
                  <label className="text-xs font-black text-slate-400 uppercase tracking-widest mb-1 block">Rating</label>
                  <input type="number" step="0.1" min="0" max="5" value={form.rating} onChange={e => setForm(f => ({ ...f, rating: e.target.value }))} className="w-full p-3 bg-slate-100 rounded-xl border border-slate-200 focus:outline-none focus:border-blue-400" />
                </div>
                <div>
                  <label className="text-xs font-black text-slate-400 uppercase tracking-widest mb-1 block">Stock</label>
                  <input type="number" min="0" value={form.stock} onChange={e => setForm(f => ({ ...f, stock: e.target.value }))} className="w-full p-3 bg-slate-100 rounded-xl border border-slate-200 focus:outline-none focus:border-blue-400" />
                </div>
                <div>
                  <label className="text-xs font-black text-slate-400 uppercase tracking-widest mb-1 block">Image</label>
                  <input type="file" ref={fileRef} accept="image/jpeg,image/png,image/gif,image/webp" className="w-full p-3 bg-slate-100 rounded-xl border border-slate-200 text-sm" />
                </div>
                <button type="submit" disabled={submitting} className="w-full bg-blue-600 text-white py-3 rounded-xl font-black hover:bg-blue-700 transition-all disabled:opacity-60">
                  {submitting ? 'Adding...' : '+ Add Product'}
                </button>
              </form>
            </div>
          </div>

          {/* Products List */}
          <div className="lg:col-span-2">
            <div className="bg-white p-8 rounded-[2rem] shadow-xl">
              <h2 className="text-2xl font-black text-slate-900 mb-6">Products ({products.length})</h2>
              {loading ? (
                <div className="flex justify-center py-10"><div className="w-10 h-10 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" /></div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-slate-200">
                        {['Image', 'Name', 'Price', 'Category', 'Stock', 'Actions'].map(h => (
                          <th key={h} className="text-left py-3 px-3 font-bold text-slate-500 uppercase text-xs tracking-widest">{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {products.map(p => (
                        <tr key={p.id} className="border-b border-slate-50 hover:bg-slate-50">
                          <td className="py-3 px-3">
                            {p.image && <img src={`/frontend/${p.image}`} className="w-12 h-12 rounded-lg object-cover" alt={p.name} onError={e => e.target.style.display='none'} />}
                          </td>
                          <td className="py-3 px-3">
                            <p className="font-bold text-slate-900 text-xs">{p.name}</p>
                            {p.name_en && <p className="text-slate-400 text-[11px]">{p.name_en}</p>}
                          </td>
                          <td className="py-3 px-3 font-black text-slate-900">${Number(p.price).toFixed(2)}</td>
                          <td className="py-3 px-3"><span className="bg-blue-100 text-blue-700 px-2 py-1 rounded-full text-xs font-bold">{p.category}</span></td>
                          <td className="py-3 px-3">
                            <div className="flex items-center gap-2">
                              <input type="number" min="0"
                                value={editingStock[p.id] !== undefined ? editingStock[p.id] : p.stock}
                                onChange={e => setEditingStock(prev => ({ ...prev, [p.id]: e.target.value }))}
                                className="w-16 p-1 text-center rounded-lg border border-slate-200 text-sm font-bold" />
                              {editingStock[p.id] !== undefined && (
                                <button onClick={() => saveStock(p.id)} className="text-xs font-bold text-blue-600 hover:bg-blue-50 px-2 py-1 rounded">Save</button>
                              )}
                            </div>
                          </td>
                          <td className="py-3 px-3">
                            <button onClick={() => handleDelete(p.id)} className="text-xs font-bold text-red-600 hover:bg-red-50 px-2 py-1 rounded">Delete</button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
