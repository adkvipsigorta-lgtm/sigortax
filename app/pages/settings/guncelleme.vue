<script setup lang="ts">
definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Güncellemeler' })

const { user } = useAuth()
const { get, post } = useApi()
const toast = useToast()

if (user.value?.role !== 'admin') {
  navigateTo('/settings')
}

type Release = {
  version: string
  zip_url: string
  title?: string
  released_at?: string
  changelog?: string
  sha256?: string
}

type CheckResponse = {
  current: string
  latest: string | null
  updateAvailable: boolean
  release: Release | null
  githubRepo: string
}

type Backup = {
  name: string
  version: string
  created_at: string
  size_bytes: number
}

const info = ref<CheckResponse | null>(null)
const backups = ref<Backup[]>([])
const checking = ref(false)
const applying = ref(false)
const rollingBack = ref<string | null>(null)

async function check() {
  checking.value = true
  try {
    const res = await get<any>('updates/check')
    info.value = res.data
  } catch (e: any) {
    toast.add({ title: e?.data?.message || 'Kontrol başarısız', color: 'error' })
  } finally {
    checking.value = false
  }
}

async function fetchHistory() {
  try {
    const res = await get<any>('updates/history')
    backups.value = res.data?.backups || []
  } catch {}
}

async function apply() {
  if (!info.value?.release) return
  const confirmed = confirm(`${info.value.current} → ${info.value.release.version} güncellemesi uygulanacak. Devam edilsin mi?`)
  if (!confirmed) return
  applying.value = true
  try {
    const res = await post<any>('updates/apply', { version: info.value.release.version })
    toast.add({ title: res.message || 'Güncelleme tamamlandı', color: 'success' })
    setTimeout(() => window.location.reload(), 1500)
  } catch (e: any) {
    toast.add({ title: e?.data?.message || 'Güncelleme başarısız', color: 'error' })
  } finally {
    applying.value = false
  }
}

async function rollback(name: string) {
  const confirmed = confirm(`${name} backup'ına geri dönülecek. Devam edilsin mi?`)
  if (!confirmed) return
  rollingBack.value = name
  try {
    const res = await post<any>('updates/rollback', { backup: name })
    toast.add({ title: res.message || 'Geri alma tamamlandı', color: 'success' })
    setTimeout(() => window.location.reload(), 1500)
  } catch (e: any) {
    toast.add({ title: e?.data?.message || 'Geri alma başarısız', color: 'error' })
  } finally {
    rollingBack.value = null
  }
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1024 * 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB'
  return (bytes / 1024 / 1024 / 1024).toFixed(2) + ' GB'
}

function formatDate(iso: string): string {
  try {
    return new Date(iso).toLocaleString('tr-TR')
  } catch { return iso }
}

onMounted(() => {
  check()
  fetchHistory()
})
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <!-- Sayfa Başlığı -->
    <div class="mb-6 pb-4 border-b border-default">
      <h1 class="text-xl font-semibold">Güncellemeler</h1>
      <p class="text-sm text-muted mt-1">Uzaktan uygulama güncellemelerini yönetin.</p>
    </div>

    <!-- Güncelleme Kontrolü -->
    <UCard class="mb-6">
      <template #header>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <h2 class="font-semibold">Sürüm Durumu</h2>
          <UButton
            label="Tekrar Kontrol Et"
            icon="i-lucide-refresh-cw"
            size="xl"
            variant="outline"
            color="neutral"
            class="font-semibold"
            :loading="checking"
            @click="check"
          />
        </div>
      </template>

      <div v-if="!info && checking" class="text-sm text-muted">Kontrol ediliyor…</div>

      <div v-else-if="info" class="space-y-4">
        <!-- Mevcut Sürüm -->
        <div class="flex items-center justify-between p-3 bg-elevated rounded-lg">
          <div>
            <p class="text-xs text-muted">Mevcut Sürüm</p>
            <p class="font-semibold">v{{ info.current }}</p>
          </div>
          <UIcon name="i-lucide-package" class="size-6 text-muted" />
        </div>

        <!-- Güncelleme yok -->
        <div v-if="!info.updateAvailable" class="p-4 border border-success/30 bg-success/5 rounded-lg flex items-center gap-3">
          <UIcon name="i-lucide-check-circle" class="size-5 text-success" />
          <div>
            <p class="text-sm font-medium">Sisteminiz güncel.</p>
            <p class="text-xs text-muted">Son sürüm: v{{ info.latest || '—' }}</p>
          </div>
        </div>

        <!-- Güncelleme var -->
        <div v-else class="p-4 border border-primary/30 bg-primary/5 rounded-lg space-y-3">
          <div class="flex items-center gap-3">
            <UIcon name="i-lucide-arrow-up-circle" class="size-5 text-primary" />
            <div>
              <p class="text-sm font-medium">
                <span v-if="info.release?.title">{{ info.release.title }}</span>
                <span v-else>Yeni sürüm mevcut</span>
                <span class="text-primary ml-1">v{{ info.release?.version }}</span>
              </p>
              <p v-if="info.release?.released_at" class="text-xs text-muted">
                Yayınlanma: {{ formatDate(info.release.released_at) }}
              </p>
            </div>
          </div>

          <div v-if="info.release?.changelog" class="pt-2 border-t border-default">
            <p class="text-xs font-medium mb-1">Değişiklikler</p>
            <pre class="text-xs text-muted whitespace-pre-wrap font-sans">{{ info.release.changelog }}</pre>
          </div>

          <UButton
            :label="`v${info.release?.version} sürümüne güncelle`"
            icon="i-lucide-download"
            color="primary"
            size="xl"
            class="font-semibold"
            :loading="applying"
            block
            @click="apply"
          />
          <p v-if="applying" class="text-xs text-warning-600 text-center">Güncelleme uygulanıyor, kapatmayın…</p>
        </div>

        <p class="text-xs text-muted flex items-center gap-1">
          <UIcon name="i-simple-icons-github" class="size-3.5" />
          <a :href="`https://github.com/${info.githubRepo}/releases`" target="_blank" class="hover:underline">
            {{ info.githubRepo }}
          </a>
        </p>
      </div>
    </UCard>

    <!-- Yedekler -->
    <UCard v-if="backups.length">
      <template #header>
        <div>
          <h2 class="font-semibold">Yedekler</h2>
          <p class="text-xs text-muted mt-0.5">Son {{ backups.length }} yedek — gerekirse geri alabilirsiniz.</p>
        </div>
      </template>

      <div class="space-y-2">
        <div
          v-for="b in backups"
          :key="b.name"
          class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 border border-default rounded-lg"
        >
          <div class="min-w-0">
            <p class="text-sm font-medium">v{{ b.version }}</p>
            <p class="text-xs text-muted">{{ formatDate(b.created_at) }} · {{ formatSize(b.size_bytes) }}</p>
          </div>
          <UButton
            label="Geri Al"
            icon="i-lucide-history"
            size="xl"
            variant="outline"
            color="warning"
            class="font-semibold shrink-0 sm:w-auto w-full"
            :loading="rollingBack === b.name"
            @click="rollback(b.name)"
          />
        </div>
      </div>
    </UCard>
  </div>
</template>
