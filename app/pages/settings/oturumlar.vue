<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Oturumlar' })

const toast = useToast()
const { get, del } = useApi()

interface Session {
  id: number
  ipAddress: string
  device: string
  browser: string
  os: string
  location: string | null
  city: string | null
  country: string | null
  countryCode: string | null
  language: string | null
  isCurrent: boolean
  loggedInAt: string
}

const sessions = ref<Session[]>([])
const loading = ref(true)

const deviceIcons: Record<string, string> = {
  'Masaustu': 'i-lucide-monitor',
  'Mobil': 'i-lucide-smartphone',
  'Tablet': 'i-lucide-tablet'
}

function countryFlag(code: string | null): string {
  if (!code) return ''
  return code.toUpperCase().split('').map(c => String.fromCodePoint(0x1F1E6 + c.charCodeAt(0) - 65)).join('')
}

async function fetchSessions() {
  loading.value = true
  try {
    const res = await get('auth/sessions')
    sessions.value = res.data || []
  } catch (error: any) {
    toast.add({ title: error.message || 'Oturumlar yüklenemedi', color: 'error' })
  } finally {
    loading.value = false
  }
}

const deleteModalOpen = ref(false)
const deletingSession = ref<Session | null>(null)
const deleting = ref(false)

function confirmDelete(s: Session) {
  deletingSession.value = s
  deleteModalOpen.value = true
}

async function deleteSession() {
  if (!deletingSession.value) return
  deleting.value = true
  try {
    await del(`auth/sessions/${deletingSession.value.id}`)
    toast.add({ title: 'Oturum sonlandırıldı', color: 'success' })
    deleteModalOpen.value = false
    await fetchSessions()
  } catch (error: any) {
    toast.add({ title: error.message || 'Oturum sonlandırılamadı', color: 'error' })
  } finally {
    deleting.value = false
  }
}

function formatDate(date: string) {
  const d = new Date(date)
  const now = new Date()
  const diffMs = now.getTime() - d.getTime()
  const diffMin = Math.floor(diffMs / 60000)
  const diffHour = Math.floor(diffMin / 60)
  const diffDay = Math.floor(diffHour / 24)

  if (diffMin < 1) return 'Şimdi'
  if (diffMin < 60) return `${diffMin} dakika önce`
  if (diffHour < 24) return `${diffHour} saat önce`
  if (diffDay < 7) return `${diffDay} gün önce`

  return d.toLocaleDateString('tr-TR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const sessionsUpdated = useState<number>('ws-sessions-updated', () => 0)
watch(sessionsUpdated, () => { fetchSessions() })

onMounted(fetchSessions)
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <!-- Sayfa Başlığı -->
    <div class="mb-6 pb-4 border-b border-default">
      <h1 class="text-xl">Oturumlar</h1>
      <p class="text-sm text-muted mt-1">Hesabınıza yapılan giriş geçmişini görüntüleyin ve yönetin.</p>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="space-y-3">
      <SkeletonCard v-for="i in 3" :key="i">
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-lg bg-neutral-200 shrink-0" />
          <div class="flex-1 space-y-2">
            <div class="h-4 bg-neutral-200 rounded w-48" />
            <div class="h-3 bg-neutral-200 rounded w-32" />
            <div class="h-3 bg-neutral-200 rounded w-64" />
          </div>
        </div>
      </SkeletonCard>
    </div>

    <!-- Boş -->
    <div v-else-if="sessions.length === 0" class="text-center py-12 text-muted text-sm">
      Henüz oturum kaydı bulunmuyor.
    </div>

    <!-- Oturum Listesi -->
    <div v-else class="space-y-3">
      <UCard
        v-for="session in sessions"
        :key="session.id"
        :class="session.isCurrent ? 'ring-1 ring-primary/30' : ''"
      >
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 sm:gap-4">
          <!-- Sol: Cihaz bilgisi -->
          <div class="flex items-start gap-3 min-w-0">
            <div
              class="size-10 rounded-lg flex items-center justify-center shrink-0"
              :class="session.isCurrent ? 'bg-primary/10 text-primary' : 'bg-neutral-100 text-muted'"
            >
              <UIcon :name="deviceIcons[session.device] || 'i-lucide-monitor'" class="size-5" />
            </div>

            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <p class="font-medium text-sm">{{ session.browser }} - {{ session.os }}</p>
                <UBadge v-if="session.isCurrent" color="success" variant="subtle" size="sm">Bu oturum</UBadge>
              </div>

              <p class="text-xs text-muted mt-0.5">{{ session.device }}</p>

              <div class="flex items-center gap-3 mt-1.5 text-xs text-muted flex-wrap">
                <span class="flex items-center gap-1">
                  <UIcon name="i-lucide-globe" class="size-3.5" />
                  {{ session.ipAddress }}
                </span>

                <span v-if="session.location || session.countryCode" class="flex items-center gap-1">
                  <UIcon name="i-lucide-map-pin" class="size-3.5" />
                  <span v-if="session.countryCode" class="text-base leading-none">{{ countryFlag(session.countryCode) }}</span>
                  {{ session.location || session.country || '' }}
                </span>

                <span v-if="session.language" class="flex items-center gap-1">
                  <UIcon name="i-lucide-languages" class="size-3.5" />
                  {{ session.language }}
                </span>

                <span class="flex items-center gap-1">
                  <UIcon name="i-lucide-clock" class="size-3.5" />
                  {{ formatDate(session.loggedInAt) }}
                </span>
              </div>
            </div>
          </div>

          <!-- Sağ: Sonlandır -->
          <UButton
            v-if="!session.isCurrent"
            label="Sonlandır"
            icon="i-lucide-log-out"
            color="error"
            variant="outline"
            size="xl"
            class="shrink-0 sm:w-auto w-full"
            @click="confirmDelete(session)"
          />
        </div>
      </UCard>
    </div>

    <!-- Silme Onay Modal -->
    <UModal :dismissible="false" v-model:open="deleteModalOpen" title="Oturumu Sonlandır">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-red-50 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-log-out" class="size-5 text-red-500" />
          </div>
          <div>
            <p class="font-medium">Bu oturumu sonlandırmak istediğinize emin misiniz?</p>
            <p class="text-xs text-muted mt-1">
              {{ deletingSession?.browser }} - {{ deletingSession?.os }} ({{ deletingSession?.ipAddress }})
            </p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" size="xl"  @click="deleteModalOpen = false" />
          <UButton label="Sonlandır" color="error" icon="i-lucide-log-out" size="xl"  :loading="deleting" @click="deleteSession" />
        </div>
      </template>
    </UModal>
  </div>
</template>
