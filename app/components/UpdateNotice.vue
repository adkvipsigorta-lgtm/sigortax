<script setup lang="ts">
const { get } = useApi()
const { user } = useAuth()

type Release = {
  version: string
  tag?: string
  title?: string
  released_at?: string
  changelog?: string
}
type CheckResponse = {
  current: string
  latest: string | null
  updateAvailable: boolean
  release: Release | null
  githubRepo: string
}

const DISMISS_KEY = 'update_dismissed_version'
const POLL_INTERVAL_MS = 60 * 1000 // dakikada bir

const open = ref(false)
const info = ref<CheckResponse | null>(null)
let timer: ReturnType<typeof setInterval> | null = null
// Bu oturumda hangi surum icin modal acildi — ayni surum icin tekrar acma
let surfaced: string | null = null

const isAdmin = computed(() => user.value?.role === 'admin')

function dismissed(version: string): boolean {
  if (!import.meta.client) return false
  return localStorage.getItem(DISMISS_KEY) === version
}

async function checkForUpdate() {
  if (!import.meta.client || !isAdmin.value) return
  try {
    const res = await get<any>('updates/check')
    const data: CheckResponse = res.data
    info.value = data
    if (
      data?.updateAvailable &&
      data.latest &&
      !dismissed(data.latest) &&
      surfaced !== data.latest
    ) {
      surfaced = data.latest
      open.value = true
    }
  } catch {
    // sessizce gec — guncelleme kontrolu kritik degil
  }
}

function later() {
  if (info.value?.latest) {
    localStorage.setItem(DISMISS_KEY, info.value.latest)
  }
  open.value = false
}

function goToUpdate() {
  open.value = false
  navigateTo('/settings/guncelleme')
}

function formatDate(d?: string) {
  if (!d) return ''
  try {
    return new Date(d).toLocaleDateString('tr-TR', { day: '2-digit', month: 'long', year: 'numeric' })
  } catch {
    return d
  }
}

function startPolling() {
  if (timer) return
  checkForUpdate()
  timer = setInterval(checkForUpdate, POLL_INTERVAL_MS)
}

onMounted(() => {
  if (isAdmin.value) startPolling()
  else watch(isAdmin, (v) => { if (v) startPolling() })
})

onUnmounted(() => {
  if (timer) { clearInterval(timer); timer = null }
})
</script>

<template>
  <UModal :dismissible="false" v-model:open="open" title="Yeni Güncelleme Mevcut" class="sm:max-w-lg">
    <template #body>
      <div class="space-y-4">
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-rocket" class="size-5 text-primary" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="font-semibold text-base">
              {{ info?.release?.title || ('Sürüm ' + (info?.latest || '')) }}
            </p>
            <p class="text-sm text-muted mt-0.5">
              <span class="font-mono">{{ info?.current }}</span>
              <UIcon name="i-lucide-arrow-right" class="size-3 inline mx-1" />
              <span class="font-mono font-semibold text-primary">{{ info?.latest }}</span>
              <span v-if="info?.release?.released_at" class="ml-2 text-xs">
                · {{ formatDate(info.release.released_at) }}
              </span>
            </p>
          </div>
        </div>

        <div
          v-if="info?.release?.changelog"
          class="max-h-64 overflow-y-auto rounded-lg border border-default bg-elevated p-3 text-sm whitespace-pre-wrap leading-relaxed"
        >{{ info.release.changelog }}</div>
        <p v-else class="text-sm text-muted">
          Yeni bir sürüm yayınlandı. Detaylar için güncelleme sayfasına gidin.
        </p>
      </div>
    </template>
    <template #footer>
      <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 w-full">
        <UButton label="Daha Sonra" color="neutral" variant="ghost" @click="later" />
        <UButton label="Güncelleme Sayfasına Git" icon="i-lucide-download-cloud" color="primary" @click="goToUpdate" />
      </div>
    </template>
  </UModal>
</template>
