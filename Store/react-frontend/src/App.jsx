import { HashRouter, Routes, Route, Navigate } from 'react-router-dom'
import StorePage from './pages/StorePage'
import InvoicePage from './pages/InvoicePage'
import AdminLogin from './pages/admin/AdminLogin'
import AdminDashboard from './pages/admin/AdminDashboard'
import AdminProducts from './pages/admin/AdminProducts'
import AdminInvoices from './pages/admin/AdminInvoices'
import { StoreProvider } from './context/StoreContext'

function AdminGuard({ children }) {
  const isLoggedIn = sessionStorage.getItem('admin_logged_in') === 'true'
  return isLoggedIn ? children : <Navigate to="/admin/login" replace />
}

export default function App() {
  return (
    <StoreProvider>
      <HashRouter>
        <Routes>
          <Route path="/" element={<StorePage />} />
          <Route path="/invoice" element={<InvoicePage />} />
          <Route path="/admin/login" element={<AdminLogin />} />
          <Route path="/admin/dashboard" element={<AdminGuard><AdminDashboard /></AdminGuard>} />
          <Route path="/admin/products" element={<AdminGuard><AdminProducts /></AdminGuard>} />
          <Route path="/admin/invoices" element={<AdminGuard><AdminInvoices /></AdminGuard>} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </HashRouter>
    </StoreProvider>
  )
}
