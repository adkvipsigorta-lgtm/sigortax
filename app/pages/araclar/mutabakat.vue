<script setup lang="ts">
import type { Branch } from '~/types'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Tali Acente Mutabakat' })

const toast = useToast()
const { get, post } = useApi()
const { token, user } = useAuth()
const { can } = usePermissions()

if (!can('tools.reconciliation')) {
  navigateTo('/araclar')
}
const { formatCurrency } = usePolicyHelpers()

// Acenteler
const branches = ref<Branch[]>([])
const branchOptions = computed(() => branches.value.map(b => ({ label: b.name, value: b.id })))

async function fetchBranches() {
  try {
    const res = await get<any>('branches?all=1')
    branches.value = res.data || []
  } catch {}
}

onMounted(() => fetchBranches())

// Filtreler
const selectedBranchId = ref<number | null>(null)
const selectedYear = ref(new Date().getFullYear())
const selectedMonth = ref(new Date().getMonth() + 1)
const selectedDateType = ref<'issued_at' | 'starts_at'>('issued_at')

const yearOptions = computed(() => {
  const current = new Date().getFullYear()
  return Array.from({ length: 5 }, (_, i) => ({ label: String(current - i), value: current - i }))
})

const monthOptions = [
  { label: 'Ocak', value: 1 },
  { label: 'Subat', value: 2 },
  { label: 'Mart', value: 3 },
  { label: 'Nisan', value: 4 },
  { label: 'Mayis', value: 5 },
  { label: 'Haziran', value: 6 },
  { label: 'Temmuz', value: 7 },
  { label: 'Agustos', value: 8 },
  { label: 'Eylul', value: 9 },
  { label: 'Ekim', value: 10 },
  { label: 'Kasim', value: 11 },
  { label: 'Aralik', value: 12 }
]

const dateTypeOptions = [
  { label: 'Tanzim Tarihi', value: 'issued_at' },
  { label: 'Başlangıç Tarihi', value: 'starts_at' }
]

// Data
const data = ref<any>(null)
const summaryData = ref<any>(null)
const loading = ref(false)
const locking = ref(false)
const showLockConfirm = ref(false)

const isSummaryMode = computed(() => summaryData.value !== null && data.value === null)
const isLocked = computed(() => data.value?.isLocked === true)

async function fetchReconciliation() {
  loading.value = true
  data.value = null
  summaryData.value = null
  try {
    const params: any = {
      year: selectedYear.value,
      month: selectedMonth.value,
      dateType: selectedDateType.value
    }
    if (selectedBranchId.value) params.branchId = selectedBranchId.value
    const res = await get<any>('branches/reconciliation', params)
    if (res.data?.mode === 'summary') {
      summaryData.value = res.data
    } else {
      data.value = res.data
    }
  } catch (error: any) {
    toast.add({ title: error.message || 'Veri cekilemedi', color: 'error' })
  } finally {
    loading.value = false
  }
}

async function lockReconciliation() {
  if (!selectedBranchId.value || !data.value || isLocked.value) return
  locking.value = true
  try {
    await post('branches/reconciliation-lock', {
      branchId: selectedBranchId.value,
      year: selectedYear.value,
      month: selectedMonth.value,
      dateType: selectedDateType.value
    })
    toast.add({ title: 'Mutabakat kilitlendi', color: 'success' })
    showLockConfirm.value = false
    await fetchReconciliation()
  } catch (error: any) {
    toast.add({ title: error.message || 'Kilit işlemi başarısız', color: 'error' })
  } finally {
    locking.value = false
  }
}

// Tablo
const sorting = ref<{ id: string, desc: boolean }[]>([])

const columns = [
  { accessorKey: 'policyNo', header: 'Poliçe No', enableSorting: true, minSize: 140, maxSize: 160 },
  { accessorKey: 'companyName', header: 'Şirket', enableSorting: true, minSize: 120, maxSize: 150 },
  { accessorKey: 'insuranceName', header: 'Alt Urun', enableSorting: true, minSize: 100, maxSize: 130 },
  { accessorKey: 'customerName', header: 'Müşteri', enableSorting: true, minSize: 150, maxSize: 200 },
  { accessorKey: 'issuedAt', header: 'Tanzim', enableSorting: false, minSize: 90, maxSize: 100 },
  { accessorKey: 'startsAt', header: 'Başlangıç', enableSorting: false, minSize: 90, maxSize: 100 },
  { accessorKey: 'grossPremium', header: 'Brüt Prim', enableSorting: true, minSize: 100, maxSize: 120 },
  { accessorKey: 'netPremium', header: 'Net Prim', enableSorting: true, minSize: 100, maxSize: 120 },
  { accessorKey: 'commission', header: 'Komisyon', enableSorting: true, minSize: 100, maxSize: 120 },
  { accessorKey: 'prod', header: 'Tür', enableSorting: true, minSize: 90, maxSize: 110 },
  { accessorKey: 'status', header: 'Durum', enableSorting: false, minSize: 80, maxSize: 100 }
]

function formatDateTr(d: string | null) {
  if (!d) return '-'
  try { return new Date(d).toLocaleDateString('tr-TR') } catch { return d }
}

function getPolicyStatus(p: any) {
  if (p.isCancelled) return { label: 'İptal', color: 'error' as const }
  if (p.endorsementNo > 1) return { label: 'Zeyil', color: 'warning' as const }
  return { label: 'Aktif', color: 'success' as const }
}

function getMonthLabel(m: number) {
  return monthOptions.find(o => o.value === m)?.label || ''
}

function exportCsv() {
  if (!selectedBranchId.value) {
    toast.add({ title: 'Lütfen acente seçin', color: 'warning' })
    return
  }
  const params = new URLSearchParams({
    branchId: String(selectedBranchId.value),
    year: String(selectedYear.value),
    month: String(selectedMonth.value),
    dateType: selectedDateType.value
  })
  const url = `/api/branches/reconciliation-export?${params.toString()}`
  fetch(url, { headers: { Authorization: `Bearer ${token.value}` } })
    .then(res => {
      const disposition = res.headers.get('Content-Disposition') || ''
      const match = disposition.match(/filename="?(.+?)"?$/)
      const filename = match ? match[1] : 'mutabakat.xlsx'
      return res.blob().then(blob => ({ blob, filename }))
    })
    .then(({ blob, filename }) => {
      const blobUrl = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = blobUrl
      link.download = filename
      link.click()
      URL.revokeObjectURL(blobUrl)
    })
    .catch(() => toast.add({ title: 'Dosya indirilemedi', color: 'error' }))
}
</script>

<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-xl">Tali Acente Mutabakat</h1>
      <p class="text-sm text-muted mt-1">Aylık komisyon mutabakat raporu.</p>
    </div>

    <!-- Filtre Kartı -->
    <UCard>
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <div class="col-span-2 sm:col-span-1 relative fl-select ">
          <USelectMenu v-model="selectedBranchId" :items="branchOptions" value-key="value" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
          <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', selectedBranchId ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">Tali Acente</label>
        </div>

        <div class="relative fl-select ">
          <USelect v-model="selectedYear" :items="yearOptions" value-key="value" placeholder=" " class="w-full" />
          <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">Yıl</label>
        </div>

        <div class="relative fl-select ">
          <USelect v-model="selectedMonth" :items="monthOptions" value-key="value" placeholder=" " class="w-full" />
          <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">Ay</label>
        </div>

        <div class="relative fl-select ">
          <USelect v-model="selectedDateType" :items="dateTypeOptions" value-key="value" placeholder=" " class="w-full" />
          <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">Tarih Tipi</label>
        </div>

        <div class="flex items-end">
          <UButton label="Getir" icon="i-lucide-search" size="xl" class="w-full" :loading="loading" @click="fetchReconciliation" />
        </div>
      </div>
    </UCard>

    <!-- Kilit Durumu Bilgisi -->
    <div v-if="data && isLocked" class="flex items-center gap-2 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800">
      <UIcon name="i-lucide-circle-check" class="size-4 text-emerald-600 shrink-0" />
      <p class="text-sm text-emerald-700 dark:text-emerald-300">
        <strong>{{ data.branch.name }}</strong> - {{ getMonthLabel(selectedMonth) }} {{ selectedYear }} dönemi komisyonu <strong>ödenmiştir</strong>. Mutabakat kilitlidir.
      </p>
    </div>

    <!-- Skeleton: loading -->
    <template v-if="loading && !data">
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <SkeletonCard v-for="i in 6" :key="i">
          <div class="text-center space-y-2">
            <div class="h-6 bg-neutral-200 rounded w-12 mx-auto" />
            <div class="h-3 bg-neutral-200 rounded w-20 mx-auto" />
          </div>
        </SkeletonCard>
      </div>
      <UCard>
        <SkeletonTable :rows="8" :cols="7" />
      </UCard>
    </template>

    <!-- Özet Kartlari -->
    <div v-if="data" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
      <UCard :ui="{ body: 'p-3' }">
        <div class="text-center">
          <p class="text-2xl font-bold">{{ data.stats.totalPolicies }}</p>
          <p class="text-xs text-muted">Toplam Poliçe</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }">
        <div class="text-center">
          <p class="text-2xl font-bold text-error">{{ data.stats.totalCancelled }}</p>
          <p class="text-xs text-muted">Toplam İptal</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }">
        <div class="text-center">
          <p class="text-sm font-bold">{{ formatCurrency(data.stats.totalGross) }}</p>
          <p class="text-xs text-muted">Brüt Prim</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }">
        <div class="text-center">
          <p class="text-sm font-bold">{{ formatCurrency(data.stats.totalNet) }}</p>
          <p class="text-xs text-muted">Net Prim</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }">
        <div class="text-center">
          <p class="text-sm font-bold text-primary">{{ formatCurrency(data.stats.totalCommission) }}</p>
          <p class="text-xs text-muted">Kazanilan Komisyon</p>
          <UBadge v-if="isLocked" color="success" variant="solid" size="sm" class="mt-1">
            <UIcon name="i-lucide-circle-check" class="size-3 mr-0.5" /> Ödendi
          </UBadge>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }">
        <div class="text-center">
          <p class="text-2xl font-bold">%{{ data.stats.commissionRate }}</p>
          <p class="text-xs text-muted">Komisyon Orani</p>
        </div>
      </UCard>
    </div>

    <!-- Tablo -->
    <UCard v-if="data" :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex items-center justify-between">
          <h3 class="flex items-center gap-2">
            <UIcon v-if="isLocked" name="i-lucide-lock" class="size-3.5 text-emerald-600" />
            {{ data.branch.name }} - {{ getMonthLabel(selectedMonth) }} {{ selectedYear }}
          </h3>
          <div class="flex items-center gap-2">
            <span class="text-xs text-muted">{{ data.policies.length }} kayıt</span>
            <UButton label="Excel İndir" icon="i-lucide-download" color="neutral" variant="outline" size="xl" class="hidden sm:flex" @click="exportCsv" />
            <UButton v-if="data.policies.length > 0 && !isLocked" label="Onayla ve Kilitle" icon="i-lucide-lock" color="success" size="xl"  @click="showLockConfirm = true" />
          </div>
        </div>
      </template>

      <div class="border border-default rounded-lg overflow-hidden">
        <UTable
          v-model:sorting="sorting"
          :data="data.policies"
          :columns="columns"
          :loading="loading"
          :sorting-options="{ manualSorting: false }"
          :ui="{
            base: 'table-fixed min-w-full mutabakat-table',
            thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10',
            th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap',
            td: 'py-2 px-3 text-xs whitespace-nowrap'
          }"
        >
          <template #policyNo-header="{ column }">
            <SortableHeader label="Poliçe No" :column="column" />
          </template>
          <template #companyName-header="{ column }">
            <SortableHeader label="Şirket" :column="column" />
          </template>
          <template #insuranceName-header="{ column }">
            <SortableHeader label="Alt Urun" :column="column" />
          </template>
          <template #customerName-header="{ column }">
            <SortableHeader label="Müşteri" :column="column" />
          </template>
          <template #grossPremium-header="{ column }">
            <SortableHeader label="Brüt Prim" :column="column" />
          </template>
          <template #netPremium-header="{ column }">
            <SortableHeader label="Net Prim" :column="column" />
          </template>
          <template #commission-header="{ column }">
            <SortableHeader label="Komisyon" :column="column" />
          </template>
          <template #prod-header="{ column }">
            <SortableHeader label="Tür" :column="column" />
          </template>

          <template #policyNo-cell="{ row }">
            <span class="font-mono text-xs">{{ row.original.policyNo }}/{{ row.original.endorsementNo }}</span>
          </template>

          <template #issuedAt-cell="{ row }">
            <span class="tabular-nums">{{ formatDateTr(row.original.issuedAt) }}</span>
          </template>

          <template #startsAt-cell="{ row }">
            <span class="tabular-nums">{{ formatDateTr(row.original.startsAt) }}</span>
          </template>

          <template #expiresAt-cell="{ row }">
            <span class="tabular-nums">{{ formatDateTr(row.original.expiresAt) }}</span>
          </template>

          <template #companyName-cell="{ row }">
            <UTooltip v-if="row.original.companyName.length > 12" :text="row.original.companyName">
              <span>{{ row.original.companyName.slice(0, 12) }}...</span>
            </UTooltip>
            <span v-else>{{ row.original.companyName }}</span>
          </template>

          <template #customerName-cell="{ row }">
            <UTooltip v-if="row.original.customerName.length > 18" :text="row.original.customerName">
              <span>{{ row.original.customerName.slice(0, 18) }}...</span>
            </UTooltip>
            <span v-else>{{ row.original.customerName }}</span>
          </template>

          <template #grossPremium-cell="{ row }">
            <span class="tabular-nums">{{ formatCurrency(row.original.grossPremium) }}</span>
          </template>

          <template #netPremium-cell="{ row }">
            <span class="tabular-nums">{{ formatCurrency(row.original.netPremium) }}</span>
          </template>

          <template #commission-cell="{ row }">
            <span class="tabular-nums font-medium text-primary">{{ formatCurrency(row.original.commission) }}</span>
          </template>

          <template #prod-cell="{ row }">
            <UBadge
              :color="row.original.prod === 'INCOMING' ? 'info' : 'warning'"
              variant="solid"
              size="sm"
            >
              {{ row.original.prod === 'INCOMING' ? 'Tali Gelen' : 'Tali Giden' }}
            </UBadge>
          </template>

          <template #status-cell="{ row }">
            <UBadge
              :color="getPolicyStatus(row.original).color"
              variant="solid"
              size="sm"
            >
              {{ getPolicyStatus(row.original).label }}
            </UBadge>
          </template>
        </UTable>
      </div>
    </UCard>

    <!-- Ozet Tablo (tum acenteler) -->
    <UCard v-if="isSummaryMode" :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex items-center justify-between">
          <h3 >
            Tüm Acenteler - {{ getMonthLabel(selectedMonth) }} {{ selectedYear }} Mutabakat Özeti
          </h3>
          <span class="text-xs text-muted">{{ summaryData.branches.length }} acente</span>
        </div>
      </template>

      <div class="border border-default rounded-lg overflow-hidden">
        <table class="text-xs w-full table-fixed">
          <thead class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
            <tr>
              <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted">Acente</th>
              <th class="hidden sm:table-cell text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Poliçe</th>
              <th class="hidden sm:table-cell text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">İptal</th>
              <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">Brüt Prim</th>
              <th class="hidden md:table-cell text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">Net Prim</th>
              <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted text-primary">Komisyon</th>
              <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Durum</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-default">
            <tr
              v-for="b in summaryData.branches"
              :key="b.branchId"
              class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors cursor-pointer"
              @click="selectedBranchId = b.branchId; fetchReconciliation()"
            >
              <td class="px-3 py-2 font-medium">{{ b.branchName }}</td>
              <td class="hidden sm:table-cell px-3 py-2 text-center">{{ b.totalPolicies }}</td>
              <td class="hidden sm:table-cell px-3 py-2 text-center text-red-500">{{ b.totalCancelled }}</td>
              <td class="px-3 py-2 text-right">{{ formatCurrency(b.totalGross) }}</td>
              <td class="hidden md:table-cell px-3 py-2 text-right">{{ formatCurrency(b.totalNet) }}</td>
              <td class="px-3 py-2 text-right font-medium text-primary">{{ formatCurrency(b.totalCommission) }}</td>
              <td class="px-3 py-2 text-center">
                <UBadge v-if="b.isLocked" color="success" variant="solid" size="sm">
                  <UIcon name="i-lucide-circle-check" class="size-3 mr-0.5" /> Ödendi
                </UBadge>
                <UBadge v-else color="warning" variant="solid" size="sm">Bekliyor</UBadge>
              </td>
            </tr>
          </tbody>
          <tfoot class="bg-gray-50 dark:bg-gray-800/50">
            <tr>
              <td class="px-3 py-2">Toplam</td>
              <td class="hidden sm:table-cell px-3 py-2 text-center">{{ summaryData.totals.totalPolicies }}</td>
              <td class="hidden sm:table-cell px-3 py-2"></td>
              <td class="px-3 py-2 text-right">{{ formatCurrency(summaryData.totals.totalGross) }}</td>
              <td class="hidden md:table-cell px-3 py-2 text-right">{{ formatCurrency(summaryData.totals.totalNet) }}</td>
              <td class="px-3 py-2 text-right text-primary">{{ formatCurrency(summaryData.totals.totalCommission) }}</td>
              <td class="px-3 py-2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </UCard>

    <!-- Bos durum -->
    <UCard v-if="!data && !summaryData && !loading" :ui="{ body: 'p-12' }">
      <div class="text-center text-muted">
        <UIcon name="i-lucide-file-search" class="size-10 mx-auto mb-3 opacity-50" />
        <p class="font-medium">Mutabakat Raporu</p>
        <p class="text-sm mt-1">Acente seçmeden getirirseniz tüm acentelerin özeti görüntülenir.</p>
      </div>
    </UCard>

    <!-- Kilit Onay Modali -->
    <UModal :dismissible="false" v-model:open="showLockConfirm" title="Mutabakatı Kilitle" :ui="{ width: 'sm:max-w-md' }">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 bg-emerald-100 dark:bg-emerald-900/30">
            <UIcon name="i-lucide-lock" class="size-5 text-emerald-600" />
          </div>
          <div>
            <p class="text-sm font-medium">
              <strong>{{ data?.branch?.name }}</strong> - {{ getMonthLabel(selectedMonth) }} {{ selectedYear }} dönemini kilitlemek istediğinize emin misiniz?
            </p>
            <p class="text-xs text-muted mt-2">
              Kilitlendiğinde bu dönemdeki {{ data?.policies?.length || 0 }} poliçenin mutabakat durumu onaylanmış olacaktır.
            </p>
            <p class="text-xs text-red-500 font-medium mt-1">
              Bu işlem geri alınamaz.
            </p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="İptal" color="neutral" variant="outline" size="xl"  @click="showLockConfirm = false" />
          <UButton label="Kilitle" icon="i-lucide-lock" color="success" size="xl"  :loading="locking" @click="lockReconciliation" />
        </div>
      </template>
    </UModal>
  </div>
</template>

<style scoped>
:deep(.mutabakat-table th:nth-child(1))  { width: 140px; min-width: 140px; max-width: 140px; }
:deep(.mutabakat-table th:nth-child(2))  { width: 130px; min-width: 130px; max-width: 130px; }
:deep(.mutabakat-table th:nth-child(3))  { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.mutabakat-table th:nth-child(4))  { width: 160px; min-width: 160px; max-width: 160px; }
:deep(.mutabakat-table th:nth-child(5))  { width: 90px;  min-width: 90px;  max-width: 90px;  }
:deep(.mutabakat-table th:nth-child(6))  { width: 90px;  min-width: 90px;  max-width: 90px;  }
:deep(.mutabakat-table th:nth-child(7))  { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.mutabakat-table th:nth-child(8))  { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.mutabakat-table th:nth-child(9))  { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.mutabakat-table th:nth-child(10)) { width: 100px; min-width: 100px; max-width: 100px; }
:deep(.mutabakat-table th:nth-child(11)) { width: 90px;  min-width: 90px;  max-width: 90px;  }
:deep(.mutabakat-table > tbody > tr > td) { overflow: hidden; text-overflow: ellipsis; }
</style>
