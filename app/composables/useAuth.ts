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
  const sessionExpiresAt = useState<string | null>('session-expires-at', () => null)
  return { user, token, socketToken, isLoggedIn, initialized, sessionExpiresAt }
}

// Session expiry timer (singleton)
let expiryTimerId: ReturnType<typeof setTimeout> | null = null
let warningTimerId: ReturnType<typeof setTimeout> | null = null

export function useAuth() {
  const { user, token, socketToken, isLoggedIn, initialized, sessionExpiresAt } = useAuthState()
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

  function setupSessionTimer(expiresAt: string) {
    if (!import.meta.client) return

    sessionExpiresAt.value = expiresAt

    // Onceki timer'lari temizle
    if (expiryTimerId) { clearTimeout(expiryTimerId); expiryTimerId = null }
    if (warningTimerId) { clearTimeout(warningTimerId); warningTimerId = null }

    const expiresMs = new Date(expiresAt).getTime()
    const now = Date.now()
    const remainingMs = expiresMs - now

    if (remainingMs <= 0) {
      // Zaten suresi dolmus
      logout('forced_logout')
      return
    }

    // 5 dakika oncesi uyari
    const warningMs = remainingMs - (5 * 60 * 1000)
    if (warningMs > 0) {
      warningTimerId = setTimeout(() => {
        const toast = useToast()
        toast.add({
          title: 'Oturum uyarisi',
          description: 'Oturumunuz guvenlik nedeniyle 5 dakika sonra sona erecek. Acik islemlerinizi kaydediniz.',
          color: 'warning',
          duration: 60000
        })
      }, warningMs)
    }

    // Oturum sona erme
    expiryTimerId = setTimeout(() => {
      logout('forced_logout')
    }, remainingMs)
  }

  function clearSessionTimer() {
    if (expiryTimerId) { clearTimeout(expiryTimerId); expiryTimerId = null }
    if (warningTimerId) { clearTimeout(warningTimerId); warningTimerId = null }
    sessionExpiresAt.value = null
  }

  async function login(email: string, password: string) {
    try {
      const response = await fetch(`${API_BASE}/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password })
      })

      const data = await response.json()

      if (!response.ok || !data.success) {
        return { success: false, error: data.message || 'Gecersiz e-posta veya sifre' }
      }

      // TOTP her zaman zorunlu — challenge token doner
      if (data.data.requiresTwoFactor) {
        return {
          success: false,
          requiresTwoFactor: true,
          requiresSetup: data.data.requiresSetup || false,
          challengeToken: data.data.challengeToken
        }
      }

      // Bu noktaya artik gelmemeli (TOTP zorunlu)
      return { success: false, error: 'Beklenmeyen sunucu yaniti' }
    } catch {
      return { success: false, error: 'Sunucuya baglanilamadi' }
    }
  }

  async function verifyTwoFactor(challengeToken: string, code: string) {
    try {
      const response = await fetch(`${API_BASE}/auth/2fa/verify`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ challengeToken, code })
      })

      const data = await response.json()

      if (!response.ok || !data.success) {
        return { success: false, error: data.message || 'Gecersiz dogrulama kodu' }
      }

      setToken(data.data.token)
      setSocketToken(data.data.socketToken)
      user.value = data.data.user
      initialized.value = true

      // Session timer baslat
      if (data.data.sessionExpiresAt) {
        setupSessionTimer(data.data.sessionExpiresAt)
      }

      return { success: true }
    } catch {
      return { success: false, error: 'Sunucuya baglanilamadi' }
    }
  }

  async function setupTwoFactor(challengeToken: string) {
    try {
      const response = await fetch(`${API_BASE}/auth/2fa/setup-login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ challengeToken })
      })
      const data = await response.json()
      if (!response.ok || !data.success) {
        return { success: false, error: data.message || 'Kurulum baslatilamadi' }
      }
      return { success: true, secret: data.data.secret, otpauthUrl: data.data.otpauthUrl }
    } catch {
      return { success: false, error: 'Sunucuya baglanilamadi' }
    }
  }

  async function enableTwoFactor(challengeToken: string, code: string) {
    try {
      const response = await fetch(`${API_BASE}/auth/2fa/enable-login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ challengeToken, code })
      })
      const data = await response.json()
      if (!response.ok || !data.success) {
        return { success: false, error: data.message || 'Dogrulama basarisiz' }
      }
      return { success: true, recoveryCodes: data.data.recoveryCodes || [] }
    } catch {
      return { success: false, error: 'Sunucuya baglanilamadi' }
    }
  }

  async function confirmSetup(challengeToken: string) {
    try {
      const response = await fetch(`${API_BASE}/auth/2fa/confirm-setup`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ challengeToken })
      })
      const data = await response.json()
      if (!response.ok || !data.success) {
        return { success: false, error: data.message || 'Kurulum onaylanamadi' }
      }

      setToken(data.data.token)
      setSocketToken(data.data.socketToken)
      user.value = data.data.user
      initialized.value = true

      if (data.data.sessionExpiresAt) {
        setupSessionTimer(data.data.sessionExpiresAt)
      }

      return { success: true }
    } catch {
      return { success: false, error: 'Sunucuya baglanilamadi' }
    }
  }

  function logout(reason?: string) {
    // Server-side revoke
    if (token.value) {
      fetch(`${API_BASE}/auth/logout`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token.value}`
        }
      }).catch(() => {})
    }

    user.value = null
    setToken(null)
    setSocketToken(null)
    initialized.value = false
    clearSessionTimer()

    // Reason'a gore mesaj
    if (import.meta.client && reason) {
      const toast = useToast()
      const messages: Record<string, string> = {
        forced_logout: 'Oturum sureniz sona erdi. Devam etmek icin tekrar giris yapiniz.',
        session_revoked: 'Oturumunuz baska bir cihazdan sonlandirildi.',
        session_expired: 'Oturum sureniz doldu.',
        inactivity_timeout: 'Uzun suredir islem yapmadiginiz icin oturumunuz sona erdi.'
      }
      if (messages[reason]) {
        toast.add({ title: messages[reason], color: 'warning', duration: 8000 })
      }
    }

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

      if (response.status === 401) {
        const reason = data.reason || 'session_expired'
        logout(reason)
        return
      }

      if (data.success && data.data) {
        const { socketToken: freshSocketToken, sessionExpiresAt: expiresAt, ...userData } = data.data
        user.value = userData
        if (freshSocketToken) setSocketToken(freshSocketToken)
        if (expiresAt) setupSessionTimer(expiresAt)
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
    sessionExpiresAt,
    login,
    verifyTwoFactor,
    setupTwoFactor,
    enableTwoFactor,
    confirmSetup,
    logout,
    fetchMe,
    hasRole,
    hasAnyRole,
    updateProfile
  }
}
