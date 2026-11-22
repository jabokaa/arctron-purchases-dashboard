import { AlertCircle, Eye, EyeOff, Loader2, Lock, Mail } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import cbfLogo from '../../assets/cbf-logo.svg'
import { AuthError, login, type User } from '../../services/auth'

type FormErrors = {
  email?: string
  password?: string
}

const CURRENT_YEAR = new Date().getFullYear()

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

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

type Props = {
  onLogin: (user: User) => void
}

function LoginPage({ onLogin }: Props) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [errors, setErrors] = useState<FormErrors>({})
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)

    const validationErrors = validate(email, password)
    setErrors(validationErrors)
    if (Object.keys(validationErrors).length > 0) return

    setSubmitting(true)
    try {
      onLogin(await login(email.trim(), password, remember))
    } catch (error) {
      if (error instanceof AuthError && error.violations?.length) {
        const fieldErrors: FormErrors = {}
        for (const violation of error.violations) {
          if (violation.field === 'email' || violation.field === 'password') {
            fieldErrors[violation.field] ??= violation.message
          }
        }
        setErrors(fieldErrors)
      } else {
        setFormError(
          error instanceof AuthError
            ? error.message
            : 'Ocorreu um erro inesperado. Tente novamente.',
        )
      }
      setSubmitting(false)
    }
  }

  return (
    <div className="grid min-h-svh lg:grid-cols-2">
      <aside
        className="relative hidden overflow-hidden bg-navy-900 text-white lg:flex lg:items-center lg:justify-center"
        aria-hidden="true"
      >
        <div className="absolute -top-32 -left-32 size-[28rem] rounded-full bg-navy-500/40 blur-3xl" />
        <div className="absolute -right-24 -bottom-32 size-[26rem] rounded-full bg-pitch-500/30 blur-3xl" />
        <div className="relative z-10 max-w-sm px-8 text-center">
          <img
            src={cbfLogo}
            alt=""
            className="mx-auto h-36 w-auto drop-shadow-2xl"
          />
          <h2 className="mt-10 text-3xl font-bold tracking-tight">
            Painel de Compras
          </h2>
          <p className="mt-3 text-lg text-navy-100/80">
            Acompanhe pedidos, clientes e produtos em um só lugar.
          </p>
        </div>
        <div className="absolute inset-x-0 bottom-0 h-2 bg-linear-to-r from-pitch-500 from-50% to-gold to-50%" />
      </aside>

      <main className="flex items-center justify-center px-5 py-10">
        <div className="w-full max-w-sm">
          <img
            src={cbfLogo}
            alt="Confederação Brasileira de Futebol"
            className="mx-auto mb-8 h-16 w-auto lg:hidden"
          />

          <header className="mb-8">
            <h1 className="text-3xl font-bold tracking-tight text-navy-900">
              Entrar
            </h1>
            <p className="mt-2 text-slate-500">
              Acesse sua conta para continuar.
            </p>
          </header>

          {formError && (
            <div className="alert alert-error mb-5" role="alert">
              <AlertCircle className="mt-0.5 size-4 shrink-0" />
              {formError}
            </div>
          )}

          <form className="space-y-5" onSubmit={handleSubmit} noValidate>
            <div>
              <label htmlFor="email" className="label">
                E-mail
              </label>
              <div className="relative">
                <Mail className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400" />
                <input
                  id="email"
                  type="email"
                  autoComplete="email"
                  placeholder="seu.nome@cbf.com.br"
                  className="input pl-10"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  aria-invalid={Boolean(errors.email)}
                  aria-describedby={errors.email ? 'email-error' : undefined}
                />
              </div>
              {errors.email && (
                <span id="email-error" className="field-error">
                  {errors.email}
                </span>
              )}
            </div>

            <div>
              <label htmlFor="password" className="label">
                Senha
              </label>
              <div className="relative">
                <Lock className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400" />
                <input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  placeholder="••••••••"
                  className="input px-10"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  aria-invalid={Boolean(errors.password)}
                  aria-describedby={
                    errors.password ? 'password-error' : undefined
                  }
                />
                <button
                  type="button"
                  className="absolute top-1/2 right-2 -translate-y-1/2 rounded-md p-1.5 text-slate-400 hover:text-slate-700"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-label={showPassword ? 'Ocultar senha' : 'Mostrar senha'}
                >
                  {showPassword ? (
                    <EyeOff className="size-4" />
                  ) : (
                    <Eye className="size-4" />
                  )}
                </button>
              </div>
              {errors.password && (
                <span id="password-error" className="field-error">
                  {errors.password}
                </span>
              )}
            </div>

            <div className="flex items-center justify-between text-sm">
              <label className="flex items-center gap-2 text-slate-600 select-none">
                <input
                  type="checkbox"
                  className="size-4 rounded border-slate-300 accent-pitch-500"
                  checked={remember}
                  onChange={(e) => setRemember(e.target.checked)}
                />
                Lembrar de mim
              </label>
              <a
                href="#"
                className="font-medium text-navy-600 hover:text-navy-800 hover:underline"
              >
                Esqueci minha senha
              </a>
            </div>

            <button
              type="submit"
              className="btn btn-primary w-full py-3"
              disabled={submitting}
            >
              {submitting && <Loader2 className="size-4 animate-spin" />}
              {submitting ? 'Entrando…' : 'Entrar'}
            </button>
          </form>

          <footer className="mt-10 text-center text-xs text-slate-400">
            © {CURRENT_YEAR} Confederação Brasileira de Futebol
          </footer>
        </div>
      </main>
    </div>
  )
}

export default LoginPage
