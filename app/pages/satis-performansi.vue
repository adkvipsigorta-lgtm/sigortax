<script setup lang="ts">
definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Aylık Satış Performansı' })

const { get } = useApi()
const { user } = useAuth()
const isAdmin = computed(() => user.value?.role === 'admin')

const loading = ref(false)
const policies = ref<any[]>([])
const stats = ref({ total: 0, new: 0, renewal: 0, totalGross: 0, totalNet: 0, cancelled: 0 })

const filterMonth = ref(new Date().getMonth() + 1)
const filterYear = ref(new Date().getFullYear())
const filterType = ref('all')
const filterInsurance = ref('all')
const filterSoldBy = ref('all')

const aylar = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık']
const monthLabel = computed(() => `${aylar[filterMonth.value]} ${filterYear.value}`)

function prevMonth() {
  if (filterMonth.value === 1) { filterMonth.value = 12; filterYear.value-- }
  else filterMonth.value--
  load()
}
function nextMonth() {
  if (filterMonth.value === 12) { filterMonth.value = 1; filterYear.value++ }
  else filterMonth.value++
  load()
}
function onSoldByChange() {
  load()
}

async function load() {
  loading.value = true
  try {
    const q: Record<string, any> = { month: filterMonth.value, year: filterYear.value }
    if (filterSoldBy.value && filterSoldBy.value !== 'all') q.soldBy = filterSoldBy.value
    const res = await get<any>('dashboard/sales-performance', q)
    const d = res?.data || {}
    stats.value = d.stats || { total: 0, new: 0, renewal: 0, totalGross: 0, totalNet: 0, cancelled: 0 }
    policies.value = d.policies || []
  } catch (e) {
    policies.value = []
  }
  loading.value = false
}

const users = ref<any[]>([])
const userOptions = computed(() => [
  { label: 'Tüm Temsilciler', value: 'all' },
  ...users.value.map((u: any) => ({ label: u.name, value: String(u.id) }))
])

const insuranceOptions = computed(() => {
  const s = new Set(policies.value.map((p: any) => p.insuranceName).filter(Boolean))
  return [...s].sort()
})

const filtered = computed(() => {
  let list = policies.value
  if (filterType.value === 'CANCELLED') list = list.filter((p: any) => p.isCancelled)
  else if (filterType.value && filterType.value !== 'all') list = list.filter((p: any) => !p.isCancelled && p.businessType === filterType.value)
  if (filterInsurance.value && filterInsurance.value !== 'all') list = list.filter((p: any) => p.insuranceName === filterInsurance.value)
  return list
})

const filteredGross = computed(() => filtered.value.reduce((s: number, p: any) => s + (p.grossPremium || 0), 0))

function fmt(val: number) {
  return val.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtDate(d: string) {
  if (!d) return '—'
  try { return new Date(d).toLocaleDateString('tr-TR') } catch { return d }
}


onMounted(async () => {
  try { const r = await get<any>('users?dropdown=1'); users.value = r.data || [] } catch {}
  await load()
})
</script>

<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-2xl font-semibold">Satış Performansı</h1>
      <p class="text-sm text-muted mt-1">Aylık poliçe üretimi ve satış detayları.</p>
    </div>

    <!-- Stat Kartları -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
      <UCard :ui="{ body: 'p-3' }">
        <div class="text-center">
          <p class="kpi-value">{{ stats.total }}</p>
          <p class="text-xs text-muted mt-1">Toplam Poliçe</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }">
        <div class="text-center">
          <p class="kpi-value text-primary">{{ stats.new }}</p>
          <p class="text-xs text-muted mt-1">Yeni İş</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }">
        <div class="text-center">
          <p class="kpi-value text-green-600 dark:text-green-400">{{ stats.renewal }}</p>
          <p class="text-xs text-muted mt-1">Yenileme</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }">
        <div class="text-center">
          <p class="kpi-value text-purple-600 dark:text-purple-400">{{ fmt(stats.totalGross) }}</p>
          <p class="text-xs text-muted mt-1">Brüt Prim</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }">
        <div class="text-center">
          <p class="kpi-value text-red-600 dark:text-red-400">{{ stats.cancelled }}</p>
          <p class="text-xs text-muted mt-1">İptal</p>
        </div>
      </UCard>
    </div>

    <!-- Tablo Kartı -->
    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="filter-toolbar">
          <div class="flex items-center gap-1 border border-[var(--ui-border)] rounded-md px-2 h-[var(--height-filter)]">
            <UButton icon="i-lucide-chevron-left" size="xs" color="neutral" variant="ghost" @click="prevMonth" />
            <span class="text-sm flex-1 text-center whitespace-nowrap w-[120px]">{{ monthLabel }}</span>
            <UButton icon="i-lucide-chevron-right" size="xs" color="neutral" variant="ghost" @click="nextMonth" />
          </div>
          <USelect v-if="isAdmin" v-model="filterSoldBy" :items="userOptions" placeholder="Temsilci" class="filter-w-md" @update:model-value="onSoldByChange" />
          <USelect v-model="filterType" :ui="filterDropdownUi" :items="[{ label: 'Tüm İş Türleri', value: 'all' }, { label: 'YENİ İŞ', value: 'NEW' }, { label: 'YENİLEME', value: 'RENEWAL' }, { label: 'İPTAL', value: 'CANCELLED' }]" placeholder="İş Türü" class="filter-w-md" />
          <USelect v-if="insuranceOptions.length" v-model="filterInsurance" :items="[{ label: 'Tüm Branşlar', value: 'all' }, ...insuranceOptions.map(n => ({ label: n, value: n }))]" placeholder="Branş" class="filter-w-md" />
          <UButton v-if="filterType !== 'all' || filterInsurance !== 'all' || filterSoldBy !== 'all'" icon="i-lucide-x" size="sm" color="error" variant="ghost" @click="filterType = 'all'; filterInsurance = 'all'; filterSoldBy = 'all'; onSoldByChange()" />
        </div>
      </template>

      <!-- Loading -->
      <div v-if="loading" class="py-4">
        <SkeletonTable :columns="9" />
      </div>

      <div v-else-if="filtered.length === 0" class="text-center py-12 text-muted">
        <UIcon name="i-lucide-inbox" class="size-8 mx-auto mb-2" />
        <p class="text-sm">Bu dönemde poliçe bulunamadı</p>
      </div>

      <!-- Tablo -->
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <table class="text-xs w-full table-fixed">
          <thead class="sticky top-0 z-10">
            <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
              <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:18%">Ad/Soyad</th>
              <th class="hidden sm:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:10%">Poliçe Türü</th>
              <th class="hidden lg:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:12%">Poliçe No</th>
              <th class="hidden md:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:8%">Plaka</th>
              <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:10%">İş Türü</th>
              <th class="hidden lg:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:10%">Kaynak</th>
              <th class="py-2 px-3 text-right text-xs font-semibold tracking-wide text-muted" style="width:10%">Brüt Prim</th>
              <th class="hidden sm:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:10%">Temsilci</th>
              <th class="hidden md:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:9%">Tarih</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in filtered" :key="p.id" class="border-b border-default" :class="p.isCancelled ? 'bg-red-50/40 dark:bg-red-900/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors'">
              <td class="py-2 px-3 overflow-hidden" style="max-width:0">
                <NuxtLink v-if="p.customerId" :to="`/musteriler/${p.customerId}`" class="text-primary hover:underline overflow-hidden whitespace-nowrap block" :title="p.customerName">{{ formatPersonName(p.customerName || '', 'compact') }}</NuxtLink>
                <span v-else class="overflow-hidden whitespace-nowrap block" :title="p.customerName">{{ formatPersonName(p.customerName || '', 'compact') || '—' }}</span>
              </td>
              <td class="hidden sm:table-cell py-2 px-3">
                <span v-if="p.insuranceName" class="badge-cell" :style="insuranceBadgeStyle(p.insuranceColor)">{{ insuranceShortLabel(p.insuranceName) }}</span>
                <span v-else class="text-muted">—</span>
              </td>
              <td class="hidden lg:table-cell py-2 px-3 text-muted overflow-hidden whitespace-nowrap" :title="p.policyNo">{{ p.policyNo || '—' }}</td>
              <td class="hidden md:table-cell py-2 px-3 overflow-hidden whitespace-nowrap">{{ p.plateNo || '—' }}</td>
              <td class="py-2 px-3">
                <span v-if="p.isCancelled" class="badge-cell badge-error">İptal</span>
                <span v-else-if="p.businessType" class="badge-cell" :class="p.businessType === 'NEW' ? 'badge-info' : 'badge-success'">{{ p.businessType === 'NEW' ? 'Yeni İş' : 'Yenileme' }}</span>
                <span v-else class="text-muted">—</span>
              </td>
              <td class="hidden lg:table-cell py-2 px-3 text-muted overflow-hidden whitespace-nowrap">{{ p.referenceSourceName || '—' }}</td>
              <td class="py-2 px-3 table-money" :class="p.isCancelled ? 'text-red-500' : ''">{{ fmt(p.grossPremium) }}</td>
              <td class="hidden sm:table-cell py-2 px-3 overflow-hidden whitespace-nowrap" :title="p.soldByName">{{ shortName(p.soldByName) }}</td>
              <td class="hidden md:table-cell py-2 px-3 text-muted table-date">{{ fmtDate(p.issuedAt) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Alt Özet -->
      <div v-if="filtered.length" class="flex items-center justify-between mt-4 pt-3 border-t border-default">
        <p class="text-xs text-muted">{{ filtered.length }} poliçe</p>
        <p class="text-sm table-money">{{ fmt(filteredGross) }} ₺</p>
      </div>
    </UCard>
  </div>
</template>

<style scoped>
table td { overflow: hidden; text-overflow: clip; white-space: nowrap; }

</style>
