import type { Notification } from '~/types'

const notifications = ref<Notification[]>([])
const unreadCount = ref(0)
const loading = ref(false)

export function useNotifications() {
  const { get, put } = useApi()

  async function fetchNotifications() {
    loading.value = true
    try {
      const res = await get<any>('notifications', { limit: 20 })
      notifications.value = res.data || []
      unreadCount.value = res.unreadCount ?? 0
    } catch {
      notifications.value = []
      unreadCount.value = 0
    } finally {
      loading.value = false
    }
  }

  async function markAsRead(id: number) {
    await put(`notifications/${id}`, {})
    const n = notifications.value.find(n => n.id === id)
    if (n) {
      n.unread = false
      unreadCount.value = Math.max(0, unreadCount.value - 1)
    }
  }

  async function markAllRead() {
    await put('notifications/read-all', {})
    notifications.value.forEach(n => n.unread = false)
    unreadCount.value = 0
  }

  return {
    notifications,
    unreadCount,
    loading,
    fetchNotifications,
    markAsRead,
    markAllRead,
  }
}
