import {
  ChevronLeft,
  ChevronRight,
  CircleCheck,
  CircleAlert,
  ImageOff,
  PackageOpen,
  Pencil,
  Plus,
  Search,
  Trash2,
  X,
} from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { AuthError } from '../../services/auth'
import {
  deleteProduct,
  listProducts,
  type Product,
  type ProductListMeta,
} from '../../services/products'
import ProductFormModal from './ProductFormModal'

const PER_PAGE = 10

type Props = {
  onUnauthorized: () => void
}

type FormState = { product: Product | null } | null

function ProductsPage({ onUnauthorized }: Props) {
  const [products, setProducts] = useState<Product[]>([])
  const [meta, setMeta] = useState<ProductListMeta | null>(null)
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)
  const [loadedKey, setLoadedKey] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [form, setForm] = useState<FormState>(null)
  const [toDelete, setToDelete] = useState<Product | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  // Debounce da busca: só consulta a API depois que a pessoa para de digitar
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput)
      setPage(1)
    }, 350)
    return () => clearTimeout(timer)
  }, [searchInput])

  useEffect(() => {
    let cancelled = false
    const key = `${search}|${page}|${reloadKey}`
    listProducts({ search, page, perPage: PER_PAGE })
      .then((result) => {
        if (cancelled) return
        if (result.meta.total_pages > 0 && page > result.meta.total_pages) {
          setPage(result.meta.total_pages)
          return
        }
        setProducts(result.data)
        setMeta(result.meta)
        setError(null)
        setLoadedKey(key)
      })
      .catch((err) => {
        if (cancelled) return
        if (err instanceof AuthError && err.status === 401) {
          onUnauthorized()
          return
        }
        setError(
          err instanceof AuthError
            ? err.message
            : 'Não foi possível carregar os produtos.',
        )
        setLoadedKey(key)
      })
    return () => {
      cancelled = true
    }
  }, [search, page, reloadKey, onUnauthorized])

  const reload = useCallback(() => setReloadKey((key) => key + 1), [])

  function handleSaved(product: Product) {
    const wasEditing = form?.product != null
    setForm(null)
    setNotice(
      wasEditing
        ? `Produto "${product.name}" atualizado.`
        : `Produto "${product.name}" criado.`,
    )
    reload()
  }

  async function handleDelete() {
    if (!toDelete) return
    setDeleting(true)
    setDeleteError(null)
    try {
      await deleteProduct(toDelete.id)
      setNotice(`Produto "${toDelete.name}" removido.`)
      setToDelete(null)
      reload()
    } catch (err) {
      if (err instanceof AuthError && err.status === 401) {
        onUnauthorized()
        return
      }
      setDeleteError(
        err instanceof AuthError
          ? err.message
          : 'Não foi possível remover o produto.',
      )
    } finally {
      setDeleting(false)
    }
  }

  const loading = loadedKey !== `${search}|${page}|${reloadKey}`
  const totalPages = meta?.total_pages ?? 0
  const firstItem = meta && meta.total > 0 ? (page - 1) * PER_PAGE + 1 : 0
  const lastItem = meta ? Math.min(page * PER_PAGE, meta.total) : 0
  const initialLoad = loading && products.length === 0

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-navy-900 sm:text-3xl">
            Produtos
          </h1>
          <p className="mt-1 text-slate-500">
            Catálogo de itens disponíveis para compra.
          </p>
        </div>
        <button
          type="button"
          className="btn btn-primary"
          onClick={() => setForm({ product: null })}
        >
          <Plus className="size-4" />
          Novo produto
        </button>
      </div>

      <div className="mt-6 space-y-4">
        {notice && (
          <div className="alert alert-success" role="status">
            <CircleCheck className="mt-0.5 size-4 shrink-0" />
            <span className="flex-1">{notice}</span>
            <button type="button" aria-label="Fechar" onClick={() => setNotice(null)}>
              <X className="size-4" />
            </button>
          </div>
        )}

        {error && (
          <div className="alert alert-error" role="alert">
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <span className="flex-1">{error}</span>
            <button type="button" className="font-semibold underline" onClick={reload}>
              Tentar novamente
            </button>
          </div>
        )}

        <div className="card overflow-hidden">
          <div className="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="relative w-full sm:max-w-sm">
              <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400" />
              <input
                type="search"
                className="input pl-10"
                placeholder="Buscar por nome ou código"
                aria-label="Buscar produtos"
                value={searchInput}
                maxLength={255}
                onChange={(event) => setSearchInput(event.target.value)}
              />
            </div>
            {meta && (
              <span className="text-sm text-slate-500">
                {meta.total} {meta.total === 1 ? 'produto' : 'produtos'}
              </span>
            )}
          </div>

          <div
            className={`overflow-x-auto transition-opacity ${loading && !initialLoad ? 'opacity-60' : ''}`}
            aria-busy={loading}
          >
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-xs font-semibold tracking-wider text-slate-500 uppercase">
                <tr>
                  <th className="px-4 py-3 pl-6">Produto</th>
                  <th className="px-4 py-3">Código interno</th>
                  <th className="hidden px-4 py-3 md:table-cell">Canais</th>
                  <th className="px-4 py-3 pr-6 text-right">
                    <span className="sr-only">Ações</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {initialLoad &&
                  Array.from({ length: 5 }, (_, index) => (
                    <tr key={index} aria-hidden="true">
                      <td className="px-4 py-3 pl-6">
                        <div className="flex items-center gap-3">
                          <div className="size-12 animate-pulse rounded-lg bg-slate-100" />
                          <div className="h-4 w-40 animate-pulse rounded bg-slate-100" />
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="h-4 w-20 animate-pulse rounded bg-slate-100" />
                      </td>
                      <td className="hidden px-4 py-3 md:table-cell">
                        <div className="h-4 w-24 animate-pulse rounded bg-slate-100" />
                      </td>
                      <td />
                    </tr>
                  ))}

                {products.map((product) => (
                  <tr key={product.id} className="transition hover:bg-slate-50/70">
                    <td className="px-4 py-3 pl-6">
                      <div className="flex items-center gap-3">
                        {product.image_url ? (
                          <img
                            className="size-12 shrink-0 rounded-lg border border-slate-200 bg-slate-100 object-cover"
                            src={product.image_url}
                            alt=""
                            loading="lazy"
                          />
                        ) : (
                          <div className="flex size-12 shrink-0 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 text-slate-400">
                            <ImageOff className="size-5" />
                          </div>
                        )}
                        <span className="font-semibold text-slate-900">
                          {product.name}
                        </span>
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      {product.internal_code ? (
                        <span className="rounded-md bg-slate-100 px-2 py-1 font-mono text-xs text-slate-700">
                          {product.internal_code}
                        </span>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="hidden px-4 py-3 md:table-cell">
                      {product.external_ids.length > 0 ? (
                        <div className="flex flex-wrap gap-1.5">
                          {product.external_ids.map((item) => (
                            <span
                              key={`${item.sales_channel}-${item.external_id}`}
                              className="rounded-full bg-navy-50 px-2.5 py-0.5 text-xs font-medium text-navy-700 ring-1 ring-navy-100 ring-inset"
                              title={`Código no canal ${item.sales_channel}`}
                            >
                              {item.sales_channel}: {item.external_id}
                            </span>
                          ))}
                        </div>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="px-4 py-3 pr-6">
                      <div className="flex justify-end gap-1">
                        <button
                          type="button"
                          className="icon-btn"
                          title="Editar"
                          aria-label={`Editar ${product.name}`}
                          onClick={() => setForm({ product })}
                        >
                          <Pencil className="size-4" />
                        </button>
                        <button
                          type="button"
                          className="icon-btn icon-btn-danger"
                          title="Excluir"
                          aria-label={`Excluir ${product.name}`}
                          onClick={() => {
                            setDeleteError(null)
                            setToDelete(product)
                          }}
                        >
                          <Trash2 className="size-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {!loading && !error && products.length === 0 && (
              <div className="flex flex-col items-center px-6 py-16 text-center">
                <div className="flex size-14 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                  <PackageOpen className="size-7" />
                </div>
                <p className="mt-4 font-semibold text-slate-900">
                  {search ? 'Nenhum produto encontrado' : 'Nenhum produto cadastrado'}
                </p>
                <p className="mt-1 text-sm text-slate-500">
                  {search
                    ? 'Tente buscar por outro nome ou código.'
                    : 'Cadastre o primeiro produto do catálogo.'}
                </p>
              </div>
            )}
          </div>

          {meta && meta.total > 0 && (
            <div className="flex flex-col items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm text-slate-500 sm:flex-row sm:px-6">
              <span>
                Mostrando <strong className="text-slate-700">{firstItem}</strong>–
                <strong className="text-slate-700">{lastItem}</strong> de{' '}
                <strong className="text-slate-700">{meta.total}</strong>
              </span>
              {totalPages > 1 && (
                <nav className="flex items-center gap-2" aria-label="Paginação">
                  <button
                    type="button"
                    className="btn btn-secondary btn-sm"
                    disabled={page <= 1 || loading}
                    onClick={() => setPage(page - 1)}
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
                    disabled={page >= totalPages || loading}
                    onClick={() => setPage(page + 1)}
                  >
                    Próxima
                    <ChevronRight className="size-4" />
                  </button>
                </nav>
              )}
            </div>
          )}
        </div>
      </div>

      {form && (
        <ProductFormModal
          product={form.product}
          onClose={(changed) => {
            setForm(null)
            if (changed) reload()
          }}
          onSaved={handleSaved}
          onUnauthorized={onUnauthorized}
        />
      )}

      {toDelete && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-navy-950/60 p-4 backdrop-blur-sm"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !deleting) setToDelete(null)
          }}
        >
          <div
            className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="delete-title"
          >
            <div className="flex size-11 items-center justify-center rounded-full bg-red-100 text-red-600">
              <Trash2 className="size-5" />
            </div>
            <h2 id="delete-title" className="mt-4 text-lg font-semibold text-slate-900">
              Excluir produto
            </h2>
            <p className="mt-2 text-sm text-slate-500">
              Deseja realmente excluir{' '}
              <strong className="text-slate-800">{toDelete.name}</strong>? Essa ação
              não pode ser desfeita.
            </p>
            {deleteError && (
              <div className="alert alert-error mt-4" role="alert">
                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                {deleteError}
              </div>
            )}
            <div className="mt-6 flex justify-end gap-3">
              <button
                type="button"
                className="btn btn-secondary"
                disabled={deleting}
                onClick={() => setToDelete(null)}
              >
                Cancelar
              </button>
              <button
                type="button"
                className="btn btn-danger"
                disabled={deleting}
                onClick={handleDelete}
              >
                {deleting ? 'Excluindo...' : 'Excluir'}
              </button>
            </div>
          </div>
        </div>
      )}
    </section>
  )
}

export default ProductsPage
