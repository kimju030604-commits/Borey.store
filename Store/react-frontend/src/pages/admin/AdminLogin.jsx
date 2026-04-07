import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { adminApi } from '../../services/api'

export default function AdminLogin() {
  const [code, setCode] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()

  async function handleSubmit(e) {
    e.preventDefault()
    if (!code.trim()) { setError('Please enter your access code.'); return }
    setLoading(true); setError('')
    try {
      const result = await adminApi.login(code.trim())
      if (result.status === 'success') {
        sessionStorage.setItem('admin_logged_in', 'true')
        navigate('/admin/dashboard', { replace: true })
      } else {
        setError(result.message || 'Invalid or expired access code.')
      }
    } catch {
      setError('Connection error. Please check your server.')
    }
    setLoading(false)
  }

  return (
    <div className="bg-slate-50 flex items-center justify-center min-h-screen p-4" style={{ fontFamily: "'Plus Jakarta Sans', sans-serif" }}>
      <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
      <div className="w-full max-w-sm p-8 space-y-6 bg-white rounded-[2rem] shadow-xl shadow-slate-200/50">
        <div className="text-center">
          <h1 className="text-2xl md:text-3xl font-black tracking-tighter">ADMIN PANEL</h1>
          <p className="mt-1 text-sm text-slate-500 font-bold">Borey<span className="text-blue-600">.store</span> Access</p>
        </div>
        {error && (
          <div className="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg">
            <p className="font-bold">{error}</p>
          </div>
        )}
        <form className="space-y-6" onSubmit={handleSubmit}>
          <div className="space-y-1.5">
            <label htmlFor="access_code" className="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Access Code</label>
            <input id="access_code" type="password" required maxLength={20}
              value={code} onChange={e => setCode(e.target.value)}
              className="w-full p-4 bg-slate-100 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all text-center text-2xl tracking-widest font-black"
              placeholder="XXXXXXXX" autoFocus />
            <p className="text-[10px] text-slate-400 mt-2">Enter your 8-character access code to proceed</p>
          </div>
          <button type="submit" disabled={loading}
            className="w-full bg-slate-900 text-white py-4 rounded-2xl font-black text-base transition-all active:scale-[0.98] shadow-lg shadow-slate-900/20 hover:bg-slate-800 disabled:opacity-60">
            {loading ? 'Authenticating...' : 'Unlock Admin Panel'}
          </button>
        </form>
      </div>
    </div>
  )
}
