<script setup lang="ts">
import { Bar, Doughnut, Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  LineElement,
  PointElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, BarElement, LineElement, PointElement, ArcElement, Title, Tooltip, Legend, Filler)

definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Raporlar' })

import type { DateRange } from 'reka-ui'
import { CalendarDate } from '@internationalized/date'

const { get } = useApi()
const loading = ref(true)

// Tarih araligi - varsayılan bu yil
const now = new Date()
const dateRange = ref<DateRange>({
  start: new CalendarDate(now.getFullYear(), 1, 1),
  end: new CalendarDate(now.getFullYear(), 12, 31)
})
const datePopoverOpen = ref(false)
const activePreset = ref('thisYear')

function calendarDateToStr(d: any): string {
  if (!d) return ''
  return `${d.year}-${String(d.month).padStart(2, '0')}-${String(d.day).padStart(2, '0')}`
}

const dateRangeLabel = computed(() => {
  const s = dateRange.value?.start
  const e = dateRange.value?.end
  if (!s && !e) return 'Tarih Aralığı'
  const f = (d: any) => `${String(d.day).padStart(2, '0')}.${String(d.month).padStart(2, '0')}.${d.year}`
  if (s && e) return `${f(s)} - ${f(e)}`
  if (s) return `${f(s)} - ...`
  return 'Tarih Aralığı'
})

function getMonday(d: Date): Date {
  const day = d.getDay()
  const diff = d.getDate() - day + (day === 0 ? -6 : 1)
  return new Date(d.getFullYear(), d.getMonth(), diff)
}

const presets = [
  { key: 'thisWeek', label: 'Bu Hafta', fn: () => {
    const mon = getMonday(new Date())
    const sun = new Date(mon); sun.setDate(mon.getDate() + 6)
    return { start: new CalendarDate(mon.getFullYear(), mon.getMonth() + 1, mon.getDate()), end: new CalendarDate(sun.getFullYear(), sun.getMonth() + 1, sun.getDate()) }
  }},
  { key: 'lastWeek', label: 'Geçen Hafta', fn: () => {
    const mon = getMonday(new Date()); mon.setDate(mon.getDate() - 7)
    const sun = new Date(mon); sun.setDate(mon.getDate() + 6)
    return { start: new CalendarDate(mon.getFullYear(), mon.getMonth() + 1, mon.getDate()), end: new CalendarDate(sun.getFullYear(), sun.getMonth() + 1, sun.getDate()) }
  }},
  { key: 'thisMonth', label: 'Bu Ay', fn: () => {
    const d = new Date()
    const last = new Date(d.getFullYear(), d.getMonth() + 1, 0)
    return { start: new CalendarDate(d.getFullYear(), d.getMonth() + 1, 1), end: new CalendarDate(last.getFullYear(), last.getMonth() + 1, last.getDate()) }
  }},
  { key: 'lastMonth', label: 'Geçen Ay', fn: () => {
    const d = new Date()
    const first = new Date(d.getFullYear(), d.getMonth() - 1, 1)
    const last = new Date(d.getFullYear(), d.getMonth(), 0)
    return { start: new CalendarDate(first.getFullYear(), first.getMonth() + 1, first.getDate()), end: new CalendarDate(last.getFullYear(), last.getMonth() + 1, last.getDate()) }
  }},
  { key: 'thisYear', label: 'Bu Yıl', fn: () => {
    const y = new Date().getFullYear()
    return { start: new CalendarDate(y, 1, 1), end: new CalendarDate(y, 12, 31) }
  }},
  { key: 'lastYear', label: 'Önceki Yıl', fn: () => {
    const y = new Date().getFullYear() - 1
    return { start: new CalendarDate(y, 1, 1), end: new CalendarDate(y, 12, 31) }
  }},
]

function applyPreset(preset: typeof presets[0]) {
  dateRange.value = preset.fn()
  activePreset.value = preset.key
  datePopoverOpen.value = false
}

const months = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara']

// Tarih araligindaki tüm yil-ay ikililerini sirayla uret: [{year, month, key, label}, ...]
function buildMonthBuckets(start: any, end: any): { year: number; month: number; key: string; label: string }[] {
  if (!start || !end) return []
  const startDate = new Date(start.year, start.month - 1, 1)
  const endDate = new Date(end.year, end.month - 1, 1)
  const buckets: { year: number; month: number; key: string; label: string }[] = []
  const cur = new Date(startDate)
  const sameYear = start.year === end.year
  while (cur <= endDate) {
    const y = cur.getFullYear()
    const m = cur.getMonth() + 1
    buckets.push({
      year: y,
      month: m,
      key: `${y}-${m}`,
      label: sameYear ? months[m - 1] : `${months[m - 1]} ${y}`
    })
    cur.setMonth(cur.getMonth() + 1)
  }
  return buckets
}

const monthBuckets = computed(() => buildMonthBuckets(dateRange.value?.start, dateRange.value?.end))

// Data
const data = ref<any>(null)

async function fetchReport() {
  loading.value = true
  try {
    const params: Record<string, string> = {}
    const from = calendarDateToStr(dateRange.value?.start)
    const to = calendarDateToStr(dateRange.value?.end)
    if (from) params.dateFrom = from
    if (to) params.dateTo = to
    const res = await get<any>('reports', params)
    data.value = res.data || res
  } catch {
  } finally {
    loading.value = false
  }
}

watch(dateRange, (val) => {
  if (val?.start && val?.end) fetchReport()
}, { deep: true })
onMounted(fetchReport)

function fmt(n: number): string {
  return n.toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
}

function fmtCurrency(n: number): string {
  return n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' TL'
}

// Colors
const palette = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#f97316', '#14b8a6', '#6366f1', '#84cc16', '#e11d48']
const paletteBg = palette.map(c => c + '20')

// --- Chart configs ---

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (ctx: any) => {
          const v = ctx.parsed?.y ?? ctx.parsed
          return typeof v === 'number' ? fmtCurrency(v) : v
        }
      }
    }
  },
  scales: {
    y: {
      ticks: {
        callback: (v: any) => fmt(v / 1000) + 'K'
      }
    }
  }
}

const doughnutOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { position: 'right' as const, labels: { boxWidth: 12, padding: 8, font: { size: 11 } } },
    tooltip: {
      callbacks: {
        label: (ctx: any) => {
          const label = ctx.label || ''
          const v = ctx.parsed
          return `${label}: ${fmtCurrency(v)}`
        }
      }
    }
  }
}

// Monthly line/bar — tarih araligina gore dinamik bucket
const monthlyChartData = computed(() => {
  if (!data.value) return null
  const buckets = monthBuckets.value
  if (!buckets.length) return null

  const currentMap = new Map<string, number>()
  const countMap = new Map<string, number>()
  for (const m of data.value.monthly || []) {
    currentMap.set(`${m.year}-${m.month}`, m.premium)
    countMap.set(`${m.year}-${m.month}`, m.count)
  }

  // Onceki donem: siralanmis, bucket ile ayni uzunlukta karsilastir
  const prevSorted = [...(data.value.monthlyPrev || [])].sort((a, b) => (a.year - b.year) || (a.month - b.month))
  const prevArr = buckets.map((_, i) => prevSorted[i]?.premium || 0)

  const current = buckets.map(b => currentMap.get(b.key) || 0)
  const labels = buckets.map(b => b.label)

  return {
    labels,
    datasets: [
      {
        label: 'Seçili Dönem',
        data: current,
        backgroundColor: '#3b82f6',
        borderColor: '#3b82f6',
        borderWidth: 2,
        borderRadius: 4,
      },
      {
        label: 'Önceki Dönem',
        data: prevArr,
        backgroundColor: '#e2e8f0',
        borderColor: '#94a3b8',
        borderWidth: 1,
        borderRadius: 4,
      }
    ]
  }
})

const monthlyBarOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: true, position: 'top' as const, labels: { boxWidth: 12, padding: 16, font: { size: 11 } } },
    tooltip: {
      callbacks: {
        label: (ctx: any) => `${ctx.dataset.label}: ${fmtCurrency(ctx.parsed.y)}`
      }
    }
  },
  scales: {
    y: { ticks: { callback: (v: any) => fmt(v / 1000) + 'K' } }
  }
}))

// Monthly count line — bucket bazli
const monthlyCountData = computed(() => {
  if (!data.value) return null
  const buckets = monthBuckets.value
  if (!buckets.length) return null

  const countMap = new Map<string, number>()
  for (const m of data.value.monthly || []) {
    countMap.set(`${m.year}-${m.month}`, m.count)
  }
  const current = buckets.map(b => countMap.get(b.key) || 0)
  const labels = buckets.map(b => b.label)

  return {
    labels,
    datasets: [{
      label: 'Adet',
      data: current,
      borderColor: '#10b981',
      backgroundColor: '#10b98120',
      fill: true,
      tension: 0.3,
      pointBackgroundColor: '#10b981',
      pointRadius: 4,
    }]
  }
})

const lineOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false }, tooltip: {} },
  scales: { y: { beginAtZero: true } }
}

// Company doughnut
const companyChartData = computed(() => {
  if (!data.value) return null
  const items = (data.value.byCompany || []).slice(0, 8)
  return {
    labels: items.map((c: any) => c.name),
    datasets: [{
      data: items.map((c: any) => c.premium),
      backgroundColor: palette.slice(0, items.length),
      borderWidth: 0,
    }]
  }
})

// Insurance group doughnut
const groupChartData = computed(() => {
  if (!data.value) return null
  const items = data.value.byGroup || []
  return {
    labels: items.map((g: any) => g.group),
    datasets: [{
      data: items.map((g: any) => g.premium),
      backgroundColor: palette.slice(0, items.length),
      borderWidth: 0,
    }]
  }
})

// Prod doughnut
const prodChartData = computed(() => {
  if (!data.value) return null
  const items = data.value.byProd || []
  return {
    labels: items.map((p: any) => p.prodLabel),
    datasets: [{
      data: items.map((p: any) => p.premium),
      backgroundColor: ['#3b82f6', '#10b981', '#f59e0b'],
      borderWidth: 0,
    }]
  }
})

// Active tab
const activeTab = ref('overview')
const tabs = [
  { label: 'Genel Bakış', value: 'overview', icon: 'i-lucide-layout-dashboard' },
  { label: 'Müşteri Bazlı', value: 'customer', icon: 'i-lucide-users' },
  { label: 'Şirket Bazlı', value: 'company', icon: 'i-lucide-building-2' },
  { label: 'Branş Bazlı', value: 'insurance', icon: 'i-lucide-shield' },
  { label: 'Üretim Bazlı', value: 'production', icon: 'i-lucide-factory' },
]

// Total premium bar by insurance
const insuranceBarData = computed(() => {
  if (!data.value) return null
  const items = (data.value.byInsurance || []).slice(0, 10)
  return {
    labels: items.map((i: any) => formatCompanyName(i.name || '', 'compact')),
    datasets: [{
      label: 'Brüt Prim',
      data: items.map((i: any) => i.premium),
      backgroundColor: palette.slice(0, items.length),
      borderRadius: 4,
    }]
  }
})

const horizontalBarOptions = {
  responsive: true,
  maintainAspectRatio: false,
  indexAxis: 'y' as const,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (ctx: any) => fmtCurrency(ctx.parsed.x)
      }
    }
  },
  scales: {
    x: { ticks: { callback: (v: any) => fmt(v / 1000) + 'K' } }
  }
}

// Customer bar
const customerBarData = computed(() => {
  if (!data.value) return null
  const items = (data.value.byCustomer || []).slice(0, 15)
  return {
    labels: items.map((c: any) => formatPersonName(c.name || '', 'compact')),
    datasets: [{
      label: 'Brüt Prim',
      data: items.map((c: any) => c.premium),
      backgroundColor: '#3b82f6',
      borderRadius: 4,
    }]
  }
})

// Company bar
const companyBarData = computed(() => {
  if (!data.value) return null
  const items = (data.value.byCompany || []).slice(0, 10)
  return {
    labels: items.map((c: any) => formatCompanyName(c.name || '', 'compact')),
    datasets: [{
      label: 'Brüt Prim',
      data: items.map((c: any) => c.premium),
      backgroundColor: '#10b981',
      borderRadius: 4,
    }]
  }
})

// Branch bar
const branchBarData = computed(() => {
  if (!data.value) return null
  const items = (data.value.byBranch || []).slice(0, 10)
  return {
    labels: items.map((b: any) => formatCompanyName(b.name || '', 'compact')),
    datasets: [{
      label: 'Brüt Prim',
      data: items.map((b: any) => b.premium),
      backgroundColor: '#8b5cf6',
      borderRadius: 4,
    }]
  }
})
</script>

<template>
  <div class="p-4 sm:p-6 space-y-4">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold">Raporlar</h1>
      <div class="flex items-center gap-2 flex-wrap">
        <UButton
          v-for="p in presets"
          :key="p.key"
          :label="p.label"
          size="xs"
          :color="activePreset === p.key ? 'primary' : 'neutral'"
          :variant="activePreset === p.key ? 'solid' : 'outline'"
          @click="applyPreset(p)"
        />
        <UPopover v-model:open="datePopoverOpen" class="hidden sm:flex">
          <UButton
            :label="dateRangeLabel"
            icon="i-lucide-calendar-range"
            color="neutral"
            variant="outline"
            size="xs"
           
          />
          <template #content>
            <UCalendar locale="tr-TR"
              v-model="dateRange"
              range
              :number-of-months="2"
              class="p-2"
              @update:model-value="(v: any) => { if (v?.start && v?.end) { datePopoverOpen = false; activePreset = '' } }"
            />
          </template>
        </UPopover>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="space-y-4">
      <div class="grid grid-cols-2 xl:grid-cols-4 gap-3">
        <SkeletonCard v-for="i in 4" :key="i" />
      </div>
      <UCard>
        <SkeletonTable :rows="8" :cols="6" />
      </UCard>
    </div>

    <template v-else-if="data">
      <!-- Summary Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <UCard :ui="{ body: 'p-3' }">
          <div class="text-center">
            <p class="kpi-value">{{ fmt(data.totals.count) }}</p>
            <p class="text-xs text-muted mt-1">Toplam Poliçe</p>
          </div>
        </UCard>
        <UCard :ui="{ body: 'p-3' }">
          <div class="text-center">
            <p class="kpi-value text-green-600 dark:text-green-400">{{ fmtCurrency(data.totals.premium) }}</p>
            <p class="text-xs text-muted mt-1">Brüt Prim</p>
          </div>
        </UCard>
        <UCard :ui="{ body: 'p-3' }">
          <div class="text-center">
            <p class="kpi-value text-emerald-600 dark:text-emerald-400">{{ fmtCurrency(data.totals.net) }}</p>
            <p class="text-xs text-muted mt-1">Net Prim</p>
          </div>
        </UCard>
        <UCard :ui="{ body: 'p-3' }">
          <div class="text-center">
            <p class="kpi-value text-red-600 dark:text-red-400">{{ fmt(data.totals.cancelled) }}</p>
            <p class="text-xs text-muted mt-1">İptal</p>
          </div>
        </UCard>
      </div>

      <!-- Tabs -->
      <div class="flex gap-1 border-b border-default overflow-x-auto">
        <button
          v-for="tab in tabs"
          :key="tab.value"
          class="items-center gap-1.5 px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 transition-colors -mb-px"
          :class="[
            activeTab === tab.value ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-foreground',
            ['company', 'insurance', 'production'].includes(tab.value) ? 'hidden sm:flex' : 'flex'
          ]"
          @click="activeTab = tab.value"
        >
          <UIcon :name="tab.icon" class="size-4" />
          {{ tab.label }}
        </button>
      </div>

      <!-- ===== OVERVIEW TAB ===== -->
      <template v-if="activeTab === 'overview'">
        <!-- Monthly Premium Bar -->
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-bar-chart-3" class="text-muted size-4" />
              <h3 >Aylık Prim Üretimi (Yıllık Karşılaştırma)</h3>
            </div>
          </template>
          <div class="h-72">
            <Bar v-if="monthlyChartData" :data="monthlyChartData" :options="monthlyBarOptions" />
          </div>
        </UCard>

        <!-- Monthly Count + Prod/Group split -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <UCard>
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-trending-up" class="text-muted size-4" />
                <h3 >Aylık Poliçe Adedi</h3>
              </div>
            </template>
            <div class="h-56">
              <Line v-if="monthlyCountData" :data="monthlyCountData" :options="lineOptions" />
            </div>
          </UCard>

          <UCard>
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-pie-chart" class="text-muted size-4" />
                <h3 >Branş Grupları Dağılımı</h3>
              </div>
            </template>
            <div class="h-56">
              <Doughnut v-if="groupChartData" :data="groupChartData" :options="doughnutOptions" />
            </div>
          </UCard>
        </div>

        <!-- Prod + Company doughnut -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <UCard>
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-factory" class="text-muted size-4" />
                <h3 >Üretim Tipi Dağılımı</h3>
              </div>
            </template>
            <div class="h-56">
              <Doughnut v-if="prodChartData" :data="prodChartData" :options="doughnutOptions" />
            </div>
          </UCard>

          <UCard>
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-building-2" class="text-muted size-4" />
                <h3 >Şirket Dağılımı</h3>
              </div>
            </template>
            <div class="h-56">
              <Doughnut v-if="companyChartData" :data="companyChartData" :options="doughnutOptions" />
            </div>
          </UCard>
        </div>
      </template>

      <!-- ===== CUSTOMER TAB ===== -->
      <template v-if="activeTab === 'customer'">
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
          <!-- Chart -->
          <UCard class="lg:col-span-3">
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-users" class="text-muted size-4" />
                <h3 >En Yüksek Primli Müşteriler (Top 15)</h3>
              </div>
            </template>
            <div class="h-[28rem]">
              <Bar v-if="customerBarData" :data="customerBarData" :options="horizontalBarOptions" />
            </div>
          </UCard>

          <!-- Table -->
          <UCard class="lg:col-span-2">
            <template #header>
              <h3 >Müşteri Detayları</h3>
            </template>
            <div class="overflow-y-auto max-h-[28rem]">
              <table class="w-full text-xs">
                <thead>
                  <tr class="border-b border-default">
                    <th class="text-left py-2 px-2 font-semibold tracking-wide text-muted">Müşteri</th>
                    <th class="text-right py-2 px-2 font-semibold tracking-wide text-muted">Adet</th>
                    <th class="text-right py-2 px-2 font-semibold tracking-wide text-muted">Brüt Prim</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(c, idx) in data.byCustomer" :key="idx" class="border-b border-default last:border-0">
                    <td class="py-2 px-2 max-w-[140px]">
                      <div class="flex items-center min-w-0">
                        <span class="inline-flex items-center justify-center size-5 rounded-full text-[10px] font-bold mr-1.5 shrink-0" :class="idx < 3 ? 'bg-primary/10 text-primary' : 'bg-gray-100 dark:bg-gray-800 text-muted'">{{ idx + 1 }}</span>
                        <span class="overflow-hidden whitespace-nowrap" :title="c.name">{{ c.name }}</span>
                      </div>
                    </td>
                    <td class="py-2 px-2 text-right font-medium">{{ c.count }}</td>
                    <td class="py-2 px-2 text-right font-medium">{{ fmtCurrency(c.premium) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </UCard>
        </div>
      </template>

      <!-- ===== COMPANY TAB ===== -->
      <template v-if="activeTab === 'company'">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <!-- Company Bar -->
          <UCard>
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-building-2" class="text-muted size-4" />
                <h3 >Sigorta Şirketleri - Prim Dağılımı</h3>
              </div>
            </template>
            <div class="h-80">
              <Bar v-if="companyBarData" :data="companyBarData" :options="horizontalBarOptions" />
            </div>
          </UCard>

          <!-- Company Doughnut -->
          <UCard>
            <template #header>
              <h3 >Şirket Payı</h3>
            </template>
            <div class="h-80">
              <Doughnut v-if="companyChartData" :data="companyChartData" :options="doughnutOptions" />
            </div>
          </UCard>
        </div>

        <!-- Company Table -->
        <UCard>
          <template #header>
            <h3 >Şirket Detayları</h3>
          </template>
          <div class="border border-default rounded-lg overflow-hidden">
            <table class="text-xs w-full table-fixed">
              <thead class="sticky top-0 z-10">
                <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                  <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted">Şirket</th>
                  <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">Brüt Prim</th>
                  <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">Oran</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(c, idx) in data.byCompany" :key="idx" class="border-b border-default last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                  <td class="py-2 px-3 font-medium">{{ c.name }}</td>
                  <td class="py-2 px-3 text-right font-medium">{{ fmtCurrency(c.premium) }}</td>
                  <td class="py-2 px-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                      <div class="w-16 h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                        <div class="h-full rounded-full bg-green-500" :style="{ width: (data.totals.premium > 0 ? (c.premium / data.totals.premium * 100) : 0) + '%' }" />
                      </div>
                      <span class="text-xs text-muted w-10 text-right">{{ data.totals.premium > 0 ? (c.premium / data.totals.premium * 100).toFixed(1) : 0 }}%</span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </UCard>
      </template>

      <!-- ===== INSURANCE TAB ===== -->
      <template v-if="activeTab === 'insurance'">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <!-- Insurance horizontal bar -->
          <UCard>
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-shield" class="text-muted size-4" />
                <h3 >Branş Bazlı Prim (Top 10)</h3>
              </div>
            </template>
            <div class="h-80">
              <Bar v-if="insuranceBarData" :data="insuranceBarData" :options="horizontalBarOptions" />
            </div>
          </UCard>

          <!-- Group doughnut -->
          <UCard>
            <template #header>
              <h3 >Grup Bazlı Dağılım</h3>
            </template>
            <div class="h-80">
              <Doughnut v-if="groupChartData" :data="groupChartData" :options="doughnutOptions" />
            </div>
          </UCard>
        </div>

        <!-- Insurance Table -->
        <UCard>
          <template #header>
            <h3 >Branş Detayları</h3>
          </template>
          <div class="border border-default rounded-lg overflow-hidden">
            <table class="text-xs w-full table-fixed">
              <thead class="sticky top-0 z-10">
                <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                  <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:5%">#</th>
                  <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:20%">Poliçe Türü</th>
                  <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:12%">Grup</th>
                  <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:10%">Poliçe</th>
                  <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:18%">Brüt Prim</th>
                  <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:18%">Net Prim</th>
                  <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:17%">Oran</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(ins, idx) in data.byInsurance" :key="idx" class="border-b border-default last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                  <td class="py-2 px-3 text-muted">{{ idx + 1 }}</td>
                  <td class="py-2 px-3 font-medium">{{ ins.name }}</td>
                  <td class="py-2 px-3">
                    <span class="badge-cell badge-info">{{ ins.group }}</span>
                  </td>
                  <td class="py-2 px-3 text-right">{{ ins.count }}</td>
                  <td class="py-2 px-3 text-right font-medium">{{ fmtCurrency(ins.premium) }}</td>
                  <td class="py-2 px-3 text-right">{{ fmtCurrency(ins.net) }}</td>
                  <td class="py-2 px-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                      <div class="w-16 h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                        <div class="h-full rounded-full bg-blue-500" :style="{ width: (data.totals.premium > 0 ? (ins.premium / data.totals.premium * 100) : 0) + '%' }" />
                      </div>
                      <span class="text-xs text-muted w-10 text-right">{{ data.totals.premium > 0 ? (ins.premium / data.totals.premium * 100).toFixed(1) : 0 }}%</span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </UCard>

        <!-- Group Summary Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
          <UCard v-for="(g, idx) in data.byGroup" :key="idx">
            <div class="flex items-center gap-3">
              <div class="w-1 h-8 rounded-full" :style="{ backgroundColor: palette[idx % palette.length] }" />
              <div class="min-w-0">
                <p class="text-sm font-semibold">{{ g.group }}</p>
                <p class="text-xs text-muted">{{ g.count }} poliçe</p>
                <p class="text-xs font-medium mt-0.5">{{ fmtCurrency(g.premium) }}</p>
              </div>
            </div>
          </UCard>
        </div>
      </template>

      <!-- ===== PRODUCTION TAB ===== -->
      <template v-if="activeTab === 'production'">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <!-- Prod Doughnut -->
          <UCard>
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-factory" class="text-muted size-4" />
                <h3 >Üretim Tipi Dağılımı</h3>
              </div>
            </template>
            <div class="h-64">
              <Doughnut v-if="prodChartData" :data="prodChartData" :options="doughnutOptions" />
            </div>
          </UCard>

          <!-- Prod Cards -->
          <div class="lg:col-span-2 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <UCard v-for="(p, idx) in data.byProd" :key="idx">
              <div class="space-y-3">
                <div class="flex items-center gap-2">
                  <div class="size-3 rounded-full" :style="{ backgroundColor: ['#3b82f6', '#10b981', '#f59e0b'][idx] }" />
                  <p >{{ p.prodLabel }}</p>
                </div>
                <div class="space-y-2">
                  <div class="flex justify-between text-sm">
                    <span class="text-muted">Poliçe</span>
                    <span class="font-bold text-lg">{{ fmt(p.count) }}</span>
                  </div>
                  <div class="flex justify-between text-sm">
                    <span class="text-muted">Brüt Prim</span>
                    <span class="font-medium">{{ fmtCurrency(p.premium) }}</span>
                  </div>
                  <div class="flex justify-between text-sm">
                    <span class="text-muted">Net Prim</span>
                    <span class="font-medium">{{ fmtCurrency(p.net) }}</span>
                  </div>
                  <div class="w-full h-2 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                    <div
                      class="h-full rounded-full"
                      :style="{ width: (data.totals.count > 0 ? (p.count / data.totals.count * 100) : 0) + '%', backgroundColor: ['#3b82f6', '#10b981', '#f59e0b'][idx] }"
                    />
                  </div>
                  <p class="text-[11px] text-muted text-right">
                    {{ data.totals.count > 0 ? (p.count / data.totals.count * 100).toFixed(1) : 0 }}% toplam
                  </p>
                </div>
              </div>
            </UCard>
          </div>
        </div>

        <!-- Branch (Acente) section -->
        <template v-if="data.byBranch && data.byBranch.length > 0">
          <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
            <UCard class="lg:col-span-3">
              <template #header>
                <div class="flex items-center gap-2">
                  <UIcon name="i-lucide-store" class="text-muted size-4" />
                  <h3 >Acente Bazlı Prim</h3>
                </div>
              </template>
              <div class="h-72">
                <Bar v-if="branchBarData" :data="branchBarData" :options="horizontalBarOptions" />
              </div>
            </UCard>

            <UCard class="lg:col-span-2">
              <template #header>
                <h3 >Acente Detayları</h3>
              </template>
              <div class="overflow-y-auto max-h-72">
                <table class="w-full text-xs">
                  <thead>
                    <tr class="border-b border-default">
                      <th class="text-left py-2 px-2 font-semibold tracking-wide text-muted">Acente</th>
                      <th class="text-right py-2 px-2 font-semibold tracking-wide text-muted">Adet</th>
                      <th class="text-right py-2 px-2 font-semibold tracking-wide text-muted">Brüt Prim</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(b, idx) in data.byBranch" :key="idx" class="border-b border-default last:border-0">
                      <td class="py-2 px-2 max-w-[120px]">{{ b.name }}</td>
                      <td class="py-2 px-2 text-right">{{ b.count }}</td>
                      <td class="py-2 px-2 text-right font-medium">{{ fmtCurrency(b.premium) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </UCard>
          </div>
        </template>
      </template>
    </template>
  </div>
</template>

<style scoped>
table td { overflow: hidden; text-overflow: clip; white-space: nowrap; }

</style>
