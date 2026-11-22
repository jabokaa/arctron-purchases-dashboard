import { useState, type FormEvent } from 'react'
import cbfLogo from '../../assets/cbf-logo.svg'
import './LoginPage.css'

type FormErrors = {
  email?: string
  password?: string
}

const CURRENT_YEAR = new Date().getFullYear()

const EMAIL_PATTERN =/^[^\s@]+@[^\s@]+\.[^\s@]+$/

function validate(email: string, password: string): FormErrors {
  const errors: FormErrors = {}

  if (!email.trim()) {
    errors.email = 'Informe seu e-mail.'
  } else if (!EMAIL_PATTERN.test(email.trim())) {
    errors.email = 'E-mail inválido.'
  }

  if (!password) {
    errors.password = 'Informe sua senha.'
  } else if (password.length < 6) {
    errors.password = 'A senha deve ter pelo menos 6 caracteres.'
  }

  return errors
}

function LoginPage() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [errors, setErrors] = useState<FormErrors>({})
  const [submitting, setSubmitting] = useState(false)
  const [success, setSuccess] = useState(false)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSuccess(false)

    const validationErrors = validate(email, password)
    setErrors(validationErrors)
    if (Object.keys(validationErrors).length > 0) return

    // TODO: integrar com o endpoint de autenticação do backend.
    setSubmitting(true)
    await new Promise((resolve) => setTimeout(resolve, 800))
    setSubmitting(false)
    setSuccess(true)
  }

  return (
    <div className="login">
      <aside className="login__brand" aria-hidden="true">
        <div className="login__brand-inner">
          <img src={cbfLogo} alt="" className="login__brand-logo" />
          <h2 className="login__brand-title">Painel de Compras</h2>
          <p className="login__brand-text">
            Acompanhe pedidos, clientes e produtos em um só lugar.
          </p>
        </div>
        <div className="login__stripe" />
      </aside>

      <main className="login__main">
        <div className="login__card">
          <img
            src={cbfLogo}
            alt="Confederação Brasileira de Futebol"
            className="login__logo"
          />

          <header className="login__header">
            <h1>Entrar</h1>
            <p>Acesse sua conta para continuar.</p>
          </header>

          {success && (
            <div className="login__alert" role="status">
              Login simulado com sucesso. A integração com o backend ainda
              não foi implementada.
            </div>
          )}

          <form className="login__form" onSubmit={handleSubmit} noValidate>
            <div className="field">
              <label htmlFor="email">E-mail</label>
              <input
                id="email"
                type="email"
                autoComplete="email"
                placeholder="seu.nome@cbf.com.br"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                aria-invalid={Boolean(errors.email)}
                aria-describedby={errors.email ? 'email-error' : undefined}
              />
              {errors.email && (
                <span id="email-error" className="field__error">
                  {errors.email}
                </span>
              )}
            </div>

            <div className="field">
              <label htmlFor="password">Senha</label>
              <div className="field__password">
                <input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  placeholder="••••••••"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  aria-invalid={Boolean(errors.password)}
                  aria-describedby={
                    errors.password ? 'password-error' : undefined
                  }
                />
                <button
                  type="button"
                  className="field__toggle"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-label={showPassword ? 'Ocultar senha' : 'Mostrar senha'}
                >
                  {showPassword ? 'Ocultar' : 'Mostrar'}
                </button>
              </div>
              {errors.password && (
                <span id="password-error" className="field__error">
                  {errors.password}
                </span>
              )}
            </div>

            <div className="login__options">
              <label className="checkbox">
                <input
                  type="checkbox"
                  checked={remember}
                  onChange={(e) => setRemember(e.target.checked)}
                />
                Lembrar de mim
              </label>
              <a href="#" className="login__link">
                Esqueci minha senha
              </a>
            </div>

            <button
              type="submit"
              className="login__submit"
              disabled={submitting}
            >
              {submitting ? 'Entrando…' : 'Entrar'}
            </button>
          </form>

          <footer className="login__footer">
            © {CURRENT_YEAR}Confederação Brasileira de Futebol
          </footer>
        </div>
      </main>
    </div>
  )
}

export default LoginPage
