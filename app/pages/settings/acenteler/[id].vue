<script setup lang="ts">
import { Bar, Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, BarElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js'

ChartJS.register(ArcElement, BarElement, CategoryScale, LinearScale, Tooltip, Legend)

definePageMeta({ layout: 'default', middleware: 'auth' })

const route = useRoute()
const { get } = useApi()
const toast = useToast()

const loading = ref(true)
const branch = ref<any>(null)

async function fetchBranch() {
  loading.value = true
  try {
    const res = await get(`branches/${route.params.id}`)
    branch.value = res.data || res
  } catch {
    toast.add({ title: 'Acente bulunamadı', color: 'error' })
  } finally {
    loading.value = false
  }
}

// Aylık poliçeler
const policyMonth = ref(new Date().getMonth() + 1)
const policyYear = ref(new Date().getFullYear())
const policyDateType = ref('issued_at')
const monthlyPolicies = ref<any[]>([])
const policiesTotals = ref({ count: 0, gross: 0, net: 0, commission: 0, earning: 0 })
const policiesLoading = ref(false)

const aylarFull = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık']
const monthLabel = computed(() => `${aylarFull[policyMonth.value]} ${policyYear.value}`)

function prevMonth() {
  if (policyMonth.value === 1) { policyMonth.value = 12; policyYear.value-- }
  else policyMonth.value--
}
function nextMonth() {
  if (policyMonth.value === 12) { policyMonth.value = 1; policyYear.value++ }
  else policyMonth.value++
}

async function fetchMonthlyPolicies() {
  policiesLoading.value = true
  try {
    const res = await get(`branches/${route.params.id}/policies`, {
      month: policyMonth.value,
      year: policyYear.value,
      dateType: policyDateType.value
    })
    const d = res.data || res
    monthlyPolicies.value = d.policies || []
    policiesTotals.value = d.totals || { count: 0, gross: 0, net: 0, commission: 0, earning: 0 }
  } catch {
    monthlyPolicies.value = []
  } finally {
    policiesLoading.value = false
  }
}

watch([policyMonth, policyYear, policyDateType], () => fetchMonthlyPolicies())

// AI Analiz
const aiAnalysis = ref('')
const aiSummary = ref<any>(null)
const aiLoading = ref(false)
const aiLoaded = ref(false)
const aiOpen = ref(false)

async function fetchAiAnalysis() {
  aiLoading.value = true
  try {
    const res = await get(`branches/${route.params.id}/ai-analysis`)
    const d = res.data || res
    aiAnalysis.value = d.analysis || ''
    aiSummary.value = d.summary || null
    aiLoaded.value = true
  } catch {
    // sessiz hata — veri yoksa kart gizli kalır
  } finally {
    aiLoading.value = false
  }
}

// Özet cümlesini çıkar (ilk paragraf, **Özet** başlığından sonraki metin)
const aiSummaryText = computed(() => {
  if (!aiAnalysis.value) return ''
  const lines = aiAnalysis.value.split('\n').filter((l: string) => l.trim())
  // **Özet** başlığını atla, sonraki ilk dolu satırı al
  for (let i = 0; i < lines.length; i++) {
    if (lines[i].includes('**Özet**') && i + 1 < lines.length) {
      return lines[i + 1].trim()
    }
  }
  return ''
})

// Markdown bold (**text**) → HTML <strong>
function renderMarkdown(text: string): string {
  return text
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\n/g, '<br>')
}

onMounted(() => {
  fetchBranch()
  fetchMonthlyPolicies()
  fetchAiAnalysis()
})

useSeoMeta({ title: computed(() => branch.value?.name || 'Acente Detay') })

function formatCurrency(val: number): string {
  return val.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatDate(d: string): string {
  if (!d) return '-'
  try { return new Date(d).toLocaleDateString('tr-TR') } catch { return d }
}


// Ürün dağılımı chart
const productChartData = computed(() => {
  if (!branch.value?.byProduct?.length) return null
  const items = branch.value.byProduct.slice(0, 8)
  return {
    labels: items.map((p: any) => p.name),
    datasets: [{
      data: items.map((p: any) => p.gross),
      backgroundColor: items.map((p: any) => toHex(p.color)),
      borderWidth: 0
    }]
  }
})

// Aylık üretim chart
const monthlyChartData = computed(() => {
  if (!branch.value?.monthly?.length) return null
  return {
    labels: branch.value.monthly.map((m: any) => m.label),
    datasets: [
      {
        label: 'Brüt Prim',
        data: branch.value.monthly.map((m: any) => m.gross),
        backgroundColor: '#3b82f6',
        borderRadius: 4,
        yAxisID: 'y'
      },
      {
        label: 'Hak Ediş',
        data: branch.value.monthly.map((m: any) => m.earning),
        backgroundColor: '#22c55e',
        borderRadius: 4,
        yAxisID: 'y1'
      }
    ]
  }
})

const chartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
const barOptions = {
  responsive: true, maintainAspectRatio: false,
  plugins: { legend: { display: true, position: 'top' as const, labels: { boxWidth: 12, font: { size: 11 } } } },
  scales: {
    y: {
      position: 'left' as const,
      ticks: { callback: (v: any) => v >= 1000000 ? (v / 1000000).toFixed(1) + 'M' : v >= 1000 ? (v / 1000).toFixed(0) + 'K' : v }
    },
    y1: {
      position: 'right' as const,
      grid: { drawOnChartArea: false },
      ticks: { callback: (v: any) => v >= 1000000 ? (v / 1000000).toFixed(1) + 'M' : v >= 1000 ? (v / 1000).toFixed(0) + 'K' : v }
    },
    x: { ticks: { font: { size: 11 } } }
  }
}

const stats = computed(() => {
  const s = branch.value?.stats
  if (!s) return []
  return [
    { label: 'Toplam Poliçe', value: s.total, icon: 'i-lucide-file-text', color: 'text-blue-500', bg: 'bg-blue-50 dark:bg-blue-900/30' },
    { label: 'Aktif Poliçe', value: s.active, icon: 'i-lucide-shield-check', color: 'text-green-500', bg: 'bg-green-50 dark:bg-green-900/30' },
    { label: 'Aktif Prim', value: formatCurrency(s.activeGross) + ' ₺', icon: 'i-lucide-banknote', color: 'text-purple-500', bg: 'bg-purple-50 dark:bg-purple-900/30' },
    { label: 'İptal', value: s.cancelled, icon: 'i-lucide-x-circle', color: 'text-red-500', bg: 'bg-red-50 dark:bg-red-900/30' },
  ]
})
</script>

<template>
  <div class="space-y-4">
    <!-- Loading -->
    <div v-if="loading" class="space-y-4">
      <div class="animate-pulse h-24 bg-gray-200 dark:bg-gray-700 rounded-xl" />
      <div class="grid grid-cols-4 gap-3">
        <div v-for="i in 4" :key="i" class="animate-pulse h-20 bg-gray-200 dark:bg-gray-700 rounded-xl" />
      </div>
    </div>

    <template v-else-if="branch">
      <!-- Acente Kartı -->
      <UCard>
        <div class="flex items-start justify-between gap-4">
          <div class="flex items-center gap-3 min-w-0">
            <div class="size-12 rounded-xl flex items-center justify-center shrink-0 bg-primary/10">
              <UIcon name="i-lucide-building-2" class="size-6 text-primary" />
            </div>
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <h3 >{{ branch.name }}</h3>
                <UBadge :color="branch.isActive ? 'success' : 'neutral'" variant="solid" size="xs">
                  {{ branch.isActive ? 'Aktif' : 'Pasif' }}
                </UBadge>
              </div>
              <div class="flex flex-wrap items-center gap-3 mt-1 text-xs text-muted">
                <span v-if="branch.phone" class="flex items-center gap-1">
                  <UIcon name="i-lucide-phone" class="size-3.5" />
                  {{ branch.phone }}
                </span>
                <span class="flex items-center gap-1">
                  <UIcon name="i-lucide-percent" class="size-3.5" />
                  Komisyon: %{{ branch.commissionRate }}
                </span>
              </div>
              <div v-if="branch.firstPolicyDate" class="flex items-center gap-1 mt-1 text-xs text-muted">
                <UIcon name="i-lucide-calendar" class="size-3.5" />
                Çalışmaya Başlama: {{ formatDate(branch.firstPolicyDate) }}
              </div>
            </div>
          </div>
          <UButton icon="i-lucide-arrow-left" label="Geri" color="neutral" variant="outline" size="sm" to="/settings/acenteler" />
        </div>

        <!-- İstatistik Kartları -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-4 pt-4 border-t border-default">
          <div
            v-for="stat in stats" :key="stat.label"
            class="flex items-center gap-2.5 p-3 rounded-xl border border-default"
          >
            <div class="size-9 rounded-lg flex items-center justify-center shrink-0" :class="stat.bg">
              <UIcon :name="stat.icon" :class="[stat.color, 'size-4.5']" />
            </div>
            <div>
              <p class="text-lg font-semibold leading-tight" :class="stat.color">{{ stat.value }}</p>
              <p class="text-xs text-muted">{{ stat.label }}</p>
            </div>
          </div>
        </div>
      </UCard>

      <!-- Performans Analizi -->
      <UCard v-if="aiLoaded || aiLoading">
        <template #header>
          <button class="w-full flex items-center justify-between cursor-pointer" @click="aiOpen = !aiOpen">
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-bar-chart-3" class="size-4 text-amber-500" />
              <h3 >Performans Analizi</h3>
            </div>
            <UIcon
              :name="aiOpen ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4 text-muted"
            />
          </button>
        </template>

        <!-- Özet cümlesi — her zaman görünür -->
        <div v-if="aiLoading" class="flex items-center gap-2 text-xs text-muted">
          <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
          Analiz yükleniyor...
        </div>
        <p v-else-if="aiSummaryText" class="text-xs text-muted leading-relaxed">{{ aiSummaryText }}</p>

        <!-- Detay — tıklayınca açılır -->
        <div v-if="aiLoaded && aiOpen" class="mt-4 pt-4 border-t border-default">
          <div class="prose prose-sm dark:prose-invert max-w-none text-xs leading-relaxed" v-html="renderMarkdown(aiAnalysis)" />
        </div>
      </UCard>

      <!-- Grafikler -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Ürün Dağılımı -->
        <UCard v-if="productChartData">
          <template #header>
            <h3 >Ürün Dağılımı</h3>
          </template>
          <div class="flex gap-6">
            <div class="w-40 h-40 shrink-0">
              <Doughnut :data="productChartData" :options="{ ...chartOptions, cutout: '60%' }" />
            </div>
            <div class="flex-1 space-y-1.5 overflow-hidden">
              <div v-for="p in branch.byProduct" :key="p.name" class="flex items-center gap-2 text-xs">
                <span class="size-2.5 rounded-full shrink-0" :style="{ backgroundColor: toHex(p.color) }" />
                <span class="overflow-hidden whitespace-nowrap flex-1">{{ p.name }}</span>
                <span class="text-muted shrink-0">{{ p.count }}</span>
              </div>
            </div>
          </div>
        </UCard>

        <!-- Aylık Üretim -->
        <UCard v-if="monthlyChartData">
          <template #header>
            <h3 >Aylık Üretim (Son 12 Ay)</h3>
          </template>
          <div class="h-48">
            <Bar :data="monthlyChartData" :options="barOptions" />
          </div>
        </UCard>
      </div>

      <!-- Ürün Kırılımı Tablo -->
      <UCard v-if="branch.byProduct?.length">
        <template #header>
          <h3 >Ürün Kırılımı</h3>
        </template>
        <div class="border border-default rounded-lg overflow-hidden">
          <table class="text-xs w-full table-fixed">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Ürün</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Poliçe</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Brüt Prim</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Net Prim</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Komisyon</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Hak Ediş</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Oran</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in branch.byProduct" :key="p.name" class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                <td class="py-2 px-3">
                  <div class="flex items-center gap-2">
                    <span class="size-2.5 rounded-full shrink-0" :style="{ backgroundColor: toHex(p.color) }" />
                    <span >{{ p.name }}</span>
                  </div>
                </td>
                <td class="py-2 px-3 text-right ">{{ p.count }}</td>
                <td class="py-2 px-3 text-right ">{{ formatCurrency(p.gross) }} ₺</td>
                <td class="py-2 px-3 text-right ">{{ formatCurrency(p.net) }} ₺</td>
                <td class="py-2 px-3 text-right ">{{ formatCurrency(p.commission) }} ₺</td>
                <td class="py-2 px-3 text-right font-semibold text-success">{{ formatCurrency(p.earning) }} ₺</td>
                <td class="py-2 px-3 text-right font-medium">
                  %{{ branch.stats.totalGross > 0 ? (p.gross / branch.stats.totalGross * 100).toFixed(1) : '0' }}
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t-2 border-default font-bold">
                <td class="py-2 px-3">TOPLAM</td>
                <td class="py-2 px-3 text-right ">{{ branch.stats.total }}</td>
                <td class="py-2 px-3 text-right ">{{ formatCurrency(branch.stats.totalGross) }} ₺</td>
                <td class="py-2 px-3 text-right ">{{ formatCurrency(branch.stats.totalNet) }} ₺</td>
                <td class="py-2 px-3 text-right ">{{ formatCurrency(branch.byProduct.reduce((s: number, p: any) => s + p.commission, 0)) }} ₺</td>
                <td class="py-2 px-3 text-right font-semibold text-success">{{ formatCurrency(branch.byProduct.reduce((s: number, p: any) => s + p.earning, 0)) }} ₺</td>
                <td class="py-2 px-3 text-right ">%100</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </UCard>

      <!-- Yıllık Üretim (Son 5 Yıl) -->
      <UCard v-if="branch.yearly?.length">
        <template #header>
          <h3 >Yıllık Üretim (Son 5 Yıl)</h3>
        </template>
        <div class="border border-default rounded-lg overflow-hidden">
          <table class="text-xs w-full table-fixed">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Yıl</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Poliçe</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Brüt Prim</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Net Prim</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Komisyon</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Hak Ediş</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="y in branch.yearly" :key="y.year" class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                <td class="py-2 px-3">{{ y.year }}</td>
                <td class="py-2 px-3 text-right ">{{ y.count }}</td>
                <td class="py-2 px-3 text-right ">{{ formatCurrency(y.gross) }} ₺</td>
                <td class="py-2 px-3 text-right ">{{ formatCurrency(y.net) }} ₺</td>
                <td class="py-2 px-3 text-right ">{{ formatCurrency(y.commission) }} ₺</td>
                <td class="py-2 px-3 text-right font-semibold text-success">{{ formatCurrency(y.earning) }} ₺</td>
              </tr>
            </tbody>
          </table>
        </div>
      </UCard>

      <!-- Aylık Poliçeler -->
      <UCard>
        <template #header>
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <h3 >Poliçeler</h3>
            <div class="filter-toolbar">
              <USelect v-model="policyDateType" :items="[{ label: 'Tanzim Tarihi', value: 'issued_at' }, { label: 'Başlangıç Tarihi', value: 'starts_at' }]" :ui="filterDropdownUi" class="filter-w-sm" />
              <div class="flex items-center gap-1 border border-default rounded-lg px-2 h-[var(--height-filter)]">
                <UButton icon="i-lucide-chevron-left" size="xs" color="neutral" variant="ghost" @click="prevMonth" />
                <span class="text-xs font-medium min-w-[110px] text-center whitespace-nowrap">{{ monthLabel }}</span>
                <UButton icon="i-lucide-chevron-right" size="xs" color="neutral" variant="ghost" @click="nextMonth" />
              </div>
            </div>
          </div>
        </template>

        <div v-if="policiesLoading" class="py-8 text-center text-muted text-xs">Yükleniyor...</div>
        <div v-else-if="!monthlyPolicies.length" class="py-8 text-center text-muted text-xs">Bu dönemde poliçe bulunamadı</div>
        <template v-else>
          <!-- Özet -->
          <div class="flex flex-wrap gap-4 mb-4 text-xs">
            <span class="text-muted">{{ policiesTotals.count }} poliçe</span>
            <span>Brüt: <strong>{{ formatCurrency(policiesTotals.gross) }} ₺</strong></span>
            <span>Komisyon: <strong>{{ formatCurrency(policiesTotals.commission) }} ₺</strong></span>
            <span class="text-success">Hak Ediş: {{ formatCurrency(policiesTotals.earning) }} ₺</span>
          </div>

          <div class="border border-default rounded-lg overflow-hidden">
            <table class="text-xs w-full table-fixed">
              <thead>
                <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                  <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Müşteri</th>
                  <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Poliçe No</th>
                  <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Tür</th>
                  <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Şirket</th>
                  <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Plaka</th>
                  <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Net Prim</th>
                  <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Komisyon</th>
                  <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Hak Ediş</th>
                  <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Tarih</th>
                  <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Durum</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="p in monthlyPolicies" :key="p.id"
                  class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors"
                  :class="p.isCancelled ? 'bg-red-50/50 dark:bg-red-900/10' : ''"
                >
                  <td class="py-2 px-3 whitespace-nowrap overflow-hidden"><span class="text-primary overflow-hidden whitespace-nowrap block" :title="p.customerName">{{ formatPersonName(p.customerName || '', 'compact') }}</span></td>
                  <td class="py-2 px-3 whitespace-nowrap overflow-hidden font-mono text-muted">{{ p.policyNo }}</td>
                  <td class="py-2 px-3 whitespace-nowrap overflow-hidden">
                    <span class="badge-cell" :style="insuranceBadgeStyle(p.insuranceColor)">{{ insuranceShortLabel(p.insuranceName) }}</span>
                  </td>
                  <td class="py-2 px-3 whitespace-nowrap overflow-hidden text-muted">{{ formatCompanyName(p.companyName || '', 'compact') }}</td>
                  <td class="py-2 px-3 whitespace-nowrap overflow-hidden">{{ p.plateNo || '—' }}</td>
                  <td class="py-2 px-3 whitespace-nowrap overflow-hidden text-right font-medium">{{ formatCurrency(p.netPremium) }}</td>
                  <td class="py-2 px-3 whitespace-nowrap overflow-hidden text-right ">{{ formatCurrency(p.commission) }}</td>
                  <td class="py-2 px-3 whitespace-nowrap overflow-hidden text-right font-semibold text-success">{{ formatCurrency(p.earning) }}</td>
                  <td class="py-2 px-3 whitespace-nowrap overflow-hidden ">{{ formatDate(policyDateType === 'starts_at' ? p.startsAt : p.issuedAt) }}</td>
                  <td class="py-2 px-3 whitespace-nowrap overflow-hidden">
                    <UBadge v-if="p.isCancelled" color="error" variant="solid" size="xs">İptal</UBadge>
                    <UBadge v-else color="success" variant="solid" size="xs">Aktif</UBadge>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </UCard>
    </template>
  </div>
</template>
