import { useCallback, useEffect, useState } from 'react'
import AppShell, { type PageId } from './components/AppShell'
import ProductsPage from './pages/Products/ProductsPage'
import LoginPage from './pages/Login/LoginPage'
import { currentUser, logout, type User } from './services/auth'

function App() {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState<PageId>('products')

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
      onNavigate={setPage}
      onLogout={handleLogout}
    >
      {page === 'products' && <ProductsPage onUnauthorized={handleLogout} />}
    </AppShell>
  )
}

export default App
