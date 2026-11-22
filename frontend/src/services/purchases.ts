import { request } from './auth'

export type PurchaseItem = {
  product_id: number
  name: string
  quantity: number
  unit_price: number
  discount: number
  total_price: number
}

export type PurchaseCustomer = {
  id: number
  type: string
  name: string
  trade_name: string | null
  email: string
  document_type: 'CPF' | 'CNPJ'
  document: string
}

export type Purchase = {
  id: number
  reference_code: string
  sales_channel: string
  amount: number
  discount: number
  purchased_at: string
  notes: string | null
  customer: PurchaseCustomer
  items: PurchaseItem[]
  created_at: string
}

export type PurchaseListMeta = {
  page: number
  per_page: number
  total: number
  total_pages: number
}

export type PurchaseList = {
  data: Purchase[]
  meta: PurchaseListMeta
}

export type PurchaseFilters = {
  referenceCode?: string
  customerName?: string
  dateFrom?: string
  dateTo?: string
  /** Em centavos */
  amountMin?: number
  amountMax?: number
}

export function listPurchases(
  filters: PurchaseFilters,
  page: number,
  perPage: number,
): Promise<PurchaseList> {
  const query = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  })
  const optional: Record<string, string | number | undefined> = {
    reference_code: filters.referenceCode?.trim(),
    customer_name: filters.customerName?.trim(),
    date_from: filters.dateFrom,
    date_to: filters.dateTo,
    amount_min: filters.amountMin,
    amount_max: filters.amountMax,
  }
  for (const [key, value] of Object.entries(optional)) {
    if (value !== undefined && value !== '') query.set(key, String(value))
  }
  return request<PurchaseList>(`/api/purchases?${query}`)
}
