import { TrendingUp } from 'lucide-react'
import { useState } from 'react'
import ErrorAlert from '../../components/ErrorAlert'
import Pagination from '../../components/Pagination'
import SortHeader from '../../components/SortHeader'
import { useAsync } from '../../hooks/useAsync'
import { formatMoney, formatNumber } from '../../lib/format'
import {
  getProductRanking,
  type ProductSort,
  type SortOrder,
} from '../../services/reports'

const PAGE_SIZE = 10

type Props = {
  onUnauthorized: () => void
}

/** Ranking dos produtos mais vendidos (aba "Mais vendidos") */
function ProductRanking({ onUnauthorized }: Props) {
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<ProductSort>('total')
  const [order, setOrder] = useState<SortOrder>('desc')

  const { data, error, loading, reload } = useAsync(
    () => getProductRanking({ page, limit: PAGE_SIZE, sort, order }),
    `${page}|${sort}|${order}`,
    onUnauthorized,
  )

  function handleSort(field: ProductSort) {
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
    <div className="space-y-4">
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
                  Produto
                </th>
                <SortHeader
                  label="Unidades"
                  field="quantity"
                  sort={sort}
                  order={order}
                  onSort={handleSort}
                  className="text-right"
                />
                <th className="hidden px-4 py-3 text-right font-semibold tracking-wider uppercase md:table-cell">
                  Compras
                </th>
                <SortHeader
                  label="Valor total"
                  field="total"
                  sort={sort}
                  order={order}
                  onSort={handleSort}
                  className="text-right"
                />
                <SortHeader
                  label="Preço médio"
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
                <tr key={row.product.id} className="transition hover:bg-slate-50/70">
                  <td className="px-4 py-3 pl-6 text-slate-400 tabular-nums">
                    {(page - 1) * PAGE_SIZE + index + 1}
                  </td>
                  <td className="px-4 py-3">
                    <p className="font-semibold text-slate-900">{row.product.name}</p>
                    {row.product.internal_code && (
                      <span className="mt-0.5 inline-block rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600">
                        {row.product.internal_code}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {formatNumber(row.quantity_sold)}
                  </td>
                  <td className="hidden px-4 py-3 text-right tabular-nums md:table-cell">
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
                <TrendingUp className="size-7" />
              </div>
              <p className="mt-4 font-semibold text-slate-900">
                Nenhum produto vendido ainda
              </p>
              <p className="mt-1 text-sm text-slate-500">
                O ranking aparece assim que as primeiras compras forem registradas.
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
  )
}

export default ProductRanking
