<script setup lang="ts">
definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Personel Yönetimi' })

const { get } = useApi()
const router = useRouter()

interface PersonnelItem {
  id: number
  name: string
  email: string
  phone: string | null
  tcNo: string | null
  role: string
  isActive: boolean
  hireDate: string | null
  terminationDate: string | null
  position: string | null
  birthDate: string | null
  isOnLeave: boolean
}

interface Stats {
  totalActive: number
  totalInactive: number
  onLeave: number
  pendingLeaves: number
}

const loading = ref(true)
const data = ref<PersonnelItem[]>([])
const stats = ref<Stats>({ totalActive: 0, totalInactive: 0, onLeave: 0, pendingLeaves: 0 })

const roleLabels: Record<string, string> = { admin: 'Yönetici', acente: 'Acente', kullanici: 'Kullanıcı' }
const roleColors: Record<string, string> = { admin: 'error', acente: 'warning', kullanici: 'info' }

function formatDate(d: string | null) {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('tr-TR')
}

function calcSeniority(hireDate: string | null) {
  if (!hireDate) return '-'
  const hire = new Date(hireDate)
  const now = new Date()
  const years = now.getFullYear() - hire.getFullYear()
  const months = now.getMonth() - hire.getMonth()
  if (years === 0) return `${months < 0 ? 0 : months} ay`
  if (months < 0) return `${years - 1} yıl ${12 + months} ay`
  return `${years} yıl ${months} ay`
}

async function loadData() {
  loading.value = true
  try {
    const [listRes, statsRes] = await Promise.all([
      get<any>('personnel', { limit: 50 }),
      get<any>('personnel/stats'),
    ])
    data.value = listRes.data || []
    stats.value = statsRes.data
  } catch (e) {
    console.error('Personnel load error:', e)
  } finally {
    loading.value = false
  }
}

onMounted(() => { loadData() })
</script>

<template>
  <div class="space-y-4">
    <!-- Stats Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <UCard :ui="{ body: 'p-4' }">
        <div class="flex items-center gap-3">
          <div class="size-10 rounded-lg bg-primary/10 flex items-center justify-center">
            <UIcon name="i-lucide-users" class="size-5 text-primary" />
          </div>
          <div>
            <p class="text-2xl font-bold">{{ stats.totalActive }}</p>
            <p class="text-xs text-muted">Aktif Personel</p>
          </div>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-4' }">
        <div class="flex items-center gap-3">
          <div class="size-10 rounded-lg bg-warning/10 flex items-center justify-center">
            <UIcon name="i-lucide-palm-tree" class="size-5 text-warning" />
          </div>
          <div>
            <p class="text-2xl font-bold">{{ stats.onLeave }}</p>
            <p class="text-xs text-muted">İzinde</p>
          </div>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-4' }">
        <div class="flex items-center gap-3">
          <div class="size-10 rounded-lg bg-info/10 flex items-center justify-center">
            <UIcon name="i-lucide-clock" class="size-5 text-info" />
          </div>
          <div>
            <p class="text-2xl font-bold">{{ stats.pendingLeaves }}</p>
            <p class="text-xs text-muted">Bekleyen İzin Talebi</p>
          </div>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-4' }">
        <div class="flex items-center gap-3">
          <div class="size-10 rounded-lg bg-neutral/10 flex items-center justify-center">
            <UIcon name="i-lucide-user-x" class="size-5 text-neutral" />
          </div>
          <div>
            <p class="text-2xl font-bold">{{ stats.totalInactive }}</p>
            <p class="text-xs text-muted">Pasif Personel</p>
          </div>
        </div>
      </UCard>
    </div>

    <!-- Table -->
    <UCard :ui="{ body: 'p-4' }">
      <div v-if="loading" class="flex items-center justify-center py-20">
        <UIcon name="i-lucide-loader-2" class="size-8 animate-spin text-muted" />
      </div>
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <table class="text-xs w-full table-fixed">
          <thead class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
            <tr>
              <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Personel</th>
              <th class="hidden sm:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Pozisyon</th>
              <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">İşe Giriş</th>
              <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Kıdem</th>
              <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Durum</th>
              <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-default">
            <tr v-for="p in data" :key="p.id" class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
              <td class="py-2 px-3 whitespace-nowrap overflow-hidden text-ellipsis">
                <div class="flex items-center gap-3 cursor-pointer" @click="router.push(`/personel/${p.id}`)">
                  <div class="size-9 rounded-full bg-primary/10 text-primary flex items-center justify-center text-xs font-semibold shrink-0">
                    {{ p.name.split(' ').map((n: string) => n[0]).join('').toUpperCase().slice(0, 2) }}
                  </div>
                  <div class="min-w-0">
                    <span class="text-primary truncate block" :title="p.name">{{ p.name }}</span>
                    <p class="text-xs text-muted">{{ p.phone || p.email }}</p>
                  </div>
                </div>
              </td>
              <td class="hidden sm:table-cell py-2 px-3 whitespace-nowrap overflow-hidden text-ellipsis">
                <div class="flex flex-col gap-0.5">
                  <span>{{ p.position || '-' }}</span>
                  <UBadge :color="(roleColors[p.role] as any)" variant="subtle" size="xs">
                    {{ roleLabels[p.role] }}
                  </UBadge>
                </div>
              </td>
              <td class="hidden md:table-cell py-2 px-3 whitespace-nowrap overflow-hidden text-ellipsis">
                <span class="tabular-nums text-muted">{{ formatDate(p.hireDate) }}</span>
              </td>
              <td class="hidden md:table-cell py-2 px-3 whitespace-nowrap overflow-hidden text-ellipsis">
                <span class="text-muted">{{ calcSeniority(p.hireDate) }}</span>
              </td>
              <td class="py-2 px-3 text-center whitespace-nowrap overflow-hidden text-ellipsis">
                <UBadge v-if="p.isOnLeave" color="warning" variant="subtle" size="xs">İzinde</UBadge>
                <UBadge v-else :color="p.isActive ? 'success' : 'neutral'" variant="subtle" size="xs">
                  {{ p.isActive ? 'Aktif' : 'Pasif' }}
                </UBadge>
              </td>
              <td class="py-2 px-3 text-right whitespace-nowrap overflow-hidden text-ellipsis">
                <UButton
                  icon="i-lucide-arrow-right"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  @click="router.push(`/personel/${p.id}`)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </UCard>
  </div>
</template>
