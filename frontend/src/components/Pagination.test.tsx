import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import Pagination from './Pagination'

describe('Pagination', () => {
  it('não mostra nada quando não há resultados', () => {
    const { container } = render(
      <Pagination page={1} pageSize={20} total={0} totalPages={0} onPageChange={() => {}} />,
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('mostra o intervalo exibido e esconde os botões com uma página só', () => {
    render(<Pagination page={1} pageSize={20} total={7} totalPages={1} onPageChange={() => {}} />)

    expect(screen.getByText(/Mostrando/)).toHaveTextContent('Mostrando 1–7 de 7')
    expect(screen.queryByRole('navigation', { name: 'Paginação' })).not.toBeInTheDocument()
  })

  it('na última página desabilita "Próxima" e volta com "Anterior"', async () => {
    const onPageChange = vi.fn()
    render(<Pagination page={3} pageSize={20} total={45} totalPages={3} onPageChange={onPageChange} />)

    expect(screen.getByText(/Mostrando/)).toHaveTextContent('Mostrando 41–45 de 45')
    expect(screen.getByRole('button', { name: /Próxima/ })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: /Anterior/ }))

    expect(onPageChange).toHaveBeenCalledWith(2)
  })

  it('desabilita os dois botões enquanto carrega', () => {
    render(<Pagination page={2} pageSize={20} total={45} totalPages={3} disabled onPageChange={() => {}} />)

    expect(screen.getByRole('button', { name: /Anterior/ })).toBeDisabled()
    expect(screen.getByRole('button', { name: /Próxima/ })).toBeDisabled()
  })
})
