import {
  BadgePercent,
  CircleDollarSign,
  Package,
  Receipt,
  RefreshCw,
  ShoppingCart,
  Tag,
  Wallet,
  type LucideIcon,
} from 'lucide-react'
import { useState, type ReactNode } from 'react'
import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
  type TooltipContentProps,
} from 'recharts'
import ErrorAlert from '../../components/ErrorAlert'
import { useAsync } from '../../hooks/useAsync'
import {
  formatDate,
  formatDayMonth,
  formatMoney,
  formatMoneyCompact,
  formatMonth,
  formatNumber,
} from '../../lib/format'
import {
  getCustomerRanking,
  getProductRanking,
  getSummary,
  getTimeline,
  type TimelineInterval,
  type TimelinePoint,
} from '../../services/reports'

type Props = {
  onUnauthorized: () => void
  onOpenCustomers: () => void
  onOpenProductRanking: () => void
}

type Metric = 'amount' | 'purchases_count'

const INTERVALS: { id: TimelineInterval; label: string }[] = [
  { id: 'day', label: 'Dia' },
  { id: 'week', label: 'Semana' },
  { id: 'month', label: 'Mês' },
]

const METRICS: { id: Metric; label: string }[] = [
  { id: 'amount', label: 'Valor' },
  { id: 'purchases_count', label: 'Compras' },
]

function tickLabel(point: TimelinePoint, interval: TimelineInterval) {
  return interval === 'month'
    ? formatMonth(point.period)
    : formatDayMonth(point.start)
}

function periodLabel(point: TimelinePoint, interval: TimelineInterval) {
  if (interval === 'month') return formatMonth(point.period)
  if (interval === 'day') return formatDate(point.start)
  return `${formatDate(point.start)} – ${formatDate(point.end)}`
}

function Segmented<T extends string>({
  options,
  value,
  onChange,
  label,
}: {
  options: { id: T; label: string }[]
  value: T
  onChange: (value: T) => void
  label: string
}) {
  return (
    <div
      className="inline-flex rounded-lg bg-slate-100 p-0.5"
      role="group"
      aria-label={label}
    >
      {options.map((option) => (
        <button
          key={option.id}
          type="button"
          aria-pressed={value === option.id}
          onClick={() => onChange(option.id)}
          className={`rounded-md px-3 py-1 text-[13px] font-semibold transition ${
            value === option.id
              ? 'bg-white text-navy-900 shadow-sm'
              : 'text-slate-500 hover:text-slate-700'
          }`}
        >
          {option.label}
        </button>
      ))}
    </div>
  )
}

function KpiCard({
  icon: Icon,
  label,
  value,
  hint,
  tone,
  loading,
}: {
  icon: LucideIcon
  label: string
  value: string | null | undefined
  hint?: string
  tone: string
  loading: boolean
}) {
  return (
    <div className="card p-5">
      <div className="flex items-center gap-3">
        <div className={`flex size-9 shrink-0 items-center justify-center rounded-lg ${tone}`}>
          <Icon className="size-[18px]" />
        </div>
        <p className="text-sm font-medium text-slate-500">{label}</p>
      </div>
      {value == null || loading ? (
        <div className="mt-4 h-8 w-32 animate-pulse rounded bg-slate-100" />
      ) : (
        <p className="mt-3 text-2xl font-bold tracking-tight whitespace-nowrap text-navy-900 tabular-nums">
          {value}
        </p>
      )}
      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </div>
  )
}

function ChartTooltip({
  active,
  payload,
  interval,
}: TooltipContentProps & { interval: TimelineInterval }) {
  const point = payload?.[0]?.payload as TimelinePoint | undefined
  if (!active || !point) return null

  return (
    <div className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-lg">
      <p className="font-semibold text-navy-900">{periodLabel(point, interval)}</p>
      <dl className="mt-1 space-y-0.5 text-slate-600">
        <div className="flex justify-between gap-6">
          <dt>Compras</dt>
          <dd className="font-medium tabular-nums">{formatNumber(point.purchases_count)}</dd>
        </div>
        <div className="flex justify-between gap-6">
          <dt>Valor</dt>
          <dd className="font-medium tabular-nums">{formatMoney(point.amount)}</dd>
        </div>
        <div className="flex justify-between gap-6">
          <dt>Descontos</dt>
          <dd className="font-medium tabular-nums">{formatMoney(point.discount)}</dd>
        </div>
      </dl>
    </div>
  )
}

function TopList({
  title,
  action,
  onAction,
  loading,
  error,
  onRetry,
  empty,
  children,
}: {
  title: string
  action: string
  onAction: () => void
  loading: boolean
  error: string | null
  onRetry: () => void
  empty: boolean
  children: ReactNode
}) {
  return (
    <div className="card flex flex-col">
      <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
        <h2 className="font-semibold text-navy-900">{title}</h2>
        <button
          type="button"
          onClick={onAction}
          className="text-sm font-semibold text-navy-600 hover:text-navy-800 hover:underline"
        >
          {action}
        </button>
      </div>
      <div className="flex-1 p-5">
        {error ? (
          <ErrorAlert message={error} onRetry={onRetry} />
        ) : loading ? (
          <div className="space-y-4">
            {Array.from({ length: 5 }, (_, index) => (
              <div key={index} className="h-10 animate-pulse rounded bg-slate-100" />
            ))}
          </div>
        ) : empty ? (
          <p className="py-8 text-center text-sm text-slate-400">
            Ainda não há compras registradas.
          </p>
        ) : (
          <ol className="space-y-4">{children}</ol>
        )}
      </div>
    </div>
  )
}

function RankItem({
  position,
  title,
  subtitle,
  value,
  share,
}: {
  position: number
  title: string
  subtitle: string
  value: string
  share: number
}) {
  return (
    <li className="flex items-center gap-3">
      <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-navy-50 text-xs font-bold text-navy-700">
        {position}
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex items-baseline justify-between gap-3">
          <p className="truncate text-sm font-semibold text-slate-900">{title}</p>
          <p className="shrink-0 text-sm font-semibold text-navy-900 tabular-nums">{value}</p>
        </div>
        <p className="truncate text-xs text-slate-500">{subtitle}</p>
        <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
          <div
            className="h-full rounded-full bg-pitch-500"
            style={{ width: `${Math.max(share, 3)}%` }}
          />
        </div>
      </div>
    </li>
  )
}

function DashboardPage({ onUnauthorized, onOpenCustomers, onOpenProductRanking }: Props) {
  const [interval, setInterval] = useState<TimelineInterval>('month')
  const [metric, setMetric] = useState<Metric>('amount')

  const summary = useAsync(getSummary, 'summary', onUnauthorized)
  const timeline = useAsync(() => getTimeline(interval), interval, onUnauthorized)
  const customers = useAsync(
    () => getCustomerRanking({ limit: 5, sort: 'total', order: 'desc' }),
    'top5',
    onUnauthorized,
  )
  const products = useAsync(
    () => getProductRanking({ limit: 5, sort: 'total', order: 'desc' }),
    'top5',
    onUnauthorized,
  )

  function refresh() {
    summary.reload()
    timeline.reload()
    customers.reload()
    products.reload()
  }

  const s = summary.data
  const loadingSummary = summary.loading && !s
  const points = timeline.data?.data ?? []
  const chartData = points.map((point) => ({
    ...point,
    label: tickLabel(point, interval),
  }))
  const topCustomers = customers.data?.data ?? []
  const topProducts = products.data?.data ?? []
  const refreshing =
    summary.loading || timeline.loading || customers.loading || products.loading

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-navy-900 sm:text-3xl">
            Dashboard
          </h1>
          <p className="mt-1 text-slate-500">
            Visão geral das compras, clientes e produtos.
          </p>
        </div>
        <button
          type="button"
          className="btn btn-secondary"
          onClick={refresh}
          disabled={refreshing}
        >
          <RefreshCw className={`size-4 ${refreshing ? 'animate-spin' : ''}`} />
          Atualizar
        </button>
      </div>

      <div className="mt-6 space-y-6">
        {summary.error && <ErrorAlert message={summary.error} onRetry={summary.reload} />}

        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <KpiCard
            icon={ShoppingCart}
            label="Compras"
            value={s && formatNumber(s.purchases_count)}
            hint="Total de compras registradas"
            tone="bg-navy-50 text-navy-700"
            loading={loadingSummary}
          />
          <KpiCard
            icon={CircleDollarSign}
            label="Valor total"
            value={s && formatMoney(s.amount.total)}
            hint="Já com os descontos"
            tone="bg-pitch-50 text-pitch-600"
            loading={loadingSummary}
          />
          <KpiCard
            icon={Receipt}
            label="Ticket médio"
            value={s && formatMoney(s.amount.average)}
            hint="Valor médio por compra"
            tone="bg-navy-50 text-navy-700"
            loading={loadingSummary}
          />
          <KpiCard
            icon={Package}
            label="Unidades vendidas"
            value={s && formatNumber(s.products.units_sold)}
            hint="Soma das quantidades"
            tone="bg-amber-50 text-amber-600"
            loading={loadingSummary}
          />
          <KpiCard
            icon={BadgePercent}
            label="Descontos"
            value={s && formatMoney(s.discount.total)}
            hint="Total concedido"
            tone="bg-rose-50 text-rose-600"
            loading={loadingSummary}
          />
          <KpiCard
            icon={Tag}
            label="Desconto médio"
            value={s && formatMoney(s.discount.average)}
            hint="Por compra"
            tone="bg-rose-50 text-rose-600"
            loading={loadingSummary}
          />
          <KpiCard
            icon={Wallet}
            label="Produtos vendidos"
            value={s && formatMoney(s.products.total)}
            hint="Antes dos descontos"
            tone="bg-amber-50 text-amber-600"
            loading={loadingSummary}
          />
          <KpiCard
            icon={Tag}
            label="Preço médio"
            value={s && formatMoney(s.products.average)}
            hint="Por unidade vendida"
            tone="bg-amber-50 text-amber-600"
            loading={loadingSummary}
          />
        </div>

        <div className="card">
          <div className="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="font-semibold text-navy-900">Evolução das compras</h2>
              <p className="text-sm text-slate-500">
                {metric === 'amount' ? 'Valor das compras' : 'Quantidade de compras'} por{' '}
                {INTERVALS.find((item) => item.id === interval)?.label.toLowerCase()}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Segmented
                label="Métrica"
                options={METRICS}
                value={metric}
                onChange={setMetric}
              />
              <Segmented
                label="Período"
                options={INTERVALS}
                value={interval}
                onChange={setInterval}
              />
            </div>
          </div>
          <div className="p-5">
            {timeline.error ? (
              <ErrorAlert message={timeline.error} onRetry={timeline.reload} />
            ) : timeline.loading && !timeline.data ? (
              <div className="h-72 animate-pulse rounded-lg bg-slate-100" />
            ) : chartData.length === 0 ? (
              <div className="flex h-72 items-center justify-center text-sm text-slate-400">
                Ainda não há compras para exibir no gráfico.
              </div>
            ) : (
              <div className={`h-72 transition-opacity ${timeline.loading ? 'opacity-60' : ''}`}>
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={chartData} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e2e8f0" />
                    <XAxis
                      dataKey="label"
                      tickLine={false}
                      axisLine={false}
                      tick={{ fill: '#64748b', fontSize: 12 }}
                      tickMargin={8}
                    />
                    <YAxis
                      tickLine={false}
                      axisLine={false}
                      width={metric === 'amount' ? 72 : 40}
                      allowDecimals={false}
                      tick={{ fill: '#64748b', fontSize: 12 }}
                      tickFormatter={(value: number) =>
                        metric === 'amount' ? formatMoneyCompact(value) : formatNumber(value)
                      }
                    />
                    <Tooltip
                      cursor={{ fill: '#f1f5f9' }}
                      content={(props) => <ChartTooltip {...props} interval={interval} />}
                    />
                    <Bar
                      dataKey={metric}
                      fill="#19ae47"
                      radius={[6, 6, 0, 0]}
                      maxBarSize={48}
                    />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            )}
          </div>
        </div>

        <div className="grid gap-6 lg:grid-cols-2">
          <TopList
            title="Top 5 clientes"
            action="Ver ranking"
            onAction={onOpenCustomers}
            loading={customers.loading && !customers.data}
            error={customers.error}
            onRetry={customers.reload}
            empty={topCustomers.length === 0}
          >
            {topCustomers.map((row, index) => (
              <RankItem
                key={row.customer.id}
                position={index + 1}
                title={row.customer.name}
                subtitle={`${formatNumber(row.purchases_count)} ${row.purchases_count === 1 ? 'compra' : 'compras'} · ${row.customer.email}`}
                value={formatMoney(row.total)}
                share={(row.total / topCustomers[0].total) * 100 || 0}
              />
            ))}
          </TopList>

          <TopList
            title="Top 5 produtos"
            action="Ver ranking"
            onAction={onOpenProductRanking}
            loading={products.loading && !products.data}
            error={products.error}
            onRetry={products.reload}
            empty={topProducts.length === 0}
          >
            {topProducts.map((row, index) => (
              <RankItem
                key={row.product.id}
                position={index + 1}
                title={row.product.name}
                subtitle={`${formatNumber(row.quantity_sold)} ${row.quantity_sold === 1 ? 'unidade' : 'unidades'}${row.product.internal_code ? ` · ${row.product.internal_code}` : ''}`}
                value={formatMoney(row.total)}
                share={(row.total / topProducts[0].total) * 100 || 0}
              />
            ))}
          </TopList>
        </div>
      </div>
    </section>
  )
}

export default DashboardPage
