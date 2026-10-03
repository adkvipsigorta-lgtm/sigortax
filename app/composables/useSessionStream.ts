/**
 * Socket.IO client — tek 'evt' event'i uzerinden tum realtime iletişim.
 * Server sadece relay yapar, type'a göre dispatch burada olur.
 */
import { io, type Socket } from 'socket.io-client'

type EvtPayload = { type: string; [key: string]: any }

export function useSessionStream() {
  const config = useRuntimeConfig()
  const { user, socketToken, logout, fetchMe } = useAuth()
  const toast = useToast()
  // Ses artık default.vue'da yönetiliyor

  const socket = useState<Socket | null>('socket-io-instance', () => null)

  // Reactive triggers — diğer composable/page'ler bunlari watch eder
  const onSessionsUpdated = useState<number>('ws-sessions-updated', () => 0)
  const onNewNotification = useState<number>('ws-new-notification', () => 0)
  const onNotificationsRead = useState<number>('ws-notifications-read', () => 0)

  async function handleEvt(payload: EvtPayload) {
    console.log('[Socket.IO] evt:', payload)
    switch (payload.type) {
      case 'sessions-updated':
        // Token'imiz hala geçerli mi kontrol et
        await fetchMe()
        if (!user.value) {
          // Token revoke edilmis — bu tab'in oturumu sonlandirildi
          toast.add({ title: 'Oturumunuz sonlandirildi', color: 'warning' })
          disconnect()
          logout()
          return
        }
        // Token geçerli — sadece oturum listesini yenile
        onSessionsUpdated.value++
        break

      case 'notification':
        onNewNotification.value++
        break

      case 'notifications-read':
        onNotificationsRead.value++
        break
    }
  }

  // Expired/gecersiz socketToken'i /auth/me'den yenilemeyi dener (oturum hala gecerliyse)
  let refreshing = false
  async function tryRefreshToken(): Promise<boolean> {
    if (refreshing) return false
    refreshing = true
    try {
      const before = socketToken.value
      await fetchMe()              // /me taze socketToken doner, useAuth setSocketToken yapar
      if (!user.value) return false // oturum gercekten dusmus
      return !!socketToken.value && socketToken.value !== before
    } catch {
      return false
    } finally {
      refreshing = false
    }
  }

  function connect() {
    if (!import.meta.client || !socketToken.value) return
    disconnect()

    const s = io(config.public.socketUrl as string, {
      auth: { token: socketToken.value, tenant: window.location.hostname },
      transports: ['websocket', 'polling'],
      reconnection: true,
      reconnectionAttempts: Infinity,
      reconnectionDelay: 1000,
      reconnectionDelayMax: 10000
    })

    s.on('connect', () => console.log('[Socket.IO] Connected'))
    s.on('evt', handleEvt)
    s.on('disconnect', (r) => console.log('[Socket.IO] Disconnected:', r))
    s.on('connect_error', async (e) => {
      console.warn('[Socket.IO] Error:', e.message)
      // Token hatasi → oturum hala gecerliyse taze token alip yeniden baglan
      if (/token/i.test(e.message)) {
        const ok = await tryRefreshToken()
        if (ok) {
          console.log('[Socket.IO] socketToken yenilendi, yeniden baglaniliyor')
          connect()
        }
      }
    })

    socket.value = s
  }

  function disconnect() {
    if (socket.value) {
      socket.value.disconnect()
      socket.value = null
    }
  }

  /** Client -> diğer tab/cihazlara relay (server ayni user room'una iletir) */
  function emit(type: string, data: Record<string, any> = {}) {
    socket.value?.emit('evt', { type, ...data })
  }

  watch(() => socketToken.value, (val) => val ? connect() : disconnect())
  onMounted(() => { if (socketToken.value) connect() })
  onUnmounted(() => disconnect())

  return {
    connect,
    disconnect,
    emit,
    onSessionsUpdated,
    onNewNotification,
    onNotificationsRead
  }
}
