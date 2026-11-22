import { describe, expect, it } from 'vitest'
import {
  formatDate,
  formatDateTime,
  formatDayMonth,
  formatDocument,
  formatMoney,
  formatMonth,
} from './format'

// O Intl usa espaço não separável entre "R$" e o valor
const normalize = (value: string) => value.replace(/ /g, ' ')

describe('formatMoney', () => {
  it('converte centavos para reais no padrão brasileiro', () => {
    expect(normalize(formatMoney(25000))).toBe('R$ 250,00')
    expect(normalize(formatMoney(123456789))).toBe('R$ 1.234.567,89')
    expect(normalize(formatMoney(0))).toBe('R$ 0,00')
  })
})

describe('datas', () => {
  it('formata datas ISO sem depender do fuso', () => {
    expect(formatDate('2026-11-21')).toBe('21/11/2026')
    expect(formatDayMonth('2026-11-21')).toBe('21/11')
    expect(formatMonth('2026-11')).toBe('nov/26')
    expect(formatMonth('2026-01')).toBe('jan/26')
  })

  it('mostra data e hora no fuso do navegador', () => {
    // 14:30 UTC = 11:30 em São Paulo (fuso fixado no vite.config.ts)
    expect(formatDateTime('2026-11-21T14:30:00+00:00')).toBe('21/11/2026 11:30')
  })
})

describe('formatDocument', () => {
  it('mascara CPF e CNPJ numéricos', () => {
    expect(formatDocument('52998224725')).toBe('529.982.247-25')
    expect(formatDocument('11222333000181')).toBe('11.222.333/0001-81')
  })

  it('mantém documentos em outros formatos como vieram', () => {
    expect(formatDocument('529.982.247-25')).toBe('529.982.247-25')
    expect(formatDocument('12ABC34501DE35')).toBe('12ABC34501DE35')
  })
})
