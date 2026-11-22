import { beforeEach, describe, expect, it, vi } from 'vitest'
import { listPurchases } from './purchases'

const emptyList = { data: [], page: 1, per_page: 20, total: 0, total_pages: 0 }

function mockFetch(response: Response) {
  const fetchMock = vi.fn().mockResolvedValue(response)
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

/** Query string da URL chamada pelo fetch */
function calledQuery(fetchMock: ReturnType<typeof vi.fn>): URLSearchParams {
  const url = fetchMock.mock.calls[0][0] as string
  return new URLSearchParams(url.split('?')[1])
}

describe('listPurchases', () => {
  beforeEach(() => {
    sessionStorage.setItem('auth_token', 'token-de-teste')
  })

  it('envia só os filtros preenchidos, em snake_case', async () => {
    const fetchMock = mockFetch(jsonResponse(emptyList))

    await listPurchases(
      {
        referenceCode: '  PED-1  ',
        customerName: '',
        dateFrom: '2026-11-01',
        amountMin: 0,
        amountMax: 5000,
      },
      2,
      20,
    )

    const query = calledQuery(fetchMock)
    expect(Object.fromEntries(query)).toEqual({
      page: '2',
      per_page: '20',
      reference_code: 'PED-1',
      date_from: '2026-11-01',
      amount_min: '0',
      amount_max: '5000',
    })
    expect(fetchMock.mock.calls[0][0]).toMatch(/^\/api\/purchases\?/)
  })

  it('envia o token do usuário no cabeçalho Authorization', async () => {
    const fetchMock = mockFetch(jsonResponse(emptyList))

    await listPurchases({}, 1, 20)

    const headers = fetchMock.mock.calls[0][1].headers as Headers
    expect(headers.get('Authorization')).toBe('Bearer token-de-teste')
  })

  it('transforma a resposta de erro da API em exceção com status e violações', async () => {
    mockFetch(
      jsonResponse(
        {
          title: 'Dados inválidos',
          status: 422,
          detail: 'O valor mínimo não pode ser maior que o valor máximo.',
          violations: [{ field: 'amount_min', message: 'O valor mínimo não pode ser maior que o valor máximo.' }],
        },
        422,
      ),
    )

    await expect(listPurchases({ amountMin: 10, amountMax: 1 }, 1, 20)).rejects.toMatchObject({
      status: 422,
      message: 'O valor mínimo não pode ser maior que o valor máximo.',
      violations: [{ field: 'amount_min' }],
    })
  })

  it('avisa quando não consegue conectar ao servidor', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    await expect(listPurchases({}, 1, 20)).rejects.toMatchObject({ status: 0 })
  })
})
