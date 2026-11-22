import { useCallback, useEffect, useState } from 'react'
import { AuthError } from '../../services/auth'
import {
  deleteProduct,
  listProducts,
  type Product,
  type ProductListMeta,
} from '../../services/products'
import ProductFormModal from './ProductFormModal'
import './ProductsPage.css'

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

  return (
    <section className="products">
      <div className="products__head">
        <div>
          <h1>Produtos</h1>
          <p>Catálogo de itens disponíveis para compra.</p>
        </div>
        <button
          type="button"
          className="btn btn--primary"
          onClick={() => setForm({ product: null })}
        >
          + Novo produto
        </button>
      </div>

      <div className="products__toolbar">
        <input
          type="search"
          placeholder="Buscar por nome ou código interno"
          aria-label="Buscar produtos"
          value={searchInput}
          maxLength={255}
          onChange={(event) => setSearchInput(event.target.value)}
        />
        {meta && (
          <span className="products__count">
            {meta.total} {meta.total === 1 ? 'produto' : 'produtos'}
          </span>
        )}
      </div>

      {notice && (
        <div className="alert alert--success" role="status">
          <span>{notice}</span>
          <button type="button" aria-label="Fechar" onClick={() => setNotice(null)}>
            ×
          </button>
        </div>
      )}

      {error && (
        <div className="alert" role="alert">
          <span>{error}</span>
          <button type="button" onClick={reload}>
            Tentar novamente
          </button>
        </div>
      )}

      <div className="products__table-wrap" aria-busy={loading}>
        <table className="products__table">
          <thead>
            <tr>
              <th>Imagem</th>
              <th>Nome</th>
              <th>Código interno</th>
              <th>Canais</th>
              <th aria-label="Ações" />
            </tr>
          </thead>
          <tbody>
            {products.map((product) => (
              <tr key={product.id}>
                <td>
                  {product.image_url ? (
                    <img
                      className="products__thumb"
                      src={product.image_url}
                      alt={product.name}
                      loading="lazy"
                    />
                  ) : (
                    <div className="products__thumb products__thumb--empty" />
                  )}
                </td>
                <td className="products__name">{product.name}</td>
                <td>{product.internal_code ?? '—'}</td>
                <td>
                  {product.external_ids.length > 0
                    ? product.external_ids.map((item) => (
                        <span
                          key={`${item.sales_channel}-${item.external_id}`}
                          className="tag"
                          title={`Código no canal ${item.sales_channel}`}
                        >
                          {item.sales_channel}: {item.external_id}
                        </span>
                      ))
                    : '—'}
                </td>
                <td className="products__actions">
                  <button
                    type="button"
                    className="btn btn--small"
                    onClick={() => setForm({ product })}
                  >
                    Editar
                  </button>
                  <button
                    type="button"
                    className="btn btn--small btn--danger"
                    onClick={() => {
                      setDeleteError(null)
                      setToDelete(product)
                    }}
                  >
                    Excluir
                  </button>
                </td>
              </tr>
            ))}
            {!loading && !error && products.length === 0 && (
              <tr>
                <td colSpan={5} className="products__empty">
                  {search
                    ? 'Nenhum produto encontrado para essa busca.'
                    : 'Nenhum produto cadastrado ainda.'}
                </td>
              </tr>
            )}
            {loading && products.length === 0 && (
              <tr>
                <td colSpan={5} className="products__empty">
                  Carregando...
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {totalPages > 1 && (
        <nav className="products__pager" aria-label="Paginação">
          <button
            type="button"
            className="btn btn--small"
            disabled={page <= 1 || loading}
            onClick={() => setPage(page - 1)}
          >
            Anterior
          </button>
          <span>
            Página {page} de {totalPages}
          </span>
          <button
            type="button"
            className="btn btn--small"
            disabled={page >= totalPages || loading}
            onClick={() => setPage(page + 1)}
          >
            Próxima
          </button>
        </nav>
      )}

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
          className="modal"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !deleting)
              setToDelete(null)
          }}
        >
          <div
            className="modal__card modal__card--narrow"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="delete-title"
          >
            <h2 id="delete-title">Excluir produto</h2>
            <p>
              Deseja realmente excluir <strong>{toDelete.name}</strong>? Essa
              ação não pode ser desfeita.
            </p>
            {deleteError && (
              <div className="alert" role="alert">
                {deleteError}
              </div>
            )}
            <div className="modal__actions">
              <button
                type="button"
                className="btn btn--ghost"
                disabled={deleting}
                onClick={() => setToDelete(null)}
              >
                Cancelar
              </button>
              <button
                type="button"
                className="btn btn--danger-solid"
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
