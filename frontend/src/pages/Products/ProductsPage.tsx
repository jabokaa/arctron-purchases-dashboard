import { useState } from 'react'
import ProductCatalog from './ProductCatalog'
import ProductRanking from './ProductRanking'

export type ProductsTab = 'catalog' | 'ranking'

const TABS: { id: ProductsTab; label: string }[] = [
  { id: 'catalog', label: 'Catálogo' },
  { id: 'ranking', label: 'Mais vendidos' },
]

type Props = {
  initialTab?: ProductsTab
  onUnauthorized: () => void
}

function ProductsPage({ initialTab = 'catalog', onUnauthorized }: Props) {
  const [tab, setTab] = useState<ProductsTab>(initialTab)

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
      <h1 className="text-2xl font-bold tracking-tight text-navy-900 sm:text-3xl">
        Produtos
      </h1>
      <p className="mt-1 text-slate-500">
        {tab === 'catalog'
          ? 'Catálogo de itens disponíveis para compra.'
          : 'Ranking dos produtos mais vendidos. Clique nos títulos para ordenar.'}
      </p>

      <div
        className="mt-6 inline-flex rounded-xl bg-slate-200/60 p-1"
        role="tablist"
        aria-label="Seções de produtos"
      >
        {TABS.map(({ id, label }) => (
          <button
            key={id}
            type="button"
            role="tab"
            aria-selected={tab === id}
            onClick={() => setTab(id)}
            className={`rounded-lg px-4 py-1.5 text-sm font-semibold transition ${
              tab === id
                ? 'bg-white text-navy-900 shadow-sm'
                : 'text-slate-500 hover:text-slate-700'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      <div className="mt-6">
        {tab === 'catalog' ? (
          <ProductCatalog onUnauthorized={onUnauthorized} />
        ) : (
          <ProductRanking onUnauthorized={onUnauthorized} />
        )}
      </div>
    </section>
  )
}

export default ProductsPage
