import axios from 'axios'

const api = axios.create({ withCredentials: true })

// ── Public APIs ──────────────────────────────────────────────────────────────

export const productsApi = {
  getAll: () => api.get('/api/products').then(r => r.data.data || []),
}

export const paymentApi = {
  generateKHQR: (data) => {
    const fd = new FormData()
    Object.entries(data).forEach(([k, v]) => fd.append(k, v))
    return api.post('/api/payment/generate-khqr', fd).then(r => r.data)
  },
  checkPayment: (orderId) => {
    const fd = new FormData()
    fd.append('order_id', orderId)
    return api.post('/api/payment/check', fd).then(r => r.data)
  },
}

export const invoiceApi = {
  create: (data) => {
    const fd = new FormData()
    Object.entries(data).forEach(([k, v]) => fd.append(k, v))
    return api.post('/api/invoice', fd).then(r => r.data)
  },
  getByNumber: (id) =>
    api.get(`/api/invoice?id=${encodeURIComponent(id)}`).then(r => r.data),
  getByOrder: (orderId) =>
    api.get(`/api/invoice?order=${encodeURIComponent(orderId)}`).then(r => r.data),
  downloadUrl: (invoiceNumber) =>
    `http://127.0.0.1:8001/storage/invoices/Invoice_${encodeURIComponent(invoiceNumber)}.pdf`,
}

// ── Admin APIs ───────────────────────────────────────────────────────────────

export const adminApi = {
  login: (code) => {
    const fd = new FormData()
    fd.append('access_code', code)
    return api.post('/api/admin/auth/login', fd).then(r => r.data)
  },
  logout: () => api.post('/api/admin/auth/logout').then(r => r.data),
  checkAuth: () => api.get('/api/admin/auth/check').then(r => r.data),

  getStats: () => api.get('/api/admin/stats').then(r => r.data),

  getProducts: () => api.get('/api/admin/products').then(r => r.data),
  addProduct: (fd) => api.post('/api/admin/products', fd).then(r => r.data),
  updateStock: (id, stock) => {
    const fd = new FormData()
    fd.append('id', id)
    fd.append('stock', stock)
    return api.post('/api/admin/products/update-stock', fd).then(r => r.data)
  },
  deleteProduct: (id) => api.delete(`/api/admin/products/${id}`).then(r => r.data),

  getInvoices: (params = {}) =>
    api.get('/api/admin/invoices', { params }).then(r => r.data),
  deleteInvoice: (id) => api.delete(`/api/admin/invoices/${id}`).then(r => r.data),
  regeneratePdf: (id) => {
    const fd = new FormData()
    fd.append('id', id)
    return api.post('/api/admin/invoices/regenerate-pdf', fd).then(r => r.data)
  },

  getCodes: () => api.get('/api/admin/codes').then(r => r.data),
  generateCode: () => api.post('/api/admin/codes/generate').then(r => r.data),
  deactivateCode: (id) => {
    const fd = new FormData()
    fd.append('id', id)
    return api.post('/api/admin/codes/deactivate', fd).then(r => r.data)
  },
}

