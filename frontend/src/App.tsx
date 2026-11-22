import { useEffect, useState } from 'react'
import HomePage from './pages/Home/HomePage'
import LoginPage from './pages/Login/LoginPage'
import { currentUser, logout, type User } from './services/auth'

function App() {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  // Ao abrir o site, reaproveita o token salvo (se ainda for válido)
  useEffect(() => {
    currentUser()
      .then(setUser)
      .finally(() => setLoading(false))
  }, [])

  async function handleLogout() {
    await logout().catch(() => undefined)
    setUser(null)
  }

  if (loading) return null

  return user ? (
    <HomePage user={user} onLogout={handleLogout} />
  ) : (
    <LoginPage onLogin={setUser} />
  )
}

export default App
