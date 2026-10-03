const API_BASE = '/api'

interface ApiOptions {
  method?: string
  body?: any
  query?: Record<string, any>
}

export function useApi() {
  const { token, logout } = useAuth()

  async function api<T = any>(endpoint: string, options: ApiOptions = {}): Promise<T> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json'
    }

    if (token.value) {
      headers.Authorization = `Bearer ${token.value}`
    }

    let url = `${API_BASE}/${endpoint.replace(/^\//, '')}`
    if (options.query) {
      const params = new URLSearchParams()
      for (const [key, value] of Object.entries(options.query)) {
        if (value !== undefined && value !== null && value !== '') {
          params.append(key, String(value))
        }
      }
      const qs = params.toString()
      if (qs) url += `?${qs}`
    }

    const fetchOptions: RequestInit = {
      method: options.method || 'GET',
      headers
    }

    if (options.body && options.method !== 'GET') {
      fetchOptions.body = JSON.stringify(options.body)
    }

    const response = await fetch(url, fetchOptions)

    if (response.status === 401) {
      logout()
      throw new Error('Oturum süresi doldu')
    }

    const data = await response.json()

    if (!response.ok) {
      throw new Error(data.message || 'Bir hata olustu')
    }

    return data
  }

  function get<T = any>(endpoint: string, query?: Record<string, any>) {
    return api<T>(endpoint, { method: 'GET', query })
  }

  function post<T = any>(endpoint: string, body?: any) {
    return api<T>(endpoint, { method: 'POST', body })
  }

  function put<T = any>(endpoint: string, body?: any) {
    return api<T>(endpoint, { method: 'PUT', body })
  }

  function del<T = any>(endpoint: string) {
    return api<T>(endpoint, { method: 'DELETE' })
  }

  return { api, get, post, put, del }
}
