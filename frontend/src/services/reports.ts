import { request } from './auth'

export type Money = { total: number; average: number }

export type Summary = {
  purchases_count: number
  amount: Money
  discount: Money
  products: Money & { units_sold: number }
}

export type TimelineInterval = 'day' | 'week' | 'month'

export type TimelinePoint = {
  period: string
  start: string
  end: string
  purchases_count: number
  amount: number
  discount: number
}

export type Timeline = {
  interval: TimelineInterval
  data: TimelinePoint[]
}

export type RankingMeta = {
  page: number
  limit: number
  total: number
  total_pages: number
}

export type CustomerRow = {
  customer: {
    id: number
    type: string
    name: string
    trade_name: string | null
    email: string
    document_type: 'CPF' | 'CNPJ'
    document: string
  }
  purchases_count: number
  total: number
  average: number
}

export type ProductRow = {
  product: {
    id: number
    name: string
    internal_code: string | null
  }
  quantity_sold: number
  purchases_count: number
  total: number
  average: number
}

export type Ranking<T> = { data: T[]; meta: RankingMeta }

export type SortOrder = 'asc' | 'desc'
export type CustomerSort = 'total' | 'average' | 'count'
export type ProductSort = 'total' | 'average' | 'quantity'

type RankingParams<S extends string> = {
  page?: number
  limit?: number
  sort?: S
  order?: SortOrder
}

function query(params: Record<string, string | number | undefined>) {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) search.set(key, String(value))
  }
  return search.toString()
}

export function getSummary(): Promise<Summary> {
  return request<Summary>('/api/reports/summary')
}

export function getTimeline(interval: TimelineInterval): Promise<Timeline> {
  return request<Timeline>(`/api/reports/timeline?${query({ interval })}`)
}

export function getCustomerRanking(
  params: RankingParams<CustomerSort> = {},
): Promise<Ranking<CustomerRow>> {
  return request(`/api/reports/customers?${query(params)}`)
}

export function getProductRanking(
  params: RankingParams<ProductSort> = {},
): Promise<Ranking<ProductRow>> {
  return request(`/api/reports/products?${query(params)}`)
}
