import { useState } from 'react'
import cbfLogo from '../../assets/cbf-logo.svg'
import type { User } from '../../services/auth'
import ProductsPage from '../Products/ProductsPage'
import './HomePage.css'

type Props = {
  user: User
  onLogout: () => void
}

type Page = 'products'

const MENU: { id: Page; label: string }[] = [{ id: 'products', label: 'Produtos' }]

/** Estrutura pós-login: barra superior com menu e a tela da página ativa */
function HomePage({ user, onLogout }: Props) {
  const [page, setPage] = useState<Page>('products')

  return (
    <div className="home">
      <header className="home__bar">
        <div className="home__brand">
          <img src={cbfLogo} alt="CBF" />
          <span>Painel de Compras</span>
        </div>
        <nav className="home__menu" aria-label="Menu principal">
          {MENU.map((item) => (
            <button
              key={item.id}
              type="button"
              className={item.id === page ? 'is-active' : undefined}
              aria-current={item.id === page ? 'page' : undefined}
              onClick={() => setPage(item.id)}
            >
              {item.label}
            </button>
          ))}
        </nav>
        <div className="home__user">
          <span>{user.name}</span>
          <button type="button" onClick={onLogout}>
            Sair
          </button>
        </div>
      </header>
      <main>
        {page === 'products' && <ProductsPage onUnauthorized={onLogout} />}
      </main>
    </div>
  )
}

export default HomePage
