import { useState, useEffect, useRef, useCallback } from 'react'
import { useCart } from '../context/StoreContext'
import { productsApi, paymentApi, invoiceApi } from '../services/api'

const CATEGORIES = ['All', 'Drinks', 'Water', 'Achoholic', 'Snacks']
const KHR_RATE = 4100
const PAYMENT_TIMEOUT_MS = 10 * 60 * 1000

// ── Helpers ──────────────────────────────────────────────────────────────────
function normalizePhone(raw) {
  const digits = String(raw || '').replace(/\D+/g, '')
  if (digits.startsWith('855')) return '0' + digits.slice(3)
  return digits.startsWith('0') ? digits : digits
}
function isValidPhone(raw) { return /^0\d{8,9}$/.test(normalizePhone(raw)) }

function detectBankName(accountId) {
  const code = (accountId.split('@')[1] || '').toLowerCase()
  const map = { abaa:'ABA Bank', aclb:'ACLEDA Bank', wing:'Wing Bank', trnb:'TrueMoney', cana:'Canadia Bank', pcbc:'PPCBank', ftbl:'Foreign Trade Bank', sucb:'Sathapana Bank', cimb:'CIMB Bank' }
  return map[code] || (code ? code.toUpperCase() : 'Bakong')
}
function extractPayerName(accountId, fallback) {
  if (!accountId) return fallback || 'Unknown'
  const local = accountId.split('@')[0] || ''
  if (!local || local.includes('xxx')) return accountId
  return local.replace(/[._-]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase()).trim()
}
function fmtTimestamp(ts) {
  const n = Number(ts)
  if (!n || isNaN(n)) return ''
  const d = new Date(n)
  const p = v => String(v).padStart(2,'0')
  return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

// ── Sub-components ────────────────────────────────────────────────────────────

function Navbar({ cartCount, onCartClick, searchQuery, setSearchQuery, activeCategory, setActiveCategory, isMenuOpen, setIsMenuOpen }) {
  return (
    <nav className="sticky top-0 z-50 bg-white/80 backdrop-blur-lg border-b border-slate-100 w-full">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
        <div className="flex items-center gap-2 md:gap-4">
          <button onClick={() => setIsMenuOpen(true)} className="lg:hidden p-2 text-slate-700 active:bg-slate-100 rounded-lg">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
          </button>
          <h1 className="text-xl md:text-2xl font-black tracking-tighter cursor-pointer flex items-center" onClick={() => window.scrollTo(0,0)}>
            BOREY<span className="text-blue-600">.STORE</span>
          </h1>
        </div>
        <div className="hidden lg:flex items-center space-x-8 text-sm font-bold text-slate-500 uppercase tracking-wide">
          {CATEGORIES.map(cat => (
            <button key={cat} onClick={() => setActiveCategory(cat)}
              className={`hover:text-blue-600 transition-colors ${activeCategory === cat ? 'text-blue-600' : ''}`}>{cat}</button>
          ))}
        </div>
        <div className="flex items-center gap-2 md:gap-4">
          <div className="hidden sm:flex items-center bg-slate-100 rounded-2xl px-3 py-2 focus-within:ring-2 focus-within:ring-blue-500 transition-all">
            <svg className="text-slate-400" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" value={searchQuery} onChange={e => setSearchQuery(e.target.value)} placeholder="Search products..." className="bg-transparent border-none focus:outline-none text-xs ml-2 w-32 xl:w-48" />
          </div>
          <button onClick={onCartClick} className="relative p-2.5 bg-slate-900 text-white rounded-2xl shadow-lg shadow-slate-900/20 active:scale-90 transition-transform">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            {cartCount > 0 && <span className="absolute -top-1 -right-1 bg-blue-500 text-white text-[10px] font-bold h-5 w-5 rounded-full flex items-center justify-center border-2 border-white">{cartCount}</span>}
          </button>
        </div>
      </div>
      <div className="sm:hidden px-4 pb-4">
        <div className="flex items-center bg-slate-100 rounded-2xl px-3 py-2 focus-within:ring-2 focus-within:ring-blue-500 transition-all">
          <svg className="text-slate-400" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          <input type="text" value={searchQuery} onChange={e => setSearchQuery(e.target.value)} placeholder="Search products..." className="bg-transparent border-none focus:outline-none text-sm ml-2 w-full" />
        </div>
      </div>
      {/* Mobile menu drawer */}
      {isMenuOpen && (
        <>
          <div className="fixed inset-0 bg-black/20 backdrop-blur-sm z-[55] lg:hidden" onClick={() => setIsMenuOpen(false)} />
            <div className="fixed inset-0 z-[60] bg-white w-4/5 shadow-2xl lg:hidden flex flex-col p-6 animate-slide-in-left">
            <div className="flex justify-between items-center mb-10">
              <span className="text-xl font-extrabold italic">Borey<span className="text-blue-600">.store</span></span>
              <button onClick={() => setIsMenuOpen(false)} className="p-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
              </button>
            </div>
            <nav className="flex flex-col space-y-6 text-xl font-bold">
              {CATEGORIES.map(cat => (
                <button key={cat} onClick={() => { setActiveCategory(cat); setIsMenuOpen(false) }}
                  className={`text-left py-2 border-b border-slate-50 ${activeCategory === cat ? 'text-blue-600' : 'text-slate-700'}`}>{cat}</button>
              ))}
            </nav>
          </div>
        </>
      )}
    </nav>
  )
}

function ProductCard({ product, stockLeft, onAdd, index = 0 }) {
  return (
    <div
      className="group bg-blue-50/55 backdrop-blur-md rounded-[2rem] p-3 md:p-4 border border-blue-200/70 hover:border-blue-300/90 shadow-lg shadow-blue-100/40 transition-all flex flex-col h-full animate-fade-in-up"
      style={{ animationDelay: `${index * 60}ms` }}
    >
      <div className="relative aspect-square w-full rounded-[1.5rem] overflow-hidden mb-4">
        <img src={product.image} alt={product.name} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" />
        <div className="absolute top-2 right-2 bg-white/90 backdrop-blur px-2 py-1 rounded-full flex items-center gap-1 text-[10px] font-bold shadow-sm">
          <span className="text-yellow-500">★</span> {product.rating}
        </div>
        <div className={`absolute bottom-2 left-2 px-2 py-1 rounded-full text-[9px] font-bold shadow-sm ${stockLeft > 0 ? 'bg-white/90 text-green-700' : 'bg-slate-200/90 text-slate-500'}`}>
          {stockLeft > 0 ? `${stockLeft} in stock` : 'Out of stock'}
        </div>
      </div>
      <div className="px-1 flex-1">
        <p className="text-[10px] font-extrabold text-blue-600 uppercase tracking-widest mb-1">{product.category}</p>
        <h4 className="font-bold text-xs md:text-base lg:text-lg mb-4 text-green-600 leading-tight line-clamp-2 product-name-outline">{product.name}</h4>
      </div>
      <div className="mt-auto flex items-center justify-between p-1">
        <span className="text-sm md:text-lg lg:text-xl font-black text-amber-600">${Number(product.price).toFixed(2)}</span>
        <button onClick={() => onAdd(product)} disabled={stockLeft <= 0}
          className={`p-3 rounded-2xl transition-all active:scale-90 ${stockLeft <= 0 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-slate-100 text-slate-900 hover:bg-blue-600 hover:text-white'}`}>
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
        </button>
      </div>
    </div>
  )
}

function PaymentModal({ khqrImage, orderId, paymentError, paymentLoading, paymentVerifying, secondsLeft, onVerify, onClose }) {
  const mins = String(Math.floor(secondsLeft / 60)).padStart(2, '0')
  const secs = String(secondsLeft % 60).padStart(2, '0')
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 animate-fade-in">
      <div className="bg-white rounded-[2rem] w-full max-w-sm p-6 shadow-2xl animate-scale-in">
        <div className="flex justify-between items-center mb-4">
          <h3 className="text-xl font-black text-slate-900">Scan to Pay</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600 p-1">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
        </div>
        {paymentLoading ? (
          <div className="flex flex-col items-center justify-center py-16 gap-4">
            <div className="w-12 h-12 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" />
            <p className="text-slate-500 font-bold">Generating QR Code...</p>
          </div>
        ) : khqrImage ? (
          <>
            <div className="bg-slate-50 rounded-2xl p-4 mb-4">
              <img src={`data:image/png;base64,${khqrImage}`} alt="KHQR Payment Code" className="w-full max-w-[260px] mx-auto block" />
            </div>
            <div className="text-center mb-4">
              <p className="text-xs text-slate-500 font-bold mb-1">Order ID: <span className="font-mono text-slate-700">{orderId}</span></p>
              <div className="flex items-center justify-center gap-2">
                <span className="text-xs font-bold text-slate-400 uppercase tracking-widest">Expires in</span>
                <span className={`font-black text-lg ${secondsLeft < 60 ? 'text-red-600' : 'text-blue-600'}`}>{mins}:{secs}</span>
              </div>
            </div>
            {paymentError && <div className="bg-red-50 text-red-700 p-3 rounded-xl text-sm font-bold mb-4">{paymentError}</div>}
            <button onClick={onVerify} disabled={paymentVerifying}
              className="w-full bg-green-600 hover:bg-green-700 text-white py-4 rounded-2xl font-black transition-all disabled:opacity-60">
              {paymentVerifying ? 'Verifying...' : 'I\'ve Paid — Confirm Payment'}
            </button>
          </>
        ) : null}
      </div>
    </div>
  )
}

// ── Main StorePage ────────────────────────────────────────────────────────────
export default function StorePage() {
  const { cart, addToCart, removeFromCart, updateQty, clearCart, cartCount, cartTotal } = useCart()
  const [products, setProducts] = useState([])
  const [loadingProducts, setLoadingProducts] = useState(true)
  const [activeCategory, setActiveCategory] = useState('All')
  const [searchQuery, setSearchQuery] = useState('')
  const [view, setView] = useState('home') // 'home' | 'cart' | 'checkout'
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const [stockError, setStockError] = useState('')
  const [lastCategory, setLastCategory] = useState('')
  const [showToast, setShowToast] = useState(false)

  // Delivery form
  const [deliveryForm, setDeliveryForm] = useState({ name: '', phone: '', location: '' })
  const [paymentMethod, setPaymentMethod] = useState('khqr')
  const [deliveryCityType, setDeliveryCityType] = useState('pp')

  // Payment state
  const [showPaymentModal, setShowPaymentModal] = useState(false)
  const [paymentLoading, setPaymentLoading] = useState(false)
  const [paymentError, setPaymentError] = useState('')
  const [khqrImage, setKhqrImage] = useState('')
  const [currentOrderId, setCurrentOrderId] = useState('')
  const [paymentVerifying, setPaymentVerifying] = useState(false)
  const [paymentSecondsLeft, setPaymentSecondsLeft] = useState(0)
  const [paymentCompleted, setPaymentCompleted] = useState(false)

  // Invoice / success state
  const [showInvoicePopup, setShowInvoicePopup] = useState(false)
  const [invoiceNumber, setInvoiceNumber] = useState('')
  const [invoiceLoading, setInvoiceLoading] = useState(false)
  const [showCashModal, setShowCashModal] = useState(false)

  // Timer refs
  const pollRef = useRef(null)
  const timerRef = useRef(null)
  const expiresAtRef = useRef(null)

  // Load products
  useEffect(() => {
    productsApi.getAll()
      .then(data => setProducts(data.map(p => ({ ...p, price: parseFloat(p.price) || 0, rating: parseFloat(p.rating) || 5, stock: parseInt(p.stock ?? 0) }))))
      .finally(() => setLoadingProducts(false))
  }, [])

  // Cleanup on unmount
  useEffect(() => () => { stopPolling(); stopTimer() }, [])

  const getStock = useCallback((id) => {
    const p = products.find(pr => pr.id === id)
    return p ? parseInt(p.stock || 0) : 0
  }, [products])

  const stockLeft = useCallback((product) => {
    const base = getStock(product.id)
    const inCart = cart.find(i => i.id === product.id)?.qty || 0
    return Math.max(base - inCart, 0)
  }, [products, cart])

  const filteredProducts = products.filter(p => {
    const matchCat = activeCategory === 'All' || p.category === activeCategory
    const matchSearch = p.name.toLowerCase().includes(searchQuery.toLowerCase())
    return matchCat && matchSearch
  })

  const bestSellers = [...products].filter(p => (p.stock ?? 0) > 0).sort((a, b) => {
    const soldDiff = (parseInt(b.sold ?? 0) || 0) - (parseInt(a.sold ?? 0) || 0)
    if (soldDiff !== 0) return soldDiff
    return (parseFloat(b.rating ?? 0) || 0) - (parseFloat(a.rating ?? 0) || 0)
  })

  const recommendedFromLastCategory = products.filter(p => p.category === lastCategory && (p.stock ?? 0) > 0).slice(0, 4)
  const cartTotalKHR = Math.round(cartTotal * KHR_RATE)

  function handleAddToCart(product) {
    const available = stockLeft(product)
    if (available <= 0) { setStockError(`${product.name} is out of stock.`); return }
    addToCart(product, getStock(product.id))
    setLastCategory(product.category || '')
    setStockError('')
    setShowToast(true)
    setTimeout(() => setShowToast(false), 2000)
  }

  // ── Checkout / Payment ─────────────────────────────────────────────────────
  function proceedToPayment() {
    if (!deliveryForm.name || !deliveryForm.phone || !deliveryForm.location) {
      alert('Please fill in all delivery details')
      return
    }
    const phone = normalizePhone(deliveryForm.phone)
    if (!isValidPhone(phone)) { alert('Please enter a valid phone number (e.g., 0967900198).'); return }
    const updatedForm = { ...deliveryForm, phone }
    setDeliveryForm(updatedForm)

    if (paymentMethod === 'cash') {
      if (deliveryCityType !== 'pp') { alert('Cash on delivery is only available for Phnom Penh orders.'); return }
      handleCashOrder(updatedForm)
      return
    }
    setShowPaymentModal(true)
    generateKHQR(updatedForm)
  }

  async function generateKHQR(form) {
    setPaymentLoading(true)
    setPaymentError('')
    const orderId = 'BRY-' + Date.now()
    try {
      const result = await paymentApi.generateKHQR({
        amount: cartTotalKHR,
        order_id: orderId,
        description: `Borey Store - ${cart.length} items`,
        customer_name: form.name,
        customer_phone: form.phone,
        customer_location: form.location,
      })
      if (result.status === 'success' && result.data.qr_image) {
        setKhqrImage(result.data.qr_image)
        setCurrentOrderId(result.data.order_id)
        sessionStorage.setItem('currentOrder', JSON.stringify({
          orderId: result.data.order_id, amount: result.data.amount,
          customerName: form.name, customerPhone: form.phone,
          customerLocation: form.location, items: cart, timestamp: Date.now()
        }))
        startTimer()
        startPolling()
      } else {
        setPaymentError(result.message || 'Failed to generate payment code')
      }
    } catch (e) {
      setPaymentError('Network error: ' + (e.message || 'KHQR generation failed'))
    }
    setPaymentLoading(false)
  }

  function startTimer() {
    stopTimer()
    expiresAtRef.current = Date.now() + PAYMENT_TIMEOUT_MS
    setPaymentSecondsLeft(Math.ceil(PAYMENT_TIMEOUT_MS / 1000))
    timerRef.current = setInterval(() => {
      const left = expiresAtRef.current - Date.now()
      setPaymentSecondsLeft(Math.max(0, Math.ceil(left / 1000)))
      if (left <= 0) { stopTimer(); handlePaymentTimeout() }
    }, 1000)
  }
  function stopTimer() { if (timerRef.current) { clearInterval(timerRef.current); timerRef.current = null } }
  function startPolling() {
    stopPolling()
    pollRef.current = setInterval(async () => { await verifyPayment(true) }, 3000)
  }
  function stopPolling() { if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null } }

  function handlePaymentTimeout() {
    stopPolling()
    setPaymentError('Payment timed out. Please try again.')
    setShowPaymentModal(false)
    setKhqrImage('')
    setCurrentOrderId('')
    sessionStorage.removeItem('currentOrder')
  }

  async function verifyPayment(silent = false) {
    if (!currentOrderId) return false
    if (expiresAtRef.current && Date.now() > expiresAtRef.current) { handlePaymentTimeout(); return false }
    setPaymentVerifying(true)
    try {
      const result = await paymentApi.checkPayment(currentOrderId)
      setPaymentVerifying(false)
      if (result.data?.status === 'completed') {
        handlePaymentSuccess(result.data.transaction || {})
        return true
      } else if (!silent) {
        setPaymentError('Payment not yet confirmed. Please try again.')
      }
    } catch (e) {
      if (!silent) setPaymentError('Error verifying: ' + e.message)
      setPaymentVerifying(false)
    }
    return false
  }

  async function handlePaymentSuccess(transaction = {}) {
    if (paymentCompleted) return
    stopTimer(); stopPolling()
    setPaymentCompleted(true)
    setShowPaymentModal(false)
    setKhqrImage(''); setCurrentOrderId('')

    const orderData = JSON.parse(sessionStorage.getItem('currentOrder') || '{}')
    const orderId = orderData.orderId || currentOrderId
    const savedCart = [...cart]
    const totalUsd = cartTotal, totalKhr = cartTotalKHR
    const { name: customerName, phone: customerPhone, location: customerLocation } = deliveryForm

    setShowInvoicePopup(true)
    clearCart()
    setView('home')
    setDeliveryForm({ name: '', phone: '', location: '' })
    sessionStorage.removeItem('currentOrder')

    const payerAccountId = transaction.from_account || ''
    await createInvoice(orderId, customerName, customerPhone, customerLocation, savedCart, totalUsd, totalKhr, {
      payerName: transaction.payer_name || extractPayerName(payerAccountId, customerName),
      paymentBank: detectBankName(payerAccountId),
      payerAccountId,
      paymentTime: fmtTimestamp(transaction.acknowledged_at || transaction.created_at),
      bakongHash: transaction.hash || '',
    }, 'Bakong KHQR')
  }

  async function handleCashOrder(form) {
    const orderId = 'BRY-CASH-' + Date.now()
    const savedCart = [...cart]
    const totalUsd = cartTotal, totalKhr = cartTotalKHR
    const { name, phone, location } = form
    setShowCashModal(true)
    clearCart()
    setView('home')
    setDeliveryForm({ name: '', phone: '', location: '' })
    await createInvoice(orderId, name, phone, location, savedCart, totalUsd, totalKhr,
      { payerName: name, paymentBank: 'Cash', payerAccountId: '', paymentTime: '', bakongHash: '' },
      'Cash on Delivery')
    setShowInvoicePopup(true)
  }

  async function createInvoice(orderId, name, phone, location, items, totalUsd, totalKhr, details, method) {
    setInvoiceLoading(true)
    const p = normalizePhone(phone)
    if (!isValidPhone(p)) { setInvoiceLoading(false); return }
    try {
      const result = await invoiceApi.create({
        order_id: orderId, customer_name: name, customer_phone: p, customer_location: location,
        items: JSON.stringify(items), subtotal: totalUsd.toFixed(2), delivery_fee: '0',
        total_usd: totalUsd.toFixed(2), total_khr: String(totalKhr),
        payment_method: method, payment_bank: details.paymentBank || 'Bakong',
        payer_name: details.payerName || name, payer_account_id: details.payerAccountId || '',
        payment_time: details.paymentTime || '', bakong_hash: details.bakongHash || '',
      })
      if (result.status === 'success') setInvoiceNumber(result.data.invoice_number)
    } catch (e) { console.error('Invoice creation error:', e) }
    setInvoiceLoading(false)
  }

  const bestSellersRef = useRef(null)
  function scrollToBestSellers() { bestSellersRef.current?.scrollIntoView({ behavior: 'smooth' }) }

  // ── Render ─────────────────────────────────────────────────────────────────
  return (
    <div className="ocean-forest-bg text-slate-900 overflow-x-hidden min-h-screen">
      <Navbar
        cartCount={cartCount} onCartClick={() => setView('cart')}
        searchQuery={searchQuery} setSearchQuery={setSearchQuery}
        activeCategory={activeCategory} setActiveCategory={cat => { setActiveCategory(cat); setView('home') }}
        isMenuOpen={isMenuOpen} setIsMenuOpen={setIsMenuOpen}
      />

      {/* Toast notification */}
      {showToast && (
        <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-[80] bg-slate-900 text-white px-6 py-3 rounded-2xl font-bold text-sm shadow-2xl animate-toast-in">
          Added to bag!
        </div>
      )}

      <main className="w-full">
        {/* ── Home View ────────────────────────────────────────────── */}
        {view === 'home' && (
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 md:py-10">
            {/* Hero */}
            <div className="relative overflow-hidden bg-slate-900 text-white rounded-[1.5rem] md:rounded-[3rem] p-4 md:p-16 mb-6 md:mb-12 min-h-[320px] md:min-h-[400px] flex items-center">
              <div className="absolute inset-0 z-0">
                <img src="frontend/assets/img/Hero2.jpg" className="w-full h-full object-cover opacity-90 brightness-110 scale-105" alt="Hero background" />
                <div className="absolute inset-0 bg-gradient-to-r from-slate-900/65 via-slate-900/45 to-transparent" />
              </div>
              <div className="relative z-10 max-w-2xl animate-fade-in-up">
                <div className="inline-flex items-center gap-2 px-3 py-1 bg-blue-600 rounded-full text-[10px] font-black uppercase tracking-[0.2em] mb-4 md:mb-6">
                  <span className="relative flex h-2 w-2"><span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75" /><span className="relative inline-flex rounded-full h-2 w-2 bg-white" /></span>
                  New in Stock
                </div>
                <h2 className="text-2xl md:text-5xl lg:text-6xl font-black mb-3 md:mb-4 leading-[1.1] animate-fade-in-up delay-100">Elevate Your living style with Borey Store</h2>
                <p className="text-slate-300 text-xs md:text-lg mb-6 md:mb-8 max-w-lg leading-relaxed animate-fade-in-up delay-200">All Staffs is here, At Borey is a Modern Local Store.</p>
                <div className="flex flex-wrap gap-3 animate-fade-in-up delay-300">
                  <button onClick={() => { setActiveCategory('All'); document.getElementById('products-grid')?.scrollIntoView({ behavior: 'smooth' }) }}
                    className="bg-white text-slate-900 px-5 md:px-6 py-3 md:py-3.5 rounded-2xl font-extrabold text-xs md:text-sm hover:bg-blue-50 transition-colors shadow-xl">Start Exploring</button>
                  <button onClick={scrollToBestSellers} className="bg-white/10 backdrop-blur-md text-white border border-white/20 px-5 md:px-6 py-3 md:py-3.5 rounded-2xl font-extrabold text-xs md:text-sm hover:bg-white/20 transition-colors">See Best Sellers</button>
                </div>
              </div>
            </div>

            {/* Best Sellers */}
            <div ref={bestSellersRef} className="bg-white/70 backdrop-blur-lg border border-slate-100 rounded-[2rem] p-4 md:p-6 mb-8 shadow-lg shadow-blue-100/40">
              <div className="flex items-center justify-between mb-4 md:mb-6">
                <div>
                  <p className="text-[10px] font-black text-blue-600 uppercase tracking-[0.22em]">Top Picks</p>
                  <h3 className="text-xl md:text-2xl font-black">Best Sellers</h3>
                  <p className="text-[12px] text-slate-500 font-semibold">Based on purchases and ratings</p>
                </div>
                <span className="text-[10px] font-bold text-slate-500 uppercase tracking-[0.2em]">Showing {Math.min(bestSellers.length, 6)} items</span>
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
                {bestSellers.slice(0, 6).map((product, idx) => (
                  <div key={`best-${product.id}`} className="relative bg-blue-50/60 border border-blue-100 rounded-2xl p-3 flex gap-3 items-start shadow-sm animate-fade-in-up" style={{ animationDelay: `${idx * 80}ms` }}>
                    <div className="absolute -top-2 -left-2 bg-blue-600 text-white text-[10px] font-black px-2 py-1 rounded-xl shadow">#{idx + 1}</div>
                    <div className="w-20 h-20 rounded-xl overflow-hidden bg-white flex-shrink-0">
                      <img src={product.image} className="w-full h-full object-cover" loading="lazy" alt={product.name} />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 text-[10px] font-bold text-slate-500 mb-1">
                        <span>{product.category}</span><span className="text-yellow-500">★</span><span>{product.rating}</span>
                      </div>
                      <p className="font-black text-sm text-slate-900 truncate">{product.name}</p>
                      <p className="text-[11px] font-bold text-slate-500 mt-1">{stockLeft(product) > 0 ? `${stockLeft(product)} in stock` : 'Out of stock'}</p>
                      <div className="mt-2 flex items-center justify-between">
                        <span className="text-base font-black text-amber-600">${Number(product.price).toFixed(2)}</span>
                        <button onClick={() => handleAddToCart(product)} disabled={stockLeft(product) <= 0}
                          className={`px-3 py-2 rounded-xl text-xs font-black transition-all ${stockLeft(product) <= 0 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-blue-600 text-white hover:bg-blue-700 shadow-sm'}`}>Add</button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {/* Category tabs (mobile) */}
            <div className="lg:hidden flex overflow-x-auto gap-2 no-scrollbar mb-8 pb-2">
              {CATEGORIES.map(cat => (
                <button key={cat} onClick={() => setActiveCategory(cat)}
                  className={`whitespace-nowrap px-5 py-2.5 rounded-xl text-xs font-bold border transition-all ${activeCategory === cat ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200'}`}>{cat}</button>
              ))}
            </div>

            {/* Product Grid */}
            <div id="products-grid">
              <div className="flex items-center justify-between mb-8 px-1">
                <h3 className="text-xl md:text-2xl font-black tracking-tight">{activeCategory === 'All' ? 'Curated Collection' : activeCategory}</h3>
                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{filteredProducts.length} items</span>
              </div>
              {loadingProducts ? (
                <div className="flex justify-center py-20"><div className="w-12 h-12 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" /></div>
              ) : (
                <div className="grid grid-cols-3 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6">
                  {filteredProducts.map((p, idx) => (
                    <ProductCard key={p.id} product={p} stockLeft={stockLeft(p)} onAdd={handleAddToCart} index={idx} />
                  ))}
                </div>
              )}
            </div>
          </div>
        )}

        {/* ── Cart View ─────────────────────────────────────────────── */}
        {view === 'cart' && (
          <div className="max-w-3xl mx-auto px-4 sm:px-6 py-8 md:py-16 animate-fade-in-up">
            <button onClick={() => setView('home')} className="flex items-center text-slate-500 font-extrabold mb-8 text-sm group">
              <div className="p-2 bg-slate-100 rounded-xl mr-3 group-hover:bg-slate-200 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><path d="m15 18-6-6 6-6"/></svg>
              </div>
              Continue Shopping
            </button>
            <h2 className="text-3xl md:text-4xl font-black mb-8 text-white">Your Bag <span className="text-slate-400">({cartCount})</span></h2>
            {stockError && (
              <div className="mb-4 p-4 rounded-2xl bg-red-50 border border-red-200 text-red-700 font-bold text-sm">{stockError}</div>
            )}
            {cart.length === 0 ? (
              <div className="text-center py-20 bg-white/35 backdrop-blur-xl rounded-[2.5rem] border border-white/60 px-6 animate-fade-in">
                <div className="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 text-slate-300">
                  <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                </div>
                <p className="text-slate-400 font-bold mb-8">Your shopping bag is currently empty.</p>
                <button onClick={() => setView('home')} className="bg-blue-500 hover:bg-blue-400 text-white px-10 py-4 rounded-2xl font-black shadow-xl transition-colors">Back to Store</button>
              </div>
            ) : (
              <div className="space-y-4">
                {cart.map((item, idx) => (
                  <div key={item.id} className="bg-white/35 backdrop-blur-lg p-4 rounded-[2rem] border border-white/60 flex gap-4 md:gap-6 items-center animate-fade-in-up" style={{ animationDelay: `${idx * 60}ms` }}>
                    <img src={item.image} className="w-20 h-20 md:w-28 md:h-28 object-cover rounded-[1.5rem]" alt={item.name} />
                    <div className="flex-1 flex flex-col min-w-0">
                      <div className="flex justify-between items-start mb-2">
                        <h4 className="font-black text-sm md:text-lg text-white truncate">{item.name}</h4>
                        <button onClick={() => removeFromCart(item.id)} className="text-slate-400 hover:text-red-500 p-1">
                          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </button>
                      </div>
                      <div className="flex justify-between items-center mt-auto">
                        <div className="flex items-center gap-3 bg-slate-50 p-1 rounded-xl">
                          <button onClick={() => updateQty(item.id, -1)} className="w-8 h-8 bg-white rounded-lg shadow-sm font-black text-slate-900">-</button>
                          <span className="font-black text-sm w-4 text-center text-slate-900">{item.qty}</span>
                          <button onClick={() => updateQty(item.id, 1, getStock(item.id))} className="w-8 h-8 bg-white rounded-lg shadow-sm font-black text-slate-900">+</button>
                        </div>
                        <span className="font-black text-base md:text-lg text-white">${(item.price * item.qty).toFixed(2)}</span>
                      </div>
                    </div>
                  </div>
                ))}
                <div className="mt-12 bg-white/35 backdrop-blur-xl text-white p-8 md:p-10 rounded-[2.5rem] shadow-2xl border border-white/60">
                  <div className="space-y-4 mb-10">
                    <div className="flex justify-between text-slate-300 font-bold text-sm uppercase tracking-widest"><span>Subtotal</span><span>${cartTotal.toFixed(2)}</span></div>
                    <div className="flex justify-between text-slate-300 font-bold text-sm uppercase tracking-widest"><span>Delivery</span><span className="text-blue-400">FREE</span></div>
                    <div className="pt-6 border-t border-slate-600 flex justify-between text-2xl md:text-3xl font-black"><span>Total</span><span className="text-blue-400">${cartTotal.toFixed(2)}</span></div>
                  </div>
                  <button onClick={() => setView('checkout')} className="w-full bg-blue-600 hover:bg-blue-700 py-5 rounded-[1.5rem] font-black text-lg transition-all active:scale-[0.98] shadow-xl shadow-blue-500/20">Proceed to Checkout</button>
                  {recommendedFromLastCategory.length > 0 && (
                    <div className="mt-8 bg-white/80 text-slate-900 border border-slate-100 rounded-[1.75rem] p-4 md:p-5 shadow-lg">
                      <p className="text-[10px] font-black text-slate-400 uppercase tracking-[0.22em] mb-1">More from this category</p>
                      <p className="text-lg font-black mb-4">{lastCategory || 'Suggested for you'}</p>
                      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {recommendedFromLastCategory.map(rec => (
                          <div key={`rec-${rec.id}`} className="flex items-center gap-3 bg-white border border-slate-100 rounded-[1.25rem] p-3 shadow-sm">
                            <img src={rec.image} className="w-14 h-14 rounded-xl object-cover flex-shrink-0" alt={rec.name} />
                            <div className="flex-1 min-w-0">
                              <p className="text-[9px] font-extrabold text-blue-600 uppercase tracking-widest">{rec.category}</p>
                              <p className="text-sm font-black text-slate-900 truncate">{rec.name}</p>
                              <p className="text-xs font-bold text-slate-500">${Number(rec.price).toFixed(2)}</p>
                            </div>
                            <button onClick={() => handleAddToCart(rec)} disabled={stockLeft(rec) <= 0}
                              className={`h-10 min-w-[2.75rem] px-3 rounded-full font-black text-xl border transition-all ${stockLeft(rec) <= 0 ? 'bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed' : 'bg-white text-slate-900 border-slate-200 hover:bg-blue-50 hover:border-blue-300 active:scale-95'}`}>+</button>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
        )}

        {/* ── Checkout View ─────────────────────────────────────────── */}
        {view === 'checkout' && (
          <div className="max-w-6xl mx-auto px-4 sm:px-6 py-8 md:py-16 animate-fade-in-up">
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-10">
              <div className="lg:col-span-7">
                <h2 className="text-3xl font-black mb-8 text-white flex items-center gap-4">
                  <span className="w-10 h-10 rounded-2xl bg-blue-500 flex items-center justify-center text-sm font-black text-white shadow-md">1</span>
                  Delivery Details
                </h2>
                <div className="space-y-4 mb-10 bg-white/35 backdrop-blur-xl border border-white/60 rounded-[2rem] p-6">
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="space-y-1.5 col-span-2">
                      <label className="text-[10px] font-black text-slate-300 uppercase tracking-widest ml-1">Full Name</label>
                      <input type="text" value={deliveryForm.name} onChange={e => setDeliveryForm(f => ({ ...f, name: e.target.value }))} placeholder="Your Name" className="w-full p-4 bg-white border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all" />
                    </div>
                    <div className="space-y-1.5 col-span-2">
                      <label className="text-[10px] font-black text-slate-300 uppercase tracking-widest ml-1">Address</label>
                      <input type="text" value={deliveryForm.location} onChange={e => setDeliveryForm(f => ({ ...f, location: e.target.value }))} placeholder="Your current location" className="w-full p-4 bg-white border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all" />
                    </div>
                    <div className="space-y-1.5 col-span-2">
                      <label className="text-[10px] font-black text-slate-300 uppercase tracking-widest ml-1">Phone</label>
                      <div className="flex gap-2">
                        <span className="bg-slate-100 px-4 py-4 rounded-2xl font-black text-slate-500">+855</span>
                        <input type="tel" value={deliveryForm.phone} onChange={e => setDeliveryForm(f => ({ ...f, phone: e.target.value }))} placeholder="Your Phone Number" inputMode="numeric" maxLength="10" className="flex-1 p-4 bg-white border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all" />
                      </div>
                    </div>
                  </div>
                  {/* Payment Method */}
                  <div className="mt-6 space-y-3">
                    <label className="text-[10px] font-black text-slate-300 uppercase tracking-widest ml-1">Payment Method</label>
                    <div className="flex gap-2 mb-1">
                      <button type="button" onClick={() => setDeliveryCityType('pp')}
                        className={`flex-1 py-2 rounded-xl text-xs font-black border-2 transition-all ${deliveryCityType === 'pp' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-500'}`}>🏙️ Phnom Penh</button>
                      <button type="button" onClick={() => { setDeliveryCityType('province'); setPaymentMethod('khqr') }}
                        className={`flex-1 py-2 rounded-xl text-xs font-black border-2 transition-all ${deliveryCityType === 'province' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-500'}`}>🌾 Province</button>
                    </div>
                    <button type="button" onClick={() => setPaymentMethod('khqr')}
                      className={`w-full flex items-center gap-4 p-4 rounded-2xl border-2 transition-all ${paymentMethod === 'khqr' ? 'border-blue-500 bg-blue-50' : 'border-slate-200 bg-white hover:border-blue-300'}`}>
                      <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${paymentMethod === 'khqr' ? 'bg-blue-500' : 'bg-slate-100'}`}>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={paymentMethod === 'khqr' ? 'white' : '#64748b'} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3"/><path d="M17 21v-4"/><path d="M21 14v3"/><path d="M21 21h-4"/></svg>
                      </div>
                      <div className="text-left flex-1">
                        <p className={`font-black text-sm ${paymentMethod === 'khqr' ? 'text-blue-700' : 'text-slate-800'}`}>Pay by KHQR</p>
                        <p className="text-[11px] font-semibold text-slate-500">ABA · ACLEDA · Wing · TrueMoney and all Bakong banks</p>
                      </div>
                      <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0 ${paymentMethod === 'khqr' ? 'border-blue-500 bg-blue-500' : 'border-slate-300'}`}>
                        {paymentMethod === 'khqr' && <div className="w-2 h-2 rounded-full bg-white" />}
                      </div>
                    </button>
                    <button type="button" onClick={() => deliveryCityType === 'pp' && setPaymentMethod('cash')} disabled={deliveryCityType !== 'pp'}
                      className={`w-full flex items-center gap-4 p-4 rounded-2xl border-2 transition-all ${deliveryCityType !== 'pp' ? 'opacity-50 cursor-not-allowed border-slate-200 bg-white' : paymentMethod === 'cash' ? 'border-green-500 bg-green-50' : 'border-slate-200 bg-white hover:border-green-300'}`}>
                      <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${paymentMethod === 'cash' ? 'bg-green-500' : 'bg-slate-100'}`}>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={paymentMethod === 'cash' ? 'white' : '#64748b'} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                      </div>
                      <div className="text-left flex-1">
                        <p className={`font-black text-sm ${paymentMethod === 'cash' ? 'text-green-700' : 'text-slate-800'}`}>Cash on Delivery</p>
                        <p className="text-[11px] font-semibold text-slate-500">{deliveryCityType === 'pp' ? 'Phnom Penh only' : 'Not available for province'}</p>
                      </div>
                      <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0 ${paymentMethod === 'cash' ? 'border-green-500 bg-green-500' : 'border-slate-300'}`}>
                        {paymentMethod === 'cash' && <div className="w-2 h-2 rounded-full bg-white" />}
                      </div>
                    </button>
                  </div>
                  <button onClick={proceedToPayment}
                    className="w-full bg-blue-600 hover:bg-blue-700 text-white py-5 rounded-[1.5rem] font-black text-lg transition-all active:scale-[0.98] shadow-xl mt-4">
                    {paymentMethod === 'khqr' ? 'Generate KHQR Code' : 'Place Order (Cash)'}
                  </button>
                </div>
              </div>

              {/* Order summary */}
              <div className="lg:col-span-5">
                <div className="bg-white/35 backdrop-blur-xl border border-white/60 rounded-[2rem] p-6 sticky top-24">
                  <h3 className="text-xl font-black mb-6 text-white">Order Summary</h3>
                  <div className="space-y-3 mb-6">
                    {cart.map(item => (
                      <div key={item.id} className="flex items-center gap-3">
                        <img src={item.image} className="w-12 h-12 rounded-xl object-cover" alt={item.name} />
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-black text-white truncate">{item.name}</p>
                          <p className="text-xs text-slate-400">x{item.qty}</p>
                        </div>
                        <span className="text-sm font-black text-white">${(item.price * item.qty).toFixed(2)}</span>
                      </div>
                    ))}
                  </div>
                  <div className="border-t border-white/20 pt-4 space-y-2">
                    <div className="flex justify-between text-slate-300 text-sm font-bold"><span>Subtotal</span><span>${cartTotal.toFixed(2)}</span></div>
                    <div className="flex justify-between text-sm font-bold"><span className="text-slate-300">Delivery</span><span className="text-blue-400">FREE</span></div>
                    <div className="flex justify-between text-xl font-black text-white pt-2 border-t border-white/20"><span>Total</span><span className="text-blue-400">${cartTotal.toFixed(2)}</span></div>
                    <p className="text-xs text-slate-400 text-right">≈ {cartTotalKHR.toLocaleString()} ៛</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}
      </main>

      {/* ── Payment Modal ────────────────────────────────────────────── */}
      {showPaymentModal && (
        <PaymentModal
          khqrImage={khqrImage}
          orderId={currentOrderId}
          paymentError={paymentError}
          paymentLoading={paymentLoading}
          paymentVerifying={paymentVerifying}
          secondsLeft={paymentSecondsLeft}
          onVerify={() => verifyPayment(false)}
          onClose={() => { setShowPaymentModal(false); stopPolling(); stopTimer() }}
        />
      )}

      {/* ── Cash Order Success Modal ─────────────────────────────────── */}
      {showCashModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 animate-fade-in">
          <div className="bg-white rounded-[2rem] w-full max-w-sm p-8 shadow-2xl text-center animate-scale-in">
            <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4 animate-bounce-in">
              <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#16a34a" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </div>
            <h3 className="text-2xl font-black text-slate-900 mb-2">Order Placed!</h3>
            <p className="text-slate-500 font-bold mb-6">Your cash on delivery order has been received. We'll contact you shortly.</p>
            {invoiceNumber && (
              <a href={`#/invoice?id=${encodeURIComponent(invoiceNumber)}`}
                className="inline-block bg-blue-600 text-white px-6 py-3 rounded-2xl font-bold mb-4 hover:bg-blue-700 transition-colors">View Invoice</a>
            )}
            <button onClick={() => setShowCashModal(false)} className="block w-full bg-slate-100 text-slate-700 px-6 py-3 rounded-2xl font-bold hover:bg-slate-200 transition-colors">Close</button>
          </div>
        </div>
      )}

      {/* ── Invoice Popup ─────────────────────────────────────────────── */}
      {showInvoicePopup && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 animate-fade-in">
          <div className="bg-white rounded-[2rem] w-full max-w-sm p-8 shadow-2xl text-center animate-scale-in">
            <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4 animate-bounce-in">
              <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#16a34a" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </div>
            <h3 className="text-2xl font-black text-slate-900 mb-2">Payment Successful!</h3>
            <p className="text-slate-500 font-bold mb-6">Your order has been confirmed.</p>
            {invoiceLoading ? (
              <div className="flex justify-center mb-4"><div className="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" /></div>
            ) : invoiceNumber ? (
              <a href={`#/invoice?id=${encodeURIComponent(invoiceNumber)}`} onClick={() => setShowInvoicePopup(false)}
                className="inline-block bg-blue-600 text-white px-6 py-3 rounded-2xl font-bold mb-4 hover:bg-blue-700 transition-colors">View Invoice #{invoiceNumber}</a>
            ) : null}
            <button onClick={() => setShowInvoicePopup(false)} className="block w-full bg-slate-100 text-slate-700 px-6 py-3 rounded-2xl font-bold hover:bg-slate-200 transition-colors mt-2">Continue Shopping</button>
          </div>
        </div>
      )}
    </div>
  )
}
