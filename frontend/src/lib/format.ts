const brl = new Intl.NumberFormat('pt-BR', {
  style: 'currency',
  currency: 'BRL',
})

const brlCompact = new Intl.NumberFormat('pt-BR', {
  style: 'currency',
  currency: 'BRL',
  notation: 'compact',
  maximumFractionDigits: 1,
})

const integer = new Intl.NumberFormat('pt-BR')

/** Os valores da API vêm em centavos */
export function formatMoney(cents: number): string {
  return brl.format(cents / 100)
}

export function formatMoneyCompact(cents: number): string {
  return brlCompact.format(cents / 100)
}

export function formatNumber(value: number): string {
  return integer.format(value)
}

const MONTHS = [
  'jan',
  'fev',
  'mar',
  'abr',
  'mai',
  'jun',
  'jul',
  'ago',
  'set',
  'out',
  'nov',
  'dez',
]

/** "2026-11-21" → "21/11" */
export function formatDayMonth(isoDate: string): string {
  const [, month, day] = isoDate.split('-')
  return `${day}/${month}`
}

/** "2026-11-21" → "21/11/2026" */
export function formatDate(isoDate: string): string {
  const [year, month, day] = isoDate.split('-')
  return `${day}/${month}/${year}`
}

/** "2026-11" → "nov/26" */
export function formatMonth(period: string): string {
  const [year, month] = period.split('-')
  return `${MONTHS[Number(month) - 1]}/${year.slice(2)}`
}

/** Mascara CPF (000.000.000-00) ou CNPJ numérico (00.000.000/0000-00); outros formatos passam como vieram */
export function formatDocument(document: string): string {
  const digits = document.replace(/\D/g, '')
  if (digits.length === 11 && digits === document)
    return digits.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')
  if (digits.length === 14 && digits === document)
    return digits.replace(
      /(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/,
      '$1.$2.$3/$4-$5',
    )
  return document
}
