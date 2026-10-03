<script setup lang="ts">
import type { Policy } from '~/types'
import type { DateRange } from 'reka-ui'
import { CalendarDate } from '@internationalized/date'
import { z } from 'zod'

definePageMeta({
  layout: 'default',
  middleware: 'auth',
  keepalive: true
})

useSeoMeta({ title: 'Poliçeler' })

const toast = useToast()
const { get, post, del } = useApi()
const { token, hasRole, user } = useAuth()
const { can } = usePermissions()
const isAdmin = computed(() => hasRole('admin'))
const { formatCurrency, getPolicyStatus, getStatusColor, getStatusLabel, getProdLabel } = usePolicyHelpers()
const { insurances, fetchInsurances } = useInsuranceTypes()
const { isFieldEnabled, fetchFieldSettings } = useFieldSettings()
const { openPolicyModal } = useGlobalModals()

// Poliçe Detay Slideover
const detailPolicyId = ref<number | null>(null)
const isDetailOpen = ref(false)
function openPolicyDetail(id: number) {
  detailPolicyId.value = id
  isDetailOpen.value = true
}
function onDetailEdit(policy: any) {
  isDetailOpen.value = false
  openPolicyModal({ policy, onSaved: () => policies.refresh() })
}

// Server-side paginated data
const policies = usePaginatedData<Policy>({
  endpoint: 'policies',
  defaultLimit: 15,
  defaultSort: 'issued_at',
  defaultOrder: 'desc'
})

const route = useRoute()

// Filter state
const filterInsuranceId = ref<number | string>('all')
const filterCompanyId = ref<number | string>('all')
const filterProd = ref('all')
const filterStatus = ref('all')
const filterBranchId = ref<number | string>('all')
const filterDateRange = ref<DateRange>({ start: undefined, end: undefined })
const filterDateRangePopoverOpen = ref(false)

const showBranchFilter = computed(() => filterProd.value === 'INCOMING' || filterProd.value === 'OUTGOING')

const statusFilterOptions = [
  { label: 'Durumlar', value: 'all' },
  { label: 'Aktif', value: 'ACTIVE' },
  { label: 'Süresi Dolmuş', value: 'EXPIRED' },
  { label: 'İptal', value: 'CANCELLED' }
]

// Companies
const companies = ref<{ id: number, name: string }[]>([])
async function fetchCompanies() {
  try {
    const res = await get<any>('companies?all=1')
    companies.value = res.data || []
  } catch {}
}
const companyFilterOptions = computed(() => [
  { label: 'Sigorta Şirketleri', value: 'all' as any },
  ...companies.value
    .sort((a, b) => a.name.localeCompare(b.name, 'tr'))
    .map(c => ({ label: c.name, value: c.id }))
])

// Branches (filter)
const branches = ref<{ id: number, name: string, commission: number }[]>([])
async function fetchBranches() {
  try {
    const res = await get<any>('branches?all=1')
    branches.value = (res.data || []).map((b: any) => ({ id: b.id, name: b.name, commissionRate: b.commissionRate || 0 }))
  } catch {}
}
const branchFilterOptions = computed(() => [
  { label: 'Tüm Acenteler', value: 'all' as any },
  ...branches.value.map(b => ({ label: b.name, value: b.id }))
])

const allSubcategories = computed(() =>
  insurances.value.filter(i => i.level === 'subcategory')
)

const prodFilterOptions = [
  { label: 'Üretim Yeri', value: 'all' },
  { label: 'Acentem', value: 'SELF' },
  { label: 'Tali Gelen', value: 'INCOMING' },
  { label: 'Tali Giden', value: 'OUTGOING' }
]

const insuranceFilterOptions = computed(() => [
  { label: 'Sigortalar', value: 'all' as any },
  ...allSubcategories.value
    .sort((a, b) => a.name.localeCompare(b.name, 'tr'))
    .map(i => ({ label: i.name, value: i.id }))
])

let policelerActivated = false

onMounted(() => {
  fetchInsurances()
  fetchCompanies()
  fetchBranches()
  fetchFieldSettings()
  const qInsurance = route.query.insuranceId
  if (qInsurance) {
    filterInsuranceId.value = Number(qInsurance)
    policies.filters.value.insuranceId = String(qInsurance)
  }
  const qStatus = route.query.status as string
  if (qStatus) {
    filterStatus.value = qStatus
    policies.filters.value.status = qStatus
  }
  policies.fetchData()
})

onActivated(() => {
  if (policelerActivated) policies.fetchData()
  policelerActivated = true
})

// Search
const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    policies.setSearch(val)
  }, 400)
})

// Sidebar insurance filter via query param
watch(() => route.query.insuranceId, (val) => {
  filterInsuranceId.value = val ? Number(val) : 'all'
})

// Helper: CalendarDate -> 'YYYY-MM-DD'
function calendarDateToStr(d: any): string {
  if (!d) return ''
  return `${d.year}-${String(d.month).padStart(2, '0')}-${String(d.day).padStart(2, '0')}`
}

// Header filters
function applyFilters() {
  policies.setFilters({
    insuranceId: filterInsuranceId.value && filterInsuranceId.value !== 'all' ? String(filterInsuranceId.value) : '',
    companyId: filterCompanyId.value && filterCompanyId.value !== 'all' ? String(filterCompanyId.value) : '',
    productionType: filterProd.value && filterProd.value !== 'all' ? filterProd.value : '',
    status: filterStatus.value && filterStatus.value !== 'all' ? filterStatus.value : '',
    branchId: showBranchFilter.value && filterBranchId.value && filterBranchId.value !== 'all' ? String(filterBranchId.value) : '',
    dateFrom: calendarDateToStr(filterDateRange.value?.start),
    dateTo: calendarDateToStr(filterDateRange.value?.end)
  })
}

watch(filterInsuranceId, applyFilters)
watch(filterCompanyId, applyFilters)
watch(filterProd, applyFilters)
watch(filterStatus, applyFilters)
watch(filterBranchId, applyFilters)
watch(filterDateRange, applyFilters, { deep: true })

// Üretim tipi değişince branch filtresini sifirla
watch(filterProd, (val) => {
  if (val === 'all' || val === 'SELF') {
    filterBranchId.value = 'all'
  }
})

const hasActiveFilters = computed(() =>
  (filterInsuranceId.value && filterInsuranceId.value !== 'all')
  || (filterCompanyId.value && filterCompanyId.value !== 'all')
  || (filterProd.value && filterProd.value !== 'all')
  || (filterStatus.value && filterStatus.value !== 'all')
  || (showBranchFilter.value && filterBranchId.value && filterBranchId.value !== 'all')
  || filterDateRange.value?.start
  || filterDateRange.value?.end
)

function clearFilters() {
  filterInsuranceId.value = 'all'
  filterCompanyId.value = 'all'
  filterProd.value = 'all'
  filterStatus.value = 'all'
  filterBranchId.value = 'all'
  filterDateRange.value = { start: undefined, end: undefined }
  searchInput.value = ''
  policies.setFilters({})
}

// Date range display label
const dateRangeLabel = computed(() => {
  const s = filterDateRange.value?.start
  const e = filterDateRange.value?.end
  if (!s && !e) return 'Tarih Aralığı'
  const fmt = (d: any) => `${String(d.day).padStart(2, '0')}.${String(d.month).padStart(2, '0')}.${d.year}`
  if (s && e) return `${fmt(s)} - ${fmt(e)}`
  if (s) return `${fmt(s)} - ...`
  return 'Tarih Aralığı'
})

// Excel export
function exportExcel() {
  const params = new URLSearchParams()
  if (filterInsuranceId.value && filterInsuranceId.value !== 'all') params.set('insuranceId', String(filterInsuranceId.value))
  if (filterCompanyId.value && filterCompanyId.value !== 'all') params.set('companyId', String(filterCompanyId.value))
  if (filterProd.value && filterProd.value !== 'all') params.set('prod', filterProd.value)
  if (filterStatus.value && filterStatus.value !== 'all') params.set('status', filterStatus.value)
  if (showBranchFilter.value && filterBranchId.value && filterBranchId.value !== 'all') params.set('branchId', String(filterBranchId.value))
  const sd = calendarDateToStr(filterDateRange.value?.start)
  const ed = calendarDateToStr(filterDateRange.value?.end)
  if (sd) params.set('dateFrom', sd)
  if (ed) params.set('dateTo', ed)
  if (searchInput.value) params.set('search', searchInput.value)

  const url = `/api/policies/export?${params.toString()}`
  fetch(url, { headers: { Authorization: `Bearer ${token.value}` } })
    .then(res => {
      const disposition = res.headers.get('Content-Disposition') || ''
      const match = disposition.match(/filename="?(.+?)"?$/)
      const filename = match ? match[1] : 'policeler.xlsx'
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

// Sorting
const sortKeyMap: Record<string, string> = {
  policyNo: 'policy_no',
  customerName: 'customer_name',
  insuranceName: 'insurance_name',
  issuedAt: 'issued_at',
  startsAt: 'starts_at',
  expiresAt: 'expires_at',
  grossPremium: 'gross_premium'
}

const sorting = ref<{ id: string, desc: boolean }[]>([{ id: 'issuedAt', desc: true }])
watch(sorting, (val) => {
  if (val.length) {
    const apiKey = sortKeyMap[val[0].id] || val[0].id
    policies.setSort(apiKey, val[0].desc ? 'desc' : 'asc')
  } else {
    policies.setSort('updated_at', 'desc')
  }
}, { deep: true })

// Table columns
const columns = [
  { accessorKey: 'expand', header: '', enableSorting: false },
  { accessorKey: 'policyNo', header: 'Poliçe No', enableSorting: true },
  { accessorKey: 'insuranceName', header: 'Poliçe Türü', enableSorting: true },
  { accessorKey: 'customerName', header: 'Ad/Soyad', enableSorting: true },
  { accessorKey: 'plateNo', header: 'Plaka', enableSorting: false },
  { accessorKey: 'issuedAt', header: 'Tanzim T.', enableSorting: true },
  { accessorKey: 'startsAt', header: 'Başlangıç T.', enableSorting: true },
  { accessorKey: 'expiresAt', header: 'Bitiş T.', enableSorting: true },
  { accessorKey: 'grossPremium', header: 'Brüt Prim', enableSorting: true },
  { accessorKey: 'income', header: 'Gelir', enableSorting: false },
  { accessorKey: 'policyStatus', header: 'P. Durumu', enableSorting: false },
  { accessorKey: 'actions', header: 'İşlem', enableSorting: false }
]

// Modal state
const isDeleteModalOpen = ref(false)
const isCancelModalOpen = ref(false)
const deletingPolicyId = ref<number | null>(null)
const cancellingPolicyId = ref<number | null>(null)

// Expandable sub-table for zeyil history
const expanded = ref<Record<string, boolean>>({})
const zeyilCache = ref<Record<string, Policy[]>>({})
const zeyilLoading = ref<Record<string, boolean>>({})

async function toggleExpand(row: any) {
  const policyId = String(row.original.id)

  if (row.getIsExpanded()) {
    row.toggleExpanded(false)
    return
  }

  // Fetch zeyil history if not cached
  if (!zeyilCache.value[row.original.policyNo]) {
    zeyilLoading.value = { ...zeyilLoading.value, [policyId]: true }
    try {
      const res = await get(`policies/zeyil/${row.original.policyNo}`)
      zeyilCache.value[row.original.policyNo] = res.data || []
    } catch {
      zeyilCache.value[row.original.policyNo] = []
    }
    zeyilLoading.value = { ...zeyilLoading.value, [policyId]: false }
  }

  row.toggleExpanded(true)
}

function getZeyilHistory(policyNo: string): Policy[] {
  return zeyilCache.value[policyNo] || []
}

function getZeyilLabel(z: Policy) {
  if (z.isCancelled) return 'İptal'
  if (z.endorsementNo === 1) return 'Ana Poliçe'
  return `Zeyil ${z.endorsementNo}`
}

function getZeyilColor(z: Policy) {
  if (z.isCancelled) return 'error' as const
  if (z.endorsementNo === 1) return 'primary' as const
  return 'info' as const
}

// Sub-table actions
function clearZeyilCache(policyNo: string) {
  delete zeyilCache.value[policyNo]
}

async function refreshZeyilCache(policyNo: string) {
  try {
    const res = await get(`policies/zeyil/${policyNo}`)
    zeyilCache.value[policyNo] = res.data || []
  } catch {
    zeyilCache.value[policyNo] = []
  }
}

function editZeyil(z: Policy) {
  openPolicyModal({ policy: z, onSaved: () => { refreshZeyilCache(z.policyNo); policies.refresh() } })
}

const deletingZeyilId = ref<number | null>(null)
const deletingZeyilPolicyNo = ref('')
const isZeyilDeleteModalOpen = ref(false)

function confirmZeyilDelete(z: Policy) {
  deletingZeyilId.value = z.id
  deletingZeyilPolicyNo.value = z.policyNo
  isZeyilDeleteModalOpen.value = true
}

async function doZeyilDelete() {
  if (!deletingZeyilId.value) return
  const policyNo = deletingZeyilPolicyNo.value
  try {
    await del(`policies/${deletingZeyilId.value}`)
    toast.add({ title: 'Zeyil silindi', color: 'success' })
    await Promise.all([refreshZeyilCache(policyNo), policies.refresh()])
  } catch {
    toast.add({ title: 'Zeyil silinemedi', color: 'error' })
  }
  isZeyilDeleteModalOpen.value = false
  deletingZeyilId.value = null
  deletingZeyilPolicyNo.value = ''
}

// Cancel form
const cancellingPolicy = ref<Policy | null>(null)
const cancelForm = ref({
  cancelDate: new Date().toISOString().split('T')[0],
  cancelGrossRefund: 0,
  cancelNetRefund: 0
})

// Türkish decimal formatting for cancel refund inputs
const cancelGrossDisplay = ref('')
const cancelNetDisplay = ref('')

function formatTrDecimal(num: number): string {
  return num.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function parseTrDecimal(val: string): number {
  // Remove dots (thousands sep), replace comma with dot (decimal sep)
  const cleaned = val.replace(/\./g, '').replace(',', '.')
  const num = parseFloat(cleaned)
  return isNaN(num) ? 0 : num
}

const cancelPrimError = ref('')

function onCancelCurrencyBlur(field: 'cancelGrossRefund' | 'cancelNetRefund') {
  if (field === 'cancelGrossRefund') {
    cancelGrossDisplay.value = cancelForm.value.cancelGrossRefund > 0 ? formatTrDecimal(cancelForm.value.cancelGrossRefund) : ''
  } else {
    cancelNetDisplay.value = cancelForm.value.cancelNetRefund > 0 ? formatTrDecimal(cancelForm.value.cancelNetRefund) : ''
  }
  // Brüt < Net kontrolü
  const g = cancelForm.value.cancelGrossRefund
  const n = cancelForm.value.cancelNetRefund
  cancelPrimError.value = (g > 0 && n > 0 && g < n) ? 'Brüt prim, net primden küçük olamaz' : ''
}

function onCancelCurrencyInput(field: 'cancelGrossRefund' | 'cancelNetRefund', val: string) {
  const cleaned = val.replace(/[^\d.,]/g, '')
  if (field === 'cancelGrossRefund') {
    cancelGrossDisplay.value = cleaned
    cancelForm.value.cancelGrossRefund = parseTrDecimal(cleaned)
  } else {
    cancelNetDisplay.value = cleaned
    cancelForm.value.cancelNetRefund = parseTrDecimal(cleaned)
  }
  // Anlık kontrol
  const g = cancelForm.value.cancelGrossRefund
  const n = cancelForm.value.cancelNetRefund
  cancelPrimError.value = (g > 0 && n > 0 && g < n) ? 'Brüt prim, net primden küçük olamaz' : ''
}

const cancelSchema = computed(() => {
  const maxGross = (cancellingPolicy.value as any)?.totalGrossPremium ?? cancellingPolicy.value?.grossPremium ?? 0
  const maxNet = (cancellingPolicy.value as any)?.totalNetPremium ?? cancellingPolicy.value?.netPremium ?? 0
  const issuedAt = cancellingPolicy.value?.issuedAt || ''

  return z.object({
    cancelDate: z.string().min(1, 'İptal tarihi zorunludur').refine((val) => {
      if (!issuedAt) return true
      return val >= issuedAt
    }, { message: `İptal tarihi tanzim tarihinden (${issuedAt ? new Date(issuedAt).toLocaleDateString('tr-TR') : ''}) önce olamaz` }),
    cancelGrossRefund: z.number().min(0, 'Negatif olamaz').max(maxGross, `İade brüt prim ${maxGross.toLocaleString('tr-TR')} TL'den büyük olamaz`),
    cancelNetRefund: z.number().min(0, 'Negatif olamaz').max(maxNet, `İade net prim ${maxNet.toLocaleString('tr-TR')} TL'den büyük olamaz`)
  }).superRefine((data, ctx) => {
    if (data.cancelGrossRefund > 0 && data.cancelNetRefund > 0 && data.cancelGrossRefund < data.cancelNetRefund) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Brüt prim, net primden küçük olamaz', path: ['cancelGrossRefund'] })
    }
  })
})

// Cancel date display (dd.mm.yyyy format)
const cancelDateDisplay = ref('')
const cancelCalendarDate = ref<InstanceType<typeof CalendarDate> | undefined>()
const cancelDatePopoverOpen = ref(false)
let cancelDateSyncing = false

// Sync cancelForm.cancelDate -> cancelDateDisplay + cancelCalendarDate
watch(() => cancelForm.value.cancelDate, (val) => {
  if (cancelDateSyncing) return
  if (val) {
    const [y, m, d] = val.split('-')
    cancelDateDisplay.value = `${d}.${m}.${y}`
    cancelCalendarDate.value = new CalendarDate(parseInt(y), parseInt(m), parseInt(d))
  }
}, { immediate: true })

// Text input auto-format: 01021991 -> 01.02.1991
function formatCancelDateInput(val: string) {
  let raw = val.replace(/\D/g, '')
  if (raw.length > 8) raw = raw.slice(0, 8)

  let formatted = ''
  if (raw.length > 0) formatted = raw.slice(0, Math.min(2, raw.length))
  if (raw.length > 2) formatted += '.' + raw.slice(2, Math.min(4, raw.length))
  if (raw.length > 4) formatted += '.' + raw.slice(4)

  return { formatted, raw }
}

watch(cancelDateDisplay, (val) => {
  const { formatted, raw } = formatCancelDateInput(val)
  if (formatted !== val) {
    cancelDateDisplay.value = formatted
    return
  }

  if (raw.length === 8) {
    const day = raw.slice(0, 2)
    const month = raw.slice(2, 4)
    const year = raw.slice(4, 8)
    const d = parseInt(day), m = parseInt(month), y = parseInt(year)
    if (d >= 1 && d <= 31 && m >= 1 && m <= 12 && y >= 1900 && y <= 2100) {
      cancelDateSyncing = true
      cancelForm.value.cancelDate = `${year}-${month}-${day}`
      cancelCalendarDate.value = new CalendarDate(y, m, d)
      nextTick(() => { cancelDateSyncing = false })
    }
  }
})

// UCalendar selection -> sync back
function onCancelCalendarChange(val: any) {
  if (val) {
    cancelDateSyncing = true
    cancelCalendarDate.value = val
    cancelForm.value.cancelDate = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
    cancelDateDisplay.value = `${String(val.day).padStart(2, '0')}.${String(val.month).padStart(2, '0')}.${val.year}`
    cancelDatePopoverOpen.value = false
    nextTick(() => { cancelDateSyncing = false })
  }
}


// Delete
function confirmDelete(id: number) {
  deletingPolicyId.value = id
  isDeleteModalOpen.value = true
}

async function doDelete() {
  if (!deletingPolicyId.value) return
  try {
    await del(`policies/${deletingPolicyId.value}`)
    toast.add({ title: 'Poliçe silindi', color: 'success' })
    policies.refresh()
  } catch {
    toast.add({ title: 'Poliçe silinemedi', color: 'error' })
  }
  isDeleteModalOpen.value = false
  deletingPolicyId.value = null
}

// Cancel
function confirmCancel(id: number) {
  cancellingPolicyId.value = id
  const policy = policies.data.value.find(p => p.id === id)
  cancellingPolicy.value = policy || null
  cancelForm.value = {
    cancelDate: new Date().toISOString().split('T')[0],
    cancelGrossRefund: 0,
    cancelNetRefund: 0
  }
  cancelGrossDisplay.value = ''
  cancelNetDisplay.value = ''
  cancelPrimError.value = ''
  cancelFile.value = null
  isCancelModalOpen.value = true
}

const cancellingInProgress = ref(false)
const cancelFile = ref<File | null>(null)
const cancelFileInput = ref<HTMLInputElement | null>(null)

function onCancelFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files?.[0]) {
    cancelFile.value = input.files[0]
  }
  input.value = ''
}

async function doCancel() {
  if (!cancellingPolicyId.value || cancellingInProgress.value) return
  cancellingInProgress.value = true
  try {
    const cancelRes = await post(`policies/${cancellingPolicyId.value}/cancel`, {
      cancelDate: cancelForm.value.cancelDate,
      cancelGrossRefund: cancelForm.value.cancelGrossRefund,
      cancelNetRefund: cancelForm.value.cancelNetRefund
    })

    // Dosya varsa iptal zeyiline yükle
    const zeyilId = cancelRes?.data?.zeyilId
    if (cancelFile.value && zeyilId) {
      try {
        const cancelledPolicy = policies.data.value.find(p => p.id === cancellingPolicyId.value)
        const fd = new FormData()
        fd.append('file', cancelFile.value)
        fd.append('policyId', String(zeyilId))
        if (cancelledPolicy?.customerId) fd.append('customerId', String(cancelledPolicy.customerId))
        await fetch('/api/documents', {
          method: 'POST',
          headers: { Authorization: `Bearer ${token.value}` },
          body: fd,
        })
      } catch {}
      cancelFile.value = null
    }

    toast.add({ title: 'Poliçe iptal edildi', color: 'warning' })
    const cancelledPolicy = policies.data.value.find(p => p.id === cancellingPolicyId.value)
    const policyNo = cancelledPolicy?.policyNo
    if (policyNo) {
      await Promise.all([refreshZeyilCache(policyNo), policies.refresh()])
    } else {
      policies.refresh()
    }
    isCancelModalOpen.value = false
    cancellingPolicyId.value = null
  } catch {
    toast.add({ title: 'Poliçe iptal edilemedi', color: 'error' })
  }
  cancellingInProgress.value = false
}

// Row actions
function getRowActions(policy: Policy) {
  const status = getPolicyStatus(policy)
  const actions: any[][] = [[]]
  if (status === 'aktif') {
    actions[0].push({ label: 'İptal Et', icon: 'i-lucide-x-circle', color: 'warning' as const, onSelect: () => confirmCancel(policy.id) })
  }
  return actions
}

function formatDate(date: string) {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('tr-TR')
}

function getRowStatusColor(policy: Policy): string {
  const status = getPolicyStatus(policy)
  if (status === 'iptal') return '#EF4444'
  if (status === 'vadesi_gecmis') return '#F59E0B'
  return '#22C55E'
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
</script>

<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-xl">Poliçeler</h1>
      <p class="text-sm text-muted mt-1">Poliçe listesi ve yönetimi.</p>
    </div>

    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="space-y-3">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="relative w-full sm:w-72 [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="searchInput" placeholder=" " class="w-full peer/fl-polsearch" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-polsearch:top-0 peer-focus-within/fl-polsearch:-translate-y-1/2 peer-focus-within/fl-polsearch:text-xs peer-focus-within/fl-polsearch:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-polsearch:top-0 peer-has-[input:not(:placeholder-shown)]/fl-polsearch:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-polsearch:text-xs peer-has-[input:not(:placeholder-shown)]/fl-polsearch:text-[var(--ui-text-highlighted)]">Poliçe, müşteri, plaka ara</label>
            </div>
            <div class="flex items-center gap-2">
              <UButton v-if="hasActiveFilters" label="Temizle" icon="i-lucide-x" color="neutral" variant="ghost" size="xl"  @click="clearFilters" />
            </div>
          </div>

          <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
            <div class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
              <USelectMenu v-model="filterInsuranceId" :items="insuranceFilterOptions" value-key="value" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', filterInsuranceId ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">Sigortalar</label>
            </div>

            <div class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
              <USelectMenu v-model="filterCompanyId" :items="companyFilterOptions" value-key="value" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', filterCompanyId ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">Sigorta Şirketleri</label>
            </div>

            <div class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
              <USelect v-model="filterProd" :items="prodFilterOptions" value-key="value" placeholder=" " class="w-full" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">Üretim Yeri</label>
            </div>

            <div class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
              <USelect v-model="filterStatus" :items="statusFilterOptions" value-key="value" placeholder=" " class="w-full" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">Durum</label>
            </div>

            <div v-if="showBranchFilter" class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
              <USelectMenu v-model="filterBranchId" :items="branchFilterOptions" value-key="value" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', filterBranchId ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">Acente</label>
            </div>

            <UPopover v-model:open="filterDateRangePopoverOpen">
              <UButton :label="dateRangeLabel" icon="i-lucide-calendar-range" color="neutral" variant="outline" size="xl" class="w-full justify-start" />
              <template #content>
                <UCalendar locale="tr-TR" v-model="filterDateRange" range :number-of-months="2" class="p-2" @update:model-value="(v: any) => { if (v?.start && v?.end) filterDateRangePopoverOpen = false }" />
              </template>
            </UPopover>

            <UButton v-if="can('policies.export')" label="Excel" icon="i-lucide-download" color="neutral" variant="outline" size="xl" class="w-full hidden sm:flex" @click="exportExcel" />
          </div>
        </div>
      </template>

      <SkeletonTable v-if="policies.loading.value && !policies.data.value.length" :rows="10" :cols="8" />

      <!-- Mobil Kart Listesi -->
      <div v-if="!policies.loading.value || policies.data.value.length" class="sm:hidden space-y-2">
        <div
          v-for="p in policies.data.value"
          :key="p.id"
          class="border border-default rounded-lg p-3 hover:bg-elevated cursor-pointer"
          :style="{ borderLeftWidth: '3px', borderLeftColor: getRowStatusColor(p) }"
          @click="openPolicyDetail(p.id)"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold text-primary uppercase truncate">{{ p.customerName }}</p>
              <p class="text-xs text-muted font-mono">{{ p.customerIdentity }}</p>
            </div>
            <UBadge
              :color="p.status === 'ACTIVE' ? 'success' : p.status === 'CANCELLED' ? 'error' : 'warning'"
              variant="solid"
              size="sm"
            >
              {{ p.status === 'ACTIVE' ? 'Aktif' : p.status === 'CANCELLED' ? 'İptal' : 'Süresi Dolmuş' }}
            </UBadge>
          </div>
          <div class="flex items-center gap-3 mt-2">
            <span
              class="inline-block rounded-md px-2 py-0.5 text-xs font-medium"
              :style="{
                backgroundColor: toHex(p.insuranceColor) + '1a',
                color: toHex(p.insuranceColor)
              }"
            >{{ p.insuranceName?.split(' ')[0] || '' }}</span>
            <span class="text-xs font-semibold tabular-nums">{{ (p.totalGrossPremium ?? p.grossPremium ?? 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} ₺</span>
          </div>
        </div>
        <div v-if="!policies.data.value.length" class="text-center text-sm text-muted py-8">Poliçe bulunamadı</div>
      </div>

      <!-- Masaüstü Tablo -->
      <div v-if="!policies.loading.value || policies.data.value.length" class="hidden sm:block border border-default rounded-lg overflow-hidden">
      <UTable
        v-model:sorting="sorting"
        v-model:expanded="expanded"
        :data="policies.data.value"
        :columns="columns"
        :loading="policies.loading.value && !policies.data.value.length"
        :sorting-options="{ manualSorting: true }"
        :get-row-id="(row: any) => String(row.id)"
        :ui="{
          base: 'table-fixed w-full policeler-table',
          thead: 'bg-gray-100 dark:bg-gray-800 sticky top-0 z-10',
          th: 'py-2 px-3 text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400 whitespace-nowrap',
          td: 'py-2 px-3 text-xs whitespace-nowrap'
        }"
      >
        <template #policyNo-header="{ column }">
          <SortableHeader label="Poliçe No" :column="column" />
        </template>
        <template #insuranceName-header="{ column }">
          <SortableHeader label="Poliçe Türü" :column="column" />
        </template>
        <template #customerName-header="{ column }">
          <SortableHeader label="Ad/Soyad" :column="column" />
        </template>
        <template #startsAt-header="{ column }">
          <SortableHeader label="Başlangıç T." :column="column" />
        </template>
        <template #expiresAt-header="{ column }">
          <SortableHeader label="Bitiş T." :column="column" />
        </template>
        <template #grossPremium-header="{ column }">
          <SortableHeader label="Brüt Prim" :column="column" />
        </template>

        <template #expand-cell="{ row }">
          <div class="flex items-center justify-center relative">
            <div
              class="absolute -left-4 top-1/2 -translate-y-1/2 w-1 h-8 rounded-r"
              :style="{ backgroundColor: getRowStatusColor(row.original) }"
            />
            <div class="relative inline-flex items-center justify-center">
              <UButton
                :icon="row.getIsExpanded() ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'"
                color="neutral"
                variant="ghost"
                size="xs"
                :loading="zeyilLoading[String(row.original.id)]"
                @click.stop="toggleExpand(row)"
              />
              <span
                v-if="(row.original.zeyilCount || 1) > 1"
                class="absolute -bottom-1 -right-1 text-[8px] text-blue-500 font-bold leading-none"
              >
                {{ row.original.zeyilCount }}
              </span>
            </div>
          </div>
        </template>

        <template #policyNo-cell="{ row }">
          <div class="flex flex-col cursor-pointer" @click="openPolicyDetail(row.original.id)">
            <span class="text-xs" :style="{ color: toHex(row.original.companyColor) }">{{ row.original.companyName }}</span>
            <span class="font-mono text-xs text-primary hover:underline">{{ row.original.policyNo }}</span>
          </div>
        </template>


        <template #insuranceName-cell="{ row }">
          <UTooltip :text="row.original.insuranceName || ''">
            <span
              class="inline-block max-w-full truncate align-middle rounded-md px-2 py-0.5 text-xs font-medium"
              :style="{
                backgroundColor: toHex(row.original.insuranceColor) + '1a',
                color: toHex(row.original.insuranceColor)
              }"
            >
              {{ row.original.insuranceName?.split(' ')[0] || '' }}
            </span>
          </UTooltip>
        </template>

        <template #customerName-cell="{ row }">
          <div class="flex flex-col">
            <NuxtLink
              :to="`/musteriler/${row.original.customerId}`"
              :title="row.original.customerName"
              class="text-primary hover:underline text-xs uppercase"
            >
              {{ row.original.customerName?.slice(0, 16) }}{{ (row.original.customerName?.length ?? 0) > 16 ? '…' : '' }}
            </NuxtLink>
            <span v-if="row.original.customerIdentity" class="font-mono text-xs text-muted">{{ row.original.customerIdentity }}</span>
          </div>
        </template>

        <template #plateNo-cell="{ row }">
          <span class="text-xs">{{ row.original.plateNo || '-' }}</span>
        </template>

        <template #issuedAt-cell="{ row }">
          <span class="text-xs tabular-nums">{{ formatDate(row.original.issuedAt) }}</span>
        </template>

        <template #startsAt-cell="{ row }">
          <span class="text-xs tabular-nums">{{ formatDate(row.original.startsAt) }}</span>
        </template>

        <template #expiresAt-cell="{ row }">
          <span class="text-xs tabular-nums">{{ formatDate(row.original.effectiveExpiresAt ?? row.original.expiresAt) }}</span>
        </template>

        <template #grossPremium-cell="{ row }">
          <span class="text-xs tabular-nums block text-right">{{ (row.original.totalGrossPremium ?? row.original.grossPremium ?? 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
        </template>

        <template #income-cell="{ row }">
          <span class="text-xs tabular-nums block text-right">{{ (row.original.income ?? 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
        </template>

        <template #policyStatus-cell="{ row }">
          <UBadge
            :color="row.original.status === 'ACTIVE' ? 'success' : row.original.status === 'CANCELLED' ? 'error' : 'warning'"
            variant="solid"
            size="sm"
            class="whitespace-nowrap"
          >
            {{ row.original.status === 'ACTIVE' ? 'Aktif' : row.original.status === 'CANCELLED' ? 'İptal' : 'Süresi Dolmuş' }}
          </UBadge>
        </template>

        <template #actions-cell="{ row }">
          <UButton
            v-if="getPolicyStatus(row.original) === 'aktif'"
            label="İptal"
            icon="i-lucide-x-circle"
            color="warning"
            variant="ghost"
            size="xs"
            @click="confirmCancel(row.original.id)"
          />
        </template>

        <!-- Zeyil sub-table -->
        <template #expanded="{ row }">
          <div class="py-3">
            <div v-if="zeyilLoading[String(row.original.id)]" class="py-2">
              <SkeletonTable :rows="2" :cols="5" />
            </div>
            <div v-else-if="getZeyilHistory(row.original.policyNo).length === 0" class="text-sm text-muted text-center py-4">
              Zeyil geçmişi bulunamadı.
            </div>
            <div v-else>
              <div class="flex items-center gap-2 mb-2">
                <UIcon name="i-lucide-history" class="size-4 text-muted" />
                <span class="text-xs font-semibold text-muted uppercase tracking-wider">Zeyil Geçmişi</span>
              </div>
              <div class="border border-default rounded-lg overflow-x-auto">
                <table class="w-full table-fixed text-xs">
                  <colgroup>
                    <col style="width: 13%">
                    <col style="width: 9%">
                    <col style="width: 9%">
                    <col style="width: 9%">
                    <col style="width: 10%">
                    <col style="width: 10%">
                    <col style="width: 9%">
                    <col v-if="isAdmin" style="width: 12%">
                    <col v-if="isAdmin" style="width: 12%">
                    <col style="width: 7%">
                  </colgroup>
                  <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr>
                      <th class="px-3 py-2 text-left text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400 whitespace-nowrap">Zeyil</th>
                      <th class="px-2 py-2 text-left text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400">Tanzim Tarihi</th>
                      <th class="px-2 py-2 text-left text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400">Başlangıç Tarihi</th>
                      <th class="px-2 py-2 text-left text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400">Bitiş Tarihi</th>
                      <th class="px-3 py-2 text-right text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400 whitespace-nowrap">Brüt Prim</th>
                      <th class="px-3 py-2 text-right text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400 whitespace-nowrap">Net Prim</th>
                      <th class="px-3 py-2 text-left text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400 whitespace-nowrap">Tali Acente</th>
                      <th v-if="isAdmin" class="px-3 py-2 text-left text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400 whitespace-nowrap">Satış Temsilcisi</th>
                      <th v-if="isAdmin" class="px-3 py-2 text-left text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400 whitespace-nowrap">Sisteme Giren</th>
                      <th class="px-3 py-2 text-center text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400">İşlem</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="z in getZeyilHistory(row.original.policyNo)"
                      :key="z.id"
                      class="border-t border-default"
                      :class="z.isCancelled ? 'bg-error/5' : ''"
                    >
                      <td class="px-3 py-2 whitespace-nowrap">
                        <UBadge :color="getZeyilColor(z)" variant="solid" size="sm">
                          {{ getZeyilLabel(z) }}
                        </UBadge>
                      </td>
                      <td class="px-2 py-2 whitespace-nowrap tabular-nums">{{ formatDate(z.issuedAt || '') }}</td>
                      <td class="px-2 py-2 whitespace-nowrap tabular-nums">{{ formatDate(z.startsAt) }}</td>
                      <td class="px-2 py-2 whitespace-nowrap tabular-nums">{{ formatDate(z.expiresAt) }}</td>
                      <td class="px-3 py-2 whitespace-nowrap tabular-nums text-right" :class="z.grossPremium < 0 ? 'text-error' : ''">
                        {{ z.grossPremium.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                      </td>
                      <td class="px-3 py-2 whitespace-nowrap tabular-nums text-right" :class="z.netPremium < 0 ? 'text-error' : ''">
                        {{ z.netPremium.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                      </td>
                      <td class="px-3 py-2 whitespace-nowrap">
                        <UBadge
                          :color="z.productionType === 'SELF' ? 'primary' : z.productionType === 'INCOMING' ? 'info' : 'warning'"
                          variant="solid"
                          size="sm"
                        >
                          {{ getProdLabel(z.productionType) }}
                        </UBadge>
                      </td>
                      <td v-if="isAdmin" class="px-3 py-2 whitespace-nowrap">{{ z.soldByName || '-' }}</td>
                      <td v-if="isAdmin" class="px-3 py-2 whitespace-nowrap">{{ z.createdByName || '-' }}</td>
                      <td class="px-3 py-2 text-center">
                        <div class="flex items-center justify-center gap-1">
                          <UTooltip text="Düzenle">
                            <UButton
                              icon="i-lucide-pencil"
                              color="neutral"
                              variant="ghost"
                              size="xs"
                              @click="editZeyil(z)"
                            />
                          </UTooltip>
                          <UTooltip v-if="can('policies.delete')" text="Sil">
                            <UButton
                              icon="i-lucide-trash-2"
                              color="error"
                              variant="ghost"
                              size="xs"
                              @click="confirmZeyilDelete(z)"
                            />
                          </UTooltip>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot class="border-t-2 border-default bg-gray-50 dark:bg-gray-800/50">
                    <tr>
                      <td :colspan="5" class="px-3 py-2 text-right text-xs">TOPLAM</td>
                      <td class="px-3 py-2 text-right font-semibold text-xs">
                        {{ getZeyilHistory(row.original.policyNo).reduce((s, z) => s + z.grossPremium, 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                      </td>
                      <td class="px-3 py-2 text-right font-semibold text-xs">
                        {{ getZeyilHistory(row.original.policyNo).reduce((s, z) => s + z.netPremium, 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                      </td>
                      <td class="px-3 py-2"></td>
                      <td v-if="isAdmin" class="px-3 py-2"></td>
                      <td v-if="isAdmin" class="px-3 py-2"></td>
                      <td class="px-3 py-2"></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </div>
        </template>
      </UTable>
      </div>

      <!-- Pagination loading bar -->
      <div class="h-[2px] w-full overflow-hidden">
        <div v-if="policies.loading.value" class="h-full bg-primary nav-loading-bar" />
      </div>

      <!-- Pagination -->
      <div class="flex flex-col sm:flex-row items-center gap-2 py-3 sm:justify-between">
        <div class="flex items-center gap-2 text-sm text-muted">
          <span class="hidden sm:inline">Sayfa başına satır</span>
          <USelect
            :model-value="policies.limit.value"
            :items="[{ label: '10', value: 10 }, { label: '15', value: 15 }, { label: '25', value: 25 }, { label: '50', value: 50 }]"
            value-key="value"
            size="xs"
            class="w-16"
            @update:model-value="(v: any) => { policies.limit.value = v; policies.setPage(1) }"
          />
          <span class="text-xs sm:text-sm">{{ (policies.page.value - 1) * policies.limit.value + 1 }} - {{ Math.min(policies.page.value * policies.limit.value, policies.total.value) }} / {{ policies.total.value }}</span>
        </div>

        <div v-if="policies.total.value > policies.limit.value" class="flex items-center gap-1">
          <UButton icon="i-lucide-chevron-left" size="xs" color="neutral" variant="outline" :disabled="policies.page.value <= 1" class="sm:hidden" @click="policies.setPage(policies.page.value - 1)" />
          <span class="sm:hidden text-xs text-muted px-2">{{ policies.page.value }} / {{ Math.ceil(policies.total.value / policies.limit.value) }}</span>
          <UButton icon="i-lucide-chevron-right" size="xs" color="neutral" variant="outline" :disabled="policies.page.value >= Math.ceil(policies.total.value / policies.limit.value)" class="sm:hidden" @click="policies.setPage(policies.page.value + 1)" />
          <UPagination
            class="hidden sm:flex"
            :default-page="policies.page.value"
            :items-per-page="policies.limit.value"
            :total="policies.total.value"
            @update:page="policies.setPage"
          />
        </div>
      </div>
    </UCard>

    <!-- Delete Confirm -->
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Poliçe Sil">
      <template #body>
        <p>Bu poliçeyi silmek istediğinize emin misiniz? Bu işlem geri alınamaz.</p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" size="xl"  @click="isDeleteModalOpen = false" />
          <UButton label="Sil" color="error" size="xl"  @click="doDelete" />
        </div>
      </template>
    </UModal>

    <!-- Cancel Modal -->
    <UModal :dismissible="false" v-model:open="isCancelModalOpen" title="Poliçe İptal Et" class="sm:max-w-lg">
      <template #body>
        <UForm :schema="cancelSchema" :state="cancelForm" @submit="doCancel">
          <div class="space-y-4">
            <div class="p-3 bg-warning/10 rounded-lg text-sm text-warning">
              Bu poliçeyi iptal edeceksiniz. İptal zeyili otomatik oluşturulacaktır.
            </div>

            <!-- İptal Tarihi -->
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="cancelDateDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-cdate">
                <template #trailing>
                  <UPopover v-model:open="cancelDatePopoverOpen" :ui="{ content: 'p-0' }">
                    <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" />
                    <template #content>
                      <UCalendar locale="tr-TR" :model-value="cancelCalendarDate" class="p-2" @update:model-value="onCancelCalendarChange" />
                    </template>
                  </UPopover>
                </template>
              </UInput>
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-cdate:top-0 peer-focus-within/fl-cdate:-translate-y-1/2 peer-focus-within/fl-cdate:text-xs peer-focus-within/fl-cdate:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-cdate:top-0 peer-has-[input:not(:placeholder-shown)]/fl-cdate:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-cdate:text-xs peer-has-[input:not(:placeholder-shown)]/fl-cdate:text-[var(--ui-text-highlighted)]">İptal Tarihi</label>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <!-- İade Brüt Prim -->
              <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                <UInput :model-value="cancelGrossDisplay" placeholder=" " inputmode="decimal" class="w-full peer/fl-cgross" @update:model-value="onCancelCurrencyInput('cancelGrossRefund', $event)" @blur="onCancelCurrencyBlur('cancelGrossRefund')">
                  <template #trailing><span class="text-xs text-muted">TL</span></template>
                </UInput>
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-cgross:top-0 peer-focus-within/fl-cgross:-translate-y-1/2 peer-focus-within/fl-cgross:text-xs peer-focus-within/fl-cgross:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-cgross:top-0 peer-has-[input:not(:placeholder-shown)]/fl-cgross:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-cgross:text-xs peer-has-[input:not(:placeholder-shown)]/fl-cgross:text-[var(--ui-text-highlighted)]">İade Brüt Prim</label>
              </div>
              <!-- İade Net Prim -->
              <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                <UInput :model-value="cancelNetDisplay" placeholder=" " inputmode="decimal" class="w-full peer/fl-cnet" @update:model-value="onCancelCurrencyInput('cancelNetRefund', $event)" @blur="onCancelCurrencyBlur('cancelNetRefund')">
                  <template #trailing><span class="text-xs text-muted">TL</span></template>
                </UInput>
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-cnet:top-0 peer-focus-within/fl-cnet:-translate-y-1/2 peer-focus-within/fl-cnet:text-xs peer-focus-within/fl-cnet:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-cnet:top-0 peer-has-[input:not(:placeholder-shown)]/fl-cnet:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-cnet:text-xs peer-has-[input:not(:placeholder-shown)]/fl-cnet:text-[var(--ui-text-highlighted)]">İade Net Prim</label>
              </div>
            </div>
            <p v-if="cancelPrimError" class="text-xs text-[var(--ui-error)] -mt-2">{{ cancelPrimError }}</p>

            <!-- İptal poliçesi dosya yükleme -->
            <div class="border border-dashed border-neutral-300 rounded-lg p-3">
              <div v-if="!cancelFile" class="flex items-center justify-center gap-2 cursor-pointer" @click="cancelFileInput?.click()">
                <UIcon name="i-lucide-upload" class="size-4 text-muted" />
                <span class="text-sm text-muted">İptal poliçesi yükleyin (PDF, JPG, PNG)</span>
                <input ref="cancelFileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="onCancelFileChange" />
              </div>
              <div v-else class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <UIcon name="i-lucide-file-text" class="size-4 text-primary" />
                  <span class="text-sm font-medium truncate max-w-[250px]">{{ cancelFile.name }}</span>
                  <span class="text-xs text-muted">{{ Math.round(cancelFile.size / 1024) }} KB</span>
                </div>
                <UButton icon="i-lucide-x" size="xs" color="error" variant="ghost" @click="cancelFile = null" />
              </div>
            </div>

            <div class="flex justify-end gap-2">
              <UButton label="Vazgeç" color="neutral" variant="outline" size="xl"  :disabled="cancellingInProgress" @click="isCancelModalOpen = false" />
              <UButton label="İptal Et" color="warning" size="xl"  type="submit" :loading="cancellingInProgress" :disabled="cancellingInProgress || !!cancelPrimError" />
            </div>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Zeyil Delete Confirm -->
    <UModal :dismissible="false" v-model:open="isZeyilDeleteModalOpen" title="Zeyil Sil">
      <template #body>
        <div class="flex flex-col items-center text-center gap-3">
          <div class="flex items-center justify-center size-12 rounded-full bg-red-50">
            <UIcon name="i-lucide-triangle-alert" class="size-6 text-red-500" />
          </div>
          <div>
            <p class="font-medium">Bu zeyili silmek istediğinize emin misiniz?</p>
            <p class="text-sm text-muted mt-1">Bu işlem geri alınamaz. Silinen zeyil kaydı kurtarılamaz.</p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" size="xl"  @click="isZeyilDeleteModalOpen = false" />
          <UButton label="Evet, Sil" color="error" size="xl"  @click="doZeyilDelete" />
        </div>
      </template>
    </UModal>

    <!-- Poliçe Detay Slideover -->
    <PolicyDetailSlideover
      v-model:open="isDetailOpen"
      :policy-id="detailPolicyId"
      @edit="onDetailEdit"
    />

  </div>
</template>

<style scoped>
.select-fl :deep(button) {
  min-height: 50px !important;
  height: auto !important;
}
/* Mobilde expand kolonu gizle (zeyil geçmişi mobilde gereksiz) */
@media (max-width: 639px) {
  :deep(.policeler-table th:nth-child(1)),
  :deep(.policeler-table td:nth-child(1)) {
    display: none;
  }
}

/* Kolon genişlikleri — toplam %100 */
:deep(.policeler-table th:nth-child(1))  { width: 3%;  }  /* expand       */
:deep(.policeler-table th:nth-child(2))  { width: 11%; }  /* policyNo     */
:deep(.policeler-table th:nth-child(3))  { width: 8%;  }  /* insuranceName */
:deep(.policeler-table th:nth-child(4))  { width: 12%; }  /* customerName  */
:deep(.policeler-table th:nth-child(5))  { width: 7%;  }  /* plateNo       */
:deep(.policeler-table th:nth-child(6))  { width: 8%;  }  /* issuedAt      */
:deep(.policeler-table th:nth-child(7))  { width: 8%;  }  /* startsAt      */
:deep(.policeler-table th:nth-child(8))  { width: 8%;  }  /* expiresAt     */
:deep(.policeler-table th:nth-child(9))  { width: 9%;  }  /* grossPremium  */
:deep(.policeler-table th:nth-child(10)) { width: 8%;  }  /* income        */
:deep(.policeler-table th:nth-child(11)) { width: 10%; }  /* policyStatus  */
:deep(.policeler-table th:nth-child(12)) { width: 8%;  }  /* actions       */

/* Sadece müşteri adı kolonu (4. kolon) kırpılır */
:deep(.policeler-table th:nth-child(4)),
:deep(.policeler-table td:nth-child(4)) {
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
