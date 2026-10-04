<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

import { CalendarDate } from '@internationalized/date'

const api = useApi()
const { get, post, put, del } = useApi()
const { user } = useAuth()
const searchOpen = useState('global-search-open', () => false)
const toast = useToast()

const isAdmin = computed(() => user.value?.role === 'admin')

const loading = ref(true)
const stats = ref<any>(null)
const charts = ref<any>(null)
const renewals = shallowRef<any[]>([])
const renewalLoading = ref(false)
const renewalStatuses = ref(['PENDING', 'IN_PROGRESS'])
const showFollowUpCalls = ref(false)

const followUpCount = computed(() => renewals.value.filter(r => r.type === 'FOLLOW_UP_CALL' && !['COMPLETED','EXPIRED','CANCELLED'].includes(r.status)).length)


function selectStatus(status: string) {
  showFollowUpCalls.value = false
  if (status === 'EXPIRED') {
    // Süresi Geçen: toggle — aktifse default'a dön, değilse exclusive aç
    renewalStatuses.value = renewalStatuses.value.includes('EXPIRED')
      ? ['PENDING', 'IN_PROGRESS']
      : ['EXPIRED']
    return
  }
  // Bekleyen / Devam Eden: toggle — en az biri aktif kalmalı
  const current = renewalStatuses.value.filter(s => s !== 'EXPIRED')
  if (current.includes(status)) {
    // Zaten aktif — sadece birden fazlaysa kapat
    if (current.length > 1) {
      renewalStatuses.value = current.filter(s => s !== status)
    }
  } else {
    // Pasif — ekle
    renewalStatuses.value = [...current, status]
  }
}

const completedFilter = ref<{ groupLabel: string, result: string } | null>(null)

function showCompletedTasks(groupLabel: string, result: string) {
  if (completedFilter.value?.groupLabel === groupLabel && completedFilter.value?.result === result) {
    completedFilter.value = null
  } else {
    completedFilter.value = { groupLabel, result }
  }
}

function clearCompletedFilter() {
  completedFilter.value = null
}

// Users for assign
const users = ref<{ id: number; name: string }[]>([])

async function fetchUsers() {
  if (!isAdmin.value) return
  try {
    // dropdown=1 sadece aktif kullanicilari doner
    const res = await get<any>('users?dropdown=1')
    users.value = (res.data || res || []).map((u: any) => ({ id: u.id, name: u.name }))
  } catch {}
}

// Filtre dropdown'lari icin musteri ve sirket listeleri
const filterCustomers = ref<{ label: string, value: number }[]>([])
const filterCompanies = ref<{ label: string, value: number }[]>([])
async function fetchFilterCustomers() {
  try {
    const res = await get<any>('customers/list-all')
    filterCustomers.value = (res.data || []).map((c: any) => ({
      label: c.identityNo ? `${c.name} (${c.identityNo})` : c.name,
      value: c.id
    }))
  } catch {}
}
async function fetchFilterCompanies() {
  try {
    const res = await get<any>('companies?all=1')
    filterCompanies.value = (res.data || [])
      .map((c: any) => ({ label: c.name, value: c.id }))
      .sort((a: any, b: any) => a.label.localeCompare(b.label, 'tr'))
  } catch {}
}

const userAssignOptions = computed(() =>
  users.value.map(u => ({ label: u.name, value: u.id }))
)

// Renewal task modals
const selectedTask = ref<any>(null)
const showAssignModal = ref(false)
const showCompleteModal = ref(false)
const showDeleteConfirm = ref(false)
const savingAssign = ref(false)
const savingComplete = ref(false)

const assignForm = ref({ assignedTo: undefined as number | undefined })
const assignDeadlineDate = ref<CalendarDate>()
const assignDeadlineDateOpen = ref(false)

const completeForm = ref({
  result: '',
  resultReason: '',
  resultNote: '',
  remindNextYear: false,
  ileriVadeDate: '',
  hasVehicle: '' as '' | 'YES' | 'NO',
  registrationReceived: '' as '' | 'YES' | 'NO',
})

// Araç takibi
const vehicleCheckResult = ref<{ hasPlatedPolicy: boolean; hasVehicle: string } | null>(null)

async function checkCustomerVehicle(customerId: number) {
  try {
    const res = await get<any>(`customers/${customerId}/vehicle-status`)
    vehicleCheckResult.value = res.data || null
  } catch {
    vehicleCheckResult.value = null
  }
}

const isRuhsatTask = computed(() => {
  return selectedTask.value?.type === 'FOLLOW_UP_CALL' && selectedTask.value?.title?.startsWith('Ruhsat Takibi')
})

const showVehicleQuestion = computed(() => {
  if (!selectedTask.value) return false
  if (selectedTask.value.type !== 'FOLLOW_UP_CALL') return false
  if (completeForm.value.result !== 'CALLED') return false
  if (!vehicleCheckResult.value) return false
  if (isRuhsatTask.value) return false
  if (vehicleCheckResult.value.hasPlatedPolicy) return false
  if (vehicleCheckResult.value.hasVehicle === 'YES') return false
  return true
})

const showRegistrationQuestion = computed(() => {
  if (!showVehicleQuestion.value && !isRuhsatTask.value) return false
  if (isRuhsatTask.value) return completeForm.value.result === 'CALLED'
  return completeForm.value.hasVehicle === 'YES'
})

const renewalResultOptions = [
  { label: 'Yenilendi', value: 'RENEWED' },
  { label: 'Yenilenmedi', value: 'NOT_RENEWED' }
]

const offerResultOptions = [
  { label: 'Teklif Onaylandı', value: 'OFFER_APPROVED' },
  { label: 'Teklif Onaylanmadı', value: 'OFFER_REJECTED' }
]

const crossSellResultOptions = [
  { label: 'Olumlu (Poliçe Yapıldı)', value: 'DONE' },
  { label: 'Olumsuz', value: 'FAILED' }
]

const commonFailReasons = [
  'Fiyat Yüksek',
  'Başka Şirketten Alınmış',
  'Araç/Konut Satıldı',
  'İhtiyaç Duymuyor',
  'Farklı Acentenin Müşterisi'
]

const offerFailReasons = [...commonFailReasons, 'İleri Vadede Düşünüyor']
const renewalFailReasons = [...commonFailReasons, 'İleri Vadede Düşünüyor']

const referenceResultOptions = [
  { label: 'Satış Yapıldı', value: 'DONE' },
  { label: 'Satış Yapılamadı', value: 'FAILED' }
]

const followUpCallResultOptions = [
  { label: 'Ulaşıldı (Başarılı)', value: 'CALLED' },
  { label: 'Ulaşılamadı', value: 'NOT_REACHED' },
  { label: 'Müsait Değil', value: 'NOT_AVAILABLE' }
]

const followUpNotReachedReasons = [
  'Telefon açılmadı',
  'Numara yanlış/kapalı',
  'Meşgul',
  'Diğer'
]

const followUpNotAvailableReasons = [
  'Müsait değil',
  'Sonra arayın dedi',
  'Diğer'
]

function followUpStageLabel(stage?: string): string {
  const map: Record<string, string> = { '2ND_MONTH': '2. Ay Takip', '6TH_MONTH': '6. Ay Kontrol', '10TH_MONTH': '10. Ay Isıtma' }
  return map[stage || ''] || 'Takip Araması'
}

function typeIcon(type: string): string {
  if (type === 'RENEWAL') return 'i-lucide-refresh-cw'
  if (type === 'OFFER') return 'i-lucide-file-text'
  if (type === 'CROSS_SELL') return 'i-lucide-repeat-2'
  if (type === 'REFERENCE') return 'i-lucide-user-plus'
  if (type === 'FOLLOW_UP_CALL') return 'i-lucide-phone-call'
  return 'i-lucide-clipboard-list'
}

const completeResultOptions = computed(() => {
  if (!selectedTask.value) return []
  if (selectedTask.value.type === 'RENEWAL') return renewalResultOptions
  if (selectedTask.value.type === 'OFFER') return offerResultOptions
  if (selectedTask.value.type === 'CROSS_SELL') return crossSellResultOptions
  if (selectedTask.value.type === 'REFERENCE') return referenceResultOptions
  if (selectedTask.value.type === 'FOLLOW_UP_CALL') return followUpCallResultOptions
  return renewalResultOptions
})

const completeReasonOptions = computed(() => {
  if (completeForm.value.result === 'OFFER_REJECTED') return offerFailReasons
  if (completeForm.value.result === 'NOT_RENEWED') return renewalFailReasons
  if (completeForm.value.result === 'FAILED') return commonFailReasons
  if (completeForm.value.result === 'NOT_REACHED') return followUpNotReachedReasons
  if (completeForm.value.result === 'NOT_AVAILABLE') return followUpNotAvailableReasons
  return []
})

const isNegativeResult = computed(() =>
  ['NOT_RENEWED', 'OFFER_REJECTED', 'FAILED', 'NOT_REACHED', 'NOT_AVAILABLE'].includes(completeForm.value.result)
)

const isIleriVade = computed(() =>
  completeForm.value.resultReason === 'İleri Vadede Düşünüyor' &&
  (
    (completeForm.value.result === 'OFFER_REJECTED' && selectedTask.value?.type === 'OFFER') ||
    (completeForm.value.result === 'NOT_RENEWED' && selectedTask.value?.type === 'RENEWAL')
  )
)

const canSubmitComplete = computed(() => {
  if (!completeForm.value.result) return false
  if (isNegativeResult.value && !completeForm.value.resultReason) return false
  if (isIleriVade.value && !completeForm.value.ileriVadeDate) return false
  return true
})

function calendarDateToStr(d: any): string {
  if (!d) return ''
  return `${d.year}-${String(d.month).padStart(2, '0')}-${String(d.day).padStart(2, '0')}`
}

function strToCalendarDate(s?: string): CalendarDate | undefined {
  if (!s) return undefined
  let m = s.match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (m) return new CalendarDate(+m[1], +m[2], +m[3])
  return undefined
}

function calendarDateLabel(d: any): string {
  if (!d) return ''
  return `${String(d.day).padStart(2, '0')}/${String(d.month).padStart(2, '0')}/${d.year}`
}

function statusColor(status: string): string {
  const map: Record<string, string> = { PENDING: 'warning', IN_PROGRESS: 'info', COMPLETED: 'success', EXPIRED: 'error', CANCELLED: 'neutral' }
  return map[status] || 'neutral'
}


function statusLabel(status: string): string {
  const map: Record<string, string> = { PENDING: 'Bekleyen', IN_PROGRESS: 'Devam Eden', COMPLETED: 'Tamamlanan', EXPIRED: 'Süresi Geçen', CANCELLED: 'İptal' }
  return map[status] || status
}

function priorityColor(priority: string): string {
  const map: Record<string, string> = { URGENT: 'error', HIGH: 'warning', MEDIUM: 'info', LOW: 'neutral' }
  return map[priority] || 'neutral'
}

function priorityLabel(priority: string): string {
  const map: Record<string, string> = { URGENT: 'Acil', HIGH: 'Yüksek', MEDIUM: 'Orta', LOW: 'Düşük' }
  return map[priority] || priority
}

function resultLabel(result: string): string {
  const map: Record<string, string> = { RENEWED: 'Yenilendi', NOT_RENEWED: 'Yenilenmedi', OFFER_APPROVED: 'Teklif Onaylandı', OFFER_REJECTED: 'Teklif Reddedildi', DONE: 'Olumlu', FAILED: 'Olumsuz', CALLED: 'Ulaşıldı', NOT_REACHED: 'Ulaşılamadı', NOT_AVAILABLE: 'Müsait Değil' }
  return map[result] || result
}

function resultColor(result: string): string {
  const map: Record<string, string> = { RENEWED: 'success', OFFER_APPROVED: 'success', DONE: 'success', CALLED: 'success', NOT_RENEWED: 'error', OFFER_REJECTED: 'error', FAILED: 'error', NOT_REACHED: 'warning', NOT_AVAILABLE: 'warning' }
  return map[result] || 'neutral'
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

function formatDate(dateStr?: string): string {
  if (!dateStr) return '-'
  try { return new Date(dateStr).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' }) }
  catch { return dateStr }
}

function isOverdue(deadline?: string): boolean {
  if (!deadline) return false
  return new Date(deadline) < new Date()
}

function daysUntil(dateStr?: string): string {
  if (!dateStr) return ''
  const diff = Math.ceil((new Date(dateStr).getTime() - Date.now()) / (1000 * 60 * 60 * 24))
  if (diff < 0) return `${Math.abs(diff)} gün gecti`
  if (diff === 0) return 'Bugün'
  if (diff === 1) return 'Yarin'
  return `${diff} gün kaldi`
}

const statsYear = ref(new Date().getFullYear())
const statsMonth = ref(new Date().getMonth() + 1)
const portfolioYear = ref(new Date().getFullYear())

const statsMonthLabel = computed(() =>
  new Date(statsYear.value, statsMonth.value - 1, 1).toLocaleString('tr-TR', { month: 'long', year: 'numeric' })
)

function prevStatsMonth() {
  if (statsMonth.value === 1) { statsMonth.value = 12; statsYear.value-- }
  else statsMonth.value--
  fetchStats()
}
function nextStatsMonth() {
  const now = new Date()
  if (statsYear.value === now.getFullYear() && statsMonth.value === now.getMonth() + 1) return
  if (statsMonth.value === 12) { statsMonth.value = 1; statsYear.value++ }
  else statsMonth.value++
  fetchStats()
}

const isCurrentMonth = computed(() => {
  const now = new Date()
  return statsYear.value === now.getFullYear() && statsMonth.value === now.getMonth() + 1
})

function prevPortfolioYear() {
  portfolioYear.value--
  fetchStats()
}
function nextPortfolioYear() {
  if (portfolioYear.value >= new Date().getFullYear()) return
  portfolioYear.value++
  fetchStats()
}

const isCurrentPortfolioYear = computed(() => portfolioYear.value >= new Date().getFullYear())

async function fetchStats() {
  const res = await api.get(`/dashboard/stats?year=${statsYear.value}&month=${statsMonth.value}&portfolioYear=${portfolioYear.value}`)
  if (res.success) stats.value = res.data
}

const chartView = ref<'all' | 'self' | 'outgoing'>('all')

async function fetchCharts() {
  const params = chartView.value !== 'all' ? `?view=${chartView.value}` : ''
  const res = await api.get(`/dashboard/charts${params}`)
  if (res.success) {
    charts.value = res.data
  }
}

watch(chartView, () => fetchCharts())

async function fetchRenewals(silent = false) {
  if (!silent) renewalLoading.value = true
  // Tüm statüleri tek seferde çek — filtreleme client-side yapılır
  const params: any = { statuses: 'PENDING,IN_PROGRESS,COMPLETED,EXPIRED' }
  const res = await api.get('/dashboard/renewals', params)
  if (res.success) renewals.value = res.data
  if (!silent) renewalLoading.value = false
}

// renewalStatuses değişiminde API çağrısı yok — filtreleme client-side (filteredRenewals computed)

// Tablo kolon filtreleri (client-side, mevcut yüklü kayıtlar üzerinde)
const colFilters = ref({
  customer: [] as number[],
  company: [] as number[],
  plate: '',
  insurance: [] as string[],
  expiresAt: { start: '', end: '' },
  prodType: [] as string[],
  assignedTo: [] as number[],
  daysLeft: ''
})

const daysLeftOptions = [
  { label: 'Süresi Geçmiş', value: 'overdue' },
  { label: 'Bugün', value: 'today' },
  { label: '1 - 7 gün', value: '1-7' },
  { label: '8 - 30 gün', value: '8-30' },
  { label: '30+ gün', value: '30+' }
]

const prodFilterOptions = [
  { label: 'Poliçem', value: 'SELF' },
  { label: 'Tali Gelen', value: 'INCOMING' },
  { label: 'Tali Giden', value: 'OUTGOING' }
]

const assignedFilterOptions = computed(() => [
  { label: 'Atanmamış', value: 0 },
  ...users.value.map(u => ({ label: u.name, value: u.id }))
])

const insuranceFilterOptions = computed(() => {
  const set = new Set<string>()
  renewals.value.forEach(r => r.insuranceName && set.add(r.insuranceName))
  return Array.from(set).sort((a, b) => a.localeCompare(b, 'tr')).map(n => ({ label: n, value: n }))
})

const filteredRenewals = computed(() => {
  const cf = colFilters.value
  const statuses = renewalStatuses.value
  return renewals.value.filter(r => {
    // Takip aramaları ayrı görünür
    if (showFollowUpCalls.value) {
      if (r.type !== 'FOLLOW_UP_CALL') return false
    } else {
      if (r.type === 'FOLLOW_UP_CALL') return false
      // Statü filtresi (Bekleyen / Devam Eden)
      // COMPLETED/EXPIRED/CANCELLED her zaman geçer (grup özetleri için)
      // Süresi Geçen butonu aktifken tüm aktif görevler görünür (groupedRenewals tarih filtresi ayırır)
      if (!['COMPLETED','EXPIRED','CANCELLED'].includes(r.status) && !statuses.includes('EXPIRED')) {
        if (!statuses.includes(r.status)) return false
      }
    }
    if (cf.customer.length && (!r.customerId || !cf.customer.includes(r.customerId))) return false
    if (cf.company.length) {
      // companyId backend'de gelmiyor; companyName ile eslestir
      const co = filterCompanies.value.find(c => c.label === r.companyName)
      if (!co || !cf.company.includes(co.value)) return false
    }
    if (cf.plate) {
      const q = cf.plate.toLowerCase()
      const hay = ((r.plateNo || '') + ' ' + (r.registrationNo || '')).toLowerCase()
      if (!hay.includes(q)) return false
    }
    if (cf.insurance.length && !cf.insurance.includes(r.insuranceName)) return false
    if (cf.prodType.length && !cf.prodType.includes(r.productionType)) return false
    if (cf.assignedTo.length) {
      const wantUnassigned = cf.assignedTo.includes(0)
      const matched = r.assignedTo && cf.assignedTo.includes(r.assignedTo)
      const unassigned = !r.assignedTo && wantUnassigned
      if (!matched && !unassigned) return false
    }
    if (cf.expiresAt.start && (!r.expiresAt || r.expiresAt < cf.expiresAt.start)) return false
    if (cf.expiresAt.end && (!r.expiresAt || r.expiresAt > cf.expiresAt.end)) return false
    if (cf.daysLeft) {
      const today = new Date(); today.setHours(0,0,0,0)
      const d = r.expiresAt ? Math.round((new Date(r.expiresAt).setHours(0,0,0,0) - today.getTime()) / 86400000) : null
      if (d === null) return false
      if (cf.daysLeft === 'overdue' && d >= 0) return false
      if (cf.daysLeft === 'today' && d !== 0) return false
      if (cf.daysLeft === '1-7' && (d < 1 || d > 7)) return false
      if (cf.daysLeft === '8-30' && (d < 8 || d > 30)) return false
      if (cf.daysLeft === '30+' && d <= 30) return false
    }
    return true
  })
})

const activeFilterCount = computed(() => {
  const cf = colFilters.value
  let n = 0
  if (cf.customer.length) n++
  if (cf.company.length) n++
  if (cf.plate) n++
  if (cf.insurance.length) n++
  if (cf.prodType.length) n++
  if (cf.assignedTo.length) n++
  if (cf.expiresAt.start || cf.expiresAt.end) n++
  if (cf.daysLeft) n++
  return n
})

function clearAllFilters() {
  colFilters.value = {
    customer: [], company: [], plate: '',
    insurance: [], expiresAt: { start: '', end: '' },
    prodType: [], assignedTo: [], daysLeft: ''
  }
}

// Yerel tarih string (timezone sorunu olmadan)
function toLocalDateStr(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// Tarih gruplaması — tek pass ile
const groupedRenewals = computed(() => {
  const items = filteredRenewals.value
  const today = new Date()
  today.setHours(0, 0, 0, 0)

  const todayStr = toLocalDateStr(today)
  const tomorrow = new Date(today)
  tomorrow.setDate(tomorrow.getDate() + 1)
  const tomorrowStr = toLocalDateStr(tomorrow)
  const dayOfWeek = today.getDay()
  const endOfWeek = new Date(today)
  endOfWeek.setDate(today.getDate() + (7 - dayOfWeek))
  const endOfWeekStr = toLocalDateStr(endOfWeek)
  const endOfNextWeek = new Date(endOfWeek)
  endOfNextWeek.setDate(endOfWeek.getDate() + 7)
  const endOfNextWeekStr = toLocalDateStr(endOfNextWeek)

  const successResults = new Set(['RENEWED', 'OFFER_APPROVED', 'DONE'])
  const failResults = new Set(['NOT_RENEWED', 'OFFER_REJECTED', 'FAILED'])
  const doneStatuses = new Set(['COMPLETED', 'EXPIRED', 'CANCELLED'])

  // Grup tanımları
  const groupDefs = [
    { key: 'overdue', label: 'Süresi Geçen', color: 'text-red-600', icon: 'i-lucide-alert-circle', showStats: false },
    { key: 'today', label: 'Bugün', color: 'text-orange-600', icon: 'i-lucide-clock', showStats: true },
    { key: 'tomorrow', label: 'Yarın', color: 'text-amber-600', icon: 'i-lucide-calendar', showStats: true },
    { key: 'thisWeek', label: 'Bu Hafta', color: 'text-blue-600', icon: 'i-lucide-calendar-days', showStats: true },
    { key: 'nextWeek', label: 'Gelecek Hafta', color: 'text-indigo-600', icon: 'i-lucide-calendar-range', showStats: true },
    { key: 'later', label: 'Daha Sonra', color: 'text-gray-500', icon: 'i-lucide-calendar-clock', showStats: false },
    { key: 'noDate', label: 'Tarihsiz', color: 'text-gray-400', icon: 'i-lucide-calendar-off', showStats: false },
  ]

  // Tek pass: her item'ı grubuna yerleştir
  const buckets: Record<string, { active: any[], done: any[], successCount: number, failCount: number }> = {}
  for (const def of groupDefs) buckets[def.key] = { active: [], done: [], successCount: 0, failCount: 0 }

  for (let i = 0; i < items.length; i++) {
    const r = items[i]
    const d = r.expiresAt as string | undefined
    // Tarih grubunu belirle
    let key: string
    if (!d) { key = 'noDate' }
    else if (d < todayStr) { key = 'overdue' }
    else if (d === todayStr) { key = 'today' }
    else if (d === tomorrowStr) { key = 'tomorrow' }
    else if (d <= endOfWeekStr) { key = 'thisWeek' }
    else if (d <= endOfNextWeekStr) { key = 'nextWeek' }
    else { key = 'later' }

    const bucket = buckets[key]
    // Süresi geçen grubunda EXPIRED statüsü aktif sayılır (sistem kapatmış ama tamamlanmamış)
    const isExpiredInOverdue = key === 'overdue' && r.status === 'EXPIRED'
    if (doneStatuses.has(r.status) && !isExpiredInOverdue) {
      bucket.done.push(r)
      if (successResults.has(r.result)) bucket.successCount++
      else if (failResults.has(r.result)) bucket.failCount++
    } else {
      bucket.active.push(r)
    }
  }

  const onlyOverdue = !showFollowUpCalls.value && renewalStatuses.value.includes('EXPIRED') &&
    !renewalStatuses.value.some(s => ['PENDING', 'IN_PROGRESS'].includes(s))
  const showingOverdue = showFollowUpCalls.value || renewalStatuses.value.includes('EXPIRED')

  // Takip aramalarında sıra: bugün önce, süresi geçen sonda
  const order = showFollowUpCalls.value
    ? ['today', 'tomorrow', 'thisWeek', 'nextWeek', 'later', 'noDate', 'overdue']
    : ['overdue', 'today', 'tomorrow', 'thisWeek', 'nextWeek', 'later', 'noDate']

  type ResultStats = { result: string, label: string, count: number, color: string }[]
  const groups: { label: string, color: string, icon: string, items: any[], totalWithCompleted: number, completedStats: ResultStats }[] = []

  for (const key of order) {
    const def = groupDefs.find(g => g.key === key)!
    const bucket = buckets[key]

    if (key === 'overdue' && !showingOverdue) continue
    if (onlyOverdue && key !== 'overdue') continue

    const showCompletedForGroup = completedFilter.value?.groupLabel === def.label && def.showStats
    const tableItems = showCompletedForGroup ? [...bucket.active, ...bucket.done] : bucket.active
    if (tableItems.length === 0) continue

    const stats: ResultStats = []
    if (def.showStats) {
      if (bucket.successCount > 0) stats.push({ result: 'SUCCESS', label: 'Satış Yapıldı', count: bucket.successCount, color: 'text-green-600 dark:text-green-400' })
      if (bucket.failCount > 0) stats.push({ result: 'FAIL', label: 'Satış Yapılamadı', count: bucket.failCount, color: 'text-red-500 dark:text-red-400' })
    }

    groups.push({
      label: def.label,
      color: def.color,
      icon: def.icon,
      items: tableItems,
      totalWithCompleted: bucket.active.length + (def.showStats ? bucket.done.length : 0),
      completedStats: stats
    })
  }

  return groups
})

// Özet kartları
const renewalSummary = computed(() => {
  const items = filteredRenewals.value
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const todayStr = toLocalDateStr(today)

  const dayOfWeek = today.getDay()
  const endOfWeek = new Date(today)
  endOfWeek.setDate(today.getDate() + (7 - dayOfWeek))
  const endOfWeekStr = toLocalDateStr(endOfWeek)

  // Bu haftanın Cumartesi ve Pazar günleri
  const saturday = new Date(today)
  saturday.setDate(today.getDate() + (6 - dayOfWeek))
  const saturdayStr = toLocalDateStr(saturday)
  const sunday = new Date(saturday)
  sunday.setDate(saturday.getDate() + 1)
  const sundayStr = toLocalDateStr(sunday)

  const active = items.filter(r => !['COMPLETED','EXPIRED','CANCELLED'].includes(r.status))
  const overdue = active.filter(r => r.expiresAt && r.expiresAt < todayStr).length
  const todayCount = active.filter(r => r.expiresAt === todayStr).length
  const thisWeekCount = active.filter(r => r.expiresAt && r.expiresAt >= todayStr && r.expiresAt <= endOfWeekStr).length
  const weekendCount = active.filter(r => r.expiresAt === saturdayStr || r.expiresAt === sundayStr).length

  return { overdue, today: todayCount, thisWeek: thisWeekCount, weekend: weekendCount, total: active.length }
})

// Task actions
const prodLabels: Record<string, string> = {
  SELF: 'Poliçem',
  INCOMING: 'Tali Gelen',
  OUTGOING: 'Tali Giden',
}


async function startTask(task: any) {
  try {
    await put(`tasks/${task.id}`, { status: 'IN_PROGRESS' })
    toast.add({ title: 'Görev başlatıldı', color: 'success' })
    fetchRenewals(true)
  } catch (e: any) {
    toast.add({ title: 'İşlem başarısız', description: e.message, color: 'error' })
  }
}

async function cancelTask(task: any) {
  try {
    await put(`tasks/${task.id}`, { status: 'CANCELLED' })
    toast.add({ title: 'Görev iptal edildi', color: 'neutral' })
    fetchRenewals(true)
  } catch (e: any) {
    toast.add({ title: 'İşlem başarısız', description: e.message, color: 'error' })
  }
}

// Teklif calisildi olarak isaretle (status IN_PROGRESS) — satir sariya doner
async function markOfferWorked(task: any) {
  try {
    await put(`tasks/${task.id}`, { status: 'IN_PROGRESS' })
    const label = task.type === 'OFFER' ? 'Teklif' : 'Yenileme'
    toast.add({ title: `${label} çalışıldı olarak işaretlendi`, color: 'warning' })
    fetchRenewals(true)
  } catch (e: any) {
    toast.add({ title: 'İşlem başarısız', description: e.message, color: 'error' })
  }
}

// Teklif düzenleme
const editOfferTask = ref<any>(null)
const showEditOfferModal = ref(false)
function openEditOffer(task: any) {
  editOfferTask.value = task
  showEditOfferModal.value = true
}
function onEditOfferSaved() {
  showEditOfferModal.value = false
  editOfferTask.value = null
  fetchRenewals(true)
}

function openAssignModal(task: any) {
  selectedTask.value = task
  assignForm.value = { assignedTo: task.assignedTo || undefined }
  assignDeadlineDate.value = task.deadline ? strToCalendarDate(task.deadline) : undefined
  showAssignModal.value = true
}

async function assignTask() {
  if (!selectedTask.value) return
  savingAssign.value = true
  try {
    await post(`tasks/${selectedTask.value.id}/assign`, {
      assignedTo: assignForm.value.assignedTo,
      deadline: calendarDateToStr(assignDeadlineDate.value) || undefined
    })
    toast.add({ title: 'Görev atandı', color: 'success' })
    showAssignModal.value = false
    fetchRenewals(true)
  } catch (e: any) {
    toast.add({ title: 'Atama başarısız', description: e.message, color: 'error' })
  } finally {
    savingAssign.value = false
  }
}

function openCompleteModal(task: any) {
  selectedTask.value = task
  completeForm.value = {
    result: '',
    resultReason: '',
    resultNote: '',
    remindNextYear: false,
    ileriVadeDate: '',
    hasVehicle: '',
    registrationReceived: '',
  }
  vehicleCheckResult.value = null
  // FOLLOW_UP_CALL ise müşterinin araç durumunu kontrol et
  if (task.type === 'FOLLOW_UP_CALL' && task.customerId) {
    checkCustomerVehicle(task.customerId)
  }
  showCompleteModal.value = true
}

async function submitComplete() {
  if (!selectedTask.value) return
  savingComplete.value = true
  try {
    const payload: Record<string, any> = {
      result: completeForm.value.result,
    }
    if (completeForm.value.resultReason) payload.resultReason = completeForm.value.resultReason
    if (completeForm.value.resultNote) payload.resultNote = completeForm.value.resultNote
    // Olumsuz sonuç + seneye hatirlat secili ise gelecek yil takip gorevi olusur
    if (isNegativeResult.value && completeForm.value.remindNextYear && !isIleriVade.value) {
      payload.remindNextYear = true
    }
    if (isIleriVade.value && completeForm.value.ileriVadeDate) {
      payload.ileriVadeDate = completeForm.value.ileriVadeDate
    }
    // Araç takibi bilgileri
    if (selectedTask.value.type === 'FOLLOW_UP_CALL' && completeForm.value.result === 'CALLED') {
      if (completeForm.value.hasVehicle) payload.hasVehicle = completeForm.value.hasVehicle
      if (completeForm.value.registrationReceived) payload.registrationReceived = completeForm.value.registrationReceived
      if (isRuhsatTask.value && completeForm.value.registrationReceived) payload.hasVehicle = 'YES'
    }
    await post(`tasks/${selectedTask.value.id}/complete`, payload)
    const isPostponed = selectedTask.value.type === 'FOLLOW_UP_CALL' && ['NOT_REACHED', 'NOT_AVAILABLE'].includes(completeForm.value.result)
    const isIleriVadeSubmitted = isIleriVade.value
    toast.add({ title: isPostponed ? 'Görev ertelendi' : (isIleriVadeSubmitted ? 'Görev tamamlandı, ileri vade takibi oluşturuldu' : 'Görev tamamlandı'), color: isPostponed ? 'info' : 'success' })
    showCompleteModal.value = false
    fetchRenewals(true)
    fetchStats()
  } catch (e: any) {
    toast.add({ title: 'İşlem başarısız', description: e.message, color: 'error' })
  } finally {
    savingComplete.value = false
  }
}

async function deleteTask() {
  if (!selectedTask.value) return
  try {
    await del(`tasks/${selectedTask.value.id}`)
    toast.add({ title: 'Görev silindi', color: 'success' })
    showDeleteConfirm.value = false
    fetchRenewals(true)
  } catch (e: any) {
    toast.add({ title: 'Silinemedi', description: e.message, color: 'error' })
  }
}


function formatCurrency(val: number) {
  return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val)
}

function formatCurrencyShort(val: number) {
  if (val >= 1000000) return new Intl.NumberFormat('tr-TR').format(Math.round(val))
  if (val >= 1000) return new Intl.NumberFormat('tr-TR').format(Math.round(val))
  return new Intl.NumberFormat('tr-TR').format(val)
}

const monthNames = ['Oca.', 'Sub.', 'Mar.', 'Nis.', 'May.', 'Haz.', 'Tem.', 'Agu.', 'Eyl.', 'Eki.', 'Kas.', 'Ara.']

// Socket.IO: yeni bildirim gelince listeyi yenile (debounce ile)
const wsNewNotif = useState<number>('ws-new-notification')
let wsDebounce: ReturnType<typeof setTimeout> | null = null
watch(wsNewNotif, (newVal, oldVal) => {
  if (oldVal === undefined) return // ilk mount'ta tetiklenmesin
  if (wsDebounce) clearTimeout(wsDebounce)
  wsDebounce = setTimeout(() => fetchRenewals(true), 2000)
})

// ---- Task Notes (bagimsiz - sadece bu sayfa) ----
const expandedNoteTaskId = ref<number | null>(null)
const taskNotes = ref<Record<number, any[]>>({})
const taskNoteCounts = ref<Record<number, number>>({})
const taskNotesLoading = ref<Record<number, boolean>>({})
const newNoteText = ref('')
const addingNote = ref(false)

async function fetchNoteCounts() {
  try {
    const res = await get('task-notes', { counts: 1 })
    taskNoteCounts.value = res.data || {}
  } catch {
    taskNoteCounts.value = {}
  }
}

async function toggleNotes(taskId: number) {
  if (expandedNoteTaskId.value === taskId) {
    expandedNoteTaskId.value = null
    return
  }
  expandedNoteTaskId.value = taskId
  newNoteText.value = ''
  await fetchNotes(taskId)
}

async function fetchNotes(taskId: number) {
  taskNotesLoading.value[taskId] = true
  try {
    const res = await get('task-notes', { taskId })
    taskNotes.value[taskId] = res.data || []
  } catch {
    taskNotes.value[taskId] = []
  } finally {
    taskNotesLoading.value[taskId] = false
  }
}

async function submitNote(taskId: number) {
  const text = newNoteText.value.trim()
  if (!text) return
  addingNote.value = true
  try {
    const res = await post('task-notes', { taskId, note: text })
    if (!taskNotes.value[taskId]) taskNotes.value[taskId] = []
    taskNotes.value[taskId].unshift(res.data)
    taskNoteCounts.value[taskId] = (taskNoteCounts.value[taskId] || 0) + 1
    newNoteText.value = ''
    toast.add({ title: 'Not eklendi', color: 'success' })
  } catch (e: any) {
    toast.add({ title: 'Not eklenemedi', description: e.message, color: 'error' })
  } finally {
    addingNote.value = false
  }
}

async function deleteNote(noteId: number, taskId: number) {
  try {
    await del(`task-notes/${noteId}`)
    if (taskNotes.value[taskId]) {
      taskNotes.value[taskId] = taskNotes.value[taskId].filter((n: any) => n.id !== noteId)
    }
    taskNoteCounts.value[taskId] = Math.max(0, (taskNoteCounts.value[taskId] || 1) - 1)
    toast.add({ title: 'Not silindi', color: 'success' })
  } catch (e: any) {
    toast.add({ title: 'Not silinemedi', description: e.message, color: 'error' })
  }
}

// Poliçe Detay Slideover
const policyDetailOpen = ref(false)
const policyDetailId = ref<number | null>(null)
const { openPolicyModal } = useGlobalModals()

function openPolicyDetail(policyId: number) {
  policyDetailId.value = policyId
  policyDetailOpen.value = true
}

function onPolicyDetailEdit(policy: any) {
  policyDetailOpen.value = false
  openPolicyModal({ customerId: policy?.customerId, policy, onSaved: () => fetchRenewals() })
}

function formatNoteDate(dateStr: string): string {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}.${d.getFullYear()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

// Yenileme & Teklif karti aç/kapa (localStorage'da kalici)
const renewalsOpen = ref(true)
function toggleRenewals() {
  renewalsOpen.value = !renewalsOpen.value
  if (import.meta.client) {
    localStorage.setItem('home_collapse_renewals', renewalsOpen.value ? '1' : '0')
  }
}

// --- AI Satış Koçu ---
const showAiCoach = useState('ai-coach-open', () => false)
const aiCoachLoading = ref(false)
const aiCoachData = ref<any>(null)
const aiCoachError = ref('')
const aiCoachEnabled = useState('ai-coach-enabled', () => false)
const aiCoachUserId = ref('me')
const aiCoachTab = ref('suggestions')
const aiCoachOthersOpen = ref(false)
const aiCoachExpandedTask = ref<number | null>(null)
const aiCoachExpired = ref<any[]>([])
const aiCoachExpiredLoading = ref(false)

async function fetchAiCoachExpired() {
  aiCoachExpiredLoading.value = true
  try {
    const params = isAdmin.value && aiCoachUserId.value && aiCoachUserId.value !== 'me' ? `?userId=${aiCoachUserId.value}` : ''
    const { token } = useAuth()
    const res = await fetch(`/api/ai-coach/expired${params}`, {
      headers: { Authorization: `Bearer ${token.value}` },
    })
    const json = await res.json()
    if (res.ok && json.success) {
      aiCoachExpired.value = json.data.tasks || []
    }
  } catch {}
  aiCoachExpiredLoading.value = false
}

async function analyzeWithAiCoach() {
  aiCoachLoading.value = true
  aiCoachError.value = ''
  aiCoachData.value = null
  aiCoachOthersOpen.value = false
  aiCoachExpandedTask.value = null
  try {
    const body: any = {}
    if (isAdmin.value && aiCoachUserId.value && aiCoachUserId.value !== 'me') body.userId = aiCoachUserId.value
    const { token } = useAuth()
    const res = await fetch('/api/ai-coach/analyze', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token.value}` },
      body: JSON.stringify(body),
    })
    const json = await res.json()
    if (!res.ok || !json.success) {
      aiCoachError.value = json.message || 'AI Satış Koçu şu anda kullanılamıyor.'
    } else {
      aiCoachData.value = json.data
    }
  } catch (e: any) {
    aiCoachError.value = e?.message || 'AI Satış Koçu şu anda kullanılamıyor.'
  }
  aiCoachLoading.value = false
}

// Layout'taki butondan açıldığında analiz başlat
watch(showAiCoach, (open) => {
  if (open && !aiCoachData.value && !aiCoachLoading.value) {
    analyzeWithAiCoach()
  }
  if (open) {
    fetchAiCoachExpired()
  }
})

watch(aiCoachTab, (tab) => {
  if (tab === 'expired' && aiCoachExpired.value.length === 0 && !aiCoachExpiredLoading.value) {
    fetchAiCoachExpired()
  }
})

function aiPriorityColor(p: string): 'error' | 'warning' | 'info' {
  if (p === 'ACIL') return 'error'
  if (p === 'YÜKSEK' || p === 'YUKSEK') return 'warning'
  return 'info'
}

const stageLabels: Record<string, { label: string, icon: string, color: string }> = {
  'PREPARE_OFFER': { label: 'Teklif Hazırla', icon: 'i-lucide-calculator', color: 'text-blue-600' },
  'SEND_OFFER': { label: 'Teklifi İlet', icon: 'i-lucide-send', color: 'text-indigo-600' },
  'FOLLOW_UP': { label: 'Takip Et', icon: 'i-lucide-phone-call', color: 'text-amber-600' },
  'POLICY_DELIVERY': { label: 'Poliçe Teslim', icon: 'i-lucide-file-check', color: 'text-green-600' },
  'CLOSE_TASK': { label: 'Görevi Kapat', icon: 'i-lucide-check-circle', color: 'text-gray-500' },
}

function getStageInfo(stage: string) {
  return stageLabels[stage] || { label: stage, icon: 'i-lucide-circle', color: 'text-muted' }
}

const parsedSummary = computed(() => {
  if (!aiCoachData.value?.summary) return null
  const lines = aiCoachData.value.summary.split('\n').filter((l: string) => l.trim())
  const steps: string[] = []
  let intro = ''
  let outro = ''
  for (const line of lines) {
    const stepMatch = line.match(/^\d+\)\s*(.+)/)
    if (stepMatch) {
      steps.push(stepMatch[1])
    } else if (steps.length === 0) {
      intro += (intro ? ' ' : '') + line.trim()
    } else {
      outro += (outro ? ' ' : '') + line.trim()
    }
  }
  return { intro, steps, outro }
})

onMounted(async () => {
  if (import.meta.client && localStorage.getItem('home_collapse_renewals') === '0') {
    renewalsOpen.value = false
  }
  await Promise.all([fetchStats(), fetchCharts(), fetchRenewals(), fetchUsers(), fetchFilterCustomers(), fetchFilterCompanies(), fetchNoteCounts()])
  loading.value = false
})

// Year-over-year change percentages
function calcChange(current: number, previous: number): { value: string; up: boolean; neutral: boolean } {
  if (previous === 0 && current === 0) return { value: '0', up: false, neutral: true }
  if (previous === 0) return { value: '+100', up: true, neutral: false }
  const pct = ((current - previous) / previous) * 100
  return { value: (pct >= 0 ? '+' : '') + pct.toFixed(1), up: pct >= 0, neutral: pct === 0 }
}

const premiumChange = computed(() => {
  if (!stats.value) return null
  const cur = stats.value.portfolioPremium ?? stats.value.totalPremium ?? 0
  return calcChange(cur, stats.value.lastYear?.totalPremium ?? 0)
})

const portfolioComparison = computed(() => {
  if (!stats.value) return null
  const current = stats.value.portfolioPremium ?? stats.value.totalPremium ?? 0
  const previous = stats.value.lastYear?.totalPremium ?? 0
  const max = Math.max(current, previous, 1)
  let pct = 0
  if (previous > 0) pct = Math.round(((current - previous) / previous) * 100)
  else if (current > 0) pct = 100
  return {
    current,
    previous,
    pct,
    up: current >= previous,
    currentWidth: Math.max((current / max) * 100, 2),
    previousWidth: Math.max((previous / max) * 100, 2),
    currentYear: portfolioYear.value,
    previousYear: portfolioYear.value - 1,
    prevDateLabel: portfolioYear.value < new Date().getFullYear()
      ? `${portfolioYear.value - 1} yıl sonu`
      : new Date(Date.now() - 365 * 24 * 60 * 60 * 1000).toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' }),
  }
})
const monthlyPremiumChange = computed(() => {
  if (!stats.value) return null
  return calcChange(stats.value.monthlyProduction?.premium ?? 0, stats.value.lastYear?.monthlyPremium ?? 0)
})

const monthlyCountChange = computed(() => {
  if (!stats.value) return null
  return calcChange(stats.value.monthlyProduction?.count ?? 0, stats.value.lastYear?.monthlyCount ?? 0)
})

const monthlyProdComparison = computed(() => {
  if (!stats.value) return null
  const current = stats.value.monthlyProduction?.premium ?? 0
  const previous = stats.value.lastYear?.monthlyPremium ?? 0
  const currentCount = stats.value.monthlyProduction?.count ?? 0
  const previousCount = stats.value.lastYear?.monthlyCount ?? 0
  const max = Math.max(current, previous, 1)
  let pct = 0
  if (previous > 0) pct = Math.round(((current - previous) / previous) * 100)
  else if (current > 0) pct = 100
  return {
    current, previous, currentCount, previousCount, pct,
    up: current >= previous,
    currentWidth: Math.max((current / max) * 100, 2),
    previousWidth: Math.max((previous / max) * 100, 2),
    currentYear: new Date().getFullYear(),
    previousYear: new Date().getFullYear() - 1,
  }
})

const perCustomerComparison = computed(() => {
  if (!stats.value) return null
  const current = stats.value.perCustomerPremium ?? 0
  const previous = stats.value.lastYear?.perCustomerPremium ?? 0
  const max = Math.max(current, previous, 1)
  let pct = 0
  if (previous > 0) pct = Math.round(((current - previous) / previous) * 100)
  else if (current > 0) pct = 100
  return {
    current, previous, pct,
    up: current >= previous,
    currentWidth: Math.max((current / max) * 100, 2),
    previousWidth: Math.max((previous / max) * 100, 2),
    currentYear: portfolioYear.value,
    previousYear: portfolioYear.value - 1,
    currentCount: stats.value.activeCustomerCount ?? 0,
    previousCount: stats.value.lastYear?.activeCustomerCount ?? 0,
  }
})

// Full 12-month comparison data
const monthlyComparison = computed(() => {
  if (!charts.value) return []
  const thisYear = charts.value.monthly || []
  const lastYear = charts.value.monthlyLastYear || []

  const thisYearMap: Record<number, number> = {}
  thisYear.forEach((m: any) => { thisYearMap[m.monthNum] = m.premium })

  const lastYearMap: Record<number, number> = {}
  lastYear.forEach((m: any) => { lastYearMap[m.monthNum] = m.premium })

  const result = []
  for (let i = 1; i <= 12; i++) {
    result.push({
      monthNum: i,
      label: monthNames[i - 1],
      thisYear: thisYearMap[i] ?? 0,
      lastYear: lastYearMap[i] ?? 0,
    })
  }
  return result
})

const maxComparisonPremium = computed(() => {
  if (!monthlyComparison.value.length) return 1
  let max = 0
  monthlyComparison.value.forEach((m: any) => {
    if (m.thisYear > max) max = m.thisYear
    if (m.lastYear > max) max = m.lastYear
  })
  return (max || 1) * 1.1
})

// Y-axis labels for vertical chart
const yAxisLabels = computed(() => {
  const max = maxComparisonPremium.value
  const steps = 6
  const labels = []
  for (let i = steps; i >= 0; i--) {
    const val = (max / steps) * i
    if (val >= 1000000) labels.push((val / 1000000).toFixed(1) + 'M')
    else if (val >= 1000) labels.push(Math.round(val / 1000) + 'K')
    else labels.push(String(Math.round(val)))
  }
  return labels
})

// Insurance group distribution - top 4 with percentages
const groupDistribution = computed(() => {
  if (!charts.value?.byGroup?.length) return []
  const sorted = [...charts.value.byGroup].sort((a: any, b: any) => b.premium - a.premium).slice(0, 4)
  const total = charts.value.byGroup.reduce((s: number, g: any) => s + g.premium, 0)
  return sorted.map((g: any) => ({
    ...g,
    percent: total > 0 ? ((g.premium / total) * 100).toFixed(1) : '0',
  }))
})

const maxGroupPremium = computed(() => {
  if (!groupDistribution.value.length) return 1
  return Math.max(...groupDistribution.value.map((g: any) => g.premium), 1)
})

const saglikSubDistribution = computed(() => {
  if (!charts.value?.byGroupSaglik?.length) return []
  const saglik = groupDistribution.value.find((g: any) => g.group === 'SAĞLIK')
  if (!saglik) return []
  const total = saglik.premium
  return charts.value.byGroupSaglik.map((g: any) => ({
    ...g,
    percent: total > 0 ? ((g.premium / total) * 100).toFixed(1) : '0',
  }))
})

const saglikExpanded = ref(false)

const groupColorList = [
  { bar: 'bg-[#006192]', text: 'text-[#006192]' },
  { bar: 'bg-[#67d9d1]', text: 'text-[#47b9b1]' },
  { bar: 'bg-[#f59e0b]', text: 'text-[#d97706]' },
  { bar: 'bg-[#ef4444]', text: 'text-[#dc2626]' },
]


const currentYear = new Date().getFullYear()

// Dashboard tab (İstatistikler / Grafikler) - varsayılan kapalı
const dashboardTab = ref('')

function toggleDashboardTab(tab: string) {
  dashboardTab.value = dashboardTab.value === tab ? '' : tab
}
</script>

<template>
  <div class="space-y-6">

    <!-- İstatistikler & Grafikler Tabları - Sadece admin -->
    <div v-if="user?.role === 'admin'" class="hidden lg:flex justify-start gap-2 border-b border-default -mt-3 lg:-mt-4 -mx-3 lg:-mx-4 px-3 lg:px-4">
      <button
        class="flex items-center gap-1.5 px-5 py-3.5 text-sm font-medium whitespace-nowrap border-b-2 transition-colors -mb-px"
        :class="dashboardTab === 'stats' ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-foreground'"
        @click="toggleDashboardTab('stats')"
      >
        <UIcon name="i-lucide-bar-chart-3" class="size-4" />
        İstatistikler
      </button>
      <button
        class="flex items-center gap-1.5 px-5 py-3.5 text-sm font-medium whitespace-nowrap border-b-2 transition-colors -mb-px"
        :class="dashboardTab === 'charts' ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-foreground'"
        @click="toggleDashboardTab('charts')"
      >
        <UIcon name="i-lucide-trending-up" class="size-4" />
        Grafikler
      </button>
    </div>

    <!-- İstatistikler Tab İçeriği -->
    <div v-if="user?.role === 'admin' && dashboardTab === 'stats'">
    <div class="space-y-4">
    <!-- Stat Cards - Ust satır: 5 kolon -->
    <div class="grid grid-cols-2 xl:grid-cols-3 gap-4">
      <template v-if="loading">
        <SkeletonCard v-for="i in 3" :key="i" />
      </template>
      <template v-else>
      <!-- Müşteri -->
      <UCard class="cursor-pointer hover:shadow-md transition-shadow" @click="navigateTo('/musteriler')">
        <div class="flex items-start gap-4">
          <div class="rounded-lg p-2.5 bg-blue-50 dark:bg-blue-950">
            <UIcon name="i-lucide-users" class="size-6 text-blue-500" />
          </div>
          <div>
            <p class="kpi-value">{{ stats?.totalCustomers?.toLocaleString('tr-TR') }}</p>
            <p class="text-sm text-muted">Toplam Müşteri</p>
          </div>
        </div>
      </UCard>

      <!-- Aktif Poliçe -->
      <UCard class="cursor-pointer hover:shadow-md transition-shadow" @click="navigateTo('/policeler?status=ACTIVE')">
        <div class="flex items-start gap-4">
          <div class="rounded-lg p-2.5 bg-green-50 dark:bg-green-950">
            <UIcon name="i-lucide-shield-check" class="size-6 text-green-500" />
          </div>
          <div>
            <p class="kpi-value">{{ (stats?.allActivePolicies ?? 0).toLocaleString('tr-TR') }}</p>
            <p class="text-sm text-muted">Aktif Poliçe</p>
          </div>
        </div>
      </UCard>

      <!-- İptal -->
      <UCard class="cursor-pointer hover:shadow-md transition-shadow" @click="navigateTo('/policeler?status=CANCELLED')">
        <div class="flex items-start gap-4">
          <div class="rounded-lg p-2.5 bg-red-50 dark:bg-red-950">
            <UIcon name="i-lucide-x-circle" class="size-6 text-red-500" />
          </div>
          <div>
            <p class="kpi-value text-red-600">{{ stats?.totalCancelled?.toLocaleString('tr-TR') }}</p>
            <p class="text-sm text-muted">İptal ({{ currentYear }})</p>
          </div>
        </div>
      </UCard>

      </template>
    </div>

    <!-- Alt satır: 3 kolon -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <template v-if="loading">
        <SkeletonCard v-for="i in 3" :key="i" />
      </template>
      <template v-else>
      <!-- Aktif Portföy -->
      <UCard>
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm font-semibold text-muted">Aktif Portföy</p>
          <div class="flex items-center gap-1">
            <button class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-muted" @click="prevPortfolioYear">
              <UIcon name="i-lucide-chevron-left" class="size-4" />
            </button>
            <button
              class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-muted"
              :class="isCurrentPortfolioYear ? 'opacity-30 cursor-not-allowed' : ''"
              :disabled="isCurrentPortfolioYear"
              @click="nextPortfolioYear"
            >
              <UIcon name="i-lucide-chevron-right" class="size-4" />
            </button>
          </div>
        </div>
        <div v-if="portfolioComparison" class="flex items-center gap-4">
          <div class="flex-1 min-w-0 space-y-2.5">
            <div>
              <div class="flex items-baseline justify-between mb-1">
                <span class="text-sm font-semibold text-gray-800 dark:text-white">{{ portfolioComparison.currentYear }}</span>
                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 ">{{ formatCurrency(portfolioComparison.current) }} TL</span>
              </div>
              <div class="h-2.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full bg-blue-500 transition-all duration-700" :style="{ width: portfolioComparison.currentWidth + '%' }" />
              </div>
            </div>
            <div>
              <div class="flex items-baseline justify-between mb-1">
                <span class="text-sm font-semibold text-gray-400 dark:text-gray-500">{{ portfolioComparison.previousYear }}</span>
                <span class="text-xs font-semibold text-gray-400 ">{{ formatCurrency(portfolioComparison.previous) }} TL</span>
              </div>
              <div class="h-2.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full bg-gray-300 dark:bg-gray-600 transition-all duration-700" :style="{ width: portfolioComparison.previousWidth + '%' }" />
              </div>
            </div>
          </div>
          <UTooltip :text="`${portfolioComparison.prevDateLabel} itibarıyla ${portfolioComparison.previousYear} portföyüne kıyasla ${portfolioComparison.currentYear} portföyü %${portfolioComparison.pct} ${portfolioComparison.up ? 'büyüdü' : 'küçüldü'}`">
            <div class="relative shrink-0 size-20 cursor-help">
              <svg class="size-20 -rotate-90" viewBox="0 0 80 80">
                <circle cx="40" cy="40" r="34" fill="none" stroke-width="6" class="stroke-gray-200 dark:stroke-gray-700" />
                <circle cx="40" cy="40" r="34" fill="none" stroke-width="6" stroke-linecap="round" :class="portfolioComparison.up ? 'stroke-blue-500' : 'stroke-red-500'" :stroke-dasharray="213.6" :stroke-dashoffset="213.6 - (213.6 * Math.min(portfolioComparison.pct, 100) / 100)" />
              </svg>
              <div class="absolute inset-0 flex items-center justify-center">
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">%{{ portfolioComparison.pct }}</span>
              </div>
            </div>
          </UTooltip>
        </div>
      </UCard>

      <!-- Bu Ay Üretim -->
      <UCard>
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm font-semibold text-muted capitalize">{{ statsMonthLabel }} Üretimi</p>
          <div class="flex items-center gap-1">
            <button class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-muted" @click="prevStatsMonth">
              <UIcon name="i-lucide-chevron-left" class="size-4" />
            </button>
            <button
              class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-muted"
              :class="isCurrentMonth ? 'opacity-30 cursor-not-allowed' : ''"
              :disabled="isCurrentMonth"
              @click="nextStatsMonth"
            >
              <UIcon name="i-lucide-chevron-right" class="size-4" />
            </button>
          </div>
        </div>
        <div v-if="monthlyProdComparison" class="flex items-center gap-4">
          <div class="flex-1 min-w-0 space-y-2.5">
            <div>
              <div class="flex items-baseline justify-between mb-1">
                <span class="text-sm font-semibold text-gray-800 dark:text-white">{{ monthlyProdComparison.currentYear }} <span class="text-xs font-normal text-gray-500">{{ monthlyProdComparison.currentCount }} adet</span></span>
                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 ">{{ formatCurrencyShort(monthlyProdComparison.current) }} TL</span>
              </div>
              <div class="h-2.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full bg-purple-500 transition-all duration-700" :style="{ width: monthlyProdComparison.currentWidth + '%' }" />
              </div>
            </div>
            <div>
              <div class="flex items-baseline justify-between mb-1">
                <span class="text-sm font-semibold text-gray-400 dark:text-gray-500">{{ monthlyProdComparison.previousYear }} <span class="text-xs font-normal text-gray-400">{{ monthlyProdComparison.previousCount }} adet</span></span>
                <span class="text-xs font-semibold text-gray-400 ">{{ formatCurrencyShort(monthlyProdComparison.previous) }} TL</span>
              </div>
              <div class="h-2.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full bg-gray-300 dark:bg-gray-600 transition-all duration-700" :style="{ width: monthlyProdComparison.previousWidth + '%' }" />
              </div>
            </div>
          </div>
          <UTooltip :text="`Geçen yılın aynı ayına kıyasla üretim %${monthlyProdComparison.pct} ${monthlyProdComparison.up ? 'arttı' : 'azaldı'}`">
            <div class="relative shrink-0 size-20 cursor-help">
              <svg class="size-20 -rotate-90" viewBox="0 0 80 80">
                <circle cx="40" cy="40" r="34" fill="none" stroke-width="6" class="stroke-gray-200 dark:stroke-gray-700" />
                <circle cx="40" cy="40" r="34" fill="none" stroke-width="6" stroke-linecap="round" :class="monthlyProdComparison.up ? 'stroke-purple-500' : 'stroke-red-500'" :stroke-dasharray="213.6" :stroke-dashoffset="213.6 - (213.6 * Math.min(monthlyProdComparison.pct, 100) / 100)" />
              </svg>
              <div class="absolute inset-0 flex items-center justify-center">
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">%{{ monthlyProdComparison.pct }}</span>
              </div>
            </div>
          </UTooltip>
        </div>
      </UCard>

      <!-- Müşteri Başı Prim -->
      <UCard>
        <p class="text-sm font-semibold text-muted mb-3">Müşteri Başı Prim</p>
        <div v-if="perCustomerComparison" class="flex items-center gap-4">
          <div class="flex-1 min-w-0 space-y-2.5">
            <div>
              <div class="flex items-baseline justify-between mb-1">
                <span class="text-sm font-semibold text-gray-800 dark:text-white">{{ perCustomerComparison.currentYear }} <span class="text-xs font-normal text-gray-500">{{ perCustomerComparison.currentCount.toLocaleString('tr-TR') }} müşteri</span></span>
                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 ">{{ formatCurrency(perCustomerComparison.current) }} TL</span>
              </div>
              <div class="h-2.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full bg-emerald-500 transition-all duration-700" :style="{ width: perCustomerComparison.currentWidth + '%' }" />
              </div>
            </div>
            <div>
              <div class="flex items-baseline justify-between mb-1">
                <span class="text-sm font-semibold text-gray-400 dark:text-gray-500">{{ perCustomerComparison.previousYear }} <span class="text-xs font-normal text-gray-400">{{ perCustomerComparison.previousCount.toLocaleString('tr-TR') }} müşteri</span></span>
                <span class="text-xs font-semibold text-gray-400 ">{{ formatCurrency(perCustomerComparison.previous) }} TL</span>
              </div>
              <div class="h-2.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full bg-gray-300 dark:bg-gray-600 transition-all duration-700" :style="{ width: perCustomerComparison.previousWidth + '%' }" />
              </div>
            </div>
          </div>
          <UTooltip :text="`${perCustomerComparison.previousYear}'e kıyasla müşteri başı prim %${perCustomerComparison.pct} ${perCustomerComparison.up ? 'arttı' : 'azaldı'}`">
            <div class="relative shrink-0 size-20 cursor-help">
              <svg class="size-20 -rotate-90" viewBox="0 0 80 80">
                <circle cx="40" cy="40" r="34" fill="none" stroke-width="6" class="stroke-gray-200 dark:stroke-gray-700" />
                <circle cx="40" cy="40" r="34" fill="none" stroke-width="6" stroke-linecap="round" :class="perCustomerComparison.up ? 'stroke-emerald-500' : 'stroke-red-500'" :stroke-dasharray="213.6" :stroke-dashoffset="213.6 - (213.6 * Math.min(perCustomerComparison.pct, 100) / 100)" />
              </svg>
              <div class="absolute inset-0 flex items-center justify-center">
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">%{{ perCustomerComparison.pct }}</span>
              </div>
            </div>
          </UTooltip>
        </div>
      </UCard>
      </template>
    </div>
    </div>
    </div>

    <!-- Grafikler Tab İçeriği -->
    <div v-if="user?.role === 'admin' && dashboardTab === 'charts'">
    <div class="flex items-center justify-end gap-2 mb-4">
      <UButton
        v-for="opt in [{ label: 'Tümü', value: 'all' }, { label: 'Acentem', value: 'self' }, { label: 'Tali Giden', value: 'outgoing' }]"
        :key="opt.value"
        :label="opt.label"
        size="sm"
        :color="chartView === opt.value ? 'primary' : 'neutral'"
        :variant="chartView === opt.value ? 'solid' : 'outline'"
        class="min-w-[110px] justify-center"
        @click="chartView = opt.value as 'all' | 'self' | 'outgoing'"
      />
    </div>
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
      <UCard class="xl:col-span-2">
          <template #header>
            <div class="flex items-center justify-between flex-wrap gap-2">
              <div>
                <h3 >Prim Gelişimi</h3>
              </div>
              <div class="flex items-center gap-4 text-xs">
                <span class="flex items-center gap-1.5">
                  <span class="size-3 rounded-sm inline-block" style="background-color: #67d9d1" />
                  {{ currentYear - 1 }}
                </span>
                <span class="flex items-center gap-1.5">
                  <span class="size-3 rounded-sm inline-block" style="background-color: #006192" />
                  {{ currentYear }}
                </span>
              </div>
            </div>
          </template>
          <div v-if="!loading && monthlyComparison.length">
            <!-- Vertical bar chart -->
            <div class="flex h-72">
              <!-- Y-axis labels -->
              <div class="flex flex-col justify-between text-xs text-muted pr-2 py-1 w-16 shrink-0 text-right">
                <span v-for="label in yAxisLabels" :key="label">{{ label }}</span>
              </div>
              <!-- Chart area -->
              <div class="flex-1 flex flex-col">
                <!-- Bars area with grid lines -->
                <div class="flex-1 relative border-l border-b border-gray-200 dark:border-gray-700">
                  <!-- Horizontal grid lines -->
                  <div
                    v-for="(_, idx) in yAxisLabels"
                    :key="'grid-' + idx"
                    class="absolute left-0 right-0 border-t border-gray-100 dark:border-gray-800"
                    :style="{ top: (idx / (yAxisLabels.length - 1)) * 100 + '%' }"
                  />
                  <!-- Bar groups -->
                  <div class="absolute inset-0 flex items-end">
                    <div
                      v-for="month in monthlyComparison"
                      :key="month.monthNum"
                      class="flex-1 flex items-end justify-center gap-2.5 px-1.5 relative h-full"
                    >
                      <!-- Last year bar -->
                      <div
                        class="w-[25%] max-w-3 transition-all duration-500 relative group/bar"
                        :style="{ backgroundColor: '#67d9d1', borderRadius: '999px 999px 0 0', height: (month.lastYear / maxComparisonPremium * 100) + '%', minHeight: month.lastYear > 0 ? '4px' : '0' }"
                      >
                        <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover/bar:block bg-gray-800 text-white text-xs px-2 py-1 rounded whitespace-nowrap z-10">
                          {{ formatCurrencyShort(month.lastYear) }} TL
                        </div>
                      </div>
                      <!-- This year bar -->
                      <div
                        class="w-[25%] max-w-3 transition-all duration-500 relative group/bar2"
                        :style="{ backgroundColor: '#006192', borderRadius: '999px 999px 0 0', height: (month.thisYear / maxComparisonPremium * 100) + '%', minHeight: month.thisYear > 0 ? '4px' : '0' }"
                      >
                        <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover/bar2:block bg-gray-800 text-white text-xs px-2 py-1 rounded whitespace-nowrap z-10">
                          {{ formatCurrencyShort(month.thisYear) }} TL
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <!-- X-axis labels -->
                <div class="flex mt-2">
                  <div
                    v-for="month in monthlyComparison"
                    :key="'label-' + month.monthNum"
                    class="flex-1 text-center text-xs text-muted"
                  >
                    {{ month.label }}
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div v-else-if="loading" class="h-72 animate-pulse flex items-end gap-3 px-4 pb-4">
            <div v-for="i in 12" :key="i" class="flex-1 bg-gray-200 dark:bg-gray-700 rounded-t" :style="{ height: (20 + Math.random() * 60) + '%' }" />
          </div>
          <div v-else class="h-72 flex items-center justify-center text-muted">
            Veri bulunamadı
          </div>
        </UCard>

      <!-- Insurance Group Distribution -->
      <UCard>
        <template #header>
          <h3 >Sigorta Dağılımı</h3>
          <p class="text-xs text-muted">Aktif Poliçeler - Grup Bazlı Prim Dağılımı</p>
        </template>
        <div v-if="!loading && groupDistribution.length" class="h-72 overflow-y-auto flex flex-col space-y-4 pr-1 py-1">
          <div v-for="(grp, idx) in groupDistribution" :key="grp.group" class="space-y-1.5">
            <div
              class="flex justify-between items-center"
              :class="grp.group === 'SAĞLIK' && saglikSubDistribution.length ? 'cursor-pointer select-none' : ''"
              @click="grp.group === 'SAĞLIK' && saglikSubDistribution.length ? saglikExpanded = !saglikExpanded : null"
            >
              <span class="flex items-center gap-1 text-sm font-medium" :class="groupColorList[idx % groupColorList.length].text">
                {{ grp.group }}
                <UIcon
                  v-if="grp.group === 'SAĞLIK' && saglikSubDistribution.length"
                  :name="saglikExpanded ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                  class="size-3.5"
                />
              </span>
              <span class="text-sm font-semibold">%{{ grp.percent }}</span>
            </div>
            <div class="h-2.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
              <div
                :class="[groupColorList[idx % groupColorList.length].bar, 'h-full rounded-full transition-all duration-500']"
                :style="{ width: grp.percent + '%' }"
              />
            </div>
            <div class="flex justify-between text-xs text-muted">
              <span>{{ grp.count }} adet</span>
              <span>{{ formatCurrency(grp.premium) }} TL</span>
            </div>
            <!-- SAĞLIK alt dağılımı: TSS, ÖSS vb. -->
            <div v-if="grp.group === 'SAĞLIK' && saglikExpanded && saglikSubDistribution.length" class="mt-2 ml-3 space-y-1.5 border-l-2 border-gray-200 dark:border-gray-700 pl-3">
              <div v-for="sub in saglikSubDistribution" :key="sub.name" class="space-y-1">
                <div class="flex justify-between items-center">
                  <span class="text-xs text-muted">{{ sub.name }}</span>
                  <span class="text-xs font-medium">%{{ sub.percent }}</span>
                </div>
                <div class="h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                  <div class="h-full rounded-full bg-[#006192]/50 transition-all duration-500" :style="{ width: sub.percent + '%' }" />
                </div>
                <div class="flex justify-between text-[11px] text-muted">
                  <span>{{ sub.count }} adet</span>
                  <span>{{ formatCurrency(sub.premium) }} TL</span>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div v-else-if="loading" class="h-72 animate-pulse flex flex-col justify-center space-y-5 px-4">
          <div v-for="i in 4" :key="i" class="space-y-2">
            <div class="flex justify-between">
              <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-20" />
              <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-10" />
            </div>
            <div class="h-2.5 bg-gray-200 dark:bg-gray-700 rounded-full w-full" />
          </div>
        </div>
        <div v-else class="h-72 flex items-center justify-center text-muted">Veri bulunamadı</div>
      </UCard>
    </div>
    </div>

    <!-- Yenileme Görevleri -->
    <UCard>
      <template #header>
        <div class="flex items-center justify-between flex-wrap gap-2">
          <div>
            <h3 >Görev Takibi</h3>
            <p class="text-xs text-muted">{{ showFollowUpCalls ? 'Müşteri takip araması görevleri' : 'Yenileme ve teklif görevleri' }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-1.5 w-full sm:w-auto">
            <UButton
              label="Bekleyen"
              size="sm"
              :color="!showFollowUpCalls && renewalStatuses.includes('PENDING') ? 'primary' : 'neutral'"
              :variant="!showFollowUpCalls && renewalStatuses.includes('PENDING') ? 'solid' : 'outline'"
              class="flex-1 sm:flex-none sm:min-w-[110px] justify-center"
              @click="selectStatus('PENDING')"
            />
            <UButton
              label="Devam Eden"
              size="sm"
              :color="!showFollowUpCalls && renewalStatuses.includes('IN_PROGRESS') ? 'primary' : 'neutral'"
              :variant="!showFollowUpCalls && renewalStatuses.includes('IN_PROGRESS') ? 'solid' : 'outline'"
              class="flex-1 sm:flex-none sm:min-w-[110px] justify-center"
              @click="selectStatus('IN_PROGRESS')"
            />
            <UButton
              label="Takip Aramaları"
              size="sm"
              :color="showFollowUpCalls ? 'info' : 'neutral'"
              :variant="showFollowUpCalls ? 'solid' : 'outline'"
              class="flex-1 sm:flex-none sm:min-w-[110px] justify-center"
              @click="showFollowUpCalls ? (showFollowUpCalls = false) : (showFollowUpCalls = true)"
            />
            <UButton
              v-if="isAdmin"
              label="Süresi Geçen"
              size="sm"
              :color="!showFollowUpCalls && renewalStatuses.includes('EXPIRED') ? 'primary' : 'neutral'"
              :variant="!showFollowUpCalls && renewalStatuses.includes('EXPIRED') ? 'solid' : 'outline'"
              class="hidden sm:inline-flex sm:min-w-[110px] justify-center"
              @click="selectStatus('EXPIRED')"
            />
          </div>
        </div>
      </template>
      <div v-show="renewalsOpen">
      <div v-if="renewalLoading" class="py-2">
        <SkeletonTable :rows="5" :cols="8" />
      </div>
      <div v-else-if="filteredRenewals.length === 0" class="text-center py-8 text-muted">{{ showFollowUpCalls ? 'Takip araması görevi bulunamadı' : 'Yenileme görevi bulunamadı' }}</div>
      <div v-else>
        <div v-if="activeFilterCount > 0" class="flex items-center justify-between mb-2 px-1">
          <span class="text-xs text-muted">{{ filteredRenewals.length }} / {{ renewals.length }} kayıt · {{ activeFilterCount }} filtre aktif</span>
          <button type="button" class="text-xs text-primary hover:underline" @click="clearAllFilters">Tüm filtreleri temizle</button>
        </div>
      <!-- Mobil Kart Listesi -->
      <div class="sm:hidden space-y-2">
        <template v-for="group in groupedRenewals" :key="'m-'+group.label">
          <div class="flex items-center gap-2 px-2 py-1.5">
            <UIcon :name="group.icon" :class="[group.color, 'size-4']" />
            <span class="text-sm" :class="group.color">{{ group.label }}</span>
            <span class="text-xs text-muted">({{ group.totalWithCompleted }})</span>
          </div>
          <div
            v-for="r in (completedFilter?.groupLabel === group.label ? group.items.filter(i => completedFilter.result === 'SUCCESS' ? ['RENEWED','OFFER_APPROVED','DONE'].includes(i.result) : completedFilter.result === 'FAIL' ? ['NOT_RENEWED','OFFER_REJECTED','FAILED'].includes(i.result) : i.result === completedFilter.result) : group.items)"
            :key="'mc-'+r.id"
            class="rounded-xl p-4 cursor-pointer transition-colors"
            :class="(r.daysRemaining != null && r.daysRemaining < 0 && !['COMPLETED','CANCELLED'].includes(r.status))
              ? 'bg-red-50 dark:bg-red-900/20'
              : 'bg-white dark:bg-gray-800/50'"
            style="box-shadow: 0 1px 3px rgba(0,0,0,0.06)"
            @click="r.customerId && navigateTo(`/musteriler/${r.customerId}`)"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium overflow-hidden whitespace-nowrap" style="text-overflow: clip">{{ formatPersonName(r.customerName || '', 'compact') || '-' }}</p>
                <p class="text-sm mt-1" :class="r.daysRemaining != null && r.daysRemaining < 0 ? 'text-error font-medium' : 'text-muted'">
                  <template v-if="r.status === 'COMPLETED'">Tamamlandı</template>
                  <template v-else-if="r.daysRemaining != null && r.daysRemaining < 0">Süresi Geçmiş</template>
                  <template v-else-if="r.daysRemaining === 0">Son Gün</template>
                  <template v-else-if="r.daysRemaining != null">{{ r.daysRemaining }} Gün Kaldı</template>
                  <template v-else>-</template>
                </p>
              </div>
              <span
                v-if="r.insuranceName"
                class="shrink-0 rounded-full w-16 py-0.5 text-center text-xs font-semibold text-white"
                :style="{ backgroundColor: toHex(r.insuranceColor) }"
              >{{ insuranceShortLabel(r.insuranceName) }}</span>
            </div>
          </div>
        </template>
      </div>

      <!-- Masaüstü Tablo -->
      <div class="hidden sm:block border border-default rounded-lg overflow-hidden" style="min-width:0">
        <table class="task-table text-xs w-full table-fixed">
          <thead class="sticky top-0 z-10">
            <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
              <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[40%] sm:w-[35%] lg:w-[23%]">
                <ColumnFilter v-model="colFilters.customer" label="Ad / Soyad" type="multiselect" :options="filterCustomers" />
              </th>
              <th class="hidden sm:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted sm:w-[15%] lg:w-[12%]">Şirket</th>
              <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[30%] sm:w-[13%] lg:w-[10%]">
                <ColumnFilter v-model="colFilters.insurance" label="Poliçe Türü" type="multiselect" :options="insuranceFilterOptions" />
              </th>
              <th class="hidden lg:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted lg:w-[11%]">Plaka</th>
              <th class="hidden sm:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted sm:w-[15%] lg:w-[9%]">
                <ColumnFilter v-model="colFilters.expiresAt" label="Bitiş Tarihi" type="date" />
              </th>
              <th class="hidden lg:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted lg:w-[8%]">
                <ColumnFilter v-model="colFilters.prodType" label="Üretim Yeri" type="multiselect" :options="prodFilterOptions" />
              </th>
              <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[30%] sm:w-[10%] lg:w-[7%]">
                <ColumnFilter v-model="colFilters.daysLeft" label="Kalan Gün" type="select" :options="daysLeftOptions" />
              </th>
              <th class="hidden lg:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted lg:w-[8%]">
                <ColumnFilter v-model="colFilters.assignedTo" label="Temsilci" type="multiselect" :options="assignedFilterOptions" />
              </th>
              <th class="hidden sm:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted sm:w-[12%] lg:w-[12%]">İşlem Tipi</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="group in groupedRenewals" :key="group.label">
            <!-- Grup Başlığı -->
            <tr>
              <td colspan="9" class="py-2 px-3 bg-gray-100/80 dark:bg-gray-800/80 border-b border-default">
                <div class="flex items-center gap-2 flex-wrap">
                  <UIcon :name="group.icon" :class="[group.color, 'size-4']" />
                  <span class="text-sm" :class="group.color">{{ group.label }}</span>
                  <span class="text-xs text-muted">({{ group.totalWithCompleted }})</span>
                  <template v-if="group.totalWithCompleted > 0">
                    <span class="text-xs text-muted ml-1">—</span>
                    <span class="text-xs text-muted">{{ group.totalWithCompleted }} Görev</span>
                    <template v-for="s in group.completedStats" :key="s.result">
                      <span class="text-xs text-muted">,</span>
                      <button
                        class="text-xs cursor-pointer hover:underline"
                        :class="[s.color, completedFilter?.groupLabel === group.label && completedFilter?.result === s.result ? 'underline font-bold' : '']"
                        @click.stop="showCompletedTasks(group.label, s.result)"
                      >{{ s.count }} {{ s.label }}</button>
                    </template>
                  </template>
                  <button
                    v-if="completedFilter?.groupLabel === group.label"
                    class="text-xs text-primary hover:underline ml-1 cursor-pointer"
                    @click.stop="clearCompletedFilter"
                  >✕ Filtreyi kaldır</button>
                </div>
              </td>
            </tr>
            <template v-for="(r, rIndex) in (completedFilter?.groupLabel === group.label ? group.items.filter(i => completedFilter.result === 'SUCCESS' ? ['RENEWED','OFFER_APPROVED','DONE'].includes(i.result) : completedFilter.result === 'FAIL' ? ['NOT_RENEWED','OFFER_REJECTED','FAILED'].includes(i.result) : i.result === completedFilter.result) : group.items)" :key="r.id">
            <tr
              class="border-b border-default"
              :class="(r.daysRemaining != null && r.daysRemaining <= 0 && !['COMPLETED','CANCELLED'].includes(r.status))
                ? 'bg-red-50 dark:bg-red-900/20 hover:bg-red-100/70 dark:hover:bg-red-900/30'
                : (['OFFER','RENEWAL','REFERENCE'].includes(r.type) && r.status === 'IN_PROGRESS')
                  ? 'bg-yellow-100 dark:bg-yellow-900/30 hover:bg-yellow-200/70 dark:hover:bg-yellow-900/40'
                  : 'hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors'"
            >
              <!-- Müşteri -->
              <td class="py-2 px-3">
                <div class="flex items-center gap-2 min-w-0">
                  <!-- Telefon ikonu — ortada dikey hizalı -->
                  <UPopover v-if="r.customerPhone">
                    <button class="text-muted hover:text-primary transition-colors shrink-0" :title="formatPhone(r.customerPhone)">
                      <UIcon name="i-lucide-phone" class="size-3.5" />
                    </button>
                    <template #content>
                      <div class="px-4 py-3 flex items-center gap-3">
                        <UIcon name="i-lucide-phone" class="size-4 text-primary" />
                        <span class="text-sm font-medium tracking-wide">{{ formatPhone(r.customerPhone) }}</span>
                      </div>
                    </template>
                  </UPopover>
                  <div v-else class="size-3.5 shrink-0" />
                  <!-- İsim + TC -->
                  <div class="min-w-0">
                    <NuxtLink v-if="r.customerId" :to="`/musteriler/${r.customerId}`" class="text-primary font-medium hover:underline overflow-hidden whitespace-nowrap block" :title="r.customerName">
                      {{ formatPersonName(r.customerName || '', 'compact') }}
                    </NuxtLink>
                    <span v-else class="overflow-hidden whitespace-nowrap block" :title="r.customerName">{{ formatPersonName(r.customerName || '', 'compact') || '-' }}</span>
                    <span v-if="r.customerIdentity" class="text-muted overflow-hidden whitespace-nowrap block hidden sm:block">{{ r.customerIdentity }}</span>
                  </div>
                </div>
              </td>
              <!-- Poliçe No -->
              <td class="hidden sm:table-cell py-2 px-3">
                <div v-if="r.companyName">{{ formatCompanyName(r.companyName || '', 'compact') }}</div>
                <div
                  class="text-xs text-muted"
                  :class="r.policyId ? 'cursor-pointer hover:underline' : ''"
                  @click.stop="r.policyId && openPolicyDetail(r.policyId)"
                >
                  {{ r.policyNo || '-' }}
                </div>
              </td>
              <!-- Poliçe Türü -->
              <td class="py-2 px-3">
                <span
                  v-if="r.insuranceName"
                  class="badge-cell"
                  :style="insuranceBadgeStyle(r.insuranceColor)"
                >
                  {{ insuranceShortLabel(r.insuranceName) }}
                </span>
                <span v-else class="text-muted">-</span>
              </td>
              <!-- Plaka No -->
              <td class="hidden lg:table-cell py-2 px-3">
                <span v-if="r.plateNo">{{ r.plateNo }}</span>
                <span v-else class="text-muted">-</span>
                <div v-if="r.registrationNo" class="text-xs text-muted">{{ r.registrationNo }}</div>
              </td>
              <!-- Bitiş T. -->
              <td class="hidden sm:table-cell py-2 px-3">
                {{ r.expiresAt ? formatDate(r.expiresAt) : '-' }}
              </td>
              <!-- Üretim Yeri / Görev Tipi -->
              <td class="hidden lg:table-cell py-2 px-3">
                <template v-if="r.type === 'FOLLOW_UP_CALL'">
                  <span class="inline-flex items-center gap-1">
                    <UIcon name="i-lucide-phone-call" class="size-3 text-blue-500" />
                    <span>{{ followUpStageLabel(r.offerData?.stage) }}</span>
                  </span>
                </template>
                <template v-else>
                  {{ r.type === 'REFERENCE' ? 'Referans' : (prodLabels[r.productionType] || r.productionType || 'Teklif') }}
                </template>
              </td>
              <!-- Kalan Gün -->
              <td class="py-2 px-3">
                <template v-if="r.status === 'COMPLETED'">
                  <span class="text-muted">-</span>
                </template>
                <span
                  v-else-if="r.daysRemaining != null && r.daysRemaining < 0"
                  class="badge-sm badge-error"
                >
                  Geçmiş
                </span>
                <span
                  v-else-if="r.daysRemaining != null"
                  class="badge-sm"
                  :class="r.daysRemaining <= 3 ? 'badge-error' : r.daysRemaining <= 7 ? 'badge-warning' : 'badge-info'"
                >
                  {{ r.daysRemaining }}
                </span>
                <span v-else class="text-muted">-</span>
              </td>
              <!-- Atanan -->
              <td class="hidden lg:table-cell py-2 px-3">
                <span v-if="r.assignedToName" class="block overflow-hidden whitespace-nowrap" :title="r.assignedToName">{{ shortName(r.assignedToName) }}</span>
                <span v-else class="text-muted">Atanmamış</span>
              </td>
              <!-- İşlem -->
              <td class="hidden sm:table-cell py-2 px-3" @click.stop>
                <div class="flex items-center gap-0.5">
                  <template v-if="r.status === 'COMPLETED'">
                    <UBadge
                      :color="['RENEWED','OFFER_APPROVED','DONE','CALLED'].includes(r.result) ? 'success' : ['NOT_REACHED','NOT_AVAILABLE'].includes(r.result) ? 'warning' : 'error'"
                      variant="solid"
                      size="md"
                      class="whitespace-nowrap min-w-[110px] justify-center"
                    >
                      {{ r.result === 'RENEWED' ? 'Yenilendi' : r.result === 'NOT_RENEWED' ? 'Yenilenmedi' : r.result === 'OFFER_APPROVED' ? 'Teklif Onaylandı' : r.result === 'OFFER_REJECTED' ? 'Teklif Reddedildi' : r.result === 'DONE' ? 'Satış Yapıldı' : r.result === 'FAILED' ? 'Satış Yapılamadı' : r.result === 'CALLED' ? 'Ulaşıldı' : r.result === 'NOT_REACHED' ? 'Ulaşılamadı' : r.result === 'NOT_AVAILABLE' ? 'Müsait Değil' : 'Biten' }}
                    </UBadge>
                  </template>
                  <template v-else-if="isAdmin">
                    <button
                      v-if="r.status === 'PENDING' || r.status === 'IN_PROGRESS'"
                      class="size-7 flex items-center justify-center rounded-md hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-colors"
                      title="Temsilci Ata"
                      @click="openAssignModal(r)"
                    >
                      <UIcon name="i-lucide-user-plus" class="size-4 text-blue-500" />
                    </button>
                    <button
                      class="size-7 flex items-center justify-center rounded-md hover:bg-green-50 dark:hover:bg-green-900/30 transition-colors"
                      title="Görev Tamamlandı"
                      @click="openCompleteModal(r)"
                    >
                      <UIcon name="i-lucide-check" class="size-4 text-green-500" />
                    </button>
                    <button
                      v-if="['OFFER','RENEWAL','REFERENCE'].includes(r.type)"
                      class="size-7 flex items-center justify-center rounded-md transition-colors"
                      :class="r.status === 'IN_PROGRESS' ? 'cursor-default' : 'hover:bg-amber-50 dark:hover:bg-amber-900/30 cursor-pointer'"
                      :title="r.status === 'IN_PROGRESS' ? 'Teklif Çalışıldı' : (r.type === 'OFFER' ? 'Teklif Çalışıldı Olarak İşaretle' : 'Yenileme Çalışıldı Olarak İşaretle')"
                      :disabled="r.status === 'IN_PROGRESS'"
                      @click="r.status !== 'IN_PROGRESS' && markOfferWorked(r)"
                    >
                      <UIcon
                        :name="r.status === 'IN_PROGRESS' ? 'i-lucide-circle-check' : 'i-lucide-briefcase'"
                        class="size-4"
                        :class="r.status === 'IN_PROGRESS' ? 'text-amber-600 dark:text-amber-400' : 'text-amber-500'"
                      />
                    </button>
                    <span
                      v-if="r.type === 'FOLLOW_UP_CALL'"
                      class="size-7 flex items-center justify-center"
                      :title="followUpStageLabel(r.offerData?.stage)"
                    >
                      <UIcon name="i-lucide-phone-call" class="size-4 text-blue-500" />
                    </span>
                  </template>
                  <template v-else>
                    <button
                      class="size-7 flex items-center justify-center rounded-md hover:bg-green-50 dark:hover:bg-green-900/30 transition-colors"
                      title="Görev Tamamlandı"
                      @click="openCompleteModal(r)"
                    >
                      <UIcon name="i-lucide-check" class="size-4 text-green-500" />
                    </button>
                    <button
                      v-if="['OFFER','RENEWAL','REFERENCE'].includes(r.type)"
                      class="size-7 flex items-center justify-center rounded-md transition-colors"
                      :class="r.status === 'IN_PROGRESS' ? 'cursor-default' : 'hover:bg-amber-50 dark:hover:bg-amber-900/30 cursor-pointer'"
                      :title="r.status === 'IN_PROGRESS' ? 'Teklif Çalışıldı' : (r.type === 'OFFER' ? 'Teklif Çalışıldı Olarak İşaretle' : 'Yenileme Çalışıldı Olarak İşaretle')"
                      :disabled="r.status === 'IN_PROGRESS'"
                      @click="r.status !== 'IN_PROGRESS' && markOfferWorked(r)"
                    >
                      <UIcon
                        :name="r.status === 'IN_PROGRESS' ? 'i-lucide-circle-check' : 'i-lucide-briefcase'"
                        class="size-4"
                        :class="r.status === 'IN_PROGRESS' ? 'text-amber-600 dark:text-amber-400' : 'text-amber-500'"
                      />
                    </button>
                    <span
                      v-if="r.type === 'FOLLOW_UP_CALL'"
                      class="size-7 flex items-center justify-center"
                      :title="followUpStageLabel(r.offerData?.stage)"
                    >
                      <UIcon name="i-lucide-phone-call" class="size-4 text-blue-500" />
                    </span>
                  </template>
                  <!-- Not Butonu -->
                  <button
                    type="button"
                    class="relative size-7 flex items-center justify-center rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                    title="Not Ekle"
                    @click="toggleNotes(r.id)"
                  >
                    <UIcon name="i-lucide-message-square-text" class="size-4" :class="expandedNoteTaskId === r.id ? 'text-primary' : 'text-gray-400'" />
                    <span
                      v-if="taskNoteCounts[r.id]"
                      class="absolute -top-1 -right-1 min-w-[15px] h-[15px] flex items-center justify-center rounded-full bg-red-500 text-white text-[9px] font-bold leading-none px-0.5"
                    >
                      {{ taskNoteCounts[r.id] }}
                    </span>
                  </button>
                </div>
              </td>
            </tr>
            <!-- Satır Altı Not Alanı -->
            <tr v-if="expandedNoteTaskId === r.id">
              <td :colspan="9" class="p-0">
                <div class="bg-gray-50 dark:bg-gray-800/60 border-b border-default px-6 py-4">
                  <!-- Not Ekleme -->
                  <div class="flex gap-2 items-center mb-4 w-full">
                    <UInput
                      v-model="newNoteText"
                      placeholder="Not ekle... (ör: Müşteri 20.06 tarihinde aranacak)"
                      size="md"
                      class="flex-1"
                      @keyup.enter="submitNote(r.id)"
                    />
                    <UButton
                      icon="i-lucide-send"
                      size="md"
                      color="primary"
                      :loading="addingNote"
                      :disabled="!newNoteText.trim()"
                      @click="submitNote(r.id)"
                    />
                  </div>
                  <!-- Notlar Listesi -->
                  <div v-if="taskNotesLoading[r.id]" class="text-sm text-muted py-3 text-center">Yükleniyor...</div>
                  <div v-else-if="!taskNotes[r.id]?.length" class="text-sm text-muted py-3 text-center">Henüz not eklenmemiş.</div>
                  <div v-else class="space-y-2 max-h-[240px] overflow-y-auto">
                    <div
                      v-for="note in taskNotes[r.id]"
                      :key="note.id"
                      class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden"
                    >
                      <div class="px-3 py-1.5 bg-gray-50 dark:bg-gray-800/50 border-b border-default flex items-center justify-between">
                        <span class="text-xs">
                          Notu Ekleyen: <span class="text-primary">{{ note.createdByName }}</span>
                          <span class="mx-1 text-muted">/</span>
                          <span class="text-muted">{{ formatNoteDate(note.createdAt) }}</span>
                        </span>
                        <UButton
                          icon="i-heroicons-trash"
                          size="xs"
                          color="error"
                          variant="ghost"
                          @click="deleteNote(note.id, r.id)"
                        />
                      </div>
                      <div class="px-3 py-2">
                        <p class="text-sm">{{ note.note }}</p>
                      </div>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            </template>
            </template>
          </tbody>
        </table>
      </div>
      </div>
      </div>
    </UCard>

    <!-- Assign Modal -->
    <UModal :dismissible="false" v-model:open="showAssignModal" title="Görev Ata" :ui="{ width: 'sm:max-w-md' }">
      <template #body>
        <div class="flex flex-col gap-4">
          <div class="flex items-start gap-3 p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
            <UIcon name="i-lucide-refresh-cw" class="text-muted size-4 mt-0.5 shrink-0" />
            <div class="min-w-0">
              <p class="text-sm font-medium">{{ selectedTask?.title }}</p>
              <p v-if="selectedTask?.customerName" class="text-xs text-muted mt-0.5">{{ selectedTask.customerName }}</p>
            </div>
          </div>
          <UFormField label="Atanan Kişi" required>
            <USelect v-model="assignForm.assignedTo" :items="userAssignOptions" placeholder="Kişi seçin..." class="w-full" />
          </UFormField>
          <UFormField label="Son Tarih" hint="Bos birakirsaniz mevcut tarih korunur">
            <UPopover v-model:open="assignDeadlineDateOpen">
              <UButton
                :label="assignDeadlineDate ? calendarDateLabel(assignDeadlineDate) : 'Tarih sec'"
                icon="i-lucide-calendar"
                color="neutral"
                variant="outline"
                class="w-full justify-start"
                :class="{ 'text-muted': !assignDeadlineDate }"
              />
              <template #content>
                <UCalendar locale="tr-TR" v-model="assignDeadlineDate" class="p-2" @update:model-value="assignDeadlineDateOpen = false" />
              </template>
            </UPopover>
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="İptal" color="neutral" variant="outline" @click="showAssignModal = false" />
          <UButton label="Ata" icon="i-lucide-user-check" color="primary" :loading="savingAssign" :disabled="!assignForm.assignedTo" @click="assignTask" />
        </div>
      </template>
    </UModal>

    <!-- Complete Modal -->
    <UModal :dismissible="false" v-model:open="showCompleteModal" title="Görevi Tamamla" :ui="{ width: 'sm:max-w-lg' }">
      <template #body>
        <div v-if="selectedTask" class="flex flex-col gap-4 max-h-[70vh] overflow-y-auto pr-1">
          <!-- Task info -->
          <div class="flex items-start gap-3 p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
            <UIcon :name="typeIcon(selectedTask.type)" class="text-muted size-4 mt-0.5 shrink-0" />
            <div class="min-w-0">
              <template v-if="selectedTask.type === 'FOLLOW_UP_CALL'">
                <p class="text-sm font-medium">{{ selectedTask.customerName }}</p>
                <p class="text-xs text-muted mt-0.5">
                  {{ selectedTask.offerData?.branchGroup }} · {{ selectedTask.offerData?.stageLabel }} Takip Araması
                </p>
              </template>
              <template v-else>
                <p class="text-sm font-medium">{{ selectedTask.title }}</p>
                <p v-if="selectedTask.customerName" class="text-xs text-muted mt-0.5">{{ selectedTask.customerName }}</p>
              </template>
            </div>
          </div>

          <!-- Teklif Düzenle butonu -->
          <UButton
            v-if="selectedTask.type === 'OFFER' && (selectedTask.status === 'PENDING' || selectedTask.status === 'IN_PROGRESS')"
            label="Teklifi Düzenle"
            icon="i-lucide-pencil"
            color="primary"
            variant="soft"
            block
            @click="showCompleteModal = false; openEditOffer(selectedTask)"
          />

          <!-- Arama rehberi (FOLLOW_UP_CALL) -->
          <div v-if="selectedTask.type === 'FOLLOW_UP_CALL' && selectedTask.description" class="flex items-start gap-2 p-3 rounded-lg bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800">
            <UIcon name="i-lucide-phone-call" class="size-4 text-blue-500 mt-0.5 shrink-0" />
            <div class="text-sm min-w-0">
              <p class="font-medium text-blue-700 dark:text-blue-300">Ne hakkında aranacak?</p>
              <p class="text-xs text-muted mt-1 whitespace-pre-line">{{ selectedTask.description }}</p>
            </div>
          </div>

          <!-- Result selection -->
          <UFormField label="Sonuç" required>
            <div class="grid gap-2" :class="completeResultOptions.length === 3 ? 'grid-cols-1 sm:grid-cols-3' : 'grid-cols-1 sm:grid-cols-2'">
              <button
                v-for="opt in completeResultOptions"
                :key="opt.value"
                class="flex items-center gap-2 p-3 rounded-lg border-2 transition-all text-left"
                :class="completeForm.result === opt.value
                  ? (['RENEWED', 'OFFER_APPROVED', 'DONE', 'CALLED'].includes(opt.value)
                    ? 'border-green-500 bg-green-50 dark:bg-green-950'
                    : 'border-red-500 bg-red-50 dark:bg-red-950')
                  : 'border-default hover:border-gray-400'"
                @click="completeForm.result = opt.value; completeForm.resultReason = ''"
              >
                <UIcon
                  :name="['RENEWED', 'OFFER_APPROVED', 'DONE', 'CALLED'].includes(opt.value) ? 'i-lucide-check-circle' : 'i-lucide-x-circle'"
                  :class="['RENEWED', 'OFFER_APPROVED', 'DONE', 'CALLED'].includes(opt.value) ? 'text-green-600' : 'text-red-600'"
                  class="size-4 shrink-0"
                />
                <span class="text-xs font-medium leading-tight">{{ opt.label }}</span>
              </button>
            </div>
          </UFormField>

          <!-- Reason (olumsuz sonuçlar) -->
          <UFormField v-if="isNegativeResult" label="Sebep" required>
            <USelect
              v-model="completeForm.resultReason"
              :items="completeReasonOptions.map(r => ({ label: r, value: r }))"
              placeholder="Sebep seçin..."
              class="w-full"
            />
          </UFormField>

          <!-- İleri Vade: tarih seçici -->
          <UFormField v-if="isIleriVade" label="Hatırlatma Tarihi" required>
            <UInput
              v-model="completeForm.ileriVadeDate"
              type="date"
              :min="new Date(Date.now() + 86400000).toISOString().split('T')[0]"
              class="w-full"
            />
          </UFormField>

          <!-- İleri Vade: bilgi kutusu -->
          <div v-if="isIleriVade" class="flex items-start gap-2 p-3 rounded-lg bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800">
            <UIcon name="i-lucide-calendar-plus" class="size-4 text-blue-500 mt-0.5 shrink-0" />
            <div class="text-sm">
              <p class="font-medium">İleri Vade Takibi</p>
              <p class="text-xs text-muted mt-0.5">Seçilen tarihte bu müşteri için otomatik takip görevi oluşturulacak. Aynı müşteri için aktif görev varsa tarihi güncellenecek.</p>
            </div>
          </div>

          <!-- Seneye Hatırlat (olumsuz sonuçlarda, FOLLOW_UP_CALL ve ileri vade hariç) -->
          <div v-if="isNegativeResult && selectedTask?.type !== 'FOLLOW_UP_CALL' && !isIleriVade" class="flex items-start gap-2 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800">
            <UCheckbox v-model="completeForm.remindNextYear" />
            <div class="min-w-0">
              <p class="text-sm font-medium">Seneye Hatırlat</p>
              <p class="text-xs text-muted mt-0.5">Bu görev için gelecek yıl aynı tarihe otomatik takip görevi oluşturulsun.</p>
            </div>
          </div>

          <!-- Erteleme bilgisi (FOLLOW_UP_CALL) -->
          <div v-if="selectedTask?.type === 'FOLLOW_UP_CALL' && completeForm.result === 'NOT_REACHED'" class="flex items-start gap-2 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800">
            <UIcon name="i-lucide-clock" class="size-4 text-amber-500 mt-0.5 shrink-0" />
            <div class="text-sm">
              <p class="font-medium">Otomatik Erteleme</p>
              <p class="text-xs text-muted mt-0.5">Görev kapanmayacak, bitiş tarihi <strong>1 iş günü</strong> sonraya otomatik ertelenecek.</p>
            </div>
          </div>
          <div v-if="selectedTask?.type === 'FOLLOW_UP_CALL' && completeForm.result === 'NOT_AVAILABLE'" class="flex items-start gap-2 p-3 rounded-lg bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800">
            <UIcon name="i-lucide-calendar-clock" class="size-4 text-blue-500 mt-0.5 shrink-0" />
            <div class="text-sm">
              <p class="font-medium">Otomatik Erteleme</p>
              <p class="text-xs text-muted mt-0.5">Görev kapanmayacak, bitiş tarihi <strong>3 iş günü</strong> sonraya otomatik ertelenecek.</p>
            </div>
          </div>

          <!-- Araç Takibi: Aracı var mı? (ruhsat sorusu açılınca gizlenir) -->
          <div v-if="showVehicleQuestion && !showRegistrationQuestion">
            <div class="flex items-start gap-2 p-3 rounded-lg bg-violet-50 dark:bg-violet-950/40 border border-violet-200 dark:border-violet-800">
              <UIcon name="i-lucide-car" class="size-4 text-violet-500 mt-0.5 shrink-0" />
              <div class="text-sm w-full">
                <p class="font-medium text-violet-700 dark:text-violet-300">Müşterinin aracı var mı?</p>
                <div class="grid grid-cols-2 gap-2 mt-2">
                  <button class="flex items-center justify-center gap-2 p-2.5 rounded-lg border-2 transition-all text-xs font-medium" :class="completeForm.hasVehicle === 'YES' ? 'border-green-500 bg-green-50 dark:bg-green-950' : 'border-default hover:border-gray-400'" @click="completeForm.hasVehicle = 'YES'; completeForm.registrationReceived = ''">
                    <UIcon name="i-lucide-check-circle" class="size-4 text-green-600" /> Evet
                  </button>
                  <button class="flex items-center justify-center gap-2 p-2.5 rounded-lg border-2 transition-all text-xs font-medium" :class="completeForm.hasVehicle === 'NO' ? 'border-red-500 bg-red-50 dark:bg-red-950' : 'border-default hover:border-gray-400'" @click="completeForm.hasVehicle = 'NO'; completeForm.registrationReceived = ''">
                    <UIcon name="i-lucide-x-circle" class="size-4 text-red-600" /> Hayır
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Araç Takibi: Ruhsat bilgileri alındı mı? -->
          <div v-if="showRegistrationQuestion">
            <div class="flex items-start gap-2 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800">
              <UIcon name="i-lucide-file-check" class="size-4 text-amber-500 mt-0.5 shrink-0" />
              <div class="text-sm w-full">
                <p class="font-medium text-amber-700 dark:text-amber-300">Ruhsat bilgileri alındı mı?</p>
                <div class="grid grid-cols-2 gap-2 mt-2">
                  <button class="flex items-center justify-center gap-2 p-2.5 rounded-lg border-2 transition-all text-xs font-medium" :class="completeForm.registrationReceived === 'YES' ? 'border-green-500 bg-green-50 dark:bg-green-950' : 'border-default hover:border-gray-400'" @click="completeForm.registrationReceived = 'YES'">
                    <UIcon name="i-lucide-check-circle" class="size-4 text-green-600" /> Evet, Alındı
                  </button>
                  <button class="flex items-center justify-center gap-2 p-2.5 rounded-lg border-2 transition-all text-xs font-medium" :class="completeForm.registrationReceived === 'NO' ? 'border-red-500 bg-red-50 dark:bg-red-950' : 'border-default hover:border-gray-400'" @click="completeForm.registrationReceived = 'NO'">
                    <UIcon name="i-lucide-x-circle" class="size-4 text-red-600" /> Hayır, Alınamadı
                  </button>
                </div>
                <p v-if="completeForm.registrationReceived === 'NO'" class="text-xs text-amber-600 dark:text-amber-400 mt-2">
                  7 gün sonra otomatik ruhsat takip görevi oluşturulacak.
                </p>
              </div>
            </div>
          </div>

          <!-- Note -->
          <UFormField label="Not">
            <UTextarea v-model="completeForm.resultNote" placeholder="Ek açıklama (opsiyonel)" :rows="2" class="w-full" />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="İptal" color="neutral" variant="outline" @click="showCompleteModal = false" />
          <UButton
            :label="selectedTask?.type === 'FOLLOW_UP_CALL' && ['NOT_REACHED', 'NOT_AVAILABLE'].includes(completeForm.result) ? 'Ertele' : 'Tamamla'"
            :icon="selectedTask?.type === 'FOLLOW_UP_CALL' && ['NOT_REACHED', 'NOT_AVAILABLE'].includes(completeForm.result) ? 'i-lucide-clock' : (isNegativeResult ? 'i-lucide-x-circle' : 'i-lucide-check-circle')"
            :color="selectedTask?.type === 'FOLLOW_UP_CALL' && ['NOT_REACHED', 'NOT_AVAILABLE'].includes(completeForm.result) ? 'warning' : (isNegativeResult ? 'error' : 'success')"
            :loading="savingComplete"
            :disabled="!canSubmitComplete"
            @click="submitComplete"
          />
        </div>
      </template>
    </UModal>

    <!-- Delete Confirm -->
    <UModal :dismissible="false" v-model:open="showDeleteConfirm" title="Görevi Sil" :ui="{ width: 'sm:max-w-sm' }">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-trash-2" class="text-red-600 size-5" />
          </div>
          <div>
            <p class="text-sm font-medium">Bu görevi silmek istediginize emin misiniz?</p>
            <p class="text-xs text-muted mt-1">{{ selectedTask?.title }}</p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="İptal" color="neutral" variant="outline" @click="showDeleteConfirm = false" />
          <UButton label="Sil" icon="i-lucide-trash-2" color="error" @click="deleteTask" />
        </div>
      </template>
    </UModal>

    <!-- Teklif Düzenle Modal -->
    <TaskFormModal v-model:open="showEditOfferModal" :task="editOfferTask" @saved="onEditOfferSaved" />

    <!-- Poliçe Detay Slideover -->
    <PolicyDetailSlideover
      v-model:open="policyDetailOpen"
      :policy-id="policyDetailId"
      @edit="onPolicyDetailEdit"
    />

    <!-- AI Satış Koçu Modal -->
    <UModal v-model:open="showAiCoach" title="AI Satış Koçu" class="sm:max-w-2xl" :ui="{ content: 'flex flex-col max-h-[85vh]', body: 'flex-1 overflow-y-auto min-h-0' }">
      <template #body>
        <!-- Admin temsilci seçimi -->
        <div v-if="isAdmin" class="mb-4">
          <USelect
            v-model="aiCoachUserId"
            :items="[{ label: 'Benim Görevlerim', value: 'me' }, ...users.filter(u => u.id).map(u => ({ label: u.name, value: String(u.id) }))]"
            size="sm"
            class="w-full"
            @update:model-value="() => { analyzeWithAiCoach(); fetchAiCoachExpired() }"
          />
        </div>

        <!-- Tab Butonları -->
        <div class="flex gap-1 mb-4 border-b border-default">
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium border-b-2 transition-colors"
            :class="aiCoachTab === 'suggestions' ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-default'"
            @click="aiCoachTab = 'suggestions'"
          >
            <UIcon name="i-lucide-bot" class="size-4 mr-1.5 align-text-bottom" />
            AI Öneriler
          </button>
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium border-b-2 transition-colors flex items-center gap-1.5"
            :class="aiCoachTab === 'expired' ? 'border-red-500 text-red-600 dark:text-red-400' : 'border-transparent text-muted hover:text-default'"
            @click="aiCoachTab = 'expired'"
          >
            <UIcon name="i-lucide-alert-triangle" class="size-4" />
            Süresi Geçenler
            <UBadge v-if="aiCoachExpired.length > 0" :label="String(aiCoachExpired.length)" color="error" variant="subtle" size="sm" />
          </button>
        </div>

        <!-- ==================== TAB 1: AI Öneriler ==================== -->
        <div v-if="aiCoachTab === 'suggestions'">
          <!-- Loading -->
          <div v-if="aiCoachLoading" class="flex flex-col items-center justify-center py-16 gap-4">
            <UIcon name="i-lucide-bot" class="size-12 text-primary animate-pulse" />
            <p class="text-sm font-medium text-muted">Görevler analiz ediliyor...</p>
            <p class="text-xs text-muted">Bu birkaç saniye sürebilir</p>
          </div>

          <!-- Hata -->
          <div v-else-if="aiCoachError" class="flex flex-col items-center justify-center py-12 gap-3 text-center">
            <UIcon name="i-lucide-alert-circle" class="size-10 text-amber-400" />
            <p class="text-sm text-muted">{{ aiCoachError }}</p>
            <UButton label="Tekrar Dene" icon="i-lucide-refresh-cw" size="sm" color="neutral" variant="outline" @click="analyzeWithAiCoach" />
          </div>

          <!-- Sonuçlar -->
          <div v-else-if="aiCoachData" class="space-y-4">
            <!-- Özet -->
            <div class="p-4 rounded-xl bg-primary/5 border border-primary/20">
              <div v-if="parsedSummary" class="space-y-3">
                <!-- Giriş -->
                <p v-if="parsedSummary.intro" class="text-sm font-medium flex items-start gap-2">
                  <UIcon name="i-lucide-bot" class="size-5 text-primary shrink-0 mt-0.5" />
                  {{ parsedSummary.intro }}
                </p>
                <!-- Adımlar -->
                <div v-if="parsedSummary.steps.length" class="space-y-2 pl-1">
                  <div v-for="(step, i) in parsedSummary.steps" :key="i" class="flex items-start gap-2.5">
                    <span class="size-5 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center shrink-0 mt-0.5">{{ i + 1 }}</span>
                    <p class="text-sm">{{ step }}</p>
                  </div>
                </div>
                <!-- Kapanış -->
                <p v-if="parsedSummary.outro" class="text-xs text-primary font-medium flex items-center gap-1.5 pt-1">
                  <UIcon name="i-lucide-sparkles" class="size-3.5" />
                  {{ parsedSummary.outro }}
                </p>
              </div>
              <!-- Fallback -->
              <p v-else class="text-sm whitespace-pre-line flex items-start gap-2">
                <UIcon name="i-lucide-bot" class="size-5 text-primary shrink-0 mt-0.5" />
                {{ aiCoachData.summary }}
              </p>
            </div>

            <!-- 🔥 Şimdi Bunu Yap -->
            <div v-if="aiCoachData.topAction" class="border-2 rounded-xl overflow-hidden" :class="aiCoachData.topAction.priority === 'ACIL' ? 'border-red-400 dark:border-red-600' : (aiCoachData.topAction.priority === 'YÜKSEK' || aiCoachData.topAction.priority === 'YUKSEK') ? 'border-amber-400 dark:border-amber-600' : 'border-blue-400 dark:border-blue-600'">
              <!-- Başlık -->
              <div class="flex items-center gap-2 px-4 py-3" :class="aiCoachData.topAction.priority === 'ACIL' ? 'bg-red-100 dark:bg-red-900/40' : (aiCoachData.topAction.priority === 'YÜKSEK' || aiCoachData.topAction.priority === 'YUKSEK') ? 'bg-amber-100 dark:bg-amber-900/40' : 'bg-blue-100 dark:bg-blue-900/40'">
                <span class="text-lg">🔥</span>
                <span class="font-semibold text-sm">Şimdi Bunu Yap</span>
              </div>

              <div class="px-4 py-3 space-y-3">
                <!-- Müşteri + Öncelik + Kalan gün -->
                <div class="flex items-center gap-2 flex-wrap">
                  <span >{{ aiCoachData.topAction.customerName }}</span>
                  <UBadge :color="aiPriorityColor(aiCoachData.topAction.priority)" variant="solid" size="sm">{{ aiCoachData.topAction.priority }}</UBadge>
                  <span v-if="aiCoachData.topAction.daysLeft != null" class="text-xs text-muted">
                    <template v-if="aiCoachData.topAction.daysLeft < 0">{{ Math.abs(aiCoachData.topAction.daysLeft) }} gün geçmiş</template>
                    <template v-else-if="aiCoachData.topAction.daysLeft === 0">Bugün</template>
                    <template v-else>{{ aiCoachData.topAction.daysLeft }} gün kaldı</template>
                  </span>
                </div>

                <!-- Branş + Şirket + Aşama -->
                <div class="flex items-center gap-2 flex-wrap text-xs">
                  <span v-if="aiCoachData.topAction.stage" class="flex items-center gap-1 font-medium" :class="getStageInfo(aiCoachData.topAction.stage).color">
                    <UIcon :name="getStageInfo(aiCoachData.topAction.stage).icon" class="size-3.5" />
                    {{ getStageInfo(aiCoachData.topAction.stage).label }}
                  </span>
                  <span v-if="aiCoachData.topAction.insuranceName" class="text-muted">{{ aiCoachData.topAction.insuranceName }}</span>
                  <span v-if="aiCoachData.topAction.companyName" class="text-muted">• {{ aiCoachData.topAction.companyName }}</span>
                  <span v-if="aiCoachData.topAction.plateNo" class="text-muted">• {{ aiCoachData.topAction.plateNo }}</span>
                </div>

                <!-- Ana Aksiyon -->
                <div class="flex gap-2">
                  <UIcon name="i-lucide-target" class="size-4 text-primary shrink-0 mt-0.5" />
                  <p class="font-medium text-sm">{{ aiCoachData.topAction.action }}</p>
                </div>

                <!-- Neden şimdi? -->
                <div v-if="aiCoachData.topAction.why" class="flex gap-2">
                  <UIcon name="i-lucide-info" class="size-4 text-blue-500 shrink-0 mt-0.5" />
                  <div>
                    <span class="text-xs font-medium text-blue-600 dark:text-blue-400">Neden şimdi?</span>
                    <p class="text-sm text-muted mt-0.5">{{ aiCoachData.topAction.why }}</p>
                  </div>
                </div>

                <!-- Konuşma önerisi -->
                <div v-if="aiCoachData.topAction.conversation" class="flex gap-2">
                  <UIcon name="i-lucide-message-circle" class="size-4 text-green-500 shrink-0 mt-0.5" />
                  <div>
                    <span class="text-xs font-medium text-green-600 dark:text-green-400">Konuşma Önerisi</span>
                    <p class="text-sm italic text-muted mt-0.5">"{{ aiCoachData.topAction.conversation }}"</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Diğer Görevler — Accordion -->
            <div v-if="aiCoachData.tasks.length > (aiCoachData.topAction ? 1 : 0)">
              <button
                type="button"
                class="flex items-center gap-2 w-full pt-3 pb-2 group"
                @click="aiCoachOthersOpen = !aiCoachOthersOpen"
              >
                <div class="h-px flex-1 bg-default" />
                <span class="text-xs font-medium text-muted group-hover:text-default transition-colors flex items-center gap-1">
                  <UIcon :name="aiCoachOthersOpen ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-3.5" />
                  Diğer Görevler ({{ aiCoachData.topAction ? aiCoachData.tasks.filter(t => t.taskId !== aiCoachData.topAction?.taskId).length : aiCoachData.tasks.length }})
                </span>
                <div class="h-px flex-1 bg-default" />
              </button>

              <div v-show="aiCoachOthersOpen" class="space-y-2 mt-1">
                <div
                  v-for="t in (aiCoachData.topAction ? aiCoachData.tasks.filter(t => t.taskId !== aiCoachData.topAction?.taskId) : aiCoachData.tasks)"
                  :key="t.taskId"
                  class="border rounded-lg overflow-hidden"
                  :class="t.priority === 'ACIL' ? 'border-red-300 dark:border-red-700' : (t.priority === 'YÜKSEK' || t.priority === 'YUKSEK') ? 'border-amber-300 dark:border-amber-700' : 'border-default'"
                >
                  <!-- Kart başlık — tıklanabilir -->
                  <button
                    type="button"
                    class="w-full text-left px-4 py-2.5"
                    :class="t.priority === 'ACIL' ? 'bg-red-50 dark:bg-red-900/20' : (t.priority === 'YÜKSEK' || t.priority === 'YUKSEK') ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-blue-50 dark:bg-blue-900/20'"
                    @click="aiCoachExpandedTask = aiCoachExpandedTask === t.taskId ? null : t.taskId"
                  >
                    <div class="flex items-center gap-2">
                      <UBadge :color="aiPriorityColor(t.priority)" variant="solid" size="sm" class="w-16 justify-center shrink-0">{{ t.priority }}</UBadge>
                      <span class="text-sm overflow-hidden whitespace-nowrap">{{ formatPersonName(t.customerName || '', 'compact') }}</span>
                      <span v-if="t.daysLeft != null" class="ml-auto text-xs text-muted shrink-0">
                        <template v-if="t.daysLeft < 0">{{ Math.abs(t.daysLeft) }} gün geçmiş</template>
                        <template v-else-if="t.daysLeft === 0">Bugün</template>
                        <template v-else>{{ t.daysLeft }} gün</template>
                      </span>
                      <UIcon :name="aiCoachExpandedTask === t.taskId ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-4 text-muted shrink-0" />
                    </div>
                    <div class="flex items-center gap-2 mt-1 ml-[4.5rem]">
                      <span v-if="t.stage" class="flex items-center gap-1 text-[11px] font-medium" :class="getStageInfo(t.stage).color">
                        <UIcon :name="getStageInfo(t.stage).icon" class="size-3" />
                        {{ getStageInfo(t.stage).label }}
                      </span>
                      <span v-if="t.insuranceName" class="text-[11px] text-muted">{{ t.insuranceName }}</span>
                      <span v-if="t.plateNo" class="text-[11px] text-muted">• {{ t.plateNo }}</span>
                    </div>
                  </button>

                  <!-- Detay — accordion içerik -->
                  <div v-show="aiCoachExpandedTask === t.taskId">
                    <!-- Branş bilgi -->
                    <div v-if="t.insuranceName || t.companyName || t.plateNo" class="px-4 py-1.5 text-xs border-b border-default flex items-center gap-2 flex-wrap text-muted">
                      <span v-if="t.insuranceName">{{ t.insuranceName }}</span>
                      <span v-if="t.companyName">• {{ t.companyName }}</span>
                      <span v-if="t.plateNo">• {{ t.plateNo }}</span>
                      <span v-if="t.expiresAt">• Vade: {{ t.expiresAt }}</span>
                    </div>

                    <div class="px-4 py-3 space-y-3 text-sm">
                      <!-- Ana Aksiyon -->
                      <div class="flex gap-2">
                        <UIcon name="i-lucide-target" class="size-4 text-primary shrink-0 mt-0.5" />
                        <p class="font-medium">{{ t.action }}</p>
                      </div>

                      <!-- Neden -->
                      <div v-if="t.why" class="flex gap-2">
                        <UIcon name="i-lucide-info" class="size-4 text-blue-500 shrink-0 mt-0.5" />
                        <p class="text-muted">{{ t.why }}</p>
                      </div>

                      <!-- Konuşma önerisi -->
                      <div v-if="t.conversation" class="flex gap-2">
                        <UIcon :name="t.channel === 'WHATSAPP' ? 'i-lucide-message-circle' : 'i-lucide-phone'" class="size-4 text-green-500 shrink-0 mt-0.5" />
                        <div>
                          <span class="text-[11px] text-muted uppercase tracking-wide">{{ t.channel === 'WHATSAPP' ? 'WhatsApp' : 'Telefon' }}</span>
                          <p class="italic text-muted mt-0.5">"{{ t.conversation }}"</p>
                        </div>
                      </div>

                      <!-- Branş İpucu -->
                      <div v-if="t.branchTip" class="flex gap-2">
                        <UIcon name="i-lucide-lightbulb" class="size-4 text-amber-500 shrink-0 mt-0.5" />
                        <p class="text-muted">{{ t.branchTip }}</p>
                      </div>

                      <!-- İtiraz Karşılama -->
                      <div v-if="t.objection?.customer" class="border border-dashed border-default rounded-md p-2.5 bg-gray-50 dark:bg-gray-800/30">
                        <p class="text-xs text-muted mb-1"><span class="font-medium">Olası itiraz:</span> "{{ t.objection.customer }}"</p>
                        <p class="text-xs"><span class="font-medium text-green-600 dark:text-green-400">Cevap:</span> {{ t.objection.response }}</p>
                      </div>
                    </div>

                    <!-- Öncelik Sebebi -->
                    <div v-if="t.priorityReason" class="px-4 py-2 border-t border-default bg-gray-50 dark:bg-gray-800/30">
                      <p class="text-xs text-muted">{{ t.priorityReason }}</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Boş görev -->
            <div v-if="aiCoachData.tasks.length === 0" class="text-center py-8 text-muted text-sm">
              <UIcon name="i-lucide-check-circle" class="size-10 text-green-500 mx-auto mb-2" />
              <p>{{ aiCoachData.summary }}</p>
            </div>
          </div>
        </div>

        <!-- ==================== TAB 2: Süresi Geçenler ==================== -->
        <div v-else-if="aiCoachTab === 'expired'">
          <div v-if="aiCoachExpiredLoading" class="flex flex-col items-center justify-center py-16 gap-4">
            <UIcon name="i-lucide-loader-2" class="size-8 text-muted animate-spin" />
            <p class="text-sm text-muted">Yükleniyor...</p>
          </div>

          <div v-else-if="aiCoachExpired.length === 0" class="text-center py-12 text-muted text-sm">
            <UIcon name="i-lucide-check-circle" class="size-10 text-green-500 mx-auto mb-2" />
            <p>Süresi geçmiş görev bulunmuyor.</p>
          </div>

          <div v-else class="space-y-2">
            <p class="text-xs text-muted mb-3">{{ aiCoachExpired.length }} adet süresi geçmiş görev</p>

            <div v-for="t in aiCoachExpired" :key="t.taskId" class="border border-red-200 dark:border-red-800/50 rounded-lg overflow-hidden">
              <!-- Başlık -->
              <div class="flex items-center gap-2 px-4 py-2.5 bg-red-50 dark:bg-red-900/20">
                <UBadge color="error" variant="subtle" size="sm">{{ t.typeLabel }}</UBadge>
                <span class="text-sm">{{ t.customerName || '-' }}</span>
                <span v-if="t.daysLeft != null" class="ml-auto text-xs font-medium text-red-600 dark:text-red-400">
                  {{ Math.abs(t.daysLeft) }} gün geçmiş
                </span>
              </div>

              <!-- Detaylar -->
              <div class="px-4 py-2.5 space-y-1.5 text-sm">
                <div v-if="t.insuranceName || t.companyName || t.plateNo" class="flex items-center gap-2 flex-wrap text-xs text-muted">
                  <span v-if="t.insuranceName">{{ t.insuranceName }}</span>
                  <span v-if="t.companyName">• {{ t.companyName }}</span>
                  <span v-if="t.plateNo">• {{ t.plateNo }}</span>
                  <span v-if="t.expiresAt">• Vade: {{ t.expiresAt }}</span>
                </div>

                <!-- Telefon -->
                <div v-if="t.customerPhone" class="flex items-center gap-1.5 text-xs">
                  <UIcon name="i-lucide-phone" class="size-3.5 text-muted" />
                  <a :href="'tel:' + t.customerPhone" class="text-primary hover:underline">{{ t.customerPhone }}</a>
                </div>

                <!-- Son not -->
                <div v-if="t.lastNote" class="flex gap-1.5 text-xs text-muted mt-1">
                  <UIcon name="i-lucide-message-square" class="size-3.5 shrink-0 mt-0.5" />
                  <div>
                    <span class="line-clamp-2">{{ t.lastNote }}</span>
                    <span v-if="t.lastNoteAt" class="text-[11px] opacity-70"> — {{ t.lastNoteAt }}</span>
                  </div>
                </div>
                <div v-else class="text-xs text-amber-600 dark:text-amber-400 flex items-center gap-1">
                  <UIcon name="i-lucide-alert-circle" class="size-3.5" />
                  Henüz not girilmemiş
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>

      <template #footer>
        <div class="flex items-center justify-between w-full">
          <span v-if="aiCoachTab === 'suggestions' && aiCoachData?.analyzedAt" class="text-xs text-muted">Son analiz: {{ aiCoachData.analyzedAt }}</span>
          <span v-else />
          <UButton
            v-if="aiCoachTab === 'suggestions'"
            label="Tekrar Analiz Et"
            icon="i-lucide-refresh-cw"
            size="sm"
            color="neutral"
            variant="outline"
            :loading="aiCoachLoading"
            @click="analyzeWithAiCoach"
          />
          <UButton
            v-else
            label="Görevler Sayfası"
            icon="i-lucide-external-link"
            size="sm"
            color="neutral"
            variant="outline"
            to="/gorevler"
            @click="showAiCoach = false"
          />
        </div>
      </template>
    </UModal>

  </div>
</template>

<style scoped>
.task-table td,
.task-table th {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: clip;
}

</style>
