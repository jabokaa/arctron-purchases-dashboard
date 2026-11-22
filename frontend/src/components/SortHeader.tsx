import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react'
import type { SortOrder } from '../services/reports'

type Props<S extends string> = {
  label: string
  field: S
  sort: S
  order: SortOrder
  onSort: (field: S) => void
  className?: string
}

/** Cabeçalho de coluna clicável: um clique ordena (maior primeiro); outro clique inverte a ordem */
function SortHeader<S extends string>({
  label,
  field,
  sort,
  order,
  onSort,
  className = '',
}: Props<S>) {
  const active = field === sort
  const Icon = !active ? ChevronsUpDown : order === 'asc' ? ArrowUp : ArrowDown

  return (
    <th
      className={`px-4 py-3 ${className}`}
      aria-sort={active ? (order === 'asc' ? 'ascending' : 'descending') : 'none'}
    >
      <button
        type="button"
        onClick={() => onSort(field)}
        className={`inline-flex items-center gap-1 font-semibold tracking-wider uppercase transition hover:text-navy-700 ${
          active ? 'text-navy-700' : ''
        }`}
      >
        {label}
        <Icon className={`size-3.5 ${active ? '' : 'opacity-50'}`} />
      </button>
    </th>
  )
}

export default SortHeader
