import { Building2, Mail, StickyNote, User, X } from 'lucide-react'
import { useEffect } from 'react'
import {
  formatDateTime,
  formatDocument,
  formatMoney,
  formatNumber,
} from '../../lib/format'
import type { Purchase } from '../../services/purchases'
import ChannelBadge from './ChannelBadge'

type Props = {
  purchase: Purchase
  onClose: () => void
}

function PurchaseDetailModal({ purchase, onClose }: Props) {
  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [onClose])

  const { customer } = purchase
  const isCompany = customer.document_type === 'CNPJ'
  const CustomerIcon = isCompany ? Building2 : User
  const subtotal = purchase.amount + purchase.discount

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-navy-950/60 p-4 backdrop-blur-sm"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        className="w-full max-w-2xl rounded-2xl bg-white shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="purchase-title"
      >
        <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <h2 id="purchase-title" className="text-lg font-semibold text-slate-900">
                Compra{' '}
                <span className="font-mono text-base">{purchase.reference_code}</span>
              </h2>
              <ChannelBadge channel={purchase.sales_channel} />
            </div>
            <p className="mt-0.5 text-sm text-slate-500">
              {formatDateTime(purchase.purchased_at)}
            </p>
          </div>
          <button
            type="button"
            className="icon-btn"
            aria-label="Fechar"
            onClick={onClose}
          >
            <X className="size-5" />
          </button>
        </div>

        <div className="space-y-6 px-6 py-5">
          <div className="flex items-start gap-3 rounded-xl bg-slate-50 p-4">
            <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-navy-100 text-navy-700">
              <CustomerIcon className="size-5" />
            </div>
            <div className="min-w-0 text-sm">
              <p className="font-semibold text-slate-900">{customer.name}</p>
              {customer.trade_name && (
                <p className="text-slate-500">{customer.trade_name}</p>
              )}
              <p className="mt-1 flex items-center gap-1.5 text-slate-500">
                <Mail className="size-3.5 shrink-0" />
                <span className="truncate">{customer.email}</span>
              </p>
              <p className="mt-0.5 text-slate-500">
                {customer.document_type}{' '}
                <span className="font-mono text-xs">
                  {formatDocument(customer.document)}
                </span>
              </p>
            </div>
          </div>

          <div>
            <h3 className="mb-2 text-sm font-semibold text-slate-900">
              Itens ({purchase.items.length})
            </h3>
            <div className="overflow-x-auto rounded-xl border border-slate-200">
              <table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs font-semibold tracking-wider text-slate-500 uppercase">
                  <tr>
                    <th className="px-4 py-2.5">Produto</th>
                    <th className="px-3 py-2.5 text-right">Qtd.</th>
                    <th className="hidden px-3 py-2.5 text-right sm:table-cell">Unitário</th>
                    <th className="hidden px-3 py-2.5 text-right sm:table-cell">Desconto</th>
                    <th className="px-4 py-2.5 text-right">Total</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {purchase.items.map((item, index) => (
                    <tr key={`${item.product_id}-${index}`}>
                      <td className="px-4 py-2.5 font-medium text-slate-900">
                        {item.name}
                      </td>
                      <td className="px-3 py-2.5 text-right tabular-nums">
                        {formatNumber(item.quantity)}
                      </td>
                      <td className="hidden px-3 py-2.5 text-right tabular-nums sm:table-cell">
                        {formatMoney(item.unit_price)}
                      </td>
                      <td className="hidden px-3 py-2.5 text-right whitespace-nowrap tabular-nums sm:table-cell">
                        {item.discount > 0 ? `− ${formatMoney(item.discount)}` : '—'}
                      </td>
                      <td className="px-4 py-2.5 text-right font-semibold text-slate-900 tabular-nums">
                        {formatMoney(item.total_price)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <dl className="ml-auto max-w-xs space-y-1.5 text-sm">
            <div className="flex justify-between">
              <dt className="text-slate-500">Subtotal</dt>
              <dd className="tabular-nums">{formatMoney(subtotal)}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-slate-500">Descontos</dt>
              <dd className="tabular-nums">
                {purchase.discount > 0 ? `− ${formatMoney(purchase.discount)}` : formatMoney(0)}
              </dd>
            </div>
            <div className="flex justify-between border-t border-slate-200 pt-2 text-base font-bold text-navy-900">
              <dt>Total</dt>
              <dd className="tabular-nums">{formatMoney(purchase.amount)}</dd>
            </div>
          </dl>

          {purchase.notes && (
            <div className="alert border-amber-200 bg-amber-50 text-amber-800">
              <StickyNote className="mt-0.5 size-4 shrink-0" />
              <p>{purchase.notes}</p>
            </div>
          )}
        </div>

        <div className="flex justify-end rounded-b-2xl border-t border-slate-200 bg-slate-50 px-6 py-4">
          <button type="button" className="btn btn-secondary" onClick={onClose}>
            Fechar
          </button>
        </div>
      </div>
    </div>
  )
}

export default PurchaseDetailModal
