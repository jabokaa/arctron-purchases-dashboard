import {
  BarChart3,
  LogOut,
  Menu,
  Package,
  ShoppingCart,
  Users,
  X,
  type LucideIcon,
} from 'lucide-react'
import { useState, type ReactNode } from 'react'
import cbfLogo from '../assets/cbf-logo.svg'
import type { User } from '../services/auth'

export type PageId = 'products'

type MenuItem = {
  id: PageId | null
  label: string
  icon: LucideIcon
}

// Itens sem `id` ainda não têm tela: aparecem desabilitados
const MENU: MenuItem[] = [
  { id: 'products', label: 'Produtos', icon: Package },
  { id: null, label: 'Compras', icon: ShoppingCart },
  { id: null, label: 'Clientes', icon: Users },
  { id: null, label: 'Relatórios', icon: BarChart3 },
]

type Props = {
  user: User
  page: PageId
  onNavigate: (page: PageId) => void
  onLogout: () => void
  children: ReactNode
}

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')
}

function AppShell({ user, page, onNavigate, onLogout, children }: Props) {
  const [open, setOpen] = useState(false)

  return (
    <div className="min-h-svh lg:pl-64">
      {open && (
        <div
          className="fixed inset-0 z-30 bg-navy-950/50 backdrop-blur-sm lg:hidden"
          onClick={() => setOpen(false)}
          aria-hidden="true"
        />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-navy-900 text-navy-100 transition-transform duration-200 lg:translate-x-0 ${
          open ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="flex h-16 items-center justify-between px-5">
          <div className="flex items-center gap-3">
            <img src={cbfLogo} alt="CBF" className="h-9 w-auto" />
            <div className="leading-tight">
              <p className="text-sm font-semibold text-white">Painel de Compras</p>
              <p className="text-xs text-navy-200/70">CBF</p>
            </div>
          </div>
          <button
            type="button"
            className="rounded-lg p-1.5 text-navy-200 hover:bg-white/10 lg:hidden"
            onClick={() => setOpen(false)}
            aria-label="Fechar menu"
          >
            <X className="size-5" />
          </button>
        </div>

        <nav className="flex-1 space-y-1 px-3 py-4" aria-label="Menu principal">
          <p className="px-3 pb-2 text-[11px] font-semibold tracking-wider text-navy-200/50 uppercase">
            Menu
          </p>
          {MENU.map(({ id, label, icon: Icon }) => {
            const active = id !== null && id === page
            const base =
              'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition'

            if (id === null) {
              return (
                <div
                  key={label}
                  aria-disabled="true"
                  className={`${base} cursor-not-allowed text-navy-200/40`}
                >
                  <Icon className="size-[18px]" />
                  <span className="flex-1">{label}</span>
                  <span className="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase">
                    Em breve
                  </span>
                </div>
              )
            }

            return (
              <button
                key={label}
                type="button"
                aria-current={active ? 'page' : undefined}
                onClick={() => {
                  onNavigate(id)
                  setOpen(false)
                }}
                className={`${base} ${
                  active
                    ? 'bg-white/10 text-white shadow-[inset_3px_0_0_var(--color-pitch-500)]'
                    : 'text-navy-200 hover:bg-white/5 hover:text-white'
                }`}
              >
                <Icon className="size-[18px]" />
                {label}
              </button>
            )
          })}
        </nav>

        <div className="border-t border-white/10 p-3">
          <div className="flex items-center gap-3 rounded-lg px-2 py-2">
            <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-pitch-500 text-sm font-semibold text-white">
              {initials(user.name)}
            </div>
            <div className="min-w-0 flex-1 leading-tight">
              <p className="truncate text-sm font-medium text-white">{user.name}</p>
              <p className="truncate text-xs text-navy-200/70">{user.email}</p>
            </div>
            <button
              type="button"
              onClick={onLogout}
              title="Sair"
              aria-label="Sair"
              className="rounded-lg p-2 text-navy-200 transition hover:bg-white/10 hover:text-white"
            >
              <LogOut className="size-[18px]" />
            </button>
          </div>
        </div>
        <div className="h-1 bg-linear-to-r from-pitch-500 via-pitch-500 to-gold" />
      </aside>

      <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur lg:hidden">
        <button
          type="button"
          className="rounded-lg p-2 text-slate-600 hover:bg-slate-100"
          onClick={() => setOpen(true)}
          aria-label="Abrir menu"
        >
          <Menu className="size-5" />
        </button>
        <img src={cbfLogo} alt="" className="h-7 w-auto" />
        <span className="text-sm font-semibold text-navy-900">Painel de Compras</span>
      </header>

      <main>{children}</main>
    </div>
  )
}

export default AppShell
