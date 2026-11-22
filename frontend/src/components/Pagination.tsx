import { ChevronLeft, ChevronRight } from 'lucide-react'

type Props = {
  page: number
  pageSize: number
  total: number
  totalPages: number
  disabled?: boolean
  onPageChange: (page: number) => void
}

function Pagination({
  page,
  pageSize,
  total,
  totalPages,
  disabled,
  onPageChange,
}: Props) {
  if (total === 0) return null

  const first = (page - 1) * pageSize + 1
  const last = Math.min(page * pageSize, total)

  return (
    <div className="flex flex-col items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm text-slate-500 sm:flex-row sm:px-6">
      <span>
        Mostrando <strong className="text-slate-700">{first}</strong>–
        <strong className="text-slate-700">{last}</strong> de{' '}
        <strong className="text-slate-700">{total}</strong>
      </span>
      {totalPages > 1 && (
        <nav className="flex items-center gap-2" aria-label="Paginação">
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            disabled={page <= 1 || disabled}
            onClick={() => onPageChange(page - 1)}
          >
            <ChevronLeft className="size-4" />
            Anterior
          </button>
          <span className="px-2">
            Página {page} de {totalPages}
          </span>
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            disabled={page >= totalPages || disabled}
            onClick={() => onPageChange(page + 1)}
          >
            Próxima
            <ChevronRight className="size-4" />
          </button>
        </nav>
      )}
    </div>
  )
}

export default Pagination
