import { useCallback, useEffect, useState } from 'react'
import AppShell, { type PageId } from './components/AppShell'
import CustomersPage from './pages/Customers/CustomersPage'
import DashboardPage from './pages/Dashboard/DashboardPage'
import ProductsPage, { type ProductsTab } from './pages/Products/ProductsPage'
import LoginPage from './pages/Login/LoginPage'
import { currentUser, logout, type User } from './services/auth'

function App() {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState<PageId>('dashboard')
  const [productsTab, setProductsTab] = useState<ProductsTab>('catalog')

  // Ao abrir o site, reaproveita o token salvo (se ainda for válido)
  useEffect(() => {
    currentUser()
      .then(setUser)
      .finally(() => setLoading(false))
  }, [])

  const handleLogout = useCallback(async () => {
    await logout().catch(() => undefined)
    setUser(null)
  }, [])

  if (loading) return null

  if (!user) return <LoginPage onLogin={setUser} />

  return (
    <AppShell
      user={user}
      page={page}
      onNavigate={(next) => {
        if (next === 'products') setProductsTab('catalog')
        setPage(next)
      }}
      onLogout={handleLogout}
    >
      {page === 'dashboard' && (
        <DashboardPage
          onUnauthorized={handleLogout}
          onOpenCustomers={() => setPage('customers')}
          onOpenProductRanking={() => {
            setProductsTab('ranking')
            setPage('products')
          }}
        />
      )}
      {page === 'products' && (
        <ProductsPage
          key={productsTab}
          initialTab={productsTab}
          onUnauthorized={handleLogout}
        />
      )}
      {page === 'customers' && <CustomersPage onUnauthorized={handleLogout} />}
    </AppShell>
  )
}

export default App
