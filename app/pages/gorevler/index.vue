<script setup lang="ts">
import { CalendarDate } from '@internationalized/date'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Görevler' })

const toast = useToast()
const route = useRoute()
const { get, post, put, del } = useApi()
const { user, token } = useAuth()
const { can } = usePermissions()
const { insurances, subcategories, fetchInsurances } = useInsuranceTypes()
const { isFieldEnabled, fetchFieldSettings } = useFieldSettings()

const isAdmin = computed(() => user.value?.role === 'admin')

// Base data for offer form
const customers = ref<{ label: string; value: number }[]>([])
const companies = ref<{ label: string; value: number }[]>([])
const branches = ref<{ id: number; name: string; commission: number }[]>([])

async function fetchCustomers() {
  try {
    const res = await get<any>('customers/list-all')
    customers.value = (res.data || []).map((c: any) => ({ label: c.identityNo ? `${c.name} (${c.identityNo})` : c.name, value: c.id }))
  } catch {}
}

async function fetchCompanies() {
  try {
    const res = await get<any>('companies')
    companies.value = (res.data || []).map((c: any) => ({ label: c.name, value: c.id }))
  } catch {}
}

async function fetchBranches() {
  try {
    const res = await get<any>('branches?all=1')
    branches.value = (res.data || []).map((b: any) => ({ id: b.id, name: b.name, commissionRate: b.commissionRate || 0 }))
  } catch {}
}

// Reference sources
const referenceSources = ref<{ label: string; value: number }[]>([])
const showAddRefSource = ref(false)
const newRefSourceName = ref('')
const savingRefSource = ref(false)

async function fetchReferenceSources() {
  try {
    const res = await get<any>('reference-sources?all=1')
    referenceSources.value = (res.data || [])
      .filter((r: any) => r.isActive)
      .map((r: any) => ({ label: r.name, value: r.id }))
  } catch {}
}

// TC ile müşteri arama (dinamik)
const refCustomerMatch = ref<{ id: number; name: string } | null>(null)
const refCustomerSearching = ref(false)
const refCustomerNotFound = ref(false)
let refTcTimeout: ReturnType<typeof setTimeout> | null = null

function onRefIdentityInput(val: string) {
  createForm.value.refIdentityNo = val.replace(/\D/g, '').slice(0, 11)
  refCustomerMatch.value = null
  refCustomerNotFound.value = false

  if (refTcTimeout) clearTimeout(refTcTimeout)
  if (createForm.value.refIdentityNo.length >= 11) {
    refTcTimeout = setTimeout(searchRefCustomer, 300)
  }
}

async function searchRefCustomer() {
  const tc = createForm.value.refIdentityNo.trim()
  if (tc.length < 11) return
  refCustomerSearching.value = true
  try {
    const res = await get<any>('customers', { search: tc, limit: 1, page: 1 })
    const found = (res.data || []).find((c: any) => c.identityNo?.trim() === tc)
    if (found) {
      refCustomerMatch.value = { id: found.id, name: found.name }
      refCustomerNotFound.value = false
      // Ad alanı boşsa otomatik doldur
      if (!createForm.value.refName) createForm.value.refName = found.name
      if (!createForm.value.refPhone && found.phone) createForm.value.refPhone = found.phone
    } else {
      refCustomerMatch.value = null
      refCustomerNotFound.value = true
    }
  } catch {
    refCustomerNotFound.value = true
  } finally {
    refCustomerSearching.value = false
  }
}

async function addRefSource() {
  if (!newRefSourceName.value.trim()) return
  savingRefSource.value = true
  try {
    await post('reference-sources', { name: newRefSourceName.value.trim() })
    toast.add({ title: 'Referans kaynağı eklendi', color: 'success' })
    newRefSourceName.value = ''
    showAddRefSource.value = false
    await fetchReferenceSources()
  } catch (e: any) {
    toast.add({ title: 'Eklenemedi', description: e?.data?.message || e.message, color: 'error' })
  } finally {
    savingRefSource.value = false
  }
}

const insuranceOptions = computed(() =>
  subcategories.value
    .sort((a, b) => a.name.localeCompare(b.name, 'tr'))
    .map(i => ({ label: i.name, value: i.id }))
)

const companyOptions = computed(() =>
  companies.value.sort((a, b) => a.label.localeCompare(b.label, 'tr'))
)

const branchOptions = computed(() =>
  branches.value.map(b => ({ label: b.name, value: b.id }))
)

const networkOptions = [
  { label: 'Geniş', value: 'Geniş' },
  { label: 'Dar', value: 'Dar' },
  { label: 'Devlet', value: 'Devlet' }
]

const prodOptions = [
  { label: 'Acentem', value: 'SELF' },
  { label: 'Tali Gelen', value: 'INCOMING' },
  { label: 'Tali Giden', value: 'OUTGOING' }
]

// Selected insurance key for conditional fields in offer form
const selectedInsuranceKey = computed(() => {
  if (!createForm.value.insuranceId) return null
  const ins = insurances.value.find(i => i.id === createForm.value.insuranceId)
  return (ins as any)?.code || null
})

// Trafic field count for last-item full-width (poliçe formundaki gibi)
const trafficFieldCount = computed(() => {
  let count = 2 // plaka + ruhsat her zaman
  if (isFieldEnabled('chassis_no')) count++
  if (isFieldEnabled('engine_no')) count++
  if (isFieldEnabled('policy_brand')) count++
  if (isFieldEnabled('policy_model')) count++
  if (isFieldEnabled('vehicle_year')) count++
  return count
})

// Customer search for offer form
const customerSearchTerm = ref('')
const filteredCustomers = computed(() => {
  const term = customerSearchTerm.value.toLowerCase().trim()
  const filtered = term
    ? customers.value.filter(c => c.label.toLowerCase().includes(term))
    : customers.value
  const list = filtered.slice(0, 200)
  if (createForm.value.customerId && !list.some(c => c.value === createForm.value.customerId)) {
    const selected = customers.value.find(c => c.value === createForm.value.customerId)
    if (selected) list.unshift(selected)
  }
  return list
})

// ---------- Date Helpers ----------
function calendarDateToStr(d: any): string {
  if (!d) return ''
  return `${d.year}-${String(d.month).padStart(2, '0')}-${String(d.day).padStart(2, '0')}`
}

function strToCalendarDate(s?: string): CalendarDate | undefined {
  if (!s) return undefined
  // Handle YYYY-MM-DD
  let m = s.match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (m) return new CalendarDate(+m[1], +m[2], +m[3])
  // Handle DD/MM/YYYY
  m = s.match(/^(\d{2})\/(\d{2})\/(\d{4})/)
  if (m) return new CalendarDate(+m[3], +m[2], +m[1])
  return undefined
}

function calendarDateLabel(d: any): string {
  if (!d) return ''
  const day = String(d.day).padStart(2, '0')
  const month = String(d.month).padStart(2, '0')
  return `${day}/${month}/${d.year}`
}

// ---------- Types ----------
interface Task {
  id: number
  title: string
  description?: string
  type: 'RENEWAL' | 'OFFER' | 'OTHER' | 'CROSS_SELL' | 'REFERENCE' | 'FOLLOW_UP_CALL'
  status: 'PENDING' | 'IN_PROGRESS' | 'COMPLETED' | 'EXPIRED' | 'CANCELLED'
  priority: 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT'
  daysRemaining?: number | null
  customerId?: number
  customerName?: string
  policyId?: number
  policyNo?: string
  insuranceName?: string
  companyName?: string
  customerIdentity?: string
  plateNo?: string
  registrationNo?: string
  policyExpiresAt?: string
  productionType?: string
  assignedTo?: number
  assignedToName?: string
  completedBy?: number
  completedByName?: string
  completedAt?: string
  result?: string
  resultReason?: string
  resultNote?: string
  deadline?: string
  createdAt?: string
  updatedAt?: string
  logs?: TaskLog[]
  policyDetails?: {
    grossPremium?: number
    expiresAt?: string
    plateNo?: string
    registrationNo?: string
  }
  offerData?: {
    customerId?: number
    customerName?: string
    insuranceId?: number
    insuranceName?: string
    companyId?: number
    companyName?: string
    expiresAt?: string
    plateNo?: string
    registrationNo?: string
    vehicleBrand?: string
    vehicleModel?: string
    vehicleYear?: string
    uavtCode?: string
    network?: string
    insureds?: string
    note?: string
  }
}

interface TaskLog {
  id: number
  action: string
  fromUserName?: string
  toUserName?: string
  note?: string
  createdAt: string
}

interface TaskStats {
  total: number
  byStatus: { pending: number; inProgress: number; completed: number; expired: number; cancelled: number }
  byType: { renewal: number; offer: number; crossSell: number; reference: number; other: number }
  byResult: { renewed: number; notRenewed: number; offerApproved: number; offerRejected: number }
  overdue: number
  perUser?: { userId: number; fullName: string; total: number; pending: number; inProgress: number; completed: number; overdue: number }[]
}

interface UserItem {
  id: number
  name: string
  email: string
  role: string
}

interface PolicySearchItem {
  id: number
  policyNo: string
  customerName: string
  insuranceName: string
  companyName: string
  expiresAt?: string
  grossPremium?: number
}

// ---------- State ----------
const loading = ref(true)
const tasks = ref<Task[]>([])
const total = ref(0)
const page = ref(1)
const limit = ref(20)
const totalPages = ref(1)

const stats = ref<TaskStats | null>(null)
const users = ref<UserItem[]>([])

// Filters
const filterStatus = ref('all')
const filterType = ref('all')
const filterAssignedTo = ref<number | string>('all')
const filterMonth = ref(new Date().getMonth() + 1)
const filterYear = ref(new Date().getFullYear())

const filterMonthLabel = computed(() => {
  const months = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık']
  return `${months[filterMonth.value]} ${filterYear.value}`
})

const filterDateFrom = computed(() => {
  return `${filterYear.value}-${String(filterMonth.value).padStart(2, '0')}-01`
})

const filterDateTo = computed(() => {
  const lastDay = new Date(filterYear.value, filterMonth.value, 0).getDate()
  return `${filterYear.value}-${String(filterMonth.value).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`
})

function filterPrevMonth() {
  if (filterMonth.value === 1) { filterMonth.value = 12; filterYear.value-- }
  else filterMonth.value--
}

function filterNextMonth() {
  if (filterMonth.value === 12) { filterMonth.value = 1; filterYear.value++ }
  else filterMonth.value++
}
const searchQuery = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null

// Seçili ay analiz metni
const monthAnalysis = computed(() => {
  if (!stats.value) return ''
  const total = stats.value.total ?? 0
  const completed = stats.value.byStatus?.completed ?? 0
  const pending = stats.value.byStatus?.pending ?? 0
  const renewal = stats.value.byType?.renewal ?? 0
  const renewed = stats.value.byResult?.renewed ?? 0
  const overdue = stats.value.overdue ?? 0
  const followUpCall = stats.value.byType?.followUpCallPending ?? 0

  if (total === 0) return `${filterMonthLabel.value} döneminde henüz görev bulunmuyor.`

  const completionRate = total > 0 ? Math.round((completed / total) * 100) : 0
  const renewalRate = renewal > 0 ? Math.round((renewed / renewal) * 100) : 0

  const pendingPart = followUpCall > 0
    ? `${pending}'i bekliyor, ${followUpCall}'si takip araması`
    : `${pending}'i bekliyor`

  const parts = [
    `${filterMonthLabel.value}'da ${total} görevin ${completed}'i tamamlandı (%${completionRate}), ${pendingPart}.`
  ]
  if (renewal > 0) parts.push(`${renewal} yenileme hedefinden ${renewed}'i yenilendi (%${renewalRate}).`)
  if (overdue > 0) parts.push(`Gecikmiş ${overdue} görev var.`)

  return parts.join(' ')
})

// Sutun bazli filtreler (client-side)
// Aktif filtre sayisi
const hideKalanGun = computed(() => ['COMPLETED', 'CANCELLED'].includes(filterStatus.value))

const hasActiveFilters = computed(() =>
  filterStatus.value !== 'all' || filterType.value !== 'all' || filterAssignedTo.value !== 'all' ||
  searchQuery.value.trim() !== ''
)

function resetAllFilters() {
  filterStatus.value = 'all'
  filterType.value = 'all'
  filterAssignedTo.value = 'all'
  searchQuery.value = ''
  filterMonth.value = new Date().getMonth() + 1
  filterYear.value = new Date().getFullYear()
}

const tableColFilters = ref({
  deadline: { start: '', end: '' },
  result: [] as string[]
})

const resultFilterOptions = [
  { label: 'Yenilendi', value: 'RENEWED' },
  { label: 'Yenilenmedi', value: 'NOT_RENEWED' },
  { label: 'Teklif Onaylandı', value: 'OFFER_APPROVED' },
  { label: 'Teklif Onaylanmadı', value: 'OFFER_REJECTED' },
  { label: 'Olumlu', value: 'DONE' },
  { label: 'Olumsuz', value: 'FAILED' }
]

function clearTableColFilters() {
  tableColFilters.value = { deadline: { start: '', end: '' }, result: [] }
}

const tableColFilterCount = computed(() => {
  const cf = tableColFilters.value
  let n = 0
  if (cf.deadline.start || cf.deadline.end) n++
  if (cf.result.length) n++
  return n
})

const filteredTasks = computed(() => {
  const cf = tableColFilters.value
  return tasks.value.filter((t: any) => {
    if (cf.deadline.start && (!t.deadline || String(t.deadline).slice(0, 10) < cf.deadline.start)) return false
    if (cf.deadline.end && (!t.deadline || String(t.deadline).slice(0, 10) > cf.deadline.end)) return false
    if (cf.result.length && (!t.result || !cf.result.includes(t.result))) return false
    return true
  })
})

// Bulk selection
const selectedTaskIds = ref<Set<number>>(new Set())
const showBulkAssignModal = ref(false)
const bulkAssignTo = ref<number | undefined>(undefined)
const savingBulkAssign = ref(false)

const isAllSelected = computed(() =>
  filteredTasks.value.length > 0 && filteredTasks.value.every((t: Task) => selectedTaskIds.value.has(t.id))
)

function toggleSelectAll() {
  if (isAllSelected.value) {
    selectedTaskIds.value.clear()
  } else {
    filteredTasks.value.forEach((t: Task) => selectedTaskIds.value.add(t.id))
  }
  // trigger reactivity
  selectedTaskIds.value = new Set(selectedTaskIds.value)
}

function toggleSelect(id: number) {
  if (selectedTaskIds.value.has(id)) {
    selectedTaskIds.value.delete(id)
  } else {
    selectedTaskIds.value.add(id)
  }
  selectedTaskIds.value = new Set(selectedTaskIds.value)
}

async function bulkAssign() {
  if (!bulkAssignTo.value || selectedTaskIds.value.size === 0) return
  savingBulkAssign.value = true
  try {
    const ids = Array.from(selectedTaskIds.value)
    await Promise.all(ids.map(id => post(`tasks/${id}/assign`, { assignedTo: bulkAssignTo.value })))
    toast.add({ title: `${ids.length} görev atandı`, color: 'success' })
    showBulkAssignModal.value = false
    bulkAssignTo.value = undefined
    selectedTaskIds.value = new Set()
    fetchTasks()
    fetchStats()
  } catch (e: any) {
    toast.add({ title: 'Toplu atama başarısız', description: e.message, color: 'error' })
  } finally {
    savingBulkAssign.value = false
  }
}

async function bulkCancel() {
  if (selectedTaskIds.value.size === 0) return
  const ids = Array.from(selectedTaskIds.value)
  try {
    await Promise.all(ids.map(id => put(`tasks/${id}`, { status: 'CANCELLED' })))
    toast.add({ title: `${ids.length} görev iptal edildi`, color: 'neutral' })
    selectedTaskIds.value = new Set()
    fetchTasks()
    fetchStats()
  } catch (e: any) {
    toast.add({ title: 'Toplu iptal başarısız', description: e.message, color: 'error' })
  }
}

// Akıllı Toplu Kapatma
const showSmartCloseModal = ref(false)
const smartClosePreview = ref<any>(null)
const smartCloseLoading = ref(false)
const smartCloseApplying = ref(false)

async function fetchSmartClosePreview() {
  smartCloseLoading.value = true
  smartClosePreview.value = null
  try {
    const res = await post('tasks/bulk-smart-close', { preview: true })
    smartClosePreview.value = res.data
  } catch (e: any) {
    toast.add({ title: 'Önizleme alınamadı', description: e.message, color: 'error' })
  } finally {
    smartCloseLoading.value = false
  }
}

async function applySmartClose() {
  smartCloseApplying.value = true
  try {
    const res = await post('tasks/bulk-smart-close', { preview: false })
    toast.add({ title: `${res.data.total} görev kapatıldı`, description: `${res.data.completed} tamamlandı, ${res.data.cancelled} iptal edildi`, color: 'success' })
    showSmartCloseModal.value = false
    smartClosePreview.value = null
    selectedTaskIds.value = new Set()
    fetchTasks()
    fetchStats()
  } catch (e: any) {
    toast.add({ title: 'İşlem başarısız', description: e.message, color: 'error' })
  } finally {
    smartCloseApplying.value = false
  }
}

// Modals
const showCreateModal = ref(false)
const showAssignModal = ref(false)
const showDetailModal = ref(false)
const showCompleteModal = ref(false)
const showDeleteConfirm = ref(false)

const selectedTask = ref<Task | null>(null)
const savingCreate = ref(false)
const savingAssign = ref(false)
const savingComplete = ref(false)

// Create form
const createForm = ref({
  type: 'REFERENCE' as 'RENEWAL' | 'OFFER' | 'OTHER' | 'REFERENCE',
  title: '',
  description: '',
  priority: 'MEDIUM' as 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT',
  assignedTo: undefined as number | undefined,
  policyId: undefined as number | undefined,
  // Offer form fields
  customerId: undefined as number | undefined,
  insuranceId: undefined as number | undefined,
  companyId: undefined as number | undefined,
  plateNo: '',
  registrationNo: '',
  chassisNo: '',
  engineNo: '',
  vehicleBrand: '',
  vehicleModel: '',
  vehicleYear: '',
  uavtCode: '',
  daskNo: '',
  network: '',
  insureds: '',
  offerNote: '',
  // Reference form fields
  refName: '',
  refIdentityNo: '',
  refBirthDate: '',
  refPhone: '',
  refProduct: '',
  refSourceId: undefined as number | undefined,
})

// Calendar date refs (separate from form to work with UCalendar)
const createDeadlineDate = ref<CalendarDate>()
const createFinishDate = ref<CalendarDate>()
const assignDeadlineDate = ref<CalendarDate>()
const completeStartDate = ref<CalendarDate>()
const completeFinishDate = ref<CalendarDate>()
const createFinishDateOpen = ref(false)
const createDeadlineDateOpen = ref(false)
const assignDeadlineDateOpen = ref(false)
const completeStartDateOpen = ref(false)
const completeFinishDateOpen = ref(false)

// Assign form
const assignForm = ref({
  assignedTo: undefined as number | undefined,
})

// Complete form
const completeForm = ref({
  result: '',
  resultReason: '',
  resultNote: '',
  remindNextYear: false,
  ileriVadeDate: '',
  hasVehicle: '' as '' | 'YES' | 'NO',
  registrationReceived: '' as '' | 'YES' | 'NO',
})

// Araç takibi: müşterinin plakalı poliçesi var mı + has_vehicle durumu
const vehicleCheckResult = ref<{ hasPlatedPolicy: boolean; hasVehicle: string } | null>(null)
const vehicleCheckLoading = ref(false)

async function checkCustomerVehicle(customerId: number) {
  vehicleCheckLoading.value = true
  try {
    const res = await get<any>(`customers/${customerId}/vehicle-status`)
    vehicleCheckResult.value = res.data || null
    console.log('[VehicleCheck]', customerId, vehicleCheckResult.value)
  } catch (e) {
    console.error('[VehicleCheck] error', e)
    vehicleCheckResult.value = null
  }
  vehicleCheckLoading.value = false
}

const showVehicleQuestion = computed(() => {
  if (!selectedTask.value) return false
  if (selectedTask.value.type !== 'FOLLOW_UP_CALL') return false
  if (completeForm.value.result !== 'CALLED') return false
  if (!vehicleCheckResult.value) return false
  // Ruhsat takip görevinde araç sorusu gösterme — zaten aracı olduğu biliniyor
  if (isRuhsatTask.value) return false
  // Plakalı poliçesi varsa soru gösterme
  if (vehicleCheckResult.value.hasPlatedPolicy) return false
  // has_vehicle YES ise soru gösterme (daha önce cevaplanmış)
  if (vehicleCheckResult.value.hasVehicle === 'YES') return false
  return true
})

const showRegistrationQuestion = computed(() => {
  if (!showVehicleQuestion.value && !isRuhsatTask.value) return false
  if (isRuhsatTask.value) return completeForm.value.result === 'CALLED'
  return completeForm.value.hasVehicle === 'YES'
})

const isRuhsatTask = computed(() => {
  return selectedTask.value?.type === 'FOLLOW_UP_CALL' && selectedTask.value?.title?.startsWith('Ruhsat Takibi')
})

// Policy search
const policySearchQuery = ref('')
const policySearchResults = ref<PolicySearchItem[]>([])
const policySearchLoading = ref(false)
const selectedPolicy = ref<PolicySearchItem | null>(null)
let policySearchTimeout: ReturnType<typeof setTimeout> | null = null

// ---------- Lookups ----------
const statusOptions = [
  { label: 'Tümü', value: 'all' },
  { label: 'Bekleyen', value: 'PENDING' },
  { label: 'Devam Eden', value: 'IN_PROGRESS' },
  { label: 'Tamamlanan', value: 'COMPLETED' },
  { label: 'Süresi Geçen', value: 'EXPIRED' },
  { label: 'İptal', value: 'CANCELLED' }
]

const typeOptions = [
  { label: 'Tümü', value: 'all' },
  { label: 'Yenileme', value: 'RENEWAL' },
  { label: 'Teklif', value: 'OFFER' },
  { label: 'Çapraz Satış', value: 'CROSS_SELL' },
  { label: 'Referans', value: 'REFERENCE' },
  { label: 'Takip Araması', value: 'FOLLOW_UP_CALL' },
  { label: 'Diğer', value: 'OTHER' }
]

const typeFormOptions = [
  { label: 'Referans', value: 'REFERENCE', icon: 'i-lucide-user-plus' },
  { label: 'Diğer', value: 'OTHER', icon: 'i-lucide-clipboard-list' }
]

const priorityOptions = [
  { label: 'Düşük', value: 'LOW' },
  { label: 'Orta', value: 'MEDIUM' },
  { label: 'Yüksek', value: 'HIGH' },
  { label: 'Acil', value: 'URGENT' }
]

const renewalResultOptions = [
  { label: 'Yenilendi', value: 'RENEWED' },
  { label: 'Yenilenmedi', value: 'NOT_RENEWED' }
]

const offerResultOptions = [
  { label: 'Teklif Onaylandı', value: 'OFFER_APPROVED' },
  { label: 'Teklif Onaylanmadı', value: 'OFFER_REJECTED' }
]

const commonFailReasons = [
  'Fiyat Yüksek',
  'Başka Şirketten Alınmış',
  'Araç/Konut Satıldı',
  'İhtiyaç Duymuyor',
  'Farklı Acentenin Müşterisi'
]

const offerFailReasons = [
  ...commonFailReasons,
  'İleri Vadede Düşünüyor',
]

const renewalFailReasons = [
  ...commonFailReasons,
  'İleri Vadede Düşünüyor',
]

const crossSellResultOptions = [
  { label: 'Olumlu (Poliçe Yapıldı)', value: 'DONE' },
  { label: 'Olumsuz', value: 'FAILED' }
]

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
  'Hat dışı alanda',
  'Diğer'
]

const followUpNotAvailableReasons = [
  'Müşteri meşgul, sonra aranmak istiyor',
  'Toplantıda',
  'Uygun zamanda tekrar aranacak',
  'Diğer'
]

const userOptions = computed(() => [
  { label: 'Tümü', value: 'all' as any },
  ...users.value.map(u => ({ label: u.name, value: u.id }))
])

const userAssignOptions = computed(() =>
  users.value.map(u => ({ label: u.name, value: u.id }))
)

// Complete modal result options based on task type
const completeResultOptions = computed(() => {
  if (!selectedTask.value) return []
  if (selectedTask.value.type === 'RENEWAL') return renewalResultOptions
  if (selectedTask.value.type === 'OFFER') return offerResultOptions
  if (selectedTask.value.type === 'CROSS_SELL') return crossSellResultOptions
  if (selectedTask.value.type === 'REFERENCE') return referenceResultOptions
  if (selectedTask.value.type === 'FOLLOW_UP_CALL') return followUpCallResultOptions
  return []
})

const completeReasonOptions = computed(() => {
  if (!selectedTask.value) return []
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
  if (!selectedTask.value) return false
  if (selectedTask.value.type === 'OTHER') return true
  if (!completeForm.value.result) return false
  if (isNegativeResult.value && !completeForm.value.resultReason) return false
  if (isIleriVade.value && !completeForm.value.ileriVadeDate) return false
  return true
})

// ---------- Helpers ----------
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

function typeLabel(type: string): string {
  const map: Record<string, string> = { RENEWAL: 'Yenileme', OFFER: 'Teklif', CROSS_SELL: 'Çapraz Satış', REFERENCE: 'Referans', FOLLOW_UP_CALL: 'Takip Araması', OTHER: 'Diğer' }
  return map[type] || type
}

function typeIcon(type: string): string {
  const map: Record<string, string> = { RENEWAL: 'i-lucide-refresh-cw', OFFER: 'i-lucide-file-text', CROSS_SELL: 'i-lucide-repeat-2', REFERENCE: 'i-lucide-user-plus', FOLLOW_UP_CALL: 'i-lucide-phone-call', OTHER: 'i-lucide-clipboard-list' }
  return map[type] || 'i-lucide-clipboard-list'
}

function typeColor(type: string): string {
  const map: Record<string, string> = { RENEWAL: 'info', OFFER: 'success', CROSS_SELL: 'warning', REFERENCE: 'primary', FOLLOW_UP_CALL: 'secondary', OTHER: 'neutral' }
  return map[type] || 'neutral'
}

function resultLabel(result: string): string {
  const map: Record<string, string> = { RENEWED: 'Yenilendi', NOT_RENEWED: 'Yenilenmedi', OFFER_APPROVED: 'Teklif Onaylandı', OFFER_REJECTED: 'Teklif Reddedildi', DONE: 'Olumlu', FAILED: 'Olumsuz', CALLED: 'Ulaşıldı', NOT_REACHED: 'Ulaşılamadı', NOT_AVAILABLE: 'Müsait Değil' }
  return map[result] || result
}

function resultColor(result: string): string {
  const map: Record<string, string> = { RENEWED: 'success', OFFER_APPROVED: 'success', NOT_RENEWED: 'error', OFFER_REJECTED: 'error', DONE: 'success', FAILED: 'error', CALLED: 'success', NOT_REACHED: 'warning', NOT_AVAILABLE: 'warning' }
  return map[result] || 'neutral'
}

function formatDate(dateStr?: string): string {
  if (!dateStr) return '-'
  try {
    return new Date(dateStr).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' })
  } catch { return dateStr }
}

function formatDateTime(dateStr?: string): string {
  if (!dateStr) return '-'
  try {
    return new Date(dateStr).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
  } catch { return dateStr }
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

// Backend'den daysRemaining (sayisal) geliyorsa onu kullan, yoksa deadline'dan hesapla
function formatDaysFromBackend(days?: number | null, fallbackDate?: string | null): string {
  if (typeof days === 'number') {
    if (days < 0) return `${Math.abs(days)} gün geçti`
    if (days === 0) return 'Bugün'
    if (days === 1) return 'Yarın'
    return `${days} gün kaldı`
  }
  if (fallbackDate) return daysUntil(fallbackDate)
  return '-'
}

// Pagination pages (1 2 ... 5 6 7 ... 20)
const paginationPages = computed(() => {
  const tp = totalPages.value
  const cp = page.value
  if (tp <= 7) return Array.from({ length: tp }, (_, i) => i + 1)
  const pages: (number | string)[] = []
  if (cp <= 3) {
    pages.push(1, 2, 3, 4, '...', tp)
  } else if (cp >= tp - 2) {
    pages.push(1, '...', tp - 3, tp - 2, tp - 1, tp)
  } else {
    pages.push(1, '...', cp - 1, cp, cp + 1, '...', tp)
  }
  return pages
})

// ---------- Log action/note translations ----------
function actionLabel(action: string): string {
  const map: Record<string, string> = {
    CREATED: 'Oluşturuldu',
    STATUS_CHANGED: 'Durum Değiştirildi',
    ASSIGNED: 'Atandı',
    COMPLETED: 'Tamamlandı',
    CANCELLED: 'İptal Edildi',
    REOPENED: 'Yeniden Açıldı',
    UPDATED: 'Güncellendi',
    NOTE_ADDED: 'Not Eklendi',
    DEADLINE_CHANGED: 'Son Tarih Değiştirildi',
    PRIORITY_CHANGED: 'Öncelik Değiştirildi',
  }
  return map[action] || action
}

function translateNote(note?: string): string {
  if (!note) return ''
  return note
    .replace(/PENDING/g, 'Bekleyen')
    .replace(/IN_PROGRESS/g, 'Devam Eden')
    .replace(/COMPLETED/g, 'Tamamlanan')
    .replace(/EXPIRED/g, 'Süresi Geçen')
    .replace(/CANCELLED/g, 'İptal')
    .replace(/Otomatik yenileme gorevi olusturuldu/g, 'Otomatik yenileme görevi oluşturuldu')
    .replace(/Gorev atandi/g, 'Görev atandı')
}

// ---------- Task field accessors (RENEWAL uses policy fields, OFFER uses offerData) ----------
function getTaskCustomerName(t: any): string {
  return t.customerName || t.offerData?.customerName || '-'
}
function getTaskCustomerIdentity(t: any): string {
  return t.customerIdentity || ''
}
function getTaskCompanyName(t: any): string {
  return t.companyName || t.offerData?.companyName || ''
}
function getTaskPolicyNo(t: any): string {
  return t.policyNo || t.offerData?.policyNo || ''
}
function getTaskPlateNo(t: any): string {
  return t.plateNo || t.offerData?.plateNo || ''
}
function getTaskRegistrationNo(t: any): string {
  return t.registrationNo || t.offerData?.registrationNo || ''
}
function getTaskInsuranceName(t: any): string {
  return t.insuranceName || t.offerData?.insuranceName || ''
}
function getTaskInsuranceColor(t: any): string {
  return t.insuranceColor || 'primary'
}

const legacyColorMap: Record<string, string> = {
  primary: '#3b82f6', error: '#ef4444', success: '#22c55e',
  warning: '#f59e0b', info: '#8b5cf6', neutral: '#6b7280'
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
function getTaskExpiresAt(t: any): string {
  return t.policyExpiresAt || t.offerData?.expiresAt || t.deadline || ''
}
function getTaskProductionType(t: any): string {
  return t.productionType || ''
}
function prodLabel(type: string): string {
  const map: Record<string, string> = { SELF: 'Acentem', INCOMING: 'Tali Gelen', OUTGOING: 'Tali Giden' }
  return map[type] || type
}

// ---------- Data Fetching ----------
async function fetchStats() {
  try {
    const query: Record<string, any> = {}
    if (filterDateFrom.value) query.dateFrom = filterDateFrom.value
    if (filterDateTo.value) query.dateTo = filterDateTo.value
    if (filterAssignedTo.value !== 'all') query.assignedTo = filterAssignedTo.value
    const res = await get<any>('tasks/stats', query)
    stats.value = res.data || res
  } catch {}
}

async function fetchUsers() {
  try {
    // dropdown=1 sadece aktif kullanicilari doner
    const res = await get<any>('users?dropdown=1')
    users.value = res.data || res || []
  } catch {}
}

async function fetchTasks() {
  loading.value = true
  try {
    const query: Record<string, any> = { page: page.value, limit: limit.value }
    if (filterStatus.value !== 'all') query.status = filterStatus.value
    if (filterType.value !== 'all') query.type = filterType.value
    if (filterAssignedTo.value !== 'all') query.assignedTo = filterAssignedTo.value
    if (filterDateFrom.value) query.dateFrom = filterDateFrom.value
    if (filterDateTo.value) query.dateTo = filterDateTo.value
    if (searchQuery.value.trim()) query.search = searchQuery.value.trim()

    const res = await get<any>('tasks', query)
    tasks.value = res.data || []
    total.value = res.pagination?.total || res.total || 0
    totalPages.value = res.pagination?.totalPages || res.totalPages || 1
  } catch (e: any) {
    toast.add({ title: 'Görevler yüklenemedi', description: e.message, color: 'error' })
  } finally {
    loading.value = false
  }
}

async function fetchTaskDetail(id: number) {
  try {
    const res = await get<any>(`tasks/${id}`)
    selectedTask.value = res.data || res
    showDetailModal.value = true
  } catch (e: any) {
    toast.add({ title: 'Görev detayı yüklenemedi', description: e.message, color: 'error' })
  }
}

// Policy search for RENEWAL create
async function searchPolicies() {
  const q = policySearchQuery.value.trim()
  if (q.length < 2) {
    policySearchResults.value = []
    return
  }
  policySearchLoading.value = true
  try {
    const res = await get<any>('policies', { search: q, limit: 10, page: 1 })
    policySearchResults.value = (res.data || []).map((p: any) => ({
      id: p.id,
      policyNo: p.policyNo || '-',
      customerName: p.customerName || '-',
      insuranceName: p.insuranceName || '-',
      companyName: p.companyName || '-',
      expiresAt: p.expiresAt,
      grossPremium: p.grossPremium
    }))
  } catch {
    policySearchResults.value = []
  } finally {
    policySearchLoading.value = false
  }
}

function onPolicySearchInput() {
  if (policySearchTimeout) clearTimeout(policySearchTimeout)
  policySearchTimeout = setTimeout(searchPolicies, 400)
}

function selectPolicy(p: PolicySearchItem) {
  selectedPolicy.value = p
  createForm.value.policyId = p.id
  policySearchQuery.value = ''
  policySearchResults.value = []
}

function clearSelectedPolicy() {
  selectedPolicy.value = null
  createForm.value.policyId = undefined
}

// ---------- Actions ----------
async function createTask() {
  savingCreate.value = true
  try {
    const payload: Record<string, any> = {
      type: createForm.value.type,
      priority: createForm.value.priority,
      description: createForm.value.description || undefined,
      assignedTo: createForm.value.assignedTo || undefined
    }

    if (createForm.value.type === 'RENEWAL') {
      payload.policyId = createForm.value.policyId
    } else if (createForm.value.type === 'OFFER') {
      payload.customerId = createForm.value.customerId
      payload.insuranceId = createForm.value.insuranceId
      payload.expiresAt = calendarDateToStr(createFinishDate.value) || undefined
      payload.plateNo = createForm.value.plateNo || undefined
      payload.registrationNo = createForm.value.registrationNo || undefined
      payload.chassisNo = createForm.value.chassisNo || undefined
      payload.engineNo = createForm.value.engineNo || undefined
      payload.vehicleBrand = createForm.value.vehicleBrand || undefined
      payload.vehicleModel = createForm.value.vehicleModel || undefined
      payload.vehicleYear = createForm.value.vehicleYear || undefined
      payload.uavtCode = createForm.value.uavtCode || undefined
      payload.daskNo = createForm.value.daskNo || undefined
      payload.network = createForm.value.network || undefined
      payload.additionalInsureds = createForm.value.insureds || undefined
      payload.offerNote = createForm.value.offerNote || undefined
    } else if (createForm.value.type === 'REFERENCE') {
      payload.type = 'REFERENCE'
      payload.refName = createForm.value.refName
      payload.refIdentityNo = createForm.value.refIdentityNo || undefined
      payload.refBirthDate = createForm.value.refBirthDate || undefined
      payload.refPhone = createForm.value.refPhone || undefined
      payload.refProduct = createForm.value.refProduct || undefined
      payload.refSourceId = createForm.value.refSourceId || undefined
      payload.deadline = calendarDateToStr(createDeadlineDate.value) || undefined
    } else {
      payload.title = createForm.value.title
      payload.deadline = calendarDateToStr(createDeadlineDate.value) || undefined
    }

    await post('tasks', payload)
    toast.add({ title: 'Görev oluşturuldu', color: 'success' })
    showCreateModal.value = false
    resetCreateForm()
    fetchTasks()
    fetchStats()
  } catch (e: any) {
    toast.add({ title: 'Görev oluşturulamadı', description: e.message, color: 'error' })
  } finally {
    savingCreate.value = false
  }
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
    fetchTasks()
    fetchStats()
  } catch (e: any) {
    toast.add({ title: 'Atama başarısız', description: e.message, color: 'error' })
  } finally {
    savingAssign.value = false
  }
}

async function startTask(task: Task) {
  try {
    await put(`tasks/${task.id}`, { status: 'IN_PROGRESS' })
    toast.add({ title: 'Görev başlatıldı', color: 'success' })
    fetchTasks()
    fetchStats()
  } catch (e: any) {
    toast.add({ title: 'İşlem başarısız', description: e.message, color: 'error' })
  }
}

function openCompleteModal(task: Task) {
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
  // OTHER type completes directly
  if (task.type === 'OTHER') {
    submitComplete()
    return
  }
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
    const payload: Record<string, any> = {}
    if (selectedTask.value.type !== 'OTHER') {
      payload.result = completeForm.value.result
      if (completeForm.value.resultReason) payload.resultReason = completeForm.value.resultReason
      if (completeForm.value.resultNote) payload.resultNote = completeForm.value.resultNote
    }
    // Olumsuz sonuç + seneye hatirlat secili ise gelecek yil takip gorevi olusur
    if (isNegativeResult.value && completeForm.value.remindNextYear && !isIleriVade.value) {
      payload.remindNextYear = true
    }
    // İleri vadede düşünüyor: belirtilen tarihe OFFER görevi oluştur/güncelle
    if (isIleriVade.value && completeForm.value.ileriVadeDate) {
      payload.ileriVadeDate = completeForm.value.ileriVadeDate
    }
    // Araç takibi bilgileri
    if (selectedTask.value.type === 'FOLLOW_UP_CALL' && completeForm.value.result === 'CALLED') {
      if (completeForm.value.hasVehicle) payload.hasVehicle = completeForm.value.hasVehicle
      if (completeForm.value.registrationReceived) payload.registrationReceived = completeForm.value.registrationReceived
      // Ruhsat takip görevi ise ve ruhsat sorusu cevaplandıysa hasVehicle=YES gönder
      if (isRuhsatTask.value && completeForm.value.registrationReceived) payload.hasVehicle = 'YES'
    }
    await post(`tasks/${selectedTask.value.id}/complete`, payload)
    const isPostponed = selectedTask.value.type === 'FOLLOW_UP_CALL' && ['NOT_REACHED', 'NOT_AVAILABLE'].includes(completeForm.value.result)
    const isIleriVadeSubmitted = isIleriVade.value
    toast.add({ title: isPostponed ? 'Görev ertelendi' : (isIleriVadeSubmitted ? 'Görev tamamlandı, ileri vade takibi oluşturuldu' : 'Görev tamamlandı'), color: isPostponed ? 'info' : 'success' })
    showCompleteModal.value = false
    showDetailModal.value = false
    fetchTasks()
    fetchStats()
  } catch (e: any) {
    toast.add({ title: 'İşlem başarısız', description: e.message, color: 'error' })
  } finally {
    savingComplete.value = false
  }
}

const showCancelConfirm = ref(false)
const cancelTargetTask = ref<Task | null>(null)

function confirmCancelTask(task: Task) {
  cancelTargetTask.value = task
  showCancelConfirm.value = true
}

async function cancelTask() {
  if (!cancelTargetTask.value) return
  try {
    await put(`tasks/${cancelTargetTask.value.id}`, { status: 'CANCELLED' })
    toast.add({ title: 'Görev iptal edildi', color: 'neutral' })
    showCancelConfirm.value = false
    cancelTargetTask.value = null
    fetchTasks()
    fetchStats()
  } catch (e: any) {
    toast.add({ title: 'İşlem başarısız', description: e.message, color: 'error' })
  }
}

async function deleteTask() {
  if (!selectedTask.value) return
  try {
    await del(`tasks/${selectedTask.value.id}`)
    toast.add({ title: 'Görev silindi', color: 'success' })
    showDeleteConfirm.value = false
    showDetailModal.value = false
    fetchTasks()
    fetchStats()
  } catch (e: any) {
    toast.add({ title: 'Silinemedi', description: e.message, color: 'error' })
  }
}

function openAssignModal(task: Task) {
  selectedTask.value = task
  assignForm.value = { assignedTo: task.assignedTo || undefined }
  assignDeadlineDate.value = task.deadline ? strToCalendarDate(task.deadline) : undefined
  showAssignModal.value = true
}

function openCreateModal() {
  showCreateModal.value = true
}

function resetCreateForm() {
  createForm.value = {
    type: 'REFERENCE',
    title: '',
    description: '',
    priority: 'MEDIUM',
    assignedTo: undefined,
    policyId: undefined,
      customerId: undefined,
    insuranceId: undefined,
    companyId: undefined,
    plateNo: '',
    registrationNo: '',
    chassisNo: '',
    engineNo: '',
    vehicleBrand: '',
    vehicleModel: '',
    vehicleYear: '',
    uavtCode: '',
    daskNo: '',
    network: '',
    insureds: '',
    offerNote: '',
    refName: '',
    refIdentityNo: '',
    refBirthDate: '',
    refPhone: '',
    refProduct: '',
    refSourceId: undefined,
  }
  createDeadlineDate.value = undefined
  createFinishDate.value = undefined
  selectedPolicy.value = null
  policySearchQuery.value = ''
  policySearchResults.value = []
  customerSearchTerm.value = ''
}

const canSubmitCreate = computed(() => {
  if (createForm.value.type === 'RENEWAL') return !!createForm.value.policyId
  if (createForm.value.type === 'OFFER') return !!createForm.value.customerId && !!createForm.value.insuranceId
  if (createForm.value.type === 'REFERENCE') return !!createForm.value.refName.trim()
  return !!createForm.value.title.trim()
})

// ---------- Watchers ----------
watch([filterStatus, filterType, filterAssignedTo, filterMonth, filterYear], () => {
  page.value = 1
  fetchTasks()
  fetchStats()
})

watch(searchQuery, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    page.value = 1
    fetchTasks()
  }, 400)
})

watch(page, () => { selectedTaskIds.value = new Set(); fetchTasks() })
watch(limit, () => fetchTasks())

// Bildirimden gelen taskId değişirse detay aç
watch(() => route.query.taskId, (newId) => {
  if (newId) fetchTaskDetail(Number(newId))
})

// Reset policy when type changes in create form
watch(() => createForm.value.type, () => {
  clearSelectedPolicy()
  policySearchQuery.value = ''
  policySearchResults.value = []
})

// ---------- Export ----------
function exportExcel() {
  const params = new URLSearchParams()
  if (filterStatus.value !== 'all') params.set('status', filterStatus.value)
  if (filterType.value !== 'all') params.set('type', filterType.value)
  if (filterAssignedTo.value !== 'all') params.set('assignedTo', String(filterAssignedTo.value))
  if (filterDateFrom.value) params.set('dateFrom', filterDateFrom.value)
  if (filterDateTo.value) params.set('dateTo', filterDateTo.value)
  if (searchQuery.value.trim()) params.set('search', searchQuery.value.trim())

  const url = `/api/tasks/export?${params.toString()}`

  fetch(url, { headers: { Authorization: `Bearer ${token.value}` } })
    .then(res => {
      const disposition = res.headers.get('Content-Disposition') || ''
      const match = disposition.match(/filename="?([^"]+)"?/)
      const filename = match ? match[1] : `gorevler_${new Date().toISOString().slice(0, 10)}.xlsx`
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
    .catch(() => {
      toast.add({ title: 'Excel indirilemedi', color: 'error' })
    })
}

// ---------- Init ----------

onMounted(async () => {
  fetchStats()
  await fetchTasks()
  fetchUsers()
  fetchInsurances()
  fetchCustomers()
  fetchCompanies()
  fetchBranches()
  fetchReferenceSources()

  // Bildirimden gelen taskId varsa detay modalını aç
  const taskId = route.query.taskId
  if (taskId) {
    fetchTaskDetail(Number(taskId))
  }

  // Header'dan gelen action=new varsa görev oluşturma modalını aç
  if (route.query.action === 'new') {
    openCreateModal()
  }
})
</script>

<template>
  <div class="p-4 sm:p-6 space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-xl font-semibold">Görev Yönetimi</h1>
      <p class="text-sm text-muted mt-1">Yenileme, teklif ve takip araması görevleri.</p>
    </div>

    <!-- Stats Row -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <UCard :ui="{ body: 'p-3' }" class="cursor-pointer hover:ring-2 ring-primary/50 transition-all" @click="filterStatus = 'all'">
        <div class="text-center">
          <p class="text-2xl font-bold">{{ stats?.total ?? 0 }}</p>
          <p class="text-xs text-muted mt-1">Toplam</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }" class="cursor-pointer hover:ring-2 ring-primary/50 transition-all" @click="filterStatus = filterStatus === 'PENDING' ? 'all' : 'PENDING'">
        <div class="text-center">
          <p class="text-2xl font-bold text-yellow-600">{{ stats?.byStatus?.pending ?? 0 }}</p>
          <p class="text-xs text-muted mt-1">Bekleyen</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }" class="cursor-pointer hover:ring-2 ring-primary/50 transition-all" @click="filterStatus = filterStatus === 'COMPLETED' ? 'all' : 'COMPLETED'">
        <div class="text-center">
          <p class="text-2xl font-bold text-green-600">{{ stats?.byStatus?.completed ?? 0 }}</p>
          <p class="text-xs text-muted mt-1">Tamamlanan</p>
        </div>
      </UCard>
      <UCard :ui="{ body: 'p-3' }" class="cursor-pointer hover:ring-2 ring-primary/50 transition-all" @click="filterStatus = filterStatus === 'EXPIRED' ? 'all' : 'EXPIRED'">
        <div class="text-center">
          <p class="text-2xl font-bold text-red-600">{{ stats?.overdue ?? 0 }}</p>
          <p class="text-xs text-muted mt-1">Geciken</p>
          <button
            v-if="isAdmin && (stats?.overdue ?? 0) > 0"
            class="mt-1.5 text-[10px] px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 font-semibold hover:bg-red-200 dark:hover:bg-red-800/50 transition-colors"
            @click.stop="showSmartCloseModal = true; fetchSmartClosePreview()"
          >
            Akıllı Kapat
          </button>
        </div>
      </UCard>
    </div>

    <!-- Ay Analizi -->
    <div v-if="monthAnalysis" class="flex items-start gap-3 px-4 py-3 rounded-xl bg-primary/5 border border-primary/20">
      <UIcon name="i-lucide-bar-chart-2" class="text-primary mt-0.5 shrink-0" />
      <p class="text-sm text-default font-medium">{{ monthAnalysis }}</p>
    </div>

    <!-- Temsilci Özeti -->
    <div v-if="isAdmin && stats?.perUser?.length" class="overflow-x-auto">
      <table class="w-full text-xs border border-default rounded-lg overflow-hidden">
        <thead>
          <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
            <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted">Temsilci</th>
            <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Toplam</th>
            <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Bekleyen</th>
            <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Devam Eden</th>
            <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Tamamlanan</th>
            <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Geciken</th>
            <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Başarı</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in stats.perUser" :key="u.userId" class="border-t border-default hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
            <td class="py-2 px-3 font-medium" :title="u.fullName">{{ shortName(u.fullName) }}</td>
            <td class="text-center py-2 px-3">{{ u.total }}</td>
            <td class="text-center py-2 px-3 text-yellow-600">{{ u.pending }}</td>
            <td class="text-center py-2 px-3 text-blue-600">{{ u.inProgress }}</td>
            <td class="text-center py-2 px-3 text-green-600">{{ u.completed }}</td>
            <td class="text-center py-2 px-3 text-red-600">{{ u.overdue }}</td>
            <td class="text-center py-2 px-3">
              <span :class="u.total > 0 && Math.round(u.completed / u.total * 100) >= 70 ? 'text-green-600 font-bold' : u.total > 0 && Math.round(u.completed / u.total * 100) >= 40 ? 'text-yellow-600 font-bold' : 'text-red-600 font-bold'">
                {{ u.total > 0 ? Math.round(u.completed / u.total * 100) : 0 }}%
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Görev Tablosu -->
    <UCard>
      <template #header>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1 border border-[var(--ui-border)] rounded-md px-2 h-[50px]">
              <UButton icon="i-lucide-chevron-left" size="xs" color="neutral" variant="ghost" @click="filterPrevMonth" />
              <span class="font-semibold text-sm flex-1 text-center whitespace-nowrap w-[120px]">{{ filterMonthLabel }}</span>
              <UButton icon="i-lucide-chevron-right" size="xs" color="neutral" variant="ghost" @click="filterNextMonth" />
            </div>
            <div v-if="isAdmin" class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5 w-[200px]">
              <USelect v-model="filterAssignedTo" :items="[{ label: 'Tüm Temsilciler', value: 'all' }, ...users.map(u => ({ label: u.name, value: u.id }))]" value-key="value" placeholder=" " class="w-full" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">Temsilci</label>
            </div>
            <div class="relative w-[220px] [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="searchQuery" placeholder=" " class="w-full peer/fl-tsearch" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-tsearch:top-0 peer-focus-within/fl-tsearch:-translate-y-1/2 peer-focus-within/fl-tsearch:text-xs peer-focus-within/fl-tsearch:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-tsearch:top-0 peer-has-[input:not(:placeholder-shown)]/fl-tsearch:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-tsearch:text-xs peer-has-[input:not(:placeholder-shown)]/fl-tsearch:text-[var(--ui-text-highlighted)]">Poliçe, müşteri ara</label>
            </div>
            <UButton v-if="hasActiveFilters" icon="i-lucide-x" size="xl" color="error" variant="ghost" class="font-semibold" @click="resetAllFilters" title="Filtreleri Temizle" />
          </div>
          <UButton v-if="can('tasks.export')" label="Excel" icon="i-lucide-download" size="xl" color="neutral" variant="outline" class="font-semibold hidden sm:flex" @click="exportExcel" />
        </div>
      </template>
      <!-- Bulk action bar -->
      <div v-if="isAdmin && selectedTaskIds.size > 0" class="flex flex-wrap items-center gap-2 mb-3 p-2 rounded-lg bg-primary/5 border border-primary/20">
        <span class="text-sm font-medium">{{ selectedTaskIds.size }} görev seçildi</span>
        <UButton label="Toplu Ata" icon="i-lucide-user-plus" size="xl" class="font-semibold" color="info" variant="outline" @click="showBulkAssignModal = true" />
        <UButton label="Toplu İptal" icon="i-lucide-x-circle" size="xl" class="font-semibold" color="error" variant="outline" @click="bulkCancel" />
        <UButton label="Seçimi Kaldır" size="xl" class="font-semibold" color="neutral" variant="ghost" @click="selectedTaskIds = new Set()" />
      </div>

      <div v-if="tableColFilterCount > 0" class="flex items-center justify-between mb-2 px-1">
        <span class="text-xs text-muted">{{ filteredTasks.length }} / {{ tasks.length }} kayıt · {{ tableColFilterCount }} kolon filtresi aktif</span>
        <button type="button" class="text-xs text-primary hover:underline" @click="clearTableColFilters">Kolon filtrelerini temizle</button>
      </div>
      <div v-if="loading" class="py-4">
        <SkeletonTable :rows="6" :cols="8" />
      </div>
      <div v-else-if="filteredTasks.length === 0" class="text-center py-8 text-muted">Görev bulunamadı</div>
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <table class="text-xs w-full table-fixed">
          <thead class="sticky top-0 z-10">
            <tr class="border-b border-default bg-gray-50 dark:bg-gray-800/50">
              <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:20%">
                <div class="flex items-center gap-2">
                  <input v-if="isAdmin" type="checkbox" :checked="isAllSelected" @change="toggleSelectAll" class="rounded shrink-0 hidden md:inline" />
                  <span>Ad/Soyad</span>
                </div>
              </th>
              <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:13%">Görev Türü</th>
              <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:10%">Poliçe Türü</th>
              <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:9%">Görev Tarihi</th>
              <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:10%" :class="hideKalanGun ? 'opacity-0 pointer-events-none' : ''">Kalan Gün</th>
              <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:10%">Temsilci</th>
              <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:12%">
                <ColumnFilter v-model="tableColFilters.result" label="Sonuç" type="multiselect" :options="resultFilterOptions" />
              </th>
              <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:8%">İşlemler</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(task, idx) in filteredTasks"
              :key="task.id"
              class="border-b border-default hover:bg-amber-50/40 dark:hover:bg-amber-900/10 cursor-pointer transition-colors"
              @click="fetchTaskDetail(task.id)"
            >
              <!-- Ad/Soyad -->
              <td class="py-2 px-3 overflow-hidden" style="max-width:0">
                <div class="flex items-center gap-2">
                  <input v-if="isAdmin" type="checkbox" :checked="selectedTaskIds.has(task.id)" @change="toggleSelect(task.id)" class="rounded shrink-0 hidden md:inline" @click.stop />
                  <div class="min-w-0">
                    <p class="font-semibold text-primary truncate" :title="getTaskCustomerName(task)">{{ getTaskCustomerName(task) }}</p>
                    <p class="text-muted truncate">{{ getTaskCustomerIdentity(task) }}</p>
                  </div>
                </div>
              </td>

              <!-- Görev Türü -->
              <td class="py-2 px-3">
                <div class="flex items-center gap-1.5 whitespace-nowrap">
                  <UIcon :name="typeIcon(task.type)" class="size-3.5 shrink-0" :class="`text-${typeColor(task.type)}`" />
                  <span>{{ typeLabel(task.type) }}</span>
                </div>
              </td>

              <!-- Poliçe Türü -->
              <td class="hidden md:table-cell py-2 px-3">
                <span
                  v-if="getTaskInsuranceName(task)"
                  class="badge-cell"
                  :style="{ backgroundColor: toHex(getTaskInsuranceColor(task)) + '1a', color: toHex(getTaskInsuranceColor(task)) }"
                >
                  {{ getTaskInsuranceName(task) }}
                </span>
                <span v-else class="text-muted">-</span>
              </td>

              <!-- Görev Tarihi -->
              <td class="hidden md:table-cell py-2 px-3">
                <span v-if="task.deadline">{{ formatDate(task.deadline) }}</span>
                <span v-else class="text-muted">-</span>
              </td>

              <!-- Kalan Gün -->
              <td class="py-2 px-3">
                <template v-if="!hideKalanGun">
                  <span
                    v-if="task.daysRemaining != null"
                    class="badge-cell"
                    :class="task.daysRemaining <= 0 ? 'badge-error' : task.daysRemaining <= 7 ? 'badge-warning' : task.daysRemaining <= 30 ? 'badge-info' : 'badge-success'"
                  >
                    {{ formatDaysFromBackend(task.daysRemaining, task.deadline) }}
                  </span>
                  <span v-else class="text-muted">-</span>
                </template>
              </td>

              <!-- Temsilci -->
              <td class="py-2 px-3">
                <span v-if="task.assignedToName" class="block truncate" :title="task.assignedToName">{{ shortName(task.assignedToName) }}</span>
                <span v-else class="text-muted">Atanmamış</span>
              </td>


              <!-- Sonuç -->
              <td class="hidden md:table-cell py-2 px-3">
                <span v-if="task.result" class="badge-cell" :class="`badge-${resultColor(task.result)}`">
                  {{ resultLabel(task.result) }}
                </span>
                <span v-else class="text-muted">-</span>
              </td>

              <!-- İşlemler -->
              <td class="hidden md:table-cell py-2 px-3" @click.stop>
                <div class="flex items-center gap-1">
                  <template v-if="isAdmin">
                    <UButton v-if="task.status === 'PENDING' || task.status === 'IN_PROGRESS'" icon="i-lucide-user-plus" size="xs" color="info" variant="ghost" title="Ata" @click="openAssignModal(task)" />
                    <UButton v-if="task.status !== 'COMPLETED' && task.status !== 'CANCELLED'" icon="i-lucide-check" size="xs" color="success" variant="ghost" title="Tamamla" @click="openCompleteModal(task)" />
                    <UButton v-if="task.status !== 'COMPLETED' && task.status !== 'CANCELLED'" icon="i-lucide-x" size="xs" color="error" variant="ghost" title="İptal" @click="confirmCancelTask(task)" />
                  </template>
                  <template v-else>
                    <UButton v-if="task.status === 'PENDING'" icon="i-lucide-play" size="xs" color="info" variant="ghost" title="Başlat" @click="startTask(task)" />
                    <UButton v-if="task.status === 'IN_PROGRESS'" icon="i-lucide-check" size="xs" color="success" variant="ghost" title="Tamamla" @click="openCompleteModal(task)" />
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="total > 0" class="flex flex-col sm:flex-row items-center gap-3 pt-4 border-t border-default mt-4">
        <div class="flex items-center gap-2 text-sm text-muted">
          <span class="hidden sm:inline">Sayfa başına satır</span>
          <select
            :value="limit"
            class="border border-default rounded px-2 py-1 text-sm bg-white dark:bg-gray-900"
            @change="limit = Number(($event.target as HTMLSelectElement).value); page = 1"
          >
            <option :value="15">15</option>
            <option :value="20">20</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
          <span>{{ (page - 1) * limit + 1 }} - {{ Math.min(page * limit, total) }} / {{ total }}</span>
        </div>
        <div v-if="totalPages > 1" class="flex items-center gap-1 sm:ml-auto">
          <!-- Mobil: sadece önceki/sonraki + sayfa bilgisi -->
          <UButton icon="i-lucide-chevron-left" size="xs" color="neutral" variant="outline" :disabled="page <= 1" class="sm:hidden" @click="page--" />
          <span class="sm:hidden text-xs text-muted px-2">{{ page }} / {{ totalPages }}</span>
          <UButton icon="i-lucide-chevron-right" size="xs" color="neutral" variant="outline" :disabled="page >= totalPages" class="sm:hidden" @click="page++" />
          <!-- Masaüstü: tam sayfalama -->
          <UButton icon="i-lucide-chevrons-left" size="xs" color="neutral" variant="outline" :disabled="page <= 1" class="hidden sm:inline-flex" @click="page = 1" />
          <UButton icon="i-lucide-chevron-left" size="xs" color="neutral" variant="outline" :disabled="page <= 1" class="hidden sm:inline-flex" @click="page--" />
          <template v-for="p in paginationPages" :key="p">
            <span v-if="p === '...'" class="hidden sm:inline px-1 text-muted text-xs">...</span>
            <UButton
              v-else
              :label="String(p)"
              size="xs"
              :color="p === page ? 'primary' : 'neutral'"
              :variant="p === page ? 'solid' : 'outline'"
              class="hidden sm:inline-flex"
              @click="page = p as number"
            />
          </template>
          <UButton icon="i-lucide-chevron-right" size="xs" color="neutral" variant="outline" :disabled="page >= totalPages" class="hidden sm:inline-flex" @click="page++" />
          <UButton icon="i-lucide-chevrons-right" size="xs" color="neutral" variant="outline" :disabled="page >= totalPages" class="hidden sm:inline-flex" @click="page = totalPages" />
        </div>
      </div>
    </UCard>

    <!-- ==================== CREATE TASK MODAL ==================== -->
    <TaskFormModal v-model:open="showCreateModal" @saved="() => { fetchTasks(); fetchStats() }" />

    <!-- UNUSED_BLOCK_START - kept for reference, never rendered -->
    <template v-if="false">
    <UModal :dismissible="false" v-model:open="showCreateModal" title="Yeni Görev Oluştur" :ui="{ width: 'sm:max-w-2xl' }">
      <template #body>
        <div class="flex flex-col gap-4 max-h-[85vh] overflow-y-auto pr-1">
          <!-- Type Selection -->
          <div class="grid grid-cols-2 gap-2">
            <button
              v-for="opt in typeFormOptions"
              :key="opt.value"
              class="flex flex-col items-center gap-1 p-2.5 rounded-lg border-2 transition-all"
              :class="createForm.type === opt.value
                ? 'border-primary bg-primary/5 text-primary'
                : 'border-default hover:border-gray-400'"
              @click="createForm.type = opt.value as any"
            >
              <UIcon :name="opt.icon" class="size-4" />
              <span class="text-[11px] font-medium leading-tight text-center">{{ opt.label }}</span>
            </button>
          </div>

          <USeparator />

          <!-- ===== RENEWAL: Policy Search ===== -->
          <template v-if="createForm.type === 'RENEWAL'">
            <UFormField label="Poliçe Seç" required>
              <div v-if="selectedPolicy" class="flex items-start justify-between gap-3 p-3 rounded-lg border border-primary/40 bg-primary/5">
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <UIcon name="i-lucide-shield-check" class="text-primary size-4 shrink-0" />
                    <p class="font-medium text-sm">{{ selectedPolicy.customerName }}</p>
                  </div>
                  <p class="text-xs text-muted mt-1">
                    {{ selectedPolicy.policyNo }} &middot; {{ selectedPolicy.insuranceName }} / {{ selectedPolicy.companyName }}
                  </p>
                  <div v-if="selectedPolicy.expiresAt || selectedPolicy.grossPremium" class="flex items-center gap-3 mt-1">
                    <span v-if="selectedPolicy.expiresAt" class="text-xs text-muted">
                      <UIcon name="i-lucide-calendar" class="size-3 inline -mt-px" /> {{ formatDate(selectedPolicy.expiresAt) }}
                    </span>
                    <span v-if="selectedPolicy.grossPremium" class="text-xs text-muted">
                      <UIcon name="i-lucide-banknote" class="size-3 inline -mt-px" /> {{ selectedPolicy.grossPremium.toLocaleString('tr-TR') }} TL
                    </span>
                  </div>
                </div>
                <UButton icon="i-lucide-x" size="xs" color="neutral" variant="ghost" @click="clearSelectedPolicy" />
              </div>

              <div v-else class="relative">
                <UInput
                  v-model="policySearchQuery"
                  placeholder="Poliçe no veya müşteri adı ile ara..."
                  icon="i-lucide-search"
                  :loading="policySearchLoading"
                  class="w-full"
                  @input="onPolicySearchInput"
                />
                <div
                  v-if="policySearchResults.length > 0"
                  class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-900 border border-default rounded-lg shadow-lg max-h-60 overflow-y-auto"
                >
                  <button
                    v-for="p in policySearchResults"
                    :key="p.id"
                    class="w-full text-left px-3 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-800 border-b border-default last:border-0 transition-colors"
                    @click="selectPolicy(p)"
                  >
                    <div class="flex items-center gap-2">
                      <UIcon name="i-lucide-shield-check" class="text-muted size-3.5 shrink-0" />
                      <span class="text-sm font-medium">{{ p.customerName }}</span>
                    </div>
                    <p class="text-xs text-muted mt-0.5 pl-5.5">
                      {{ p.policyNo }} &middot; {{ p.insuranceName }} / {{ p.companyName }}
                    </p>
                  </button>
                </div>
                <p v-if="policySearchQuery.length >= 2 && !policySearchLoading && policySearchResults.length === 0" class="text-xs text-muted mt-1.5">
                  Sonuç bulunamadı.
                </p>
              </div>
            </UFormField>
          </template>

          <!-- ===== OFFER: Full Offer Form ===== -->
          <template v-if="createForm.type === 'OFFER'">
            <!-- Müşteri + Sigorta (zorunlu) -->
            <UFormField label="Müşteri" required>
              <USelectMenu
                v-model="createForm.customerId"
                :items="filteredCustomers"
                value-key="value"
                label-key="label"
                placeholder="Müşteri ara (ad veya TC)..."
                ignore-filter
                class="w-full"
                @update:search-term="(t: string) => customerSearchTerm = t"
              />
            </UFormField>

            <UFormField label="Sigorta Türü" required>
              <USelectMenu
                v-model="createForm.insuranceId"
                :items="insuranceOptions"
                value-key="value"
                label-key="label"
                placeholder="Branş sec..."
                searchable
                :search-input="{ placeholder: 'Ara...' }"
                :search-attributes="['label']"
                class="w-full"
              />
            </UFormField>

            <div class="grid grid-cols-2 gap-3">
              <UFormField label="Bitiş Tarihi">
                <UPopover v-model:open="createFinishDateOpen">
                  <UButton
                    :label="createFinishDate ? calendarDateLabel(createFinishDate) : 'Tarih sec'"
                    icon="i-lucide-calendar"
                    color="neutral"
                    variant="outline"
                    class="w-full justify-start"
                    :class="{ 'text-muted': !createFinishDate }"
                  />
                  <template #content>
                    <UCalendar locale="tr-TR" v-model="createFinishDate" class="p-2" @update:model-value="createFinishDateOpen = false" />
                  </template>
                </UPopover>
              </UFormField>
            </div>

            <!-- Araç Bilgileri (TRAFFIC) -->
            <template v-if="selectedInsuranceKey === 'TRAFFIC'">
              <USeparator />
              <p class="text-xs font-medium text-muted">Araç Bilgileri</p>
              <div class="grid grid-cols-2 gap-3">
                <UFormField label="Plaka">
                  <UInput v-model="createForm.plateNo" placeholder="34 ABC 123" class="w-full" />
                </UFormField>
                <UFormField label="Ruhsat Seri No">
                  <UInput v-model="createForm.registrationNo" placeholder="AA123456" class="w-full" />
                </UFormField>
                <UFormField v-if="isFieldEnabled('chassis_no')" label="Sasi No">
                  <UInput v-model="createForm.chassisNo" class="w-full" />
                </UFormField>
                <UFormField v-if="isFieldEnabled('engine_no')" label="Motor No">
                  <UInput v-model="createForm.engineNo" class="w-full" />
                </UFormField>
                <UFormField v-if="isFieldEnabled('policy_brand')" label="Marka">
                  <UInput v-model="createForm.vehicleBrand" placeholder="Marka" class="w-full" />
                </UFormField>
                <UFormField v-if="isFieldEnabled('policy_model')" label="Model">
                  <UInput v-model="createForm.vehicleModel" placeholder="Model" class="w-full" />
                </UFormField>
                <UFormField v-if="isFieldEnabled('vehicle_year')" label="Model Yili" :class="trafficFieldCount % 2 === 1 ? 'col-span-2' : ''">
                  <UInput v-model="createForm.vehicleYear" placeholder="2024" class="w-full" />
                </UFormField>
              </div>
            </template>

            <!-- Konut (HOUSING) -->
            <template v-if="selectedInsuranceKey === 'HOUSING' && isFieldEnabled('policy_uavt')">
              <USeparator />
              <UFormField label="UAVT Kodu">
                <UInput v-model="createForm.uavtCode" placeholder="UAVT kodu" class="w-full" />
              </UFormField>
            </template>

            <!-- DASK -->
            <template v-if="selectedInsuranceKey === 'DASK' && (isFieldEnabled('policy_uavt') || isFieldEnabled('dask_no'))">
              <USeparator />
              <div class="grid gap-3" :class="(isFieldEnabled('policy_uavt') && isFieldEnabled('dask_no')) ? 'grid-cols-2' : 'grid-cols-1'">
                <UFormField v-if="isFieldEnabled('policy_uavt')" label="UAVT Kodu">
                  <UInput v-model="createForm.uavtCode" placeholder="UAVT kodu" class="w-full" />
                </UFormField>
                <UFormField v-if="isFieldEnabled('dask_no')" label="DASK Poliçe No">
                  <UInput v-model="createForm.daskNo" placeholder="DASK poliçe no" class="w-full" />
                </UFormField>
              </div>
            </template>

            <!-- Sağlık (HEALTH) -->
            <template v-if="selectedInsuranceKey === 'HEALTH'">
              <USeparator />
              <p class="text-xs font-medium text-muted">Sağlık Bilgileri</p>
              <UFormField v-if="isFieldEnabled('policy_network')" label="Network">
                <USelectMenu
                  v-model="createForm.network"
                  :items="networkOptions"
                  value-key="value"
                  placeholder="Network sec..."
                  class="w-full"
                />
              </UFormField>
              <UFormField label="Sigortalilar">
                <UTextarea v-model="createForm.insureds" :rows="2" placeholder="Her satıra bir kişi..." class="w-full" />
              </UFormField>
            </template>

            <USeparator />
            <UFormField label="Teklif Notu">
              <UTextarea v-model="createForm.offerNote" :rows="2" placeholder="Ek açıklama..." class="w-full" />
            </UFormField>
          </template>

          <!-- ===== REFERENCE: Referans Formu ===== -->
          <template v-if="createForm.type === 'REFERENCE'">
            <UFormField label="TC Kimlik No">
              <UInput :model-value="createForm.refIdentityNo" placeholder="TC Kimlik No girin..." :loading="refCustomerSearching" class="w-full" @update:model-value="onRefIdentityInput" />
            </UFormField>

            <!-- TC eşleştirme sonucu -->
            <div v-if="refCustomerSearching" class="text-xs text-muted p-2 rounded bg-gray-50 dark:bg-gray-800">
              <UIcon name="i-lucide-loader" class="size-3 inline -mt-px mr-1 animate-spin" />
              Müşteri aranıyor...
            </div>
            <div v-else-if="refCustomerMatch" class="text-xs p-2 rounded bg-green-50 dark:bg-green-950/30 text-green-700 dark:text-green-400 flex items-center gap-1.5">
              <UIcon name="i-lucide-user-check" class="size-3.5 shrink-0" />
              <span>Kayıtlı müşteri bulundu: <strong>{{ refCustomerMatch.name }}</strong> — görev bu müşteriye bağlanacak.</span>
            </div>
            <div v-else-if="refCustomerNotFound" class="text-xs p-2 rounded bg-amber-50 dark:bg-amber-950/30 text-amber-700 dark:text-amber-400 flex items-center gap-1.5">
              <UIcon name="i-lucide-user-plus" class="size-3.5 shrink-0" />
              <span>Bu TC ile kayıtlı müşteri bulunamadı — yeni müşteri kaydı oluşturulacak.</span>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <UFormField label="Ad Soyad" required>
                <UInput v-model="createForm.refName" placeholder="Ad Soyad" class="w-full" />
              </UFormField>
              <UFormField label="Doğum Tarihi">
                <UInput v-model="createForm.refBirthDate" placeholder="GG/AA/YYYY" maxlength="10" class="w-full" />
              </UFormField>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <UFormField label="Telefon No">
                <UInput v-model="createForm.refPhone" placeholder="05XX XXX XX XX" class="w-full" />
              </UFormField>
              <UFormField label="Ürün">
                <USelectMenu
                  v-model="createForm.refProduct"
                  :items="insuranceOptions"
                  value-key="label"
                  label-key="label"
                  placeholder="Ürün seç..."
                  searchable
                  :search-input="{ placeholder: 'Ara...' }"
                  :search-attributes="['label']"
                  class="w-full"
                />
              </UFormField>
            </div>
            <UFormField label="Referans Kaynağı">
              <div class="flex gap-2">
                <USelectMenu
                  v-model="createForm.refSourceId"
                  :items="referenceSources"
                  value-key="value"
                  label-key="label"
                  placeholder="Kaynak seç..."
                  searchable
                  :search-input="{ placeholder: 'Ara...' }"
                  :search-attributes="['label']"
                  class="flex-1"
                />
                <UButton
                  icon="i-lucide-plus"
                  color="neutral"
                  variant="outline"
                  title="Yeni kaynak ekle"
                  @click="showAddRefSource = true"
                />
              </div>
              <!-- Inline yeni kaynak ekleme -->
              <div v-if="showAddRefSource" class="flex gap-2 mt-2">
                <UInput v-model="newRefSourceName" placeholder="Yeni kaynak adı..." class="flex-1" @keyup.enter="addRefSource" />
                <UButton icon="i-lucide-check" color="success" size="sm" :loading="savingRefSource" @click="addRefSource" />
                <UButton icon="i-lucide-x" color="neutral" variant="ghost" size="sm" @click="showAddRefSource = false; newRefSourceName = ''" />
              </div>
            </UFormField>
            <UFormField label="Açıklama">
              <UTextarea v-model="createForm.description" placeholder="Ek açıklama..." :rows="2" class="w-full" />
            </UFormField>

          </template>

          <!-- ===== OTHER: Title + Deadline ===== -->
          <template v-if="createForm.type === 'OTHER'">
            <UFormField label="Başlık" required>
              <UInput v-model="createForm.title" placeholder="Görev başlığı" class="w-full" />
            </UFormField>
            <UFormField label="Açıklama">
              <UTextarea v-model="createForm.description" placeholder="Opsiyonel" :rows="2" class="w-full" />
            </UFormField>
          </template>

          <USeparator />

          <!-- Son Tarih + Öncelik + Atanan Kişi -->
          <div class="grid grid-cols-3 gap-3">
            <UFormField label="Son Tarih">
              <UPopover v-model:open="createDeadlineDateOpen">
                <UButton
                  :label="createDeadlineDate ? calendarDateLabel(createDeadlineDate) : 'Tarih seç'"
                  icon="i-lucide-calendar"
                  color="neutral"
                  variant="outline"
                  class="w-full justify-start"
                  :class="{ 'text-muted': !createDeadlineDate }"
                />
                <template #content>
                  <UCalendar locale="tr-TR" v-model="createDeadlineDate" class="p-2" @update:model-value="createDeadlineDateOpen = false" />
                </template>
              </UPopover>
            </UFormField>
            <UFormField label="Öncelik">
              <USelect v-model="createForm.priority" :items="priorityOptions" class="w-full" />
            </UFormField>
            <UFormField label="Atanan Kişi">
              <USelect v-model="createForm.assignedTo" :items="userAssignOptions" placeholder="Opsiyonel" class="w-full" />
            </UFormField>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="İptal" color="neutral" variant="outline" size="xl" class="font-semibold" @click="showCreateModal = false" />
          <UButton
            label="Oluştur"
            icon="i-lucide-plus"
            color="primary"
            :loading="savingCreate"
            :disabled="!canSubmitCreate"
            @click="createTask"
          />
        </div>
      </template>
    </UModal>
    </template>
    <!-- UNUSED_BLOCK_END -->

    <!-- ==================== ASSIGN MODAL ==================== -->
    <UModal :dismissible="false" v-model:open="showAssignModal" title="Görev Ata" :ui="{ width: 'sm:max-w-md' }">
      <template #body>
        <div class="flex flex-col gap-4">
          <div class="flex items-start gap-3 p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
            <UIcon :name="typeIcon(selectedTask?.type || 'OTHER')" class="text-muted size-4 mt-0.5 shrink-0" />
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
          <UButton label="İptal" color="neutral" variant="outline" size="xl" class="font-semibold" @click="showAssignModal = false" />
          <UButton
            label="Ata"
            icon="i-lucide-user-check"
            color="primary"
            :loading="savingAssign"
            :disabled="!assignForm.assignedTo"
            @click="assignTask"
          />
        </div>
      </template>
    </UModal>

    <!-- ==================== COMPLETE MODAL ==================== -->
    <UModal :dismissible="false" v-model:open="showCompleteModal" title="Görevi Tamamla" :ui="{ width: 'sm:max-w-lg' }">
      <template #body>
        <div v-if="selectedTask" class="flex flex-col gap-4 max-h-[70vh] overflow-y-auto pr-1">
          <!-- Task info -->
          <div class="flex items-start gap-3 p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
            <UIcon :name="typeIcon(selectedTask.type)" class="text-muted size-4 mt-0.5 shrink-0" />
            <div class="min-w-0">
              <template v-if="selectedTask.type === 'FOLLOW_UP_CALL'">
                <p class="text-sm font-medium">{{ selectedTask.customerName }}</p>
                <p class="text-xs text-muted mt-0.5">{{ selectedTask.offerData?.branchGroup }} · {{ selectedTask.offerData?.stageLabel }} Takip Araması</p>
              </template>
              <template v-else>
                <p class="text-sm font-medium">{{ selectedTask.title }}</p>
                <p v-if="selectedTask.customerName" class="text-xs text-muted mt-0.5">{{ selectedTask.customerName }}</p>
              </template>
            </div>
          </div>

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
            <div class="grid gap-2" :class="completeResultOptions.length === 3 ? 'grid-cols-3' : 'grid-cols-2'">
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

          <!-- Reason (for negative results) -->
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
              <p class="text-xs text-muted mt-0.5">Seçilen tarihte bu müşteri için otomatik Teklif görevi oluşturulacak. Aynı müşteri için aktif teklif görevi varsa tarihi güncellenecek.</p>
            </div>
          </div>

          <!-- Seneye Hatırlat (olumsuz sonuçlarda, ileri vade seçilmemişse) -->
          <div v-if="isNegativeResult && !isIleriVade" class="flex items-start gap-2 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800">
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
          <div v-if="showVehicleQuestion && !showRegistrationQuestion" class="space-y-3">
            <div class="flex items-start gap-2 p-3 rounded-lg bg-violet-50 dark:bg-violet-950/40 border border-violet-200 dark:border-violet-800">
              <UIcon name="i-lucide-car" class="size-4 text-violet-500 mt-0.5 shrink-0" />
              <div class="text-sm w-full">
                <p class="font-medium text-violet-700 dark:text-violet-300">Müşterinin aracı var mı?</p>
                <div class="grid grid-cols-2 gap-2 mt-2">
                  <button
                    class="flex items-center justify-center gap-2 p-2.5 rounded-lg border-2 transition-all text-xs font-medium"
                    :class="completeForm.hasVehicle === 'YES' ? 'border-green-500 bg-green-50 dark:bg-green-950' : 'border-default hover:border-gray-400'"
                    @click="completeForm.hasVehicle = 'YES'; completeForm.registrationReceived = ''"
                  >
                    <UIcon name="i-lucide-check-circle" class="size-4 text-green-600" />
                    Evet
                  </button>
                  <button
                    class="flex items-center justify-center gap-2 p-2.5 rounded-lg border-2 transition-all text-xs font-medium"
                    :class="completeForm.hasVehicle === 'NO' ? 'border-red-500 bg-red-50 dark:bg-red-950' : 'border-default hover:border-gray-400'"
                    @click="completeForm.hasVehicle = 'NO'; completeForm.registrationReceived = ''"
                  >
                    <UIcon name="i-lucide-x-circle" class="size-4 text-red-600" />
                    Hayır
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Araç Takibi: Ruhsat bilgileri alındı mı? -->
          <div v-if="showRegistrationQuestion" class="space-y-3">
            <div class="flex items-start gap-2 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800">
              <UIcon name="i-lucide-file-check" class="size-4 text-amber-500 mt-0.5 shrink-0" />
              <div class="text-sm w-full">
                <p class="font-medium text-amber-700 dark:text-amber-300">Ruhsat bilgileri alındı mı?</p>
                <div class="grid grid-cols-2 gap-2 mt-2">
                  <button
                    class="flex items-center justify-center gap-2 p-2.5 rounded-lg border-2 transition-all text-xs font-medium"
                    :class="completeForm.registrationReceived === 'YES' ? 'border-green-500 bg-green-50 dark:bg-green-950' : 'border-default hover:border-gray-400'"
                    @click="completeForm.registrationReceived = 'YES'"
                  >
                    <UIcon name="i-lucide-check-circle" class="size-4 text-green-600" />
                    Evet, Alındı
                  </button>
                  <button
                    class="flex items-center justify-center gap-2 p-2.5 rounded-lg border-2 transition-all text-xs font-medium"
                    :class="completeForm.registrationReceived === 'NO' ? 'border-red-500 bg-red-50 dark:bg-red-950' : 'border-default hover:border-gray-400'"
                    @click="completeForm.registrationReceived = 'NO'"
                  >
                    <UIcon name="i-lucide-x-circle" class="size-4 text-red-600" />
                    Hayır, Alınamadı
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
          <UButton label="İptal" color="neutral" variant="outline" size="xl" class="font-semibold" @click="showCompleteModal = false" />
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

    <!-- ==================== DETAIL MODAL ==================== -->
    <UModal :dismissible="false" v-model:open="showDetailModal" title="Görev Detayı" :ui="{ width: 'sm:max-w-lg' }">
      <template #body>
        <div v-if="selectedTask" class="flex flex-col gap-4 max-h-[70vh] overflow-y-auto pr-1">
          <!-- Title & badges -->
          <div>
            <template v-if="selectedTask.type === 'FOLLOW_UP_CALL'">
              <h4 class="text-base font-semibold">{{ selectedTask.customerName }}</h4>
              <p class="text-sm text-muted mt-0.5">{{ selectedTask.offerData?.branchGroup }} · {{ selectedTask.offerData?.stageLabel }} Takip Araması</p>
            </template>
            <h4 v-else class="text-base font-semibold">{{ selectedTask.title }}</h4>
            <div class="flex flex-wrap gap-1.5 mt-2">
              <UBadge :color="typeColor(selectedTask.type)" variant="solid" size="sm">
                <UIcon :name="typeIcon(selectedTask.type)" class="size-3 mr-0.5" />
                {{ typeLabel(selectedTask.type) }}
              </UBadge>
              <UBadge :color="statusColor(selectedTask.status)" variant="solid" size="sm">
                {{ statusLabel(selectedTask.status) }}
              </UBadge>
              <UBadge :color="priorityColor(selectedTask.priority)" variant="solid" size="sm">
                {{ priorityLabel(selectedTask.priority) }}
              </UBadge>
              <UBadge v-if="selectedTask.result" :color="resultColor(selectedTask.result)" variant="solid" size="sm">
                {{ resultLabel(selectedTask.result) }}
              </UBadge>
            </div>
          </div>

          <!-- Offer Data (for OFFER tasks without policy yet) -->
          <div v-if="selectedTask.offerData && selectedTask.type === 'OFFER' && !selectedTask.policyId" class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 space-y-2">
            <div class="flex items-center gap-1.5">
              <UIcon name="i-lucide-file-text" class="text-muted size-3.5" />
              <p class="text-xs font-medium text-muted">Teklif Bilgileri</p>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
              <div v-if="selectedTask.offerData.insuranceName">
                <span class="text-muted text-xs">Tür: </span>
                <span>{{ selectedTask.offerData.insuranceName }}</span>
              </div>
              <div v-if="selectedTask.offerData.expiresAt">
                <span class="text-muted text-xs">Bitiş: </span>
                <span>{{ formatDate(selectedTask.offerData.expiresAt) }}</span>
              </div>
              <div v-if="selectedTask.offerData.plateNo">
                <span class="text-muted text-xs">Plaka: </span>
                <span>{{ selectedTask.offerData.plateNo }}</span>
              </div>
              <div v-if="selectedTask.offerData.vehicleBrand">
                <span class="text-muted text-xs">Araç: </span>
                <span>{{ selectedTask.offerData.vehicleBrand }} {{ selectedTask.offerData.vehicleModel }} {{ selectedTask.offerData.vehicleYear }}</span>
              </div>
              <div v-if="selectedTask.offerData.uavtCode">
                <span class="text-muted text-xs">UAVT: </span>
                <span>{{ selectedTask.offerData.uavtCode }}</span>
              </div>
              <div v-if="selectedTask.offerData.network">
                <span class="text-muted text-xs">Network: </span>
                <span>{{ selectedTask.offerData.network }}</span>
              </div>
            </div>
            <p v-if="selectedTask.offerData.note" class="text-xs text-muted mt-1">Not: {{ selectedTask.offerData.note }}</p>
          </div>

          <!-- Policy Details (for RENEWAL or converted OFFER) -->
          <div v-if="selectedTask.policyId" class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 space-y-2">
            <div class="flex items-center gap-1.5">
              <UIcon name="i-lucide-shield-check" class="text-muted size-3.5" />
              <p class="text-xs font-medium text-muted">Poliçe Bilgileri</p>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
              <div>
                <span class="text-muted text-xs">No: </span>
                <span class="font-medium">{{ selectedTask.policyNo || '-' }}</span>
              </div>
              <div v-if="selectedTask.insuranceName">
                <span class="text-muted text-xs">Tür: </span>
                <span>{{ selectedTask.insuranceName }}</span>
              </div>
              <div v-if="selectedTask.companyName">
                <span class="text-muted text-xs">Şirket: </span>
                <span>{{ selectedTask.companyName }}</span>
              </div>
              <div v-if="selectedTask.policyDetails?.grossPremium">
                <span class="text-muted text-xs">Brüt: </span>
                <span class="font-medium">{{ selectedTask.policyDetails.grossPremium.toLocaleString('tr-TR') }} TL</span>
              </div>
              <div v-if="selectedTask.policyDetails?.expiresAt">
                <span class="text-muted text-xs">Bitiş: </span>
                <span>{{ formatDate(selectedTask.policyDetails.expiresAt) }}</span>
              </div>
              <div v-if="selectedTask.policyDetails?.plateNo">
                <span class="text-muted text-xs">Plaka: </span>
                <span>{{ selectedTask.policyDetails.plateNo }}</span>
              </div>
            </div>
          </div>

          <USeparator />

          <!-- Info Grid -->
          <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div v-if="selectedTask.customerName">
              <p class="text-xs text-muted">Müşteri</p>
              <p class="font-medium mt-0.5">{{ selectedTask.customerName }}</p>
            </div>
            <div>
              <p class="text-xs text-muted">Atanan Kişi</p>
              <p class="mt-0.5">{{ selectedTask.assignedToName || 'Atanmadi' }}</p>
            </div>
            <div v-if="selectedTask.type === 'OFFER'">
              <p class="text-xs text-muted">Bitiş Tarihi</p>
              <p class="mt-0.5">{{ selectedTask.offerData?.expiresAt ? formatDate(selectedTask.offerData.expiresAt) : '-' }}</p>
            </div>
            <div v-else>
              <p class="text-xs text-muted">Son Tarih</p>
              <p class="mt-0.5" :class="{ 'text-red-600 font-medium': selectedTask.status !== 'COMPLETED' && selectedTask.status !== 'CANCELLED' && typeof selectedTask.daysRemaining === 'number' && selectedTask.daysRemaining < 0 }">
                {{ formatDate(selectedTask.deadline) }}
                <span v-if="selectedTask.status !== 'COMPLETED' && selectedTask.status !== 'CANCELLED'" class="text-xs text-muted ml-1">
                  ({{ formatDaysFromBackend(selectedTask.daysRemaining, selectedTask.deadline) }})
                </span>
              </p>
            </div>
            <div>
              <p class="text-xs text-muted">Oluşturulma</p>
              <p class="mt-0.5">{{ formatDateTime(selectedTask.createdAt) }}</p>
            </div>
            <div v-if="selectedTask.completedByName">
              <p class="text-xs text-muted">Tamamlayan</p>
              <p class="mt-0.5">{{ selectedTask.completedByName }}</p>
            </div>
            <div v-if="selectedTask.completedAt">
              <p class="text-xs text-muted">Tamamlanma</p>
              <p class="mt-0.5">{{ formatDateTime(selectedTask.completedAt) }}</p>
            </div>
          </div>

          <!-- Description -->
          <template v-if="selectedTask.description">
            <div v-if="selectedTask.type === 'FOLLOW_UP_CALL'" class="flex items-start gap-2 p-3 rounded-lg bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800">
              <UIcon name="i-lucide-phone-call" class="size-4 text-blue-500 mt-0.5 shrink-0" />
              <div class="text-sm min-w-0">
                <p class="font-medium text-blue-700 dark:text-blue-300">Ne hakkında aranacak?</p>
                <p class="text-xs text-muted mt-1 whitespace-pre-line">{{ selectedTask.description }}</p>
              </div>
            </div>
            <div v-else>
              <p class="text-xs text-muted mb-1">Açıklama</p>
              <p class="text-sm whitespace-pre-wrap bg-gray-50 dark:bg-gray-800 rounded-lg p-3">{{ selectedTask.description }}</p>
            </div>
          </template>

          <!-- Result Details -->
          <div v-if="selectedTask.result" class="flex items-start gap-3 p-3 rounded-lg" :class="['RENEWED', 'OFFER_APPROVED', 'DONE'].includes(selectedTask.result) ? 'bg-green-50 dark:bg-green-950' : 'bg-red-50 dark:bg-red-950'">
            <UIcon
              :name="['RENEWED', 'OFFER_APPROVED', 'DONE'].includes(selectedTask.result) ? 'i-lucide-check-circle' : 'i-lucide-x-circle'"
              :class="['RENEWED', 'OFFER_APPROVED', 'DONE'].includes(selectedTask.result) ? 'text-green-600' : 'text-red-600'"
              class="size-5 mt-0.5 shrink-0"
            />
            <div>
              <p class="text-sm font-medium">{{ resultLabel(selectedTask.result) }}</p>
              <p v-if="selectedTask.resultReason" class="text-xs text-muted mt-0.5">Sebep: {{ selectedTask.resultReason }}</p>
              <p v-if="selectedTask.resultNote" class="text-xs text-muted mt-0.5">Not: {{ selectedTask.resultNote }}</p>
            </div>
          </div>

          <!-- Task Logs -->
          <div v-if="selectedTask.logs && selectedTask.logs.length > 0">
            <USeparator class="mb-4" />
            <p class="text-xs font-medium text-muted mb-3">İşlem Geçmişi</p>
            <div class="space-y-0">
              <div
                v-for="(log, idx) in selectedTask.logs"
                :key="log.id"
                class="flex gap-3 relative"
              >
                <div class="flex flex-col items-center">
                  <div class="size-2 rounded-full mt-1.5" :class="idx === selectedTask.logs!.length - 1 ? 'bg-primary' : 'bg-gray-300 dark:bg-gray-600'" />
                  <div v-if="idx < selectedTask.logs!.length - 1" class="w-px flex-1 bg-gray-200 dark:bg-gray-700" />
                </div>
                <div class="pb-4">
                  <p class="text-sm">
                    <span v-if="log.fromUserName" class="font-medium">{{ log.fromUserName }}</span>
                    <span class="text-muted ml-1">{{ actionLabel(log.action) }}</span>
                    <span v-if="log.toUserName" class="text-muted"> &rarr; {{ log.toUserName }}</span>
                  </p>
                  <p v-if="log.note" class="text-xs text-muted mt-0.5">{{ translateNote(log.note) }}</p>
                  <p class="text-[11px] text-muted/60 mt-0.5">{{ formatDateTime(log.createdAt) }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex items-center justify-between w-full">
          <div>
            <UButton
              v-if="isAdmin && selectedTask"
              icon="i-lucide-trash-2"
              label="Sil"
              color="error"
              variant="ghost"
              size="sm"
              @click="showDeleteConfirm = true"
            />
          </div>
          <div class="flex gap-2">
            <template v-if="selectedTask && selectedTask.status !== 'COMPLETED' && selectedTask.status !== 'CANCELLED'">
              <UButton
                v-if="isAdmin"
                icon="i-lucide-user-plus"
                label="Ata"
                color="info"
                variant="outline"
                size="sm"
                @click="openAssignModal(selectedTask!); showDetailModal = false"
              />
              <UButton
                icon="i-lucide-check"
                label="Tamamla"
                color="success"
                size="sm"
                @click="showDetailModal = false; openCompleteModal(selectedTask!)"
              />
            </template>
            <UButton label="Kapat" color="neutral" variant="outline" @click="showDetailModal = false" />
          </div>
        </div>
      </template>
    </UModal>

    <!-- Delete Confirm -->
    <UModal :dismissible="false" v-model:open="showDeleteConfirm" title="Görevi Sil" :ui="{ width: 'sm:max-w-sm' }">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
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

    <!-- ==================== BULK ASSIGN MODAL ==================== -->
    <UModal :dismissible="false" v-model:open="showBulkAssignModal" title="Toplu Görev Atama" :ui="{ width: 'sm:max-w-sm' }">
      <template #body>
        <div class="flex flex-col gap-4">
          <p class="text-sm text-muted">{{ selectedTaskIds.size }} görev seçilen kişiye atanacak.</p>
          <UFormField label="Atanan Kişi" required>
            <USelect v-model="bulkAssignTo" :items="userAssignOptions" placeholder="Kişi seçin..." class="w-full" />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="İptal" color="neutral" variant="outline" @click="showBulkAssignModal = false" />
          <UButton label="Ata" icon="i-lucide-user-check" color="primary" :loading="savingBulkAssign" :disabled="!bulkAssignTo" @click="bulkAssign" />
        </div>
      </template>
    </UModal>

    <!-- ==================== CANCEL CONFIRM MODAL ==================== -->
    <UModal :dismissible="false" v-model:open="showCancelConfirm" title="Görevi İptal Et" :ui="{ width: 'sm:max-w-sm' }">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-x-circle" class="text-orange-600 size-5" />
          </div>
          <div>
            <p class="text-sm font-medium">Bu görevi iptal etmek istediğinize emin misiniz?</p>
            <p class="text-xs text-muted mt-1">{{ cancelTargetTask?.title }}</p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" @click="showCancelConfirm = false" />
          <UButton label="İptal Et" icon="i-lucide-x-circle" color="error" @click="cancelTask" />
        </div>
      </template>
    </UModal>

    <!-- ==================== SMART CLOSE MODAL ==================== -->
    <UModal :dismissible="false" v-model:open="showSmartCloseModal" title="Akıllı Toplu Kapatma" :ui="{ width: 'sm:max-w-3xl' }">
      <template #body>
        <div class="space-y-4">
          <!-- Açıklama -->
          <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
            <p class="text-sm text-blue-800 dark:text-blue-300">
              Süresi geçmiş görevler analiz edilerek poliçe durumuna göre otomatik kapatılır.
              <strong>Yenileme</strong> görevleri: poliçe yenilenmişse tamamlanır, yenilenmemişse iptal edilir.
              <strong>Teklif</strong> görevleri: müşteriye o branşta poliçe yapılmışsa tamamlanır, yapılmamışsa iptal edilir.
            </p>
          </div>

          <!-- Loading -->
          <div v-if="smartCloseLoading" class="flex items-center justify-center py-8">
            <div class="animate-spin rounded-full h-6 w-6 border-2 border-primary border-t-transparent" />
            <span class="ml-3 text-sm text-muted">Görevler analiz ediliyor...</span>
          </div>

          <!-- Önizleme -->
          <template v-else-if="smartClosePreview">
            <!-- Özet Kartlar -->
            <div class="grid grid-cols-3 gap-3">
              <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-3 text-center">
                <p class="text-2xl font-bold">{{ smartClosePreview.totalExpired }}</p>
                <p class="text-xs text-muted">Toplam Geciken</p>
              </div>
              <div class="rounded-lg bg-green-50 dark:bg-green-900/20 p-3 text-center">
                <p class="text-2xl font-bold text-green-600">{{ smartClosePreview.toComplete }}</p>
                <p class="text-xs text-muted">Tamamlanacak</p>
              </div>
              <div class="rounded-lg bg-red-50 dark:bg-red-900/20 p-3 text-center">
                <p class="text-2xl font-bold text-red-600">{{ smartClosePreview.toCancel }}</p>
                <p class="text-xs text-muted">İptal Edilecek</p>
              </div>
            </div>

            <!-- Tamamlanacaklar -->
            <div v-if="smartClosePreview.details.toComplete.length > 0">
              <div class="flex items-center gap-2 mb-2">
                <span class="size-2.5 rounded-full bg-green-500" />
                <span class="text-sm font-semibold text-green-700 dark:text-green-400">Tamamlanacak Görevler ({{ smartClosePreview.details.toComplete.length }})</span>
              </div>
              <div class="max-h-40 overflow-y-auto border border-default rounded-lg">
                <table class="w-full text-xs">
                  <thead class="sticky top-0 bg-white dark:bg-gray-900">
                    <tr class="border-b border-default">
                      <th class="text-left py-1.5 px-3 font-semibold text-muted">Tür</th>
                      <th class="text-left py-1.5 px-3 font-semibold text-muted">Müşteri</th>
                      <th class="text-left py-1.5 px-3 font-semibold text-muted">Poliçe No</th>
                      <th class="text-left py-1.5 px-3 font-semibold text-muted">Sebep</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in smartClosePreview.details.toComplete" :key="item.id" class="border-b border-default">
                      <td class="py-1 px-3">
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold" :class="item.type === 'RENEWAL' ? 'bg-blue-100 text-blue-700' : 'bg-violet-100 text-violet-700'">
                          {{ item.type === 'RENEWAL' ? 'YENİLEME' : 'TEKLİF' }}
                        </span>
                      </td>
                      <td class="py-1 px-3">{{ item.customerName || '-' }}</td>
                      <td class="py-1 px-3 tabular-nums">{{ item.policyNo || '-' }}</td>
                      <td class="py-1 px-3 text-green-600">{{ item.reason }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- İptal Edilecekler -->
            <div v-if="smartClosePreview.details.toCancel.length > 0">
              <div class="flex items-center gap-2 mb-2">
                <span class="size-2.5 rounded-full bg-red-500" />
                <span class="text-sm font-semibold text-red-700 dark:text-red-400">İptal Edilecek Görevler ({{ smartClosePreview.details.toCancel.length }})</span>
              </div>
              <div class="max-h-40 overflow-y-auto border border-default rounded-lg">
                <table class="w-full text-xs">
                  <thead class="sticky top-0 bg-white dark:bg-gray-900">
                    <tr class="border-b border-default">
                      <th class="text-left py-1.5 px-3 font-semibold text-muted">Tür</th>
                      <th class="text-left py-1.5 px-3 font-semibold text-muted">Müşteri</th>
                      <th class="text-left py-1.5 px-3 font-semibold text-muted">Poliçe No</th>
                      <th class="text-left py-1.5 px-3 font-semibold text-muted">Sebep</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in smartClosePreview.details.toCancel" :key="item.id" class="border-b border-default">
                      <td class="py-1 px-3">
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold" :class="item.type === 'RENEWAL' ? 'bg-blue-100 text-blue-700' : 'bg-violet-100 text-violet-700'">
                          {{ item.type === 'RENEWAL' ? 'YENİLEME' : item.type === 'OFFER' ? 'TEKLİF' : item.type }}
                        </span>
                      </td>
                      <td class="py-1 px-3">{{ item.customerName || '-' }}</td>
                      <td class="py-1 px-3 tabular-nums">{{ item.policyNo || '-' }}</td>
                      <td class="py-1 px-3 text-red-600">{{ item.reason }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Boşsa -->
            <div v-if="smartClosePreview.totalExpired === 0" class="text-center py-6">
              <UIcon name="i-lucide-check-circle" class="size-8 text-green-500 mx-auto mb-2" />
              <p class="text-sm text-muted">Geciken görev bulunmuyor.</p>
            </div>
          </template>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" @click="showSmartCloseModal = false" />
          <UButton
            v-if="smartClosePreview && smartClosePreview.totalExpired > 0"
            :label="`${smartClosePreview.totalExpired} Görevi Kapat`"
            icon="i-lucide-zap"
            color="primary"
            :loading="smartCloseApplying"
            @click="applySmartClose"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>

<style scoped>
.select-fl :deep(button) {
  min-height: 50px !important;
  height: auto !important;
}
table td { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Standart badge stili — tüm tablo badge'leri aynı boyutta */
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
