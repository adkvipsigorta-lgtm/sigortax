<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Kaçırılan Poliçeler' })

const { get, put, post } = useApi()
const { token } = useAuth()
const { can } = usePermissions()
const toast = useToast()

const loading = ref(true)
const statsLoading = ref(true)
const stats = ref<any>(null)
const items = ref<any[]>([])
const pagination = ref<any>(null)

// Filtreler
const tab = ref<'upcoming' | 'overdue'>('upcoming')
const range = ref('')
const branch = ref('all')
const search = ref('')
const statusFilter = ref('all')
const page = ref(1)

const statusOptions = [
  { value: 'all', label: 'Tümü' },
  { value: 'PENDING', label: 'Beklemede' },
  { value: 'WON', label: 'Kazanıldı (Son 12 ay)' },
  { value: 'VEHICLE_SOLD', label: 'İhtiyaç Duymuyor / Araç-Konut Satıldı' }
]

// Dropdown'da agent'in elle seçebileceği seçenekler (WON otomatik, elle seçilmez)
const manualStatusOptions = [
  { value: 'PENDING', label: 'Beklemede' },
  { value: 'VEHICLE_SOLD', label: 'İhtiyaç Duymuyor / Araç-Konut Satıldı' }
]

const statusLabels: Record<string, string> = {
  PENDING: 'Beklemede',
  WON: 'Kazanıldı',
  ABANDONED: 'İhtiyaç Duymuyor',
  VEHICLE_SOLD: 'İhtiyaç Duymuyor'
}

const statusIcons: Record<string, string> = {
  PENDING: 'i-lucide-clock',
  WON: 'i-lucide-check-circle',
  ABANDONED: 'i-lucide-ban',
  VEHICLE_SOLD: 'i-lucide-ban'
}

const statusColors: Record<string, string> = {
  PENDING: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
  WON: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
  ABANDONED: 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300',
  VEHICLE_SOLD: 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300'
}

function formatPhone(phone?: string): string {
  if (!phone) return ''
  const digits = phone.replace(/\D/g, '')
  let local = digits
  if (local.startsWith('90') && local.length === 12) local = local.slice(2)
  else if (local.startsWith('0') && local.length === 11) local = local.slice(1)
  if (local.length !== 10) return phone
  return `+90 ${local.slice(0,3)} ${local.slice(3,6)} ${local.slice(6,8)} ${local.slice(8,10)}`
}

async function fetchStats() {
  statsLoading.value = true
  try {
    const res = await get('lost-policies/stats')
    stats.value = res.data
  } catch {
    stats.value = null
  } finally {
    statsLoading.value = false
  }
}

async function fetchItems() {
  loading.value = true
  try {
    const params: Record<string, string> = {
      tab: tab.value,
      page: String(page.value),
      limit: '50'
    }
    if (range.value) params.range = range.value
    if (branch.value && branch.value !== 'all') params.branch = branch.value
    if (search.value) params.search = search.value
    if (statusFilter.value && statusFilter.value !== 'all') params.status = statusFilter.value

    const res = await get('lost-policies', params)
    const d = res.data || res
    items.value = d.items || []
    pagination.value = d.pagination || null
  } catch {
    items.value = []
  } finally {
    loading.value = false
  }
}

watch([tab, range, branch, statusFilter], () => {
  page.value = 1
  fetchItems()
})

watch(page, () => fetchItems())

let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    page.value = 1
    fetchItems()
  }, 400)
})

onMounted(() => {
  fetchStats()
  fetchItems()
})

// Durum guncelle
async function updateStatus(policyId: number, newStatus: string) {
  // Aninda UI'dan kaldir
  if (['WON', 'ABANDONED', 'VEHICLE_SOLD'].includes(newStatus) && !statusFilter.value) {
    items.value = items.value.filter(i => i.id !== policyId)
  } else {
    const item = items.value.find(i => i.id === policyId)
    if (item) item.action_status = newStatus
  }
  try {
    await put(`lost-policies/${policyId}`, { status: newStatus })
  } catch {}
  fetchStats()
}

// Vade Değiştir modal
const vadeDegistirOpen = ref(false)
const vadeDegistirItem = ref<any>(null)
const newExpectedDate = ref('')
const vadeSaving = ref(false)

function openVadeDegistir(item: any) {
  vadeDegistirItem.value = item
  newExpectedDate.value = item.expected_date || item.estimated_renewal?.split('T')[0] || ''
  vadeDegistirOpen.value = true
}

async function saveVadeAndCreateTask() {
  if (!vadeDegistirItem.value || !newExpectedDate.value) return
  vadeSaving.value = true
  try {
    await put(`lost-policies/${vadeDegistirItem.value.id}`, { expectedDate: newExpectedDate.value })
    vadeDegistirItem.value.expected_date = newExpectedDate.value
    await post('tasks', {
      type: 'RENEWAL',
      policyId: vadeDegistirItem.value.id,
      expiresAtOverride: newExpectedDate.value
    })
    toast.add({ title: 'Vade güncellendi ve yenileme görevi oluşturuldu', color: 'success' })
    vadeDegistirOpen.value = false
    fetchStats()
  } catch (e: any) {
    toast.add({ title: e?.data?.message || e?.message || 'İşlem başarısız', color: 'error' })
  } finally {
    vadeSaving.value = false
  }
}

// Belge seri no guncelle - debounce ile
const regTimers: Record<number, ReturnType<typeof setTimeout>> = {}

function onRegNoInput(item: any, value: string) {
  item.current_registration_no = value
  if (regTimers[item.id]) clearTimeout(regTimers[item.id])
  regTimers[item.id] = setTimeout(async () => {
    try {
      await put(`lost-policies/${item.id}`, { registrationNo: value || null })
    } catch {}
  }, 600)
}

// Excel export
function exportExcel() {
  const params = new URLSearchParams({ tab: tab.value, token: token.value || '' })
  if (branch.value) params.set('branch', branch.value)
  if (search.value) params.set('search', search.value)
  if (statusFilter.value) params.set('status', statusFilter.value)
  window.open(`/api/lost-policies/export?${params.toString()}`, '_blank')
}


const legacyColorMap: Record<string, string> = {
  primary: '#3b82f6',
  error: '#ef4444',
  success: '#22c55e',
  warning: '#f59e0b',
  info: '#8b5cf6',
  neutral: '#6b7280'
}

function toHex(color?: string): string {
  if (!color) return '#3b82f6'
  if (color.startsWith('#')) return color
  return legacyColorMap[color] || '#3b82f6'
}

function shortName(fullName?: string): string {
  if (!fullName) return ''
  const parts = fullName.trim().split(/\s+/)
  if (parts.length === 1) return parts[0].charAt(0).toLocaleUpperCase('tr') + parts[0].slice(1).toLocaleLowerCase('tr')
  const first = parts[0].charAt(0).toLocaleUpperCase('tr') + parts[0].slice(1).toLocaleLowerCase('tr')
  const lastInitial = parts[parts.length - 1].charAt(0).toLocaleUpperCase('tr')
  return first + ' ' + lastInitial + '.'
}

function formatCurrency(val: number) {
  return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val)
}

function formatDate(d: string) {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('tr-TR')
}

function urgencyBadge(days: number) {
  if (days < 0) return { label: `${Math.abs(days)} gün geçti`, class: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300', icon: 'i-lucide-alert-circle' }
  if (days === 0) return { label: 'Bugün', class: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300', icon: 'i-lucide-alert-triangle' }
  if (days <= 7) return { label: `${days} gün`, class: 'bg-orange-100 text-orange-700 dark:bg-orange-900/50 dark:text-orange-300', icon: 'i-lucide-clock' }
  if (days <= 15) return { label: `${days} gün`, class: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300', icon: 'i-lucide-clock' }
  return { label: `${days} gün`, class: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400', icon: 'i-lucide-clock' }
}

</script>

<template>
  <div class="p-4 sm:p-6 space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-xl font-semibold">Kaçırılan Poliçeler</h1>
      <p class="text-sm text-muted mt-1">Yenilenmeyen poliçeleri takip edin ve geri kazanım sürecini yönetin.</p>
    </div>

    <!-- Özet Kartları -->
    <div class="hidden sm:grid grid-cols-2 lg:grid-cols-5 gap-3">
      <UCard :ui="{ body: 'p-3' }" class="cursor-pointer hover:ring-2 ring-orange-400/50 transition-all" @click="tab = 'upcoming'; range = 'week'; statusFilter = 'all'">
        <div class="text-center">
          <p class="text-2xl font-bold text-orange-600 dark:text-orange-400">{{ statsLoading ? '...' : (stats?.thisWeek ?? 0) }}</p>
          <p class="text-xs text-muted mt-1">Bu Hafta</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }" class="cursor-pointer hover:ring-2 ring-blue-400/50 transition-all" @click="tab = 'upcoming'; range = 'month'; statusFilter = 'all'">
        <div class="text-center">
          <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ statsLoading ? '...' : (stats?.thisMonth ?? 0) }}</p>
          <p class="text-xs text-muted mt-1">Bu Ay</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }" class="cursor-pointer hover:ring-2 ring-red-400/50 transition-all" @click="tab = 'overdue'; range = ''; statusFilter = 'all'">
        <div class="text-center">
          <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ statsLoading ? '...' : (stats?.overdue ?? 0) }}</p>
          <p class="text-xs text-muted mt-1">Vadesi Geçmiş</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }" class="cursor-pointer hover:ring-2 ring-green-400/50 transition-all" @click="tab = 'upcoming'; range = ''; statusFilter = 'WON'">
        <div class="text-center">
          <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ statsLoading ? '...' : (stats?.won ?? 0) }}</p>
          <p class="text-xs text-muted mt-1">Geri Kazanılan</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }" class="cursor-pointer hover:ring-2 ring-amber-400/50 transition-all" @click="tab = 'upcoming'; range = ''; statusFilter = 'VEHICLE_SOLD'">
        <div class="text-center">
          <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ statsLoading ? '...' : (stats?.notNeeded ?? 0) }}</p>
          <p class="text-xs text-muted mt-1">İhtiyaç Duymuyor</p>
        </div>
      </UCard>
    </div>

    <!-- Ana İçerik -->
    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-col gap-3">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="font-semibold">Kaçırılan Poliçeler</h3>
              <p class="text-xs text-muted">Süresi dolup yenilenmeyen poliçelerin geri kazanım takibi</p>
            </div>
          </div>
          <div class="flex items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5">
              <!-- Sekmeler -->
              <div class="flex gap-1 bg-gray-100 dark:bg-gray-800 rounded-lg p-0.5 shrink-0">
                <button
                  @click="tab = 'upcoming'; range = ''"
                  :class="[
                    'px-3.5 py-1.5 text-xs font-medium rounded-md transition-all',
                    tab === 'upcoming'
                      ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
                      : 'text-muted hover:text-default'
                  ]"
                >
                  Aranacaklar
                  <span v-if="stats?.thisMonth" class="ml-1 px-1.5 py-0.5 text-xs rounded-full bg-primary-100 dark:bg-primary-900/50 text-primary-700 dark:text-primary-300">{{ stats.thisMonth }}</span>
                </button>
                <button
                  @click="tab = 'overdue'; range = ''"
                  :class="[
                    'px-3.5 py-1.5 text-xs font-medium rounded-md transition-all',
                    tab === 'overdue'
                      ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
                      : 'text-muted hover:text-default'
                  ]"
                >
                  Vadesi Geçenler
                  <span v-if="stats?.overdue" class="ml-1 px-1.5 py-0.5 text-xs rounded-full bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">{{ stats.overdue }}</span>
                </button>
              </div>
              <!-- Filtreler -->
              <div class="relative hidden sm:flex w-[200px] [&_input]:!pt-5 [&_input]:!pb-2.5">
                <UInput v-model="search" placeholder=" " class="w-full peer/fl-lpsearch" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-lpsearch:top-0 peer-focus-within/fl-lpsearch:-translate-y-1/2 peer-focus-within/fl-lpsearch:text-xs peer-focus-within/fl-lpsearch:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-lpsearch:top-0 peer-has-[input:not(:placeholder-shown)]/fl-lpsearch:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-lpsearch:text-xs peer-has-[input:not(:placeholder-shown)]/fl-lpsearch:text-[var(--ui-text-highlighted)]">Müşteri, plaka, TC</label>
              </div>
              <div class="relative hidden sm:flex w-[180px] select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
                <USelect v-model="branch" :items="[{ label: 'Tüm Branşlar', value: 'all' }, ...(stats?.branches || []).map((b: any) => ({ label: `${b.name} (${b.cnt})`, value: String(b.id) }))]" placeholder=" " class="w-full" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">Branş</label>
              </div>
              <div class="relative hidden sm:flex w-[160px] select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
                <USelect v-model="statusFilter" :items="statusOptions" placeholder=" " class="w-full" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">Durum</label>
              </div>
            </div>
            <UButton v-if="can('lost_policies.view')" label="Excel" icon="i-lucide-download" variant="outline" size="xl" class="font-semibold hidden sm:flex" @click="exportExcel" title="Excel'e Aktar" />
          </div>
        </div>
      </template>

      <div v-if="loading" class="py-4">
        <SkeletonTable :columns="9" />
      </div>

      <div v-else-if="items.length === 0" class="text-center py-12 text-muted">
        <UIcon name="i-lucide-inbox" class="size-8 mx-auto mb-2" />
        <p class="text-sm">{{ tab === 'upcoming' ? 'Önümüzdeki 45 gün içinde aranacak kaçırılmış poliçe yok.' : 'Vadesi geçmiş kayıt bulunamadı.' }}</p>
      </div>

      <div v-else class="border border-default rounded-lg overflow-hidden">
        <table class="text-xs w-full table-fixed">
          <thead class="sticky top-0 z-10">
            <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
              <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:20%">Ad/Soyad</th>
              <th class="hidden sm:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:10%">Poliçe Türü</th>
              <th class="hidden md:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:9%">Plaka</th>
              <th class="hidden md:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:11%">Şirket</th>
              <th class="hidden md:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:10%">Tahmini Vade</th>
              <th class="py-2 px-3 text-center text-xs font-semibold tracking-wide text-muted" style="width:8%">Kalan</th>
              <th class="hidden md:table-cell py-2 px-3 text-right text-xs font-semibold tracking-wide text-muted" style="width:9%">Son Prim</th>
              <th class="hidden md:table-cell py-2 px-3 text-center text-xs font-semibold tracking-wide text-muted" style="width:10%">Durum</th>
              <th class="hidden md:table-cell py-2 px-3 text-center text-xs font-semibold tracking-wide text-muted" style="width:5%">İşlem</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in items"
              :key="item.id"
              class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors"
            >
              <!-- Ad/Soyad -->
              <td class="py-2 px-3 overflow-hidden" style="max-width:0">
                <div class="flex items-center gap-2 min-w-0">
                  <UPopover v-if="item.customer_phone">
                    <button class="text-muted hover:text-primary transition-colors shrink-0" :title="formatPhone(item.customer_phone)">
                      <UIcon name="i-lucide-phone" class="size-3.5" />
                    </button>
                    <template #content>
                      <div class="px-4 py-3 flex items-center gap-3">
                        <UIcon name="i-lucide-phone" class="size-4 text-primary" />
                        <span class="text-sm font-semibold tracking-wide">{{ formatPhone(item.customer_phone) }}</span>
                      </div>
                    </template>
                  </UPopover>
                  <div v-else class="size-3.5 shrink-0" />
                  <div class="min-w-0">
                    <NuxtLink :to="`/musteriler/${item.customer_id}`" class="font-semibold text-primary hover:underline truncate block" :title="item.customer_name">
                      {{ item.customer_name }}
                    </NuxtLink>
                    <span v-if="item.customer_identity" class="text-muted truncate block">{{ item.customer_identity }}</span>
                  </div>
                </div>
              </td>
              <!-- Poliçe Türü -->
              <td class="hidden sm:table-cell py-2 px-3">
                <span
                  class="badge-cell"
                  :style="{ backgroundColor: toHex(item.branch_color) + '1a', color: toHex(item.branch_color) }"
                >
                  {{ item.branch_name }}
                </span>
              </td>
              <!-- Plaka -->
              <td class="hidden md:table-cell py-2 px-3">
                <span v-if="item.plate_no" class="truncate block" :title="item.plate_no">{{ item.plate_no }}</span>
                <span v-else class="text-muted">-</span>
              </td>
              <!-- Şirket -->
              <td class="hidden md:table-cell py-2 px-3 overflow-hidden" style="max-width:0">
                <span class="truncate block">{{ item.company_name || '-' }}</span>
                <span class="text-muted truncate block">{{ item.policy_no }}</span>
              </td>
              <!-- Tahmini Vade -->
              <td class="hidden md:table-cell py-2 px-3">
                <span class="font-semibold" :class="item.expected_date ? 'text-primary-600 dark:text-primary-400' : 'text-muted'">
                  {{ item.expected_date ? formatDate(item.expected_date) : (item.estimated_renewal ? formatDate(item.estimated_renewal) : '-') }}
                </span>
              </td>
              <!-- Kalan Gün -->
              <td class="py-2 px-3 text-center">
                <template v-if="item.action_status === 'WON'">
                  <span class="badge-cell badge-success">Kazanıldı</span>
                </template>
                <span v-else class="badge-cell" :class="item.remaining_days < 0 ? 'badge-error' : item.remaining_days <= 7 ? 'badge-warning' : item.remaining_days <= 15 ? 'badge-info' : 'badge-neutral'">
                  {{ urgencyBadge(item.remaining_days).label }}
                </span>
              </td>
              <!-- Son Prim -->
              <td class="hidden md:table-cell py-2 px-3 text-right whitespace-nowrap tabular-nums">
                <template v-if="item.action_status === 'WON' && item.new_premium">
                  <div class="text-[10px] text-muted line-through">{{ formatCurrency(item.gross_premium) }}</div>
                  <div class="font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(item.new_premium) }}</div>
                </template>
                <template v-else>
                  <span class="font-semibold">{{ formatCurrency(item.gross_premium) }}</span>
                </template>
              </td>
              <!-- Durum -->
              <td class="hidden md:table-cell py-2 px-3 text-center kp-no-clip">
                <UPopover v-model:open="item._statusOpen">
                  <button
                    :class="['badge-cell cursor-pointer transition-all hover:ring-2 hover:ring-offset-1 hover:ring-gray-300 dark:hover:ring-gray-600', statusColors[item.action_status || 'PENDING']]"
                  >
                    {{ statusLabels[item.action_status || 'PENDING'] }}
                  </button>
                  <template #content>
                    <div class="p-1.5 w-44">
                      <button
                        v-for="s in manualStatusOptions"
                        :key="s.value"
                        @click="updateStatus(item.id, s.value); item._statusOpen = false"
                        :class="[
                          'w-full flex items-center gap-2 px-2.5 py-2 rounded-md text-xs transition-colors',
                          (item.action_status || 'PENDING') === s.value
                            ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300 font-semibold'
                            : 'text-muted hover:bg-gray-100 dark:hover:bg-gray-800'
                        ]"
                      >
                        <span :class="['size-5 rounded-full flex items-center justify-center text-xs', statusColors[s.value]]">
                          <UIcon :name="statusIcons[s.value]" />
                        </span>
                        {{ s.label }}
                        <UIcon v-if="(item.action_status || 'PENDING') === s.value" name="i-lucide-check" class="ml-auto text-primary-500 text-xs" />
                      </button>
                    </div>
                  </template>
                </UPopover>
              </td>
              <!-- İşlem -->
              <td class="hidden md:table-cell py-2 px-3 text-center kp-no-clip">
                <UButton v-if="item.action_status !== 'WON'" icon="i-lucide-calendar-plus" size="xs" color="primary" variant="ghost" title="Vade değiştir ve görev oluştur" @click="openVadeDegistir(item)" />
                <span v-else class="text-muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination && pagination.totalPages > 1" class="flex items-center justify-between pt-4 mt-4 border-t border-default">
        <p class="text-xs text-muted">
          <span class="font-medium">{{ pagination.total }}</span> kayıt · Sayfa {{ pagination.page }}/{{ pagination.totalPages }}
        </p>
        <div class="flex gap-1">
          <UButton size="xs" color="neutral" variant="outline" :disabled="page <= 1" @click="page--" icon="i-lucide-chevron-left" />
          <UButton size="xs" color="neutral" variant="outline" :disabled="page >= pagination.totalPages" @click="page++" icon="i-lucide-chevron-right" />
        </div>
      </div>
    </UCard>

    <!-- Vade Değiştir Modal -->
    <UModal :dismissible="false" v-model:open="vadeDegistirOpen" title="Vade Değiştir">
      <template #body>
        <div class="space-y-4">
          <div class="p-3 bg-neutral-50 rounded-lg">
            <p class="text-sm font-semibold">{{ vadeDegistirItem?.customer_name }}</p>
            <p class="text-xs text-muted mt-0.5">Mevcut bitiş tarihi: <span class="font-medium">{{ vadeDegistirItem ? formatDate(vadeDegistirItem.expires_at) : '' }}</span></p>
          </div>
          <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput type="date" v-model="newExpectedDate" placeholder=" " class="w-full peer/fl-vdate" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-vdate:top-0 peer-focus-within/fl-vdate:-translate-y-1/2 peer-focus-within/fl-vdate:text-xs peer-focus-within/fl-vdate:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-vdate:top-0 peer-has-[input:not(:placeholder-shown)]/fl-vdate:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-vdate:text-xs peer-has-[input:not(:placeholder-shown)]/fl-vdate:text-[var(--ui-text-highlighted)]">Yeni Tahmini Vade Tarihi</label>
          </div>
          <p class="text-xs text-muted flex items-start gap-1.5">
            <UIcon name="i-lucide-info" class="shrink-0 mt-0.5" />
            Bu tarihe göre otomatik yenileme görevi oluşturulacak ve vadesi geldiğinde size hatırlatılacak.
          </p>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" size="xl" class="font-semibold" @click="vadeDegistirOpen = false" />
          <UButton label="Kaydet ve Görev Oluştur" icon="i-lucide-calendar-plus" size="xl" class="font-semibold" :loading="vadeSaving" :disabled="!newExpectedDate" @click="saveVadeAndCreateTask" />
        </div>
      </template>
    </UModal>

    <!-- Bilgi Kutusu -->
    <div class="bg-blue-50/50 dark:bg-blue-950/20 border border-blue-100 dark:border-blue-900/50 rounded-xl p-4">
      <div class="flex gap-3">
        <UIcon name="i-lucide-info" class="text-blue-500 text-base shrink-0 mt-0.5" />
        <div class="text-xs text-blue-700 dark:text-blue-400 space-y-1">
          <p class="font-semibold text-blue-800 dark:text-blue-300">Bu sayfa nasıl çalışır?</p>
          <ul class="list-disc list-inside space-y-0.5">
            <li>Vadesi geçmiş ve 45 gün içinde yenilenmeyen poliçeler bu listede otomatik olarak görünür. Sisteme yeni poliçe girildiğinde ilgili kayıt <strong>Kazanıldı</strong> olarak işaretlenir.</li>
            <li><strong>Tahmini Vade</strong> sütunu, poliçenin bitişinden 1 yıl sonrasını gösterir. Müşteri başka bir acentede yaptırmış olsa bile o tarihte tekrar vade gelmiş olacaktır. Tarihi elle de değiştirebilirsiniz.</li>
            <li><strong>İşlem</strong> sütunundaki takvim ikonuna tıklayarak yeni bir tahmini vade girebilir ve otomatik hatırlatma görevi oluşturabilirsiniz.</li>
            <li>Müşteriyi aradınız ve poliçeye ihtiyaç duymadığını öğrendiyseniz (araç sattı, konut sattı vb.) <strong>İhtiyaç Duymuyor</strong> seçeneğini işaretleyin — kayıt kalıcı olarak gizlenir.</li>
            <li>Liste her sayfa açılışında otomatik güncellenir; eski kayıtlar için Excel çıktısı alabilirsiniz.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.select-fl :deep(button) {
  min-height: 50px !important;
  height: auto !important;
}
table td { overflow: hidden; text-overflow: clip; white-space: nowrap; }
td.kp-no-clip { overflow: visible; white-space: normal; }

.badge-cell {
  display: inline-block;
  width: 90px;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.4;
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: clip;
  vertical-align: middle;
}
.badge-error   { background: rgb(239 68 68 / 0.1);  color: #ef4444; }
.badge-warning { background: rgb(245 158 11 / 0.1); color: #f59e0b; }
.badge-info    { background: rgb(59 130 246 / 0.1); color: #3b82f6; }
.badge-success { background: rgb(34 197 94 / 0.1);  color: #22c55e; }
.badge-neutral { background: rgb(107 114 128 / 0.1); color: #6b7280; }
</style>
