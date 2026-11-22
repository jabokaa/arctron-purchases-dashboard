import { request } from './auth'

export type ProductExternalId = {
  sales_channel: string
  external_id: string
}

export type Product = {
  id: number
  name: string
  internal_code: string | null
  image_url: string | null
  external_ids: ProductExternalId[]
  created_at: string
  updated_at: string
}

export type ProductInput = {
  name: string
  internal_code: string | null
}

export type ProductListMeta = {
  page: number
  per_page: number
  total: number
  total_pages: number
}

export type ProductList = {
  data: Product[]
  meta: ProductListMeta
}

export type ProductListParams = {
  search?: string
  page?: number
  perPage?: number
}

export function listProducts({
  search,
  page = 1,
  perPage = 10,
}: ProductListParams = {}): Promise<ProductList> {
  const query = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  })
  if (search?.trim()) query.set('search', search.trim())
  return request<ProductList>(`/api/products?${query}`)
}

export function createProduct(input: ProductInput): Promise<Product> {
  return request<Product>('/api/products', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function updateProduct(id: number, input: ProductInput): Promise<Product> {
  return request<Product>(`/api/products/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}

export function deleteProduct(id: number): Promise<void> {
  return request<void>(`/api/products/${id}`, { method: 'DELETE' })
}

export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
export const IMAGE_MAX_BYTES = 2 * 1024 * 1024

/** Envia (ou troca) a imagem do produto */
export function uploadProductImage(id: number, file: File): Promise<Product> {
  const body = new FormData()
  body.append('image', file)
  return request<Product>(`/api/products/${id}/image`, { method: 'POST', body })
}

export function deleteProductImage(id: number): Promise<Product> {
  return request<Product>(`/api/products/${id}/image`, { method: 'DELETE' })
}
