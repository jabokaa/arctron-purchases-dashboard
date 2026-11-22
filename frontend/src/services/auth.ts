export type User = {
  id: number
  name: string
  email: string
  roles: string[]
}

type LoginResponse = {
  token: string
  token_type: 'Bearer'
  expires_at: string
  user: User
}

type ApiError = {
  title: string
  status: number
  detail: string
  violations?: { field: string; message: string }[]
}

const TOKEN_KEY = 'auth_token'

export class AuthError extends Error {
  readonly status: number
  readonly violations: ApiError['violations']

  constructor(error: ApiError) {
    super(error.detail)
    this.status = error.status
    this.violations = error.violations
  }
}

export async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token = getToken()
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  // Com FormData (upload) o navegador define o Content-Type sozinho, com o boundary
  if (typeof init.body === 'string') headers.set('Content-Type', 'application/json')
  if (token) headers.set('Authorization', `Bearer ${token}`)

  let response: Response
  try {
    response = await fetch(path, { ...init, headers })
  } catch {
    throw new AuthError({
      title: 'Erro de conexão',
      status: 0,
      detail: 'Não foi possível conectar ao servidor. Tente novamente.',
    })
  }

  if (response.status === 204) return undefined as T

  const body = await response.json().catch(() => null)
  if (!response.ok) {
    throw new AuthError(
      body ?? {
        title: 'Erro',
        status: response.status,
        detail: 'Ocorreu um erro inesperado. Tente novamente.',
      },
    )
  }

  return body as T
}

/**
 * "Lembrar de mim" guarda o token no localStorage (sobrevive a fechar o navegador);
 * sem ele, fica no sessionStorage (some ao fechar a aba).
 */
function storage(remember: boolean): Storage {
  return remember ? localStorage : sessionStorage
}

export function getToken(): string | null {
  try {
    return sessionStorage.getItem(TOKEN_KEY) ?? localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

function clearToken() {
  try {
    sessionStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(TOKEN_KEY)
  } catch {
    // storage indisponível (ex.: navegação privada bloqueada): nada a limpar
  }
}

export async function login(
  email: string,
  password: string,
  remember: boolean,
): Promise<User> {
  clearToken()
  const data = await request<LoginResponse>('/api/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  })
  try {
    storage(remember).setItem(TOKEN_KEY, data.token)
  } catch {
    // sem storage o login vale só enquanto a página estiver aberta
  }
  return data.user
}

/** Usuário do token salvo, ou null se não houver token ou ele tiver expirado */
export async function currentUser(): Promise<User | null> {
  if (!getToken()) return null
  try {
    return await request<User>('/api/auth/me')
  } catch (error) {
    if (error instanceof AuthError && error.status === 401) clearToken()
    return null
  }
}

export async function logout(): Promise<void> {
  try {
    await request<void>('/api/auth/logout', { method: 'POST' })
  } finally {
    clearToken()
  }
}
