import cbfLogo from '../../assets/cbf-logo.svg'
import type { User } from '../../services/auth'
import './HomePage.css'

type Props = {
  user: User
  onLogout: () => void
}

/** Tela provisória pós-login, até o dashboard ser construído */
function HomePage({ user, onLogout }: Props) {
  return (
    <div className="home">
      <header className="home__bar">
        <div className="home__brand">
          <img src={cbfLogo} alt="CBF" />
          <span>Painel de Compras</span>
        </div>
        <div className="home__user">
          <span>{user.name}</span>
          <button type="button" onClick={onLogout}>
            Sair
          </button>
        </div>
      </header>
      <main className="home__main">
        <h1>Olá, {user.name}!</h1>
        <p>Você está autenticado como {user.email}.</p>
      </main>
    </div>
  )
}

export default HomePage
