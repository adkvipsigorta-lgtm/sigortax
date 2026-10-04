<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Acentemiz Hakkında' })

const { get } = useApi()
const { agency } = useAgency()

const loading = ref(true)
const data = ref<any>(null)

async function fetchStats() {
  loading.value = true
  try {
    const res = await get('agency/stats')
    data.value = res.data
  } catch {
    data.value = null
  } finally {
    loading.value = false
  }
}

onMounted(fetchStats)

function formatCurrency(val: number) {
  if (val >= 1_000_000) return (val / 1_000_000).toFixed(1).replace('.', ',') + 'M'
  if (val >= 1_000) return (val / 1_000).toFixed(0) + 'K'
  return val.toFixed(0)
}

function formatFullCurrency(val: number) {
  return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(val)
}

function getFirstYear() {
  if (!data.value?.firstPolicyDate) return '2022'
  return data.value.firstPolicyDate.substring(0, 4)
}

function getYearCount() {
  const first = parseInt(getFirstYear())
  return new Date().getFullYear() - first
}

// Buyume oranlarini hesapla
function getGrowthRates() {
  if (!data.value?.yearly?.length) return []
  const rates: { year: number; rate: number }[] = []
  const y = data.value.yearly
  for (let i = 1; i < y.length; i++) {
    const prev = parseFloat(y[i - 1].gross_premium)
    const curr = parseFloat(y[i].gross_premium)
    if (prev > 0) {
      rates.push({ year: parseInt(y[i].year), rate: ((curr - prev) / prev) * 100 })
    }
  }
  return rates
}

// Bireysel / Kurumsal oran
function getIndividualRate() {
  if (!data.value?.customerTypes?.length) return '0'
  const ind = data.value.customerTypes.find((t: any) => t.customer_type === 'INDIVIDUAL')
  const total = data.value.customerTypes.reduce((s: number, t: any) => s + parseInt(t.count), 0)
  return total > 0 ? ((parseInt(ind?.count || 0) / total) * 100).toFixed(0) : '0'
}

function getCorporateRate() {
  if (!data.value?.customerTypes?.length) return '0'
  const corp = data.value.customerTypes.find((t: any) => t.customer_type === 'CORPORATE')
  const total = data.value.customerTypes.reduce((s: number, t: any) => s + parseInt(t.count), 0)
  return total > 0 ? ((parseInt(corp?.count || 0) / total) * 100).toFixed(0) : '0'
}

// Belirli bir bransin yillara gore oran degisimini bul
function getBranchRatio(branchName: string, year: number) {
  if (!data.value?.portfolioTrend?.length) return 0
  const entry = data.value.portfolioTrend.find((t: any) => t.year === year)
  if (!entry?.branches) return 0
  const branch = entry.branches.find((b: any) => b.name === branchName)
  return branch?.ratio || 0
}

// Trafik oranindaki peak ve guncel degisim
function getTrafikTransformation() {
  if (!data.value?.portfolioTrend?.length) return { peak: 0, peakYear: 0, current: 0, currentYear: 0 }
  let peak = 0, peakYear = 0
  for (const entry of data.value.portfolioTrend) {
    const trafik = entry.branches?.find((b: any) => b.name.includes('TRAFİK') || b.name.includes('Trafik'))
    const ratio = trafik?.ratio || 0
    if (ratio > peak) { peak = ratio; peakYear = entry.year }
  }
  const last = data.value.portfolioTrend[data.value.portfolioTrend.length - 1]
  const lastTrafik = last?.branches?.find((b: any) => b.name.includes('TRAFİK') || b.name.includes('Trafik'))
  return { peak, peakYear, current: lastTrafik?.ratio || 0, currentYear: last?.year || 0 }
}

// Portfolio trend'deki brans isimleri
function getTrendBranches() {
  if (!data.value?.portfolioTrend?.length) return []
  const first = data.value.portfolioTrend[0]
  return first?.branches?.map((b: any) => b.name) || []
}

// Top 5 brans yuzdeleri
function getBranchPercentages() {
  if (!data.value?.currentBranches?.length) return []
  const total = data.value.currentBranches.reduce((s: number, b: any) => s + parseFloat(b.gross_premium), 0)
  return data.value.currentBranches.slice(0, 6).map((b: any) => ({
    name: b.name,
    percentage: total > 0 ? ((parseFloat(b.gross_premium) / total) * 100).toFixed(1) : '0',
    premium: parseFloat(b.gross_premium)
  }))
}

// Saglik, Trafik, Kasko, Konut&DASK, Isyeri, Diger
const branchColors = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ef4444', '#9ca3af']
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-8 pb-12">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-32">
      <UIcon name="i-lucide-loader-2" class="size-6 animate-spin text-primary" />
    </div>

    <template v-else-if="data">
      <!-- Hero Section -->
      <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/10 via-primary/5 to-transparent border border-primary/20 p-8 md:p-12">
        <div class="absolute top-0 right-0 w-64 h-64 bg-primary/5 rounded-full -translate-y-1/2 translate-x-1/2" />
        <div class="relative">
          <div class="flex items-center gap-3 mb-4">
            <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center">
              <UIcon name="i-lucide-building-2" class="size-6 text-primary" />
            </div>
            <div>
              <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ agency.name }}</h1>
              <p class="text-sm text-muted">Acentemiz Hakkında</p>
            </div>
          </div>
          <p class="text-lg text-gray-600 dark:text-gray-300 max-w-3xl leading-relaxed">
            {{ getFirstYear() }} yılından bu yana sigorta sektöründe güvenin ve profesyonelliğin adresi.
            {{ getYearCount() }} yıllık tecrübemizle <strong>{{ formatFullCurrency(data.totalCustomers) }}</strong> müşterimize hizmet veriyor,
            <strong>{{ formatCurrency(data.totalPremium) }} TL</strong> toplam üretim hacmine ulaşmış bulunuyoruz.
          </p>
        </div>
      </div>

      <!-- Özet Kartlar -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <UCard :ui="{ body: 'p-3' }">
          <div class="text-center">
            <p class="kpi-value text-primary">{{ getYearCount() }}</p>
            <p class="text-xs text-muted mt-1">Yıllık Tecrübe</p>
          </div>
        </UCard>
        <UCard :ui="{ body: 'p-3' }">
          <div class="text-center">
            <p class="kpi-value text-blue-600 dark:text-blue-400">{{ formatFullCurrency(data.totalCustomers) }}</p>
            <p class="text-xs text-muted mt-1">Toplam Müşteri</p>
          </div>
        </UCard>
        <UCard :ui="{ body: 'p-3' }">
          <div class="text-center">
            <p class="kpi-value text-green-600 dark:text-green-400">{{ formatFullCurrency(data.activePolicies) }}</p>
            <p class="text-xs text-muted mt-1">Aktif Poliçe</p>
          </div>
        </UCard>
        <UCard :ui="{ body: 'p-3' }">
          <div class="text-center">
            <p class="kpi-value text-amber-600 dark:text-amber-400">{{ formatCurrency(data.totalPremium) }} <span class="text-base font-normal">TL</span></p>
            <p class="text-xs text-muted mt-1">Toplam Üretim</p>
          </div>
        </UCard>
      </div>

      <!-- Bolum 1: Koklu Gecmisimiz -->
      <section class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="size-10 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
            <UIcon name="i-lucide-history" class="size-5 text-amber-600 dark:text-amber-400" />
          </div>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Köklü Geçmişimiz ve İlk Günün Heyecanı</h2>
        </div>
        <div class="prose dark:prose-invert max-w-none text-gray-600 dark:text-gray-300 leading-relaxed space-y-4">
          <p>
            {{ agency.name }}'nın hikayesi, <strong>{{ getFirstYear() }} yılında</strong> kesilen ilk poliçeyle başladı.
            O günkü heyecan ve müşterilerimize en doğru teminatı sunma azmi, bugün hâlâ her poliçemizin temelini oluşturuyor.
            İlk günden itibaren "doğru risk analizi, doğru teminat, doğru fiyat" felsefesiyle yola çıktık.
          </p>
          <p>
            Kuruluşumuzdan bu yana geçen <strong>{{ getYearCount() }} yıl</strong> boyunca, sektördeki değişimlere hızla uyum sağlayarak
            kendimizi sürekli geliştirdik. Her müşterimizi bir aile ferdi gibi gördük; onların ihtiyaçlarını anlamak,
            en uygun çözümleri sunmak ve uzun vadeli güven ilişkisi kurmak en büyük önceliğimiz oldu.
          </p>
        </div>
      </section>

      <!-- Bolum 2: Yillara Sari Buyume -->
      <section class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="size-10 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
            <UIcon name="i-lucide-bar-chart-3" class="size-5 text-green-600 dark:text-green-400" />
          </div>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Yıllara Sair İstikrarlı Büyümemiz ve Üretim Gücümüz</h2>
        </div>
        <div class="prose dark:prose-invert max-w-none text-gray-600 dark:text-gray-300 leading-relaxed mb-8">
          <p>
            Her geçen yıl portföyümüzü katlanarak büyüttük. İlk yılımızdaki mütevazı başlangıçtan bugün
            <strong>{{ formatCurrency(data.totalPremium) }} TL</strong> toplam prim üretimine ulaştık.
            Bu büyüme, sadece sayısal bir artış değil; müşteri memnuniyetine dayanan organik ve sürdürülebilir bir gelişimdir.
          </p>
        </div>

        <!-- Yillik Uretim Tablosu -->
        <div class="border border-default rounded-lg overflow-hidden">
          <table class="text-xs w-full table-fixed">
            <thead class="sticky top-0 z-10">
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:20%">Yıl</th>
                <th class="py-2 px-3 text-right text-xs font-semibold tracking-wide text-muted" style="width:25%">Poliçe Adedi</th>
                <th class="py-2 px-3 text-right text-xs font-semibold tracking-wide text-muted" style="width:30%">Brüt Prim</th>
                <th class="py-2 px-3 text-right text-xs font-semibold tracking-wide text-muted" style="width:25%">Büyüme</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(y, idx) in data.yearly" :key="y.year"
                  class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                <td class="py-2 px-3 font-medium">{{ y.year }}</td>
                <td class="py-2 px-3 text-right">{{ formatFullCurrency(parseInt(y.count)) }}</td>
                <td class="py-2 px-3 text-right">{{ formatFullCurrency(parseFloat(y.gross_premium)) }} TL</td>
                <td class="py-2 px-3 text-right">
                  <template v-if="getGrowthRates().find(g => g.year === parseInt(y.year))">
                    <span :class="[
                      'inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full',
                      (getGrowthRates().find(g => g.year === parseInt(y.year))?.rate ?? 0) > 0
                        ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                        : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400'
                    ]">
                      <UIcon :name="(getGrowthRates().find(g => g.year === parseInt(y.year))?.rate ?? 0) > 0 ? 'i-lucide-trending-up' : 'i-lucide-trending-down'" class="size-3" />
                      {{ ((getGrowthRates().find(g => g.year === parseInt(y.year))?.rate ?? 0) > 0 ? '+' : '') + (getGrowthRates().find(g => g.year === parseInt(y.year))?.rate ?? 0).toFixed(0) }}%
                    </span>
                  </template>
                  <span v-else class="text-xs text-muted">-</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Buyume Gorseli (basit bar chart) -->
        <div class="mt-8">
          <h3 class="text-sm font-semibold text-muted mb-4 uppercase tracking-wide">Üretim Trendi</h3>
          <div class="flex items-end gap-2 h-40">
            <div v-for="y in data.yearly" :key="y.year"
                 class="flex-1 flex flex-col items-center gap-1">
              <span class="text-[10px] font-semibold text-muted">
                {{ formatCurrency(parseFloat(y.gross_premium)) }}
              </span>
              <div class="w-full bg-primary/80 rounded-t-md transition-all duration-500"
                   :style="{
                     height: Math.max(4, (parseFloat(y.gross_premium) / Math.max(...data.yearly.map((y: any) => parseFloat(y.gross_premium)))) * 120) + 'px'
                   }" />
              <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ y.year }}</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Bolum 3: Musteri Profili -->
      <section class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="size-10 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center">
            <UIcon name="i-lucide-user-check" class="size-5 text-indigo-600 dark:text-indigo-400" />
          </div>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Müşteri Profilimiz ve Sadakat</h2>
        </div>

        <!-- Ozet Kartlar -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
          <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 text-center">
            <div class="kpi-value text-indigo-600 dark:text-indigo-400">
              %{{ getIndividualRate() }}
            </div>
            <div class="text-xs text-muted mt-1">Bireysel Müşteri</div>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 text-center">
            <div class="kpi-value text-gray-900 dark:text-white">
              %{{ getCorporateRate() }}
            </div>
            <div class="text-xs text-muted mt-1">Kurumsal Müşteri</div>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 text-center">
            <div class="kpi-value text-green-600 dark:text-green-400">
              %{{ data.loyalty?.returningRate || 0 }}
            </div>
            <div class="text-xs text-muted mt-1">Tekrar Gelen Müşteri</div>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 text-center">
            <div class="kpi-value text-gray-900 dark:text-white">
              {{ formatFullCurrency(data.loyalty?.y4plus || 0) }}
            </div>
            <div class="text-xs text-muted mt-1">4+ Yıllık Müşteri</div>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Musteri Sadakati Dagilimi -->
          <div>
            <h3 class="text-sm font-semibold text-muted mb-4 uppercase tracking-wide">Müşteri Sadakat Dağılımı</h3>
            <div class="space-y-3">
              <div v-for="seg in [
                { label: '1 Yıl', count: data.loyalty?.y1 || 0, color: '#d1d5db' },
                { label: '2 Yıl', count: data.loyalty?.y2 || 0, color: '#93c5fd' },
                { label: '3 Yıl', count: data.loyalty?.y3 || 0, color: '#6366f1' },
                { label: '4+ Yıl', count: data.loyalty?.y4plus || 0, color: '#4f46e5' },
              ]" :key="seg.label" class="flex items-center gap-3">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-14">{{ seg.label }}</span>
                <div class="flex-1 h-6 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                  <div class="h-full rounded-full flex items-center px-2 transition-all duration-700"
                       :style="{ width: Math.max(2, seg.count / (data.loyalty?.total || 1) * 100) + '%', backgroundColor: seg.color }">
                    <span v-if="seg.count / (data.loyalty?.total || 1) * 100 > 10" class="text-[10px] font-bold text-white whitespace-nowrap">
                      {{ seg.count }}
                    </span>
                  </div>
                </div>
                <span class="text-sm font-semibold text-gray-900 dark:text-white w-14 text-right">
                  %{{ ((seg.count / (data.loyalty?.total || 1)) * 100).toFixed(0) }}
                </span>
              </div>
            </div>
          </div>

          <!-- Ortalama Prim Trendi -->
          <div>
            <h3 class="text-sm font-semibold text-muted mb-4 uppercase tracking-wide">Ortalama Poliçe Primi</h3>
            <div class="flex items-end gap-2 h-36" v-if="data.avgPremium?.length">
              <div v-for="ap in data.avgPremium" :key="ap.year"
                   class="flex-1 flex flex-col items-center gap-1">
                <span class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400">
                  {{ formatCurrency(parseInt(ap.avg_premium)) }}
                </span>
                <div class="w-full bg-indigo-500/80 rounded-t-md transition-all duration-500"
                     :style="{ height: Math.max(4, (parseInt(ap.avg_premium) / Math.max(...data.avgPremium.map((a: any) => parseInt(a.avg_premium)))) * 110) + 'px' }" />
                <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ ap.year }}</span>
              </div>
            </div>
            <p class="text-xs text-muted mt-3 italic">
              Ortalama poliçe primi {{ data.avgPremium?.length ? formatFullCurrency(parseInt(data.avgPremium[0]?.avg_premium || 0)) : '0' }} TL'den
              {{ data.avgPremium?.length ? formatFullCurrency(parseInt(data.avgPremium[data.avgPremium.length - 1]?.avg_premium || 0)) : '0' }} TL'ye yükseldi.
            </p>
          </div>
        </div>
      </section>

      <!-- Bolum 4: Stratejik Donusum -->
      <section class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="size-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
            <UIcon name="i-lucide-shuffle" class="size-5 text-blue-600 dark:text-blue-400" />
          </div>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Pazar Dinamiklerine Uyum: Stratejik Dönüşümümüz</h2>
        </div>
        <div class="prose dark:prose-invert max-w-none text-gray-600 dark:text-gray-300 leading-relaxed space-y-4 mb-8">
          <p>
            Acentemizin en dikkat çekici başarı hikayelerinden biri, ürün portföyümüzdeki bilinçli ve stratejik dönüşümdür.
            <strong>{{ getTrafikTransformation().peakYear }}</strong> yılında toplam üretimimizin
            <strong>%{{ getTrafikTransformation().peak.toFixed(0) }}'ı</strong> Trafik Sigortası'ndan oluşuyordu.
            Bu oran, sektördeki dikey uzmanlığımızın ve pazar hakimiyetimizin açık bir göstergesiydi.
          </p>
          <p>
            Ancak biz, tek bir ürüne bağımlılığın uzun vadede sürdürülebilir olmadığının bilincinde hareket ettik.
            Pazar koşullarını, regülasyon değişikliklerini ve müşteri ihtiyaçlarını analiz ederek portföyümüzü
            <strong>bilinçli bir stratejiyle çeşitlendirdik</strong>. Bugün Trafik Sigortası payımız
            <strong>%{{ getTrafikTransformation().current.toFixed(0) }}</strong>'a gerilerken, Sağlık,
            Kasko, Konut ve İşyeri gibi geniş bir ürün yelpazesinde güçlü bir büyüme yakaladık.
          </p>
          <p>
            Bu dönüşüm, bir gerileme değil; <strong>bilinçli bir pazar yönetimi stratejisi</strong>dir.
            Müşterilerimizin tüm sigorta ihtiyaçlarını tek çatı altında karşılayabilen,
            dengeli ve dayanıklı bir portföy yapısına kavuştuk.
          </p>
        </div>

        <!-- Portfoy Dagilim Trendi (Stacked Bar) -->
        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-5">
          <h3 class="text-sm font-semibold text-muted mb-4 uppercase tracking-wide">
            Yıllara Göre Portföy Dağılımı
          </h3>
          <div class="flex gap-3">
            <div v-for="entry in data.portfolioTrend" :key="entry.year"
                 class="flex-1 flex flex-col items-center gap-1">
              <!-- Stacked bar -->
              <div class="w-full h-40 flex flex-col-reverse rounded-md overflow-hidden">
                <div v-for="(branch, bi) in entry.branches" :key="branch.name"
                     class="w-full transition-all duration-500 relative group"
                     :style="{ height: Math.max(1, branch.ratio) + '%', backgroundColor: branchColors[bi] || '#6b7280' }">
                  <div v-if="branch.ratio >= 10"
                       class="absolute inset-0 flex items-center justify-center text-[9px] font-bold text-white/90">
                    %{{ branch.ratio.toFixed(0) }}
                  </div>
                </div>
              </div>
              <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 mt-1">{{ entry.year }}</span>
            </div>
          </div>
          <!-- Legend -->
          <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-4 text-xs text-muted">
            <span v-for="(name, i) in getTrendBranches()" :key="name" class="flex items-center gap-1">
              <span class="w-2.5 h-2.5 rounded-sm flex-shrink-0" :style="{ backgroundColor: branchColors[i] }" />
              {{ name }}
            </span>
          </div>
        </div>

        <!-- Guncel Brans Dagilimi -->
        <div class="mt-8">
          <h3 class="text-sm font-semibold text-muted mb-4 uppercase tracking-wide">
            {{ data.currentYear }} Yılı Branş Dağılımı
          </h3>
          <div class="space-y-3">
            <div v-for="(b, i) in getBranchPercentages()" :key="b.name" class="flex items-center gap-3">
              <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-32">{{ b.name }}</span>
              <div class="flex-1 h-6 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full flex items-center px-2 transition-all duration-700"
                     :style="{ width: Math.max(2, parseFloat(b.percentage)) + '%', backgroundColor: branchColors[i] || '#6b7280' }">
                  <span v-if="parseFloat(b.percentage) > 8" class="text-[10px] font-bold text-white whitespace-nowrap">
                    {{ formatCurrency(b.premium) }} TL
                  </span>
                </div>
              </div>
              <span class="text-sm font-semibold text-gray-900 dark:text-white w-14 text-right">%{{ b.percentage }}</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Bolum 4: Capraz Satis Analizi -->
      <section v-if="data.crossSell" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="size-10 rounded-lg bg-teal-100 dark:bg-teal-900/30 flex items-center justify-center">
            <UIcon name="i-lucide-git-merge" class="size-5 text-teal-600 dark:text-teal-400" />
          </div>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Çapraz Satış Performansımız</h2>
        </div>
        <div class="prose dark:prose-invert max-w-none text-gray-600 dark:text-gray-300 leading-relaxed mb-8">
          <p>
            Müşterilerimizin sigorta ihtiyaçlarını tek bir ürünle sınırlı tutmak yerine, tüm risklerini kapsayan
            bütünsel çözümler sunuyoruz. Aktif müşterilerimizin <strong>%{{ data.crossSell.crossRate }}'ı</strong>
            birden fazla ürünümüzü tercih ediyor. Müşteri başına ortalama
            <strong>{{ data.crossSell.avgProducts.toFixed(2) }}</strong> farklı ürün sunarak,
            portföyümüzün derinliğini ve müşteri sadakatimizi güçlendiriyoruz.
          </p>
        </div>

        <!-- Ozet Kartlar -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
          <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 text-center">
            <div class="kpi-value text-teal-600 dark:text-teal-400">%{{ data.crossSell.crossRate }}</div>
            <div class="text-xs text-muted mt-1">Çapraz Satış Oranı</div>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 text-center">
            <div class="kpi-value text-gray-900 dark:text-white">{{ data.crossSell.avgProducts.toFixed(2) }}</div>
            <div class="text-xs text-muted mt-1">Ortalama Ürün/Müşteri</div>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 text-center">
            <div class="kpi-value text-gray-900 dark:text-white">{{ formatFullCurrency(data.crossSell.crossSold) }}</div>
            <div class="text-xs text-muted mt-1">Çoklu Ürün Müşterisi</div>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 text-center">
            <div class="kpi-value text-gray-900 dark:text-white">{{ formatFullCurrency(data.crossSell.totalCustomers) }}</div>
            <div class="text-xs text-muted mt-1">Aktif Müşteri</div>
          </div>
        </div>

        <!-- Musteri Dagilimi (kac urun) -->
        <div class="mb-8">
          <h3 class="text-sm font-semibold text-muted mb-8 uppercase tracking-wide">Müşteri Başına Ürün Dağılımı</h3>
          <div class="flex items-end gap-3 h-32">
            <div v-for="(segment, idx) in [
              { label: '1 Ürün', count: data.crossSell.singleProduct, color: 'bg-gray-300 dark:bg-gray-600' },
              { label: '2 Ürün', count: data.crossSell.twoProducts, color: 'bg-teal-400' },
              { label: '3 Ürün', count: data.crossSell.threeProducts, color: 'bg-teal-500' },
              { label: '4+ Ürün', count: data.crossSell.fourPlus, color: 'bg-teal-600' },
            ]" :key="idx" class="flex-1 flex flex-col items-center gap-1">
              <span class="text-[10px] font-bold text-gray-600 dark:text-gray-400">
                {{ segment.count }}
              </span>
              <div :class="['w-full rounded-t-md transition-all duration-500', segment.color]"
                   :style="{ height: Math.max(4, (segment.count / Math.max(data.crossSell.singleProduct, data.crossSell.twoProducts, data.crossSell.threeProducts, data.crossSell.fourPlus)) * 100) + 'px' }" />
              <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ segment.label }}</span>
              <span class="text-[10px] text-muted">
                %{{ (segment.count / data.crossSell.totalCustomers * 100).toFixed(0) }}
              </span>
            </div>
          </div>
        </div>

        <!-- Urun Bazli Capraz Satis Detayi -->
        <div>
          <h3 class="text-sm font-semibold text-muted mb-4 uppercase tracking-wide">Ürün Bazlı Çapraz Satış Detayı</h3>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div v-for="detail in data.crossSell.details" :key="detail.name"
                 class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4">
              <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-bold text-gray-900 dark:text-white">{{ detail.name }}</span>
                <span class="text-xs text-muted">{{ formatFullCurrency(detail.totalCustomers) }} müşteri</span>
              </div>
              <div class="space-y-2">
                <div v-for="cross in detail.crossSold" :key="cross.name" class="flex items-center gap-2">
                  <div class="flex-1 h-5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                    <div class="h-full bg-teal-400/80 rounded-full transition-all duration-500"
                         :style="{ width: Math.max(2, cross.ratio) + '%' }" />
                  </div>
                  <span class="text-xs font-medium text-gray-700 dark:text-gray-300 w-20">{{ cross.name }}</span>
                  <span class="text-xs font-bold text-teal-600 dark:text-teal-400 w-10 text-right">%{{ cross.ratio }}</span>
                </div>
              </div>
            </div>
          </div>
          <p class="text-xs text-muted mt-4 italic">
            * Aktif poliçeler üzerinden hesaplanmıştır. Örneğin Trafik müşterilerinin %{{ data.crossSell.details?.[0]?.crossSold?.[0]?.ratio || 0 }}'{{ (data.crossSell.details?.[0]?.crossSold?.[0]?.ratio || 0) > 10 ? 'ı' : 'i' }} aynı zamanda {{ data.crossSell.details?.[0]?.crossSold?.[0]?.name || '' }} poliçesine de sahiptir.
          </p>
        </div>
      </section>

      <!-- Bolum 5: Is Ortakliklari -->
      <section class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="size-10 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
            <UIcon name="i-lucide-handshake" class="size-5 text-purple-600 dark:text-purple-400" />
          </div>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Güçlü İş Ortaklıklarımız</h2>
        </div>
        <div class="prose dark:prose-invert max-w-none text-gray-600 dark:text-gray-300 leading-relaxed mb-6">
          <p>
            <strong>Allianz Sigorta</strong>'nın yetkili acentesi olarak sektörün en güçlü markasıyla çalışmanın
            ayrıcalığını yaşıyoruz. Bunun yanı sıra farklı sigorta şirketleriyle de çalışarak
            müşterilerimize en geniş teminat ve fiyat seçeneklerini sunuyoruz.
          </p>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
          <div v-for="c in (data.companies || [])" :key="c.name"
               class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3 text-center">
            <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ c.name }}</div>
            <div class="text-xs text-muted mt-1">{{ formatFullCurrency(parseInt(c.count)) }} poliçe</div>
          </div>
        </div>
      </section>

      <!-- Bolum 5: Gelecek Vizyonu -->
      <section class="relative overflow-hidden bg-gradient-to-br from-primary/10 via-blue-50 to-transparent dark:from-primary/5 dark:via-gray-900 dark:to-gray-900 rounded-xl border border-primary/20 p-6 md:p-8">
        <div class="absolute bottom-0 right-0 w-48 h-48 bg-primary/5 rounded-full translate-y-1/2 translate-x-1/2" />
        <div class="relative">
          <div class="flex items-center gap-3 mb-6">
            <div class="size-10 rounded-lg bg-primary/10 flex items-center justify-center">
              <UIcon name="i-lucide-rocket" class="size-5 text-primary" />
            </div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Gelecek Vizyonumuz</h2>
          </div>
          <div class="prose dark:prose-invert max-w-none text-gray-600 dark:text-gray-300 leading-relaxed space-y-4">
            <p>
              {{ agency.name }} olarak geleceğe güvenle bakıyoruz. Dijitalleşme yatırımlarımız, müşteri odaklı hizmet anlayışımız
              ve dengeli portföy stratejimizle büyüme yolculuğumuzu kararlılıkla sürdürüyoruz.
            </p>
            <p>
              Hedefimiz sadece poliçe satmak değil; müşterilerimizin hayatlarını güvence altına alan,
              onlara her an ulaşılabilir olan ve ihtiyaç duydukları anda yanlarında olan
              <strong>güvenilir bir sigorta danışmanı</strong> olmaktır.
            </p>
            <p>
              Teknolojiye yaptığımız yatırımlarla süreçlerimizi hızlandırıyor, müşteri deneyimini iyileştiriyor
              ve sektörün geleceğine öncülük ediyoruz. Bugünün güçlü temelleri üzerinde yarının
              başarı hikayelerini yazmaya devam edeceğiz.
            </p>
          </div>

          <!-- Vizyon hedefleri -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-8">
            <div class="flex items-start gap-3 bg-white/60 dark:bg-gray-800/40 rounded-lg p-4">
              <UIcon name="i-lucide-monitor-smartphone" class="size-5 text-primary mt-0.5 flex-shrink-0" />
              <div>
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Dijital Dönüşüm</div>
                <div class="text-xs text-muted mt-1">Teknoloji odaklı süreçlerle müşteri deneyimini en üst seviyeye taşımak</div>
              </div>
            </div>
            <div class="flex items-start gap-3 bg-white/60 dark:bg-gray-800/40 rounded-lg p-4">
              <UIcon name="i-lucide-heart-handshake" class="size-5 text-primary mt-0.5 flex-shrink-0" />
              <div>
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Müşteri Odaklılık</div>
                <div class="text-xs text-muted mt-1">Her müşteriye özel çözümler, 7/24 erişilebilir danışmanlık hizmeti</div>
              </div>
            </div>
            <div class="flex items-start gap-3 bg-white/60 dark:bg-gray-800/40 rounded-lg p-4">
              <UIcon name="i-lucide-layers" class="size-5 text-primary mt-0.5 flex-shrink-0" />
              <div>
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Ürün Çeşitliliği</div>
                <div class="text-xs text-muted mt-1">Tüm sigorta ihtiyaçlarını tek çatı altında karşılayan geniş ürün yelpazesi</div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
