import { useCallback, useEffect, useState } from 'react'
import { AuthError } from '../services/auth'

type Result<T> = { key: string; data: T | null; error: string | null }

/**
 * Carrega dados da API sempre que `key` muda (ou `reload()` é chamado).
 * Mantém os dados anteriores na tela enquanto carrega o próximo conjunto.
 * Token expirado (401) chama `onUnauthorized`.
 */
export function useAsync<T>(
  load: () => Promise<T>,
  key: string,
  onUnauthorized: () => void,
) {
  const [reloadKey, setReloadKey] = useState(0)
  const [result, setResult] = useState<Result<T> | null>(null)
  const fullKey = `${key}|${reloadKey}`

  useEffect(() => {
    let cancelled = false
    load()
      .then((data) => {
        if (!cancelled) setResult({ key: fullKey, data, error: null })
      })
      .catch((err) => {
        if (cancelled) return
        if (err instanceof AuthError && err.status === 401) {
          onUnauthorized()
          return
        }
        setResult((previous) => ({
          key: fullKey,
          data: previous?.data ?? null,
          error:
            err instanceof AuthError
              ? err.message
              : 'Não foi possível carregar os dados.',
        }))
      })
    return () => {
      cancelled = true
    }
    // `load` muda a cada render; `fullKey` já representa os parâmetros da requisição
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fullKey, onUnauthorized])

  const reload = useCallback(() => setReloadKey((value) => value + 1), [])

  return {
    data: result?.data ?? null,
    error: result?.key === fullKey ? result.error : null,
    loading: result?.key !== fullKey,
    reload,
  }
}
