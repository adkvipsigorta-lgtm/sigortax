import type { User, UserRole } from '~/types'

const TOKEN_KEY = 'auth_token'
const SOCKET_TOKEN_KEY = 'socket_token'
const API_BASE = '/api'

const useAuthState = () => {
  const user = useState<User | null>('auth-user', () => null)
  const token = useState<string | null>('auth-token', () => {
    if (import.meta.client) {
      return localStorage.getItem(TOKEN_KEY)
    }
    return null
  })
  const socketToken = useState<string | null>('socket-token', () => {
    if (import.meta.client) {
      return localStorage.getItem(SOCKET_TOKEN_KEY)
    }
    return null
  })
  const isLoggedIn = computed(() => !!user.value && !!token.value)
  const initialized = useState('auth-initialized', () => false)
  return { user, token, socketToken, isLoggedIn, initialized }
}

export function useAuth() {
  const { user, token, socketToken, isLoggedIn, initialized } = useAuthState()
  const router = useRouter()

  function setToken(value: string | null) {
    token.value = value
    if (import.meta.client) {
      if (value) {
        localStorage.setItem(TOKEN_KEY, value)
      } else {
        localStorage.removeItem(TOKEN_KEY)
      }
    }
  }

  function setSocketToken(value: string | null) {
    socketToken.value = value
    if (import.meta.client) {
      if (value) {
        localStorage.setItem(SOCKET_TOKEN_KEY, value)
      } else {
        localStorage.removeItem(SOCKET_TOKEN_KEY)
      }
    }
  }

  async function login(email: string, password: string, rememberMe = false) {
    try {
      const response = await fetch(`${API_BASE}/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password, rememberMe })
      })

      const data = await response.json()

      if (!response.ok || !data.success) {
        return { success: false, error: data.message || 'Geçersiz e-posta veya şifre' }
      }

      // 2FA required
      if (data.data.requiresTwoFactor) {
        return { success: false, requiresTwoFactor: true, userId: data.data.userId }
      }

      setToken(data.data.token)
      setSocketToken(data.data.socketToken)
      user.value = data.data.user
      initialized.value = true
      return { success: true }
    } catch {
      return { success: false, error: 'Sunucuya baglanilamadi' }
    }
  }

  async function verifyTwoFactor(userId: number, code: string) {
    try {
      const response = await fetch(`${API_BASE}/auth/2fa/verify`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ userId, code })
      })

      const data = await response.json()

      if (!response.ok || !data.success) {
        return { success: false, error: data.message || 'Geçersiz doğrulama kodu' }
      }

      setToken(data.data.token)
      setSocketToken(data.data.socketToken)
      user.value = data.data.user
      initialized.value = true
      return { success: true }
    } catch {
      return { success: false, error: 'Sunucuya baglanilamadi' }
    }
  }

  function logout() {
    user.value = null
    setToken(null)
    setSocketToken(null)
    initialized.value = false
    router.push('/login')
  }

  async function fetchMe() {
    if (!token.value) {
      initialized.value = true
      return
    }
    try {
      const response = await fetch(`${API_BASE}/auth/me`, {
        headers: { Authorization: `Bearer ${token.value}` }
      })
      const data = await response.json()
      if (data.success && data.data) {
        const { socketToken: freshSocketToken, ...userData } = data.data
        user.value = userData
        // /me her cagrildiginda taze socketToken doner — expired olani yenile
        if (freshSocketToken) setSocketToken(freshSocketToken)
      } else {
        setToken(null)
        setSocketToken(null)
        user.value = null
      }
    } catch {
      setToken(null)
      setSocketToken(null)
      user.value = null
    } finally {
      initialized.value = true
    }
  }

  function hasRole(role: UserRole) {
    return user.value?.role === role
  }

  function hasAnyRole(...roles: UserRole[]) {
    return roles.includes(user.value?.role as UserRole)
  }

  function updateProfile(data: Partial<User>) {
    if (user.value) {
      user.value = { ...user.value, ...data }
    }
  }

  return {
    user,
    token,
    socketToken,
    isLoggedIn,
    initialized,
    login,
    verifyTwoFactor,
    logout,
    fetchMe,
    hasRole,
    hasAnyRole,
    updateProfile
  }
}
