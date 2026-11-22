import { Eye, FilterX, Receipt, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import ErrorAlert from '../../components/ErrorAlert'
import Pagination from '../../components/Pagination'
import { useAsync } from '../../hooks/useAsync'
import {
  formatDateTime,
  formatDocument,
  formatMoney,
  formatNumber,
} from '../../lib/format'
import {
  listPurchases,
  type Purchase,
  type PurchaseFilters,
} from '../../services/purchases'
import ChannelBadge from './ChannelBadge'
import PurchaseDetailModal from './PurchaseDetailModal'

const PAGE_SIZE = 10

type Draft = {
  referenceCode: string
  customerName: string
  dateFrom: string
  dateTo: string
  amountMin: string
  amountMax: string
}

const EMPTY_DRAFT: Draft = {
  referenceCode: '',
  customerName: '',
  dateFrom: '',
  dateTo: '',
  amountMin: '',
  amountMax: '',
}

/** "12,50" ou "12.5" (reais) → 1250 centavos; vazio ou inválido → undefined */
function toCents(value: string): number | undefined {
  const parsed = Number.parseFloat(value.replace(',', '.'))
  return Number.isFinite(parsed) && parsed >= 0
    ? Math.round(parsed * 100)
    : undefined
}

function toFilters(draft: Draft): PurchaseFilters {
  return {
    referenceCode: draft.referenceCode,
    customerName: draft.customerName,
    dateFrom: draft.dateFrom || undefined,
    dateTo: draft.dateTo || undefined,
    amountMin: toCents(draft.amountMin),
    amountMax: toCents(draft.amountMax),
  }
}

type Props = {
  onUnauthorized: () => void
}

function PurchasesPage({ onUnauthorized }: Props) {
  const [draft, setDraft] = useState<Draft>(EMPTY_DRAFT)
  const [applied, setApplied] = useState<Draft>(EMPTY_DRAFT)
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<Purchase | null>(null)

  // Debounce: só consulta a API depois que a pessoa para de digitar
  useEffect(() => {
    const timer = setTimeout(() => {
      setApplied(draft)
      setPage(1)
    }, 350)
    return () => clearTimeout(timer)
  }, [draft])

  const filtersKey = JSON.stringify(applied)
  const { data, error, loading, reload } = useAsync(
    () => listPurchases(toFilters(applied), page, PAGE_SIZE),
    `${filtersKey}|${page}`,
    onUnauthorized,
  )

  function update(field: keyof Draft, value: string) {
    setDraft((current) => ({ ...current, [field]: value }))
  }

  const rows = data?.data ?? []
  const meta = data?.meta
  const initialLoad = loading && !data
  const hasFilters = Object.values(draft).some((value) => value !== '')

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
      <h1 className="text-2xl font-bold tracking-tight text-navy-900 sm:text-3xl">
        Compras
      </h1>
      <p className="mt-1 text-slate-500">
        Compras recebidas pela API e pelos canais de venda.
      </p>

      <div className="mt-6 space-y-4">
        {error && <ErrorAlert message={error} onRetry={reload} />}

        <div className="card overflow-hidden">
          <div className="space-y-3 border-b border-slate-200 p-4">
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400" />
                <input
                  type="search"
                  className="input pl-10"
                  placeholder="Código de referência"
                  aria-label="Código de referência"
                  maxLength={100}
                  value={draft.referenceCode}
                  onChange={(event) => update('referenceCode', event.target.value)}
                />
              </div>
              <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400" />
                <input
                  type="search"
                  className="input pl-10"
                  placeholder="Nome do cliente"
                  aria-label="Nome do cliente"
                  maxLength={255}
                  value={draft.customerName}
                  onChange={(event) => update('customerName', event.target.value)}
                />
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[repeat(4,minmax(0,1fr))_auto]">
              <label className="block">
                <span className="mb-1 block text-xs font-medium text-slate-500">
                  Data inicial
                </span>
                <input
                  type="date"
                  className="input"
                  value={draft.dateFrom}
                  max={draft.dateTo || undefined}
                  onChange={(event) => update('dateFrom', event.target.value)}
                />
              </label>
              <label className="block">
                <span className="mb-1 block text-xs font-medium text-slate-500">
                  Data final
                </span>
                <input
                  type="date"
                  className="input"
                  value={draft.dateTo}
                  min={draft.dateFrom || undefined}
                  onChange={(event) => update('dateTo', event.target.value)}
                />
              </label>
              <label className="block">
                <span className="mb-1 block text-xs font-medium text-slate-500">
                  Valor mínimo (R$)
                </span>
                <input
                  type="number"
                  className="input"
                  min={0}
                  step="0.01"
                  placeholder="0,00"
                  value={draft.amountMin}
                  onChange={(event) => update('amountMin', event.target.value)}
                />
              </label>
              <label className="block">
                <span className="mb-1 block text-xs font-medium text-slate-500">
                  Valor máximo (R$)
                </span>
                <input
                  type="number"
                  className="input"
                  min={0}
                  step="0.01"
                  placeholder="0,00"
                  value={draft.amountMax}
                  onChange={(event) => update('amountMax', event.target.value)}
                />
              </label>
              <div className="flex items-end">
                <button
                  type="button"
                  className="btn btn-secondary w-full lg:w-auto"
                  disabled={!hasFilters}
                  onClick={() => setDraft(EMPTY_DRAFT)}
                >
                  <FilterX className="size-4" />
                  Limpar
                </button>
              </div>
            </div>
          </div>

          <div
            className={`overflow-x-auto transition-opacity ${loading && !initialLoad ? 'opacity-60' : ''}`}
            aria-busy={loading}
          >
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-xs font-semibold tracking-wider text-slate-500 uppercase">
                <tr>
                  <th className="px-4 py-3 pl-6">Compra</th>
                  <th className="hidden px-4 py-3 md:table-cell">Data</th>
                  <th className="px-4 py-3">Cliente</th>
                  <th className="hidden px-4 py-3 text-right lg:table-cell">Itens</th>
                  <th className="hidden px-4 py-3 text-right lg:table-cell">Desconto</th>
                  <th className="px-4 py-3 text-right">Total</th>
                  <th className="px-4 py-3 pr-6 text-right">
                    <span className="sr-only">Ações</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {initialLoad &&
                  Array.from({ length: 6 }, (_, index) => (
                    <tr key={index} aria-hidden="true">
                      <td colSpan={7} className="px-6 py-4">
                        <div className="h-5 animate-pulse rounded bg-slate-100" />
                      </td>
                    </tr>
                  ))}

                {rows.map((purchase) => (
                  <tr
                    key={purchase.id}
                    className="cursor-pointer transition hover:bg-slate-50/70"
                    onClick={() => setSelected(purchase)}
                  >
                    <td className="px-4 py-3 pl-6">
                      <p className="font-mono text-xs font-semibold text-slate-900">
                        {purchase.reference_code}
                      </p>
                      <div className="mt-1">
                        <ChannelBadge channel={purchase.sales_channel} />
                      </div>
                    </td>
                    <td className="hidden px-4 py-3 whitespace-nowrap text-slate-600 md:table-cell">
                      {formatDateTime(purchase.purchased_at)}
                    </td>
                    <td className="px-4 py-3">
                      <p className="font-medium text-slate-900">
                        {purchase.customer.name}
                      </p>
                      <p className="text-xs text-slate-500">
                        {purchase.customer.document_type}{' '}
                        {formatDocument(purchase.customer.document)}
                      </p>
                    </td>
                    <td className="hidden px-4 py-3 text-right tabular-nums lg:table-cell">
                      {formatNumber(purchase.items.length)}
                    </td>
                    <td className="hidden px-4 py-3 text-right text-slate-600 tabular-nums lg:table-cell">
                      {purchase.discount > 0 ? formatMoney(purchase.discount) : '—'}
                    </td>
                    <td className="px-4 py-3 text-right font-semibold text-slate-900 tabular-nums">
                      {formatMoney(purchase.amount)}
                    </td>
                    <td className="px-4 py-3 pr-6 text-right">
                      <button
                        type="button"
                        className="icon-btn"
                        title="Ver detalhes"
                        aria-label={`Ver detalhes da compra ${purchase.reference_code}`}
                        onClick={(event) => {
                          event.stopPropagation()
                          setSelected(purchase)
                        }}
                      >
                        <Eye className="size-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {!loading && !error && rows.length === 0 && (
              <div className="flex flex-col items-center px-6 py-16 text-center">
                <div className="flex size-14 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                  <Receipt className="size-7" />
                </div>
                <p className="mt-4 font-semibold text-slate-900">
                  {hasFilters ? 'Nenhuma compra encontrada' : 'Nenhuma compra registrada'}
                </p>
                <p className="mt-1 text-sm text-slate-500">
                  {hasFilters
                    ? 'Ajuste os filtros para ver outros resultados.'
                    : 'As compras aparecem aqui assim que forem recebidas.'}
                </p>
              </div>
            )}
          </div>

          {meta && (
            <Pagination
              page={page}
              pageSize={PAGE_SIZE}
              total={meta.total}
              totalPages={meta.total_pages}
              disabled={loading}
              onPageChange={setPage}
            />
          )}
        </div>
      </div>

      {selected && (
        <PurchaseDetailModal purchase={selected} onClose={() => setSelected(null)} />
      )}
    </section>
  )
}

export default PurchasesPage
