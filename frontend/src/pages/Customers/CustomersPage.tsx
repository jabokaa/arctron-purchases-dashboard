import { Users } from 'lucide-react'
import { useState } from 'react'
import ErrorAlert from '../../components/ErrorAlert'
import Pagination from '../../components/Pagination'
import SortHeader from '../../components/SortHeader'
import { useAsync } from '../../hooks/useAsync'
import { formatDocument, formatMoney, formatNumber } from '../../lib/format'
import {
  getCustomerRanking,
  type CustomerSort,
  type SortOrder,
} from '../../services/reports'

const PAGE_SIZE = 10

type Props = {
  onUnauthorized: () => void
}

function CustomersPage({ onUnauthorized }: Props) {
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<CustomerSort>('total')
  const [order, setOrder] = useState<SortOrder>('desc')

  const { data, error, loading, reload } = useAsync(
    () => getCustomerRanking({ page, limit: PAGE_SIZE, sort, order }),
    `${page}|${sort}|${order}`,
    onUnauthorized,
  )

  function handleSort(field: CustomerSort) {
    setPage(1)
    if (field === sort) setOrder(order === 'desc' ? 'asc' : 'desc')
    else {
      setSort(field)
      setOrder('desc')
    }
  }

  const rows = data?.data ?? []
  const meta = data?.meta
  const initialLoad = loading && !data

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
      <h1 className="text-2xl font-bold tracking-tight text-navy-900 sm:text-3xl">
        Clientes
      </h1>
      <p className="mt-1 text-slate-500">
        Ranking dos clientes que mais compram. Clique nos títulos para ordenar.
      </p>

      <div className="mt-6 space-y-4">
        {error && <ErrorAlert message={error} onRetry={reload} />}

        <div className="card overflow-hidden">
          <div
            className={`overflow-x-auto transition-opacity ${loading && !initialLoad ? 'opacity-60' : ''}`}
            aria-busy={loading}
          >
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-xs text-slate-500">
                <tr>
                  <th className="w-16 px-4 py-3 pl-6 font-semibold tracking-wider uppercase">
                    #
                  </th>
                  <th className="px-4 py-3 font-semibold tracking-wider uppercase">
                    Cliente
                  </th>
                  <th className="hidden px-4 py-3 font-semibold tracking-wider uppercase md:table-cell">
                    Documento
                  </th>
                  <SortHeader
                    label="Compras"
                    field="count"
                    sort={sort}
                    order={order}
                    onSort={handleSort}
                    className="text-right"
                  />
                  <SortHeader
                    label="Valor total"
                    field="total"
                    sort={sort}
                    order={order}
                    onSort={handleSort}
                    className="text-right"
                  />
                  <SortHeader
                    label="Valor médio"
                    field="average"
                    sort={sort}
                    order={order}
                    onSort={handleSort}
                    className="hidden text-right pr-6 sm:table-cell"
                  />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {initialLoad &&
                  Array.from({ length: 5 }, (_, index) => (
                    <tr key={index} aria-hidden="true">
                      <td colSpan={6} className="px-6 py-4">
                        <div className="h-5 animate-pulse rounded bg-slate-100" />
                      </td>
                    </tr>
                  ))}

                {rows.map((row, index) => (
                  <tr key={row.customer.id} className="transition hover:bg-slate-50/70">
                    <td className="px-4 py-3 pl-6 text-slate-400 tabular-nums">
                      {(page - 1) * PAGE_SIZE + index + 1}
                    </td>
                    <td className="px-4 py-3">
                      <p className="font-semibold text-slate-900">{row.customer.name}</p>
                      <p className="text-xs text-slate-500">
                        {row.customer.trade_name
                          ? `${row.customer.trade_name} · `
                          : ''}
                        {row.customer.email}
                      </p>
                    </td>
                    <td className="hidden px-4 py-3 md:table-cell">
                      <span className="mr-1.5 rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-600">
                        {row.customer.document_type}
                      </span>
                      <span className="font-mono text-xs text-slate-600">
                        {formatDocument(row.customer.document)}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums">
                      {formatNumber(row.purchases_count)}
                    </td>
                    <td className="px-4 py-3 text-right font-semibold text-slate-900 tabular-nums">
                      {formatMoney(row.total)}
                    </td>
                    <td className="hidden px-4 py-3 pr-6 text-right tabular-nums sm:table-cell">
                      {formatMoney(row.average)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {!loading && !error && rows.length === 0 && (
              <div className="flex flex-col items-center px-6 py-16 text-center">
                <div className="flex size-14 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                  <Users className="size-7" />
                </div>
                <p className="mt-4 font-semibold text-slate-900">
                  Nenhum cliente com compras
                </p>
                <p className="mt-1 text-sm text-slate-500">
                  Os clientes aparecem aqui assim que as primeiras compras forem registradas.
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
    </section>
  )
}

export default CustomersPage
