<script setup lang="ts">
import { CalendarDate } from '@internationalized/date'
import { z } from 'zod'

const props = defineProps<{
  customerId?: number
  policy?: any
}>()

const emit = defineEmits<{
  saved: []
}>()

const isOpen = defineModel<boolean>('open', { default: false })

const isEditMode = computed(() => !!props.policy)
const isReconciled = computed(() => props.policy?.reconciliationStatus === 'RECONCILED')

const toast = useToast()
const { get, post, put, del } = useApi()
const { token } = useAuth()
const { can: hasPermission } = usePermissions()
const { insurances, subcategories, fetchInsurances } = useInsuranceTypes()
const { isFieldEnabled, pdfParsingEnabled, fetchFieldSettings } = useFieldSettings()
const {
  customerLoading,
  hasMore: customerHasMore,
  initList,
  loadMore,
  resetLimit,
  addCustomer: addCustomerToList,
  getOptions,
} = useCustomerSearch()

// ── Müşteri arama state ───────────────────────────────────────────────────────
const customerSearchTerm = ref('')

const customerOptions = computed(() =>
  getOptions(customerSearchTerm.value, form.value.customerId)
)

// Arama değişince limit sıfırla
watch(customerSearchTerm, resetLimit)


// Data sources
const companies = ref<{ id: number, name: string }[]>([])
const branches = ref<{ id: number, name: string, commissionRate: number }[]>([])
const referenceSources = ref<{ id: number, name: string, commissionRate: number, businessType: string | null }[]>([])
const usersList = ref<{ id: number, name: string }[]>([])

async function fetchCompanies() {
  if (companies.value.length) return
  try { const res = await get<any>('companies?all=1'); companies.value = res.data || [] } catch {}
}
async function fetchBranches() {
  try { const res = await get<any>('branches?all=1'); branches.value = (res.data || []).map((b: any) => ({ id: b.id, name: b.name, commissionRate: b.commissionRate || 0 })) } catch {}
}
async function fetchReferenceSources() {
  try { const res = await get<any>('reference-sources?all=1'); referenceSources.value = (res.data || []).filter((r: any) => r.isActive).map((r: any) => ({ id: r.id, name: r.name, commissionRate: r.commissionRate, businessType: r.businessType })) } catch {}
}
async function fetchUsersList() {
  if (usersList.value.length) return
  try { const res = await get<any>('users?dropdown=1'); usersList.value = res.data || [] } catch {}
}

const companyOptions = computed(() => companies.value.sort((a, b) => a.name.localeCompare(b.name, 'tr')).map(c => ({ label: c.name, value: c.id })))
const insuranceOptions = computed(() => {
  const opts = subcategories.value.slice().sort((a, b) => a.name.localeCompare(b.name, 'tr')).map(i => ({ label: i.name, value: i.id }))
  // Edit modunda mevcut tür pasif ise yine de listede göster
  const currentId = form.value.insuranceId
  if (currentId && !opts.find(o => o.value === currentId)) {
    const found = insurances.value.find(i => i.id === currentId)
    if (found) opts.unshift({ label: found.name, value: found.id })
  }
  return opts
})
const branchOptions = computed(() => branches.value.map(b => ({ label: b.name, value: b.id })))
const userOptions = computed(() => usersList.value.map(u => ({ label: u.name, value: u.id })))
const businessTypeOptions = [
  { label: 'YENİ İŞ', value: 'NEW' },
  { label: 'YENİLEME', value: 'RENEWAL' },
]

const referenceSourceOptions = computed(() => {
  if (!form.value.businessType) return referenceSources.value.map(r => ({ label: r.name, value: r.id }))
  if (form.value.businessType === 'RENEWAL') {
    // Yenileme: sadece RENEWAL veya Tümü (null) olanlar
    return referenceSources.value
      .filter(r => r.businessType === 'RENEWAL' || !r.businessType)
      .map(r => ({ label: r.name, value: r.id }))
  }
  // Yeni İş: sadece NEW veya Tümü (null) olanlar
  return referenceSources.value
    .filter(r => r.businessType === 'NEW' || !r.businessType)
    .map(r => ({ label: r.name, value: r.id }))
})

const prodOptions = [
  { label: 'POLİÇEM', value: 'SELF' },
  { label: 'TALİ GELEN', value: 'INCOMING' },
  { label: 'TALİ GİDEN', value: 'OUTGOING' }
]

// Form
const defaultForm = {
  productionType: '' as 'SELF' | 'INCOMING' | 'OUTGOING' | '',
  customerId: undefined as number | undefined,
  insuranceId: undefined as number | undefined,
  companyId: undefined as number | undefined,
  branchId: undefined as number | undefined,
  policyNo: '',
  insuredName: '',
  insuredNo: '',
  issuedAt: '',
  startsAt: '',
  expiresAt: '',
  grossPremium: 0,
  netPremium: 0,
  companyCommRate: 0,
  companyCommAmount: 0,
  branchCommRate: 0,
  branchCommAmount: 0,
  endorsementNo: 1,
  isZeyil: false,
  isCancelled: false,
  noRenewalReminder: false,
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
  additionalInsureds: '',
  businessType: '' as 'NEW' | 'RENEWAL' | '',
  referenceSource: undefined as number | undefined,
  soldBy: undefined as number | undefined
}

const form = ref({ ...defaultForm })
const policyFormRef = ref()

// Poliçe PDF dosyası
const policyFile = ref<File | null>(null)
const policyFileInput = ref<HTMLInputElement>()
const fileDragging = ref(false)

function onFileDrop(e: DragEvent) {
  fileDragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (!file) return
  if (!form.value.customerId) {
    toast.add({ title: 'Önce müşteri seçiniz', color: 'warning' })
    return
  }
  const allowed = ['application/pdf', 'image/jpeg', 'image/png']
  if (!allowed.includes(file.type)) {
    toast.add({ title: 'Sadece PDF, JPG ve PNG dosyaları yüklenebilir', color: 'error' })
    return
  }
  if (file.size > 20 * 1024 * 1024) {
    toast.add({ title: 'Dosya 20MB sınırını aşıyor', color: 'error' })
    return
  }
  policyFile.value = file
  if (pdfParsingEnabled.value) parsePdfWithAI(file)
}
const pdfParsing = ref(false)
const pdfProgress = ref(0)
const pdfProgressLabel = ref('')
const pdfWarnings = ref<string[]>([])
const pdfUnfilledFields = ref<Set<string>>(new Set())
function pdfHighlight(field: string): string {
  return pdfUnfilledFields.value.has(field) ? 'pdf-unfilled' : ''
}
let progressTimer: ReturnType<typeof setTimeout> | null = null
let stopProgressTimer: ReturnType<typeof setTimeout> | null = null

// ~7sn ortalama süreye göre gerçekçi ilerleme
const PDF_EXPECTED_DURATION = 7000 // ms
let progressStartTime = 0

function startProgress() {
  pdfProgress.value = 0
  pdfProgressLabel.value = 'Dosya yükleniyor...'
  pdfWarnings.value = []
  pdfParsing.value = true
  progressStartTime = Date.now()
  tickProgress()
}

function tickProgress() {
  const elapsed = Date.now() - progressStartTime
  // Logaritmik ilerleme: hızlı başla, yavaşla, %92'de dur
  const ratio = Math.min(elapsed / PDF_EXPECTED_DURATION, 1)
  const progress = Math.min(Math.round(ratio * 92), 92)
  pdfProgress.value = progress

  if (progress < 20) pdfProgressLabel.value = 'Dosya yükleniyor...'
  else if (progress < 50) pdfProgressLabel.value = 'PDF analiz ediliyor...'
  else if (progress < 80) pdfProgressLabel.value = 'Poliçe bilgileri çıkarılıyor...'
  else pdfProgressLabel.value = 'Alanlar eşleştiriliyor...'

  if (progress < 92) progressTimer = setTimeout(tickProgress, 100)
}

function stopProgress(success: boolean) {
  if (progressTimer) { clearTimeout(progressTimer); progressTimer = null }
  pdfProgress.value = 100
  pdfProgressLabel.value = success ? 'Tamamlandı!' : 'Hata oluştu'
  stopProgressTimer = setTimeout(() => { pdfParsing.value = false; pdfProgress.value = 0; stopProgressTimer = null }, 800)
}

function cleanupTimers() {
  if (progressTimer) { clearTimeout(progressTimer); progressTimer = null }
  if (stopProgressTimer) { clearTimeout(stopProgressTimer); stopProgressTimer = null }
}

const formDisabled = computed(() => !form.value.customerId)

onUnmounted(cleanupTimers)

function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (!form.value.customerId) {
    toast.add({ title: 'Önce müşteri seçiniz', color: 'warning' })
    input.value = ''
    return
  }
  if (pdfParsing.value) {
    toast.add({ title: 'PDF okuma devam ediyor, lütfen bekleyin', color: 'warning' })
    input.value = ''
    return
  }
  const allowed = ['application/pdf', 'image/jpeg', 'image/png']
  if (!allowed.includes(file.type)) {
    toast.add({ title: 'Sadece PDF, JPG ve PNG dosyaları yüklenebilir', color: 'error' })
    input.value = ''
    return
  }
  if (file.size > 20 * 1024 * 1024) {
    toast.add({ title: 'Dosya 20MB sınırını aşıyor', color: 'error' })
    input.value = ''
    return
  }
  policyFile.value = file
  if (pdfParsingEnabled.value) parsePdfWithAI(file)
}

async function parsePdfWithAI(file: File) {
  startProgress()
  try {
    const fd = new FormData()
    fd.append('file', file)
    const res = await fetch('/api/policies/parse-pdf', {
      method: 'POST',
      headers: { Authorization: `Bearer ${token.value}` },
      body: fd,
    })

    let json: any
    try {
      json = await res.json()
    } catch {
      stopProgress(false)
      toast.add({ title: 'PDF otomatik olarak okunamadı. Bilgileri manuel olarak girebilirsiniz.', color: 'warning' })
      return
    }

    if (!res.ok || !json.success) {
      stopProgress(false)
      toast.add({ title: json.message || 'PDF otomatik olarak okunamadı. Bilgileri manuel olarak girebilirsiniz.', color: 'warning' })
      return
    }

    const d = json.data
    let filled = 0

    // PDF'den gelen tarihler watch tarafindan override edilmesin
    isInitializing = true

    if (d.productionType && (!form.value.productionType || form.value.productionType === 'SELF')) {
      form.value.productionType = d.productionType
      filled++
      if (d.branchId) {
        await nextTick()
        form.value.branchId = d.branchId
        filled++
      }
    } else if (d.branchId && !form.value.branchId) {
      form.value.branchId = d.branchId; filled++
    }
    if (d.insuranceId && !form.value.insuranceId) { form.value.insuranceId = d.insuranceId; filled++ }
    if (d.companyId && !form.value.companyId) { form.value.companyId = d.companyId; filled++ }
    if (d.policyNo && !form.value.policyNo) { form.value.policyNo = d.policyNo; filled++; nextTick(() => checkPolicyNoDuplicate()) }

    if (d.issuedAt && !form.value.issuedAt) {
      form.value.issuedAt = d.issuedAt
      issuedAtDisplay.value = isoToDisplay(d.issuedAt)
      issuedAtCalendar.value = isoToCalendar(d.issuedAt)
      filled++
    }
    if (d.startsAt && !form.value.startsAt) {
      form.value.startsAt = d.startsAt
      startsAtDisplay.value = isoToDisplay(d.startsAt)
      startsAtCalendar.value = isoToCalendar(d.startsAt)
      filled++
    }
    if (d.expiresAt) {
      form.value.expiresAt = d.expiresAt
      expiresAtDisplay.value = isoToDisplay(d.expiresAt)
      expiresAtCalendar.value = isoToCalendar(d.expiresAt)
      filled++
    }

    if (d.grossPremium && !form.value.grossPremium) {
      form.value.grossPremium = d.grossPremium
      grossDisplay.value = formatTrCurrency(d.grossPremium)
      filled++
    }
    if (d.netPremium && !form.value.netPremium) {
      form.value.netPremium = d.netPremium
      netDisplay.value = formatTrCurrency(d.netPremium)
      filled++
    }

    if (d.plateNo && !form.value.plateNo) { form.value.plateNo = d.plateNo; filled++ }

    // Plaka + müşteri varsa önceki poliçeden ruhsat seri no çek
    if (form.value.plateNo && form.value.customerId && !form.value.registrationNo) {
      try {
        const regRes = await get<any>(`policies/lookup-registration?customerId=${form.value.customerId}&plateNo=${encodeURIComponent(form.value.plateNo)}`)
        if (regRes?.data?.registrationNo) { form.value.registrationNo = regRes.data.registrationNo; filled++ }
      } catch {}
    }

    // PDF sonrası dolmayan tüm alanları işaretle
    const unfilled = new Set<string>()
    const checkFields: [string, any][] = [
      ['customerId', form.value.customerId],
      ['productionType', form.value.productionType],
      ['insuranceId', form.value.insuranceId],
      ['companyId', form.value.companyId],
      ['policyNo', form.value.policyNo],
      ['businessType', form.value.businessType],
      ['referenceSource', form.value.referenceSource],
      ['soldBy', form.value.soldBy],
      ['issuedAt', form.value.issuedAt],
      ['startsAt', form.value.startsAt],
      ['expiresAt', form.value.expiresAt],
      ['plateNo', form.value.plateNo],
      ['registrationNo', form.value.registrationNo],
      ['grossPremium', form.value.grossPremium],
      ['netPremium', form.value.netPremium],
      ['companyCommRate', form.value.companyCommRate],
    ]
    for (const [key, val] of checkFields) {
      if (!val) unfilled.add(key)
    }
    pdfUnfilledFields.value = unfilled

    // Uyarıları kaydet
    if (d.warnings?.length) pdfWarnings.value = d.warnings

    // Validasyon hatalarını temizle
    if (filled > 0 && policyFormRef.value) {
      policyFormRef.value.clear()
    }

    nextTick(() => { isInitializing = false })

    stopProgress(true)
    if (filled > 0) {
      const warnCount = d.warnings?.length || 0
      toast.add({
        title: `PDF'den ${filled} alan dolduruldu (${d.elapsed || '?'}sn)`,
        description: warnCount > 0 ? 'Lütfen doldurulan alanları kontrol edin' : undefined,
        color: warnCount > 0 ? 'warning' : 'success'
      })
    } else {
      toast.add({ title: 'PDF okundu ancak doldurulacak yeni alan bulunamadı', color: 'info' })
    }
  } catch {
    stopProgress(false)
    toast.add({ title: 'PDF otomatik olarak okunamadı. Bilgileri manuel olarak girebilirsiniz.', color: 'warning' })
  }
}

function removeFile() {
  policyFile.value = null
  pdfWarnings.value = []
  if (policyFileInput.value) policyFileInput.value.value = ''
}

function buildDocumentName(): string {
  const parts: string[] = []
  if (form.value.plateNo) parts.push(form.value.plateNo.toUpperCase().trim())
  const ins = insurances.value.find(i => i.id === Number(form.value.insuranceId))
  if (ins) parts.push(ins.name)
  if (form.value.policyNo) parts.push(form.value.policyNo.trim())
  const ext = policyFile.value?.name.split('.').pop()?.toLowerCase() || 'pdf'
  return (parts.length > 0 ? parts.join(' ') : 'Police') + '.' + ext
}

const showBranchField = computed(() => form.value.productionType === 'INCOMING' || form.value.productionType === 'OUTGOING')
const selectedInsuranceKey = computed(() => {
  if (!form.value.insuranceId) return null
  const ins = insurances.value.find(i => i.id === Number(form.value.insuranceId))
  return ins?.code || null
})
const trafficFieldCount = computed(() => {
  let count = 2
  if (isFieldEnabled('chassis_no')) count++
  if (isFieldEnabled('engine_no')) count++
  if (isFieldEnabled('policy_brand')) count++
  if (isFieldEnabled('policy_model')) count++
  if (isFieldEnabled('vehicle_year')) count++
  return count
})

// Currency helpers
function formatTrCurrency(value: number): string {
  if (!value && value !== 0) return ''
  return value.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function parseTrCurrency(str: string): number {
  if (!str) return 0
  return parseFloat(str.replace(/\./g, '').replace(',', '.')) || 0
}

const grossDisplay = ref('')
const netDisplay = ref('')
function onGrossInput(val: string) {
  const isNeg = val.startsWith('-') && form.value.isZeyil
  const s = (isNeg ? '-' : '') + val.replace(/[^\d,]/g, '')
  form.value.grossPremium = parseTrCurrency(s)
  if (s !== grossDisplay.value) { grossDisplay.value = s } else { grossDisplay.value = s + '\u200B'; nextTick(() => { grossDisplay.value = s }) }
}
function onNetInput(val: string) {
  // Brüt negatifse net de otomatik negatif olsun
  const forceNeg = form.value.isZeyil && form.value.grossPremium < 0
  const isNeg = forceNeg || (val.startsWith('-') && form.value.isZeyil)
  const s = (isNeg ? '-' : '') + val.replace(/[^\d,]/g, '')
  form.value.netPremium = parseTrCurrency(s)
  if (s !== netDisplay.value) { netDisplay.value = s } else { netDisplay.value = s + '\u200B'; nextTick(() => { netDisplay.value = s }) }
}
function onGrossPaste(e: ClipboardEvent) {
  e.preventDefault()
  const raw = e.clipboardData?.getData('text') || ''
  const isNeg = raw.startsWith('-') && form.value.isZeyil
  const s = (isNeg ? '-' : '') + raw.replace(/[^\d,]/g, '')
  grossDisplay.value = s; form.value.grossPremium = parseTrCurrency(s)
}
function onNetPaste(e: ClipboardEvent) {
  e.preventDefault()
  const raw = e.clipboardData?.getData('text') || ''
  const forceNeg = form.value.isZeyil && form.value.grossPremium < 0
  const isNeg = forceNeg || (raw.startsWith('-') && form.value.isZeyil)
  const s = (isNeg ? '-' : '') + raw.replace(/[^\d,]/g, '')
  netDisplay.value = s; form.value.netPremium = parseTrCurrency(s)
}
function onNumberPaste(e: ClipboardEvent) {
  e.preventDefault()
  const s = (e.clipboardData?.getData('text') || '').replace(/[^\d.]/g, '')
  const input = e.target as HTMLInputElement
  if (input) { input.value = s; input.dispatchEvent(new Event('input')) }
}
function onGrossBlur() { grossDisplay.value = form.value.grossPremium ? formatTrCurrency(form.value.grossPremium) : '' }
function onNetBlur() {
  netDisplay.value = form.value.netPremium ? formatTrCurrency(form.value.netPremium) : ''
  if (!commissionAsAmount.value) syncCommFromRate()
  else syncCommFromAmount()
  if (!form.value.isZeyil && form.value.netPremium > form.value.grossPremium) {
    policyFormRef.value?.setErrors([{ path: 'netPremium', message: 'Net prim brüt primden büyük olamaz' }])
  } else {
    policyFormRef.value?.clear('netPremium')
  }
}

// Date helpers
const issuedAtDisplay = ref('')
const startsAtDisplay = ref('')
const expiresAtDisplay = ref('')
const issuedAtCalendar = ref<InstanceType<typeof CalendarDate> | undefined>()
const startsAtCalendar = ref<InstanceType<typeof CalendarDate> | undefined>()
const expiresAtCalendar = ref<InstanceType<typeof CalendarDate> | undefined>()
const issuedAtPopoverOpen = ref(false)
const startsAtPopoverOpen = ref(false)
const expiresAtPopoverOpen = ref(false)
let dateSyncing = false
let isInitializing = false

function isoToDisplay(iso: string): string {
  if (!iso) return ''
  const [y, m, d] = iso.split('-')
  return `${d}.${m}.${y}`
}
function isoToCalendar(iso: string): InstanceType<typeof CalendarDate> | undefined {
  if (!iso) return undefined
  const [y, m, d] = iso.split('-')
  return new CalendarDate(parseInt(y), parseInt(m), parseInt(d))
}
function autoFormatDateInput(raw: string): string {
  const digits = raw.replace(/\D/g, '').slice(0, 8)
  if (digits.length <= 2) return digits
  if (digits.length <= 4) return `${digits.slice(0, 2)}.${digits.slice(2)}`
  return `${digits.slice(0, 2)}.${digits.slice(2, 4)}.${digits.slice(4)}`
}
function parseDisplayToIso(display: string): string | null {
  const digits = display.replace(/\D/g, '')
  if (digits.length !== 8) return null
  const day = parseInt(digits.slice(0, 2))
  const month = parseInt(digits.slice(2, 4))
  const year = parseInt(digits.slice(4, 8))
  if (day < 1 || day > 31 || month < 1 || month > 12 || year < 1900) return null
  const date = new Date(year, month - 1, day)
  if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}
function isValidIsoDate(val: string): boolean {
  if (!val || val.length !== 10) return false
  const parts = val.split('-').map(Number)
  if (parts.length !== 3) return false
  const [y, m, d] = parts
  const date = new Date(y, m - 1, d)
  return date.getFullYear() === y && date.getMonth() === m - 1 && date.getDate() === d
}

type DateField = 'issuedAt' | 'startsAt' | 'expiresAt'
const dateDisplayMap = { issuedAt: issuedAtDisplay, startsAt: startsAtDisplay, expiresAt: expiresAtDisplay }
const dateCalendarMap = { issuedAt: issuedAtCalendar, startsAt: startsAtCalendar, expiresAt: expiresAtCalendar }
const datePopoverMap = { issuedAt: issuedAtPopoverOpen, startsAt: startsAtPopoverOpen, expiresAt: expiresAtPopoverOpen }

function onDateInput(field: DateField, val: string) {
  const formatted = autoFormatDateInput(val)
  dateDisplayMap[field].value = formatted
  const iso = parseDisplayToIso(formatted)
  if (iso) {
    dateSyncing = true
    form.value[field] = iso
    dateCalendarMap[field].value = isoToCalendar(iso)
    nextTick(() => { dateSyncing = false; policyFormRef.value?.clear(field) })
  } else if (formatted.replace(/\D/g, '').length === 8) {
    dateSyncing = true
    form.value[field] = 'INVALID_DATE'
    nextTick(() => { dateSyncing = false })
  }
}
function onCalendarChange(field: DateField, val: any) {
  if (!val) return
  dateSyncing = true
  dateCalendarMap[field].value = val
  form.value[field] = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  dateDisplayMap[field].value = `${String(val.day).padStart(2, '0')}.${String(val.month).padStart(2, '0')}.${val.year}`
  datePopoverMap[field].value = false
  nextTick(() => { dateSyncing = false; policyFormRef.value?.clear(field) })
}

watch(() => form.value.issuedAt, (v) => { if (!dateSyncing && v) { issuedAtDisplay.value = isoToDisplay(v); issuedAtCalendar.value = isoToCalendar(v) } })
watch(() => form.value.startsAt, (v) => { if (!dateSyncing && v) { startsAtDisplay.value = isoToDisplay(v); startsAtCalendar.value = isoToCalendar(v) } })
watch(() => form.value.expiresAt, (v) => { if (!dateSyncing && v) { expiresAtDisplay.value = isoToDisplay(v); expiresAtCalendar.value = isoToCalendar(v) } })

// Plate auto-format
function formatPlate(raw: string): string {
  const upper = raw.toUpperCase().replace(/[^A-ZÇĞİÖŞÜ0-9]/g, '')
  // 3 haneli il kodu gelirse (034 gibi): baştaki 0'ı kaldır (01-09 arası 2 haneli kalır)
  const match = upper.match(/^(\d{2,3})([A-ZÇĞİÖŞÜ]{1,3})(\d{0,4})$/)
  if (match) {
    let il = match[1]
    if (il.length === 3 && il.startsWith('0')) il = il.slice(1)
    return `${il} ${match[2]}${match[3] ? ' ' + match[3] : ''}`.trim()
  }
  return upper
}
function onPlateInput(val: string) { form.value.plateNo = formatPlate(val) }

// PDF sonrası sarı highlight: alan dolduğunda kaldır
watch(() => form.value, () => {
  if (pdfUnfilledFields.value.size === 0) return
  const fields = ['customerId', 'productionType', 'insuranceId', 'companyId', 'policyNo', 'businessType', 'referenceSource', 'soldBy', 'issuedAt', 'startsAt', 'expiresAt', 'plateNo', 'registrationNo', 'grossPremium', 'netPremium', 'companyCommRate'] as const
  for (const f of fields) {
    if (form.value[f]) pdfUnfilledFields.value.delete(f)
  }
}, { deep: true })
function preventNonDigitKey(e: KeyboardEvent) {
  if (e.ctrlKey || e.metaKey || e.altKey) return
  if (['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Tab', 'Home', 'End'].includes(e.key)) return
  if (!/^\d$/.test(e.key)) e.preventDefault()
}
function preventNumberFieldInvalidKey(e: KeyboardEvent) {
  if (e.ctrlKey || e.metaKey || e.altKey) return
  if (['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Tab', 'Home', 'End', '.'].includes(e.key)) return
  if (e.key === '-' && form.value.isZeyil) return
  if (!/^[\d,.]$/.test(e.key)) e.preventDefault()
}

// Auto-fill commission (sadece yeni poliçede — edit modunda mevcut oranı ezme)
let skipCommWatch = false
let commWatchTimer: ReturnType<typeof setTimeout> | null = null

function onEsc(e: KeyboardEvent) {
  if (e.key === 'Escape' && isOpen.value) isOpen.value = false
}
onMounted(() => window.addEventListener('keydown', onEsc))
onUnmounted(() => window.removeEventListener('keydown', onEsc))
watch(() => form.value.insuranceId, (id) => {
  if (skipCommWatch || !id) return
  const ins = insurances.value.find(i => i.id === id)
  if (ins?.defaultCommRate != null) form.value.companyCommRate = ins.defaultCommRate
})
watch(() => form.value.branchId, (id) => {
  if (skipCommWatch || !id) return
  const br = branches.value.find(b => b.id === id)
  if (br?.commissionRate) form.value.branchCommRate = br.commissionRate
})
// Acentem (SELF) seçilirse tali komisyon temizle
watch(() => form.value.productionType, (val) => {
  if (skipCommWatch || val !== 'SELF') return
  form.value.branchId = undefined
  form.value.branchCommRate = 0
  form.value.branchCommAmount = 0
})
// Komisyon giris modu (tutar mi oran mi)
const commissionAsAmount = computed(() => isFieldEnabled('commission_as_amount'))
const branchCommInputEnabled = computed(() => isFieldEnabled('branch_commission_input'))
const showBranchCommField = computed(() => showBranchField.value && branchCommInputEnabled.value)

function syncCommFromRate() {
  const net = Number(form.value.netPremium) || 0
  form.value.companyCommAmount = Math.round(net * (Number(form.value.companyCommRate) || 0) / 100 * 100) / 100
  if (showBranchField.value && form.value.branchCommRate) syncBranchFromRate()
}
function syncCommFromAmount() {
  const net = Number(form.value.netPremium) || 0
  form.value.companyCommRate = net > 0 ? Math.round((Number(form.value.companyCommAmount) || 0) / net * 100 * 100) / 100 : 0
  if (showBranchField.value && form.value.branchCommRate) syncBranchFromRate()
}
function syncBranchFromRate() {
  const net = Number(form.value.netPremium) || 0
  const compRate = Number(form.value.companyCommRate) || 0
  form.value.branchCommAmount = Math.round(net * compRate * (Number(form.value.branchCommRate) || 0) / 10000 * 100) / 100
}
function syncBranchFromAmount() {
  const net = Number(form.value.netPremium) || 0
  const compRate = Number(form.value.companyCommRate) || 0
  const base = net * compRate / 100
  form.value.branchCommRate = base > 0 ? Math.round((Number(form.value.branchCommAmount) || 0) / base * 100 * 100) / 100 : 0
}

// Zeyil işaretlenince endorsementNo 2'ye at
watch(() => form.value.isZeyil, (val) => {
  if (val && form.value.endorsementNo < 2) form.value.endorsementNo = 2
})

// Start date -> finish date auto (+1 year)
watch(() => form.value.startsAt, (val) => {
  if (isInitializing || !val || form.value.isZeyil) return
  const parts = val.split('-')
  if (parts.length === 3) {
    const y = parseInt(parts[0]) + 1
    const iso = `${y}-${parts[1]}-${parts[2]}`
    form.value.expiresAt = iso
    expiresAtDisplay.value = isoToDisplay(iso)
    expiresAtCalendar.value = isoToCalendar(iso)
  }
})

// Validation
const policySchema = computed(() => {
  const base: Record<string, any> = {
    customerId: z.number({ message: 'Müşteri seçimi zorunludur' }).min(1, 'Müşteri seçimi zorunludur'),
    productionType: z.string().min(1, 'Üretim türü zorunludur'),
    insuranceId: z.number({ message: 'Poliçe türü zorunludur' }).min(1, 'Poliçe türü zorunludur'),
    companyId: z.number({ message: 'Sigorta şirketi zorunludur' }).min(1, 'Sigorta şirketi zorunludur'),
    policyNo: z.string().min(1, 'Poliçe numarası zorunludur')
      .refine(val => val === val.trim(), 'Poliçe numarası başında veya sonunda boşluk olamaz')
      .refine(val => !isTcKimlikNo(val.trim()), 'TC Kimlik No girilemez'),
    issuedAt: z.string().min(1, 'Tanzim tarihi zorunludur').refine(isValidIsoDate, 'Geçersiz tarih (ör: Şubatta 31 gün yoktur)'),
    startsAt: z.string().min(1, 'Başlangıç tarihi zorunludur').refine(isValidIsoDate, 'Geçersiz tarih (ör: Şubatta 31 gün yoktur)'),
    expiresAt: z.string().min(1, 'Bitiş tarihi zorunludur').refine(isValidIsoDate, 'Geçersiz tarih (ör: Şubatta 31 gün yoktur)'),
    grossPremium: form.value.isZeyil
      ? z.number({ message: 'Brüt prim zorunludur' }).refine(v => v !== 0, 'Brüt prim 0 olamaz')
      : z.number({ message: 'Brüt prim zorunludur' }).positive('Brüt prim 0\'dan büyük olmalıdır'),
    netPremium: form.value.isZeyil
      ? z.number({ message: 'Net prim zorunludur' })
      : z.number({ message: 'Net prim zorunludur' }).min(0, 'Net prim 0 veya daha büyük olmalıdır'),
    companyCommRate: z.number({ message: 'Komisyon oranı zorunludur' }).min(0).max(100, 'Komisyon 0-100 arası olmalı'),
  }
  if (showBranchField.value) {
    base.branchId = z.number({ message: 'Tali acente zorunludur' }).min(1, 'Tali acente zorunludur')
  }
  return z.object(base).passthrough().superRefine((data, ctx) => {
    if (data.issuedAt && data.startsAt && isValidIsoDate(data.issuedAt) && isValidIsoDate(data.startsAt)) {
      if (data.issuedAt > data.startsAt) {
        ctx.addIssue({ code: 'custom', path: ['issuedAt'], message: 'Tanzim tarihi başlangıç tarihinden sonra olamaz' })
      }
    }
    if (data.startsAt && data.expiresAt && isValidIsoDate(data.startsAt) && isValidIsoDate(data.expiresAt)) {
      if (data.expiresAt <= data.startsAt) {
        ctx.addIssue({ code: 'custom', path: ['expiresAt'], message: 'Bitiş tarihi başlangıç tarihinden sonra olmalıdır' })
      }
    }
    if (!form.value.isZeyil && data.netPremium != null && data.grossPremium != null && data.netPremium > data.grossPremium) {
      ctx.addIssue({ code: 'custom', path: ['netPremium'], message: 'Net prim brüt primden büyük olamaz' })
    }
    if (!form.value.isZeyil) {
      if (!commissionAsAmount.value) {
        // Oran modunda: komisyon tutarını hesapla ve net primle karşılaştır
        const commAmount = Math.round((Number(data.netPremium) || 0) * (Number(data.companyCommRate) || 0) / 100 * 100) / 100
        if (commAmount > (Number(data.netPremium) || 0)) {
          ctx.addIssue({ code: 'custom', path: ['companyCommRate'], message: 'Komisyon tutarı net primden büyük olamaz' })
        }
      } else {
        if ((Number(data.companyCommAmount) || 0) > (Number(data.netPremium) || 0)) {
          ctx.addIssue({ code: 'custom', path: ['companyCommAmount'], message: 'Komisyon tutarı net primden büyük olamaz' })
        }
      }
    }
  })
})

// Init data on open
watch(isOpen, (val) => {
  if (!val) {
    nextTick(() => policyFormRef.value?.clear())
    policyNoError.value = ''
    showZeyilConfirm.value = false
    pendingZeyilData.value = null
    pdfUnfilledFields.value = new Set()
    return
  }
  if (val) {
    isInitializing = true
    const p = props.policy
    if (p) {
      // Edit mode: populate form with policy data — watcher'ı durdur
      if (commWatchTimer) clearTimeout(commWatchTimer)
      skipCommWatch = true
      form.value = {
        productionType: p.productionType || 'SELF',
        customerId: p.customerId,
        insuranceId: p.insuranceId,
        companyId: p.companyId,
        branchId: p.branchId ?? undefined,
        policyNo: p.policyNo || '',
        insuredName: p.insuredName || '',
        insuredNo: p.insuredNo || '',
        issuedAt: p.issuedAt || '',
        startsAt: p.startsAt || '',
        expiresAt: p.expiresAt || '',
        grossPremium: p.grossPremium || 0,
        netPremium: p.netPremium || 0,
        companyCommRate: p.companyCommRate || 0,
        companyCommAmount: p.companyCommAmount ?? Math.round((Number(p.netPremium) || 0) * (Number(p.companyCommRate) || 0) / 100 * 100) / 100,
        branchCommRate: p.branchCommRate || 0,
        branchCommAmount: p.branchCommAmount ?? 0,
        endorsementNo: p.endorsementNo || 1,
        isZeyil: (p.endorsementNo || 1) > 1,
        isCancelled: p.isCancelled ?? false,
        noRenewalReminder: p.noRenewalReminder ?? false,
        plateNo: p.plateNo || '',
        registrationNo: p.registrationNo || '',
        chassisNo: p.chassisNo || '',
        engineNo: p.engineNo || '',
        vehicleBrand: p.vehicleBrand || '',
        vehicleModel: p.vehicleModel || '',
        vehicleYear: p.vehicleYear || '',
        uavtCode: p.uavtCode || '',
        daskNo: p.daskNo || '',
        network: p.network || '',
        additionalInsureds: p.additionalInsureds || '',
        businessType: p.businessType || '',
        referenceSource: p.referenceSource || undefined,
        soldBy: p.soldBy || undefined
      }
      grossDisplay.value = p.grossPremium ? formatTrCurrency(p.grossPremium) : ''
      netDisplay.value = p.netPremium ? formatTrCurrency(p.netPremium) : ''
      issuedAtDisplay.value = isoToDisplay(p.issuedAt || '')
      startsAtDisplay.value = isoToDisplay(p.startsAt || '')
      expiresAtDisplay.value = isoToDisplay(p.expiresAt || '')
      issuedAtCalendar.value = isoToCalendar(p.issuedAt || '')
      startsAtCalendar.value = isoToCalendar(p.startsAt || '')
      expiresAtCalendar.value = isoToCalendar(p.expiresAt || '')
      commWatchTimer = setTimeout(() => { skipCommWatch = false; commWatchTimer = null }, 100)
    } else {
      // Create mode
      form.value = { ...defaultForm, customerId: props.customerId }
      removeFile()
      grossDisplay.value = ''
      netDisplay.value = ''
      issuedAtDisplay.value = ''
      startsAtDisplay.value = ''
      expiresAtDisplay.value = ''
      issuedAtCalendar.value = undefined
      startsAtCalendar.value = undefined
      expiresAtCalendar.value = undefined
    }
    nextTick(() => { isInitializing = false })
    customerSearchTerm.value = ''
    fetchInsurances()
    fetchCompanies()
    fetchBranches()
    initList()
    fetchFieldSettings()
    fetchReferenceSources()
    fetchUsersList()
  }
})

function isTcKimlikNo(val: string): boolean {
  if (!/^\d{11}$/.test(val)) return false
  if (val[0] === '0') return false
  const d = val.split('').map(Number)
  const check10 = ((d[0] + d[2] + d[4] + d[6] + d[8]) * 7 - (d[1] + d[3] + d[5] + d[7])) % 10
  if (check10 !== d[9]) return false
  const check11 = (d[0] + d[1] + d[2] + d[3] + d[4] + d[5] + d[6] + d[7] + d[8] + d[9]) % 10
  return check11 === d[10]
}

const isNewCustomerOpen = ref(false)
const policyNoChecking = ref(false)
const policyNoError = ref('')
const showZeyilConfirm = ref(false)
const pendingZeyilData = ref<any>(null)

function applyZeyilData() {
  const p = pendingZeyilData.value
  if (!p) return
  addCustomerToList({ id: p.customerId, name: p.customerName, identityNo: p.customerIdentity })
  form.value.customerId      = p.customerId
  form.value.insuranceId     = p.insuranceId
  form.value.companyId       = p.companyId
  form.value.productionType  = p.productionType
  if (p.branchId)        form.value.branchId       = p.branchId
  if (p.plateNo)         form.value.plateNo        = p.plateNo
  if (p.insuredName)     form.value.insuredName    = p.insuredName
  if (p.insuredNo)       form.value.insuredNo      = p.insuredNo
  if (p.referenceSource) form.value.referenceSource = p.referenceSource
  if (p.soldBy)          form.value.soldBy         = p.soldBy
  if (p.expiresAt) {
    form.value.expiresAt = p.expiresAt
    expiresAtDisplay.value = isoToDisplay(p.expiresAt)
    expiresAtCalendar.value = isoToCalendar(p.expiresAt)
  }
  form.value.isZeyil      = true
  form.value.endorsementNo = p.maxEndorsementNo + 1
  policyNoError.value = ''
  showZeyilConfirm.value = false
  pendingZeyilData.value = null
  nextTick(() => {
    policyFormRef.value?.clear('customerId')
    policyFormRef.value?.clear('insuranceId')
    policyFormRef.value?.clear('companyId')
  })
}

function cancelZeyil() {
  showZeyilConfirm.value = false
  pendingZeyilData.value = null
  policyNoError.value = ''
  removeFile()
  isOpen.value = false
}

// Poliçe silme
const deleting = ref(false)
const showDeleteConfirm = ref(false)

function confirmDeletePolicy() {
  showDeleteConfirm.value = true
}

async function deletePolicy() {
  if (!props.policy?.id || deleting.value) return
  deleting.value = true
  try {
    await del(`policies/${props.policy.id}`)
    toast.add({ title: 'Poliçe silindi', color: 'success' })
    showDeleteConfirm.value = false
    isOpen.value = false
    emit('saved')
  } catch (error: any) {
    toast.add({ title: error.message || 'Poliçe silinemedi', color: 'error' })
  } finally {
    deleting.value = false
  }
}

function cancelDelete() {
  showDeleteConfirm.value = false
}

watch(() => form.value.policyNo, () => { policyNoError.value = '' })

// Zeyil seçilince/kaldırılınca poliçe no kontrolünü yeniden yap
// policyNoChecking kontrolü: auto-fill sırasında isZeyil değişince sonsuz döngü engellenir
watch(() => form.value.isZeyil, () => {
  if (policyNoChecking.value) return
  policyNoError.value = ''
  if (form.value.policyNo.trim()) checkPolicyNoDuplicate()
})

async function checkPolicyNoDuplicate() {
  const no = form.value.policyNo.trim()
  if (!no) { policyNoError.value = ''; return }
  if (isTcKimlikNo(no)) {
    policyNoError.value = 'TC Kimlik No girilemez'
    return
  }
  policyNoChecking.value = true
  try {
    const endorsementNo = form.value.isZeyil ? form.value.endorsementNo : 1
    const excludeId = isEditMode.value && props.policy?.id ? props.policy.id : undefined
    const params = new URLSearchParams({ policyNo: no, endorsementNo: String(endorsementNo) })
    if (excludeId) params.append('excludeId', String(excludeId))
    if (!isEditMode.value) params.append('withData', '1')
    const res = await get<any>(`policies/check-duplicate?${params}`)

    // Mevcut poliçe bulundu — kullanıcıya sor
    if (!isEditMode.value && !form.value.isZeyil && res?.data?.policy) {
      const p = res.data.policy
      pendingZeyilData.value = p
      policyNoError.value = 'Bu poliçe numarası zaten kayıtlı'
      showZeyilConfirm.value = true
    } else {
      policyNoError.value = res?.data?.exists ? 'Poliçe No zaten kayıtlı' : ''
    }
  } catch {}
  policyNoChecking.value = false
}

function onNewCustomerSaved(customer?: { id: number, name: string, identityNo: string }) {
  if (!customer) return
  addCustomerToList(customer)
  form.value.customerId = customer.id
  nextTick(() => policyFormRef.value?.clear('customerId'))
}

const saving = ref(false)

async function onFormSubmit() {
  if (saving.value) return
  if (!policyFormRef.value) return
  if (policyNoError.value) return
  try {
    await policyFormRef.value.validate()
    await savePolicy()
  } catch {}
}

async function savePolicy() {
  if (saving.value) return
  saving.value = true
  const data: Record<string, any> = {
    productionType: form.value.productionType,
    customerId: form.value.customerId,
    insuranceId: form.value.insuranceId,
    companyId: form.value.companyId,
    branchId: form.value.branchId,
    policyNo: form.value.policyNo,
    insuredName: form.value.insuredName ? form.value.insuredName.toUpperCase() : undefined,
    insuredNo: form.value.insuredNo || undefined,
    issuedAt: form.value.issuedAt,
    startsAt: form.value.startsAt,
    expiresAt: form.value.expiresAt,
    grossPremium: form.value.grossPremium,
    netPremium: form.value.netPremium,
    companyCommRate: form.value.companyCommRate,
    companyCommAmount: form.value.companyCommAmount != null && form.value.companyCommAmount !== 0 ? form.value.companyCommAmount : Math.round((Number(form.value.netPremium) || 0) * (Number(form.value.companyCommRate) || 0) / 100 * 100) / 100,
    branchCommRate: form.value.branchCommRate,
    branchCommAmount: form.value.branchCommAmount || (form.value.branchCommRate
      ? Math.round((Number(form.value.netPremium) || 0) * (Number(form.value.companyCommRate) || 0) * (Number(form.value.branchCommRate) || 0) / 10000 * 100) / 100
      : null),
    isApproved: true,
    isCancelled: form.value.isCancelled,
    endorsementNo: form.value.isZeyil ? form.value.endorsementNo : 1,
    noRenewalReminder: form.value.noRenewalReminder,
    plateNo: form.value.plateNo ? form.value.plateNo.toUpperCase() : undefined,
    registrationNo: form.value.registrationNo ? form.value.registrationNo.toUpperCase() : undefined,
    chassisNo: form.value.chassisNo ? form.value.chassisNo.toUpperCase() : undefined,
    engineNo: form.value.engineNo ? form.value.engineNo.toUpperCase() : undefined,
    vehicleBrand: form.value.vehicleBrand ? form.value.vehicleBrand.toUpperCase() : undefined,
    vehicleModel: form.value.vehicleModel ? form.value.vehicleModel.toUpperCase() : undefined,
    vehicleYear: form.value.vehicleYear || undefined,
    uavtCode: form.value.uavtCode || undefined,
    daskNo: form.value.daskNo ? form.value.daskNo.toUpperCase() : undefined,
    network: form.value.network || undefined,
    additionalInsureds: form.value.additionalInsureds || undefined,
    businessType: form.value.businessType || undefined,
    referenceSource: form.value.referenceSource || undefined,
    soldBy: form.value.soldBy || undefined
  }

  // Mutabakat kilitli poliçelerde sadece plaka ve ruhsat seri no gönder
  if (isEditMode.value && isReconciled.value) {
    const plateNo = data.plateNo
    const registrationNo = data.registrationNo
    Object.keys(data).forEach(k => delete data[k])
    if (plateNo !== undefined) data.plateNo = plateNo
    if (registrationNo !== undefined) data.registrationNo = registrationNo
  }

  try {
    let policyId: number | null = null
    if (isEditMode.value && props.policy?.id) {
      await put(`policies/${props.policy.id}`, data)
      policyId = props.policy.id
      toast.add({ title: 'Poliçe güncellendi', color: 'success' })
    } else {
      const res = await post<any>('policies', data)
      policyId = res?.data?.id || null
      toast.add({ title: 'Yeni poliçe eklendi', color: 'success' })
    }

    // Dosya varsa yükle
    if (policyFile.value && policyId) {
      try {
        const docName = buildDocumentName()
        const renamedFile = new File([policyFile.value], docName, { type: policyFile.value.type })
        const fd = new FormData()
        fd.append('file', renamedFile)
        fd.append('policyId', String(policyId))
        if (form.value.customerId) fd.append('customerId', String(form.value.customerId))
        const docRes = await fetch('/api/documents', {
          method: 'POST',
          headers: { Authorization: `Bearer ${token.value}` },
          body: fd,
        })
        if (!docRes.ok) {
          const errText = await docRes.text().catch(() => '')
          console.error('Document upload error:', errText)
          toast.add({ title: 'Poliçe kaydedildi ancak dosya yüklenemedi', color: 'warning' })
        }
      } catch (docErr: any) {
        console.error('Document upload exception:', docErr)
        toast.add({ title: 'Poliçe kaydedildi ancak dosya yüklenemedi', color: 'warning' })
      }
    }

    removeFile()
    isOpen.value = false
    emit('saved')
  } catch (error: any) {
    toast.add({ title: error.message || 'Poliçe kaydedilemedi', color: 'error' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UModal v-model:open="isOpen" :title="isEditMode ? 'Poliçe Düzenle' : 'Yeni Poliçe'" class="sm:max-w-3xl" :dismissible="false" :ui="{ content: 'flex flex-col max-h-[90vh]', body: 'flex-1 overflow-y-auto min-h-0 relative' }">
    <template #body>
      <!-- Poliçe silme onay overlay -->
      <div v-if="showDeleteConfirm" class="absolute inset-0 z-30 flex flex-col items-center justify-center gap-4 bg-white/90 dark:bg-gray-900/90 rounded-xl backdrop-blur-sm">
        <div class="flex flex-col items-center gap-4 max-w-sm text-center">
          <div class="size-14 rounded-full bg-red-100 dark:bg-red-900/50 flex items-center justify-center">
            <UIcon name="i-heroicons-trash" class="size-7 text-red-500" />
          </div>
          <div>
            <p class="text-base font-semibold text-gray-900 dark:text-white">Poliçe Silinecek</p>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Bu poliçeyi silmek istediğinize emin misiniz?<br>Bu işlem geri alınamaz.</p>
          </div>
          <div class="flex gap-3">
            <UButton label="Evet, Sil" size="md" color="error" class="min-w-[140px] justify-center" :loading="deleting" @click="deletePolicy" />
            <UButton label="Vazgeç" size="md" color="neutral" variant="outline" class="min-w-[140px] justify-center" :disabled="deleting" @click="cancelDelete" />
          </div>
        </div>
      </div>

      <!-- Mükerrer poliçe uyarı overlay -->
      <div v-if="showZeyilConfirm" class="absolute inset-0 z-30 flex flex-col items-center justify-center gap-4 bg-white/90 dark:bg-gray-900/90 rounded-xl backdrop-blur-sm">
        <div class="flex flex-col items-center gap-4 max-w-sm text-center">
          <div class="size-14 rounded-full bg-amber-100 dark:bg-amber-900/50 flex items-center justify-center">
            <UIcon name="i-heroicons-exclamation-triangle" class="size-7 text-amber-500" />
          </div>
          <div>
            <p class="text-base font-semibold text-gray-900 dark:text-white">Poliçe Numarası Kayıtlı</p>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Bu poliçe numarası sistemde zaten mevcut.<br>Zeyil olarak eklemek ister misiniz?</p>
          </div>
          <div class="flex gap-3">
            <UButton label="Evet, Zeyil Ekle" size="md" color="primary" class="min-w-[140px] justify-center" @click="applyZeyilData" />
            <UButton label="Hayır, Vazgeç" size="md" color="neutral" variant="outline" class="min-w-[140px] justify-center" @click="cancelZeyil" />
          </div>
        </div>
      </div>

      <!-- Kaydetme overlay -->
      <div v-if="saving" class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 bg-white/85 dark:bg-gray-900/85 rounded-xl backdrop-blur-sm">
        <UIcon name="i-lucide-loader-circle" class="size-10 animate-spin text-primary" />
        <p class="text-sm">{{ isEditMode ? 'Poliçe güncelleniyor...' : 'Poliçe kaydediliyor...' }}</p>
      </div>
      <!-- PDF okuma overlay -->
      <div v-if="pdfParsing" class="fixed inset-0 z-[100] flex flex-col items-center justify-center gap-5 bg-white/95 dark:bg-gray-900/95 backdrop-blur-sm">
        <div class="relative size-20 flex items-center justify-center">
          <svg class="absolute inset-0 size-20 -rotate-90" viewBox="0 0 80 80">
            <circle cx="40" cy="40" r="34" fill="none" stroke="currentColor" stroke-width="4" class="text-gray-200 dark:text-gray-700" />
            <circle cx="40" cy="40" r="34" fill="none" stroke="currentColor" stroke-width="4" class="text-primary transition-all duration-300" stroke-linecap="round" :stroke-dasharray="213.6" :stroke-dashoffset="213.6 - (213.6 * pdfProgress / 100)" />
          </svg>
          <span class="text-lg font-bold text-primary tabular-nums">%{{ pdfProgress }}</span>
        </div>
        <div class="text-center">
          <p class="text-sm">{{ pdfProgressLabel }}</p>
        </div>
      </div>
      <UForm ref="policyFormRef" class="policy-form" :schema="policySchema" :state="form" :validate-on="['submit']" @submit="savePolicy">
        <!-- Mutabakat kilidi uyarısı -->
        <div v-if="isReconciled" class="mb-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40 p-3">
          <UIcon name="i-lucide-lock" class="size-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
          <p class="text-sm text-amber-700 dark:text-amber-300">
            Bu poliçe mutabakat kapsamındadır. Yalnızca <strong>Plaka</strong> ve <strong>Ruhsat Seri No</strong> düzenlenebilir.
          </p>
        </div>
        <div class="grid grid-cols-12 gap-4 [&_input]:!font-semibold">
          <!-- Müşteri -->
          <UFormField :class="['col-span-12', pdfHighlight('customerId')]" name="customerId">
            <template #label />
            <div class="relative fl-select-form [&_.truncate]:!font-semibold">
              <USelectMenu
                v-model="form.customerId"
                :items="customerOptions"
                value-key="value"
                label-key="label"
                placeholder=" "
                searchable
                :search-input="{ placeholder: 'Ara...' }"
                :search-attributes="['label']"
                :loading="customerLoading"
                class="w-full"
                :disabled="isReconciled"
                @update:search-term="customerSearchTerm = $event"
              >
                <template #empty>
                  <div class="flex flex-col items-center gap-2 py-3 px-2 text-center">
                    <span class="text-sm text-muted">
                      <template v-if="customerLoading">Yükleniyor…</template>
                      <template v-else>Müşteri bulunamadı</template>
                    </span>
                    <UButton
                      v-if="!customerLoading"
                      size="sm"
                      variant="soft"
                      color="primary"
                      icon="i-lucide-user-plus"
                      label="Yeni Müşteri Oluştur"
                      @click="isNewCustomerOpen = true"
                    />
                  </div>
                </template>
                <template #content-bottom>
                  <div v-if="customerHasMore" class="border-t border-default px-2 py-1.5">
                    <UButton
                      size="xs"
                      variant="ghost"
                      color="neutral"
                      icon="i-lucide-chevrons-down"
                      label="Daha fazla göster"
                      class="w-full justify-center"
                      @click.stop="loadMore()"
                    />
                  </div>
                </template>
              </USelectMenu>
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.customerId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Müşteri Adı Soyadı <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </UFormField>

          <!-- Müşteri seçilmeden diğer alanlar gizli -->
          <template v-if="form.customerId || isEditMode">
          <fieldset class="contents">
          <!-- Üretim Türü | Tali Acente (koşullu) | Poliçe Türü -->
          <UFormField name="productionType" :class="[showBranchField ? 'col-span-4' : 'col-span-6', pdfHighlight('productionType')]">
            <template #label />
            <div class="relative fl-select-form [&_.truncate]:!font-semibold">
              <USelect v-model="form.productionType" :items="prodOptions" value-key="value" placeholder=" " class="w-full" :disabled="isReconciled" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.productionType ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Üretim Yeri <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </UFormField>
          <UFormField v-if="showBranchField" name="branchId" class="col-span-4">
            <template #label />
            <div class="relative fl-select-form [&_.truncate]:!font-semibold">
              <USelectMenu v-model="form.branchId" :items="branchOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" :disabled="isReconciled" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.branchId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Tali Acente <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </UFormField>
          <UFormField name="insuranceId" :class="[showBranchField ? 'col-span-4' : 'col-span-6', pdfHighlight('insuranceId')]">
            <template #label />
            <div class="relative fl-select-form [&_.truncate]:!font-semibold">
              <USelectMenu v-model="form.insuranceId" :items="insuranceOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" :disabled="isReconciled" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.insuranceId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Poliçe Türü <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </UFormField>

          <!-- Sigorta Şirketi | Poliçe Numarası -->
          <UFormField name="companyId" :class="['col-span-6', pdfHighlight('companyId')]">
            <template #label />
            <div class="relative fl-select-form [&_.truncate]:!font-semibold">
              <USelectMenu v-model="form.companyId" :items="companyOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" :disabled="isReconciled" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.companyId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Sigorta Şirketi <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </UFormField>
          <UFormField name="policyNo" :class="['col-span-6', pdfHighlight('policyNo')]">
            <template #label />
            <div class="relative fl-form">
              <UInput v-model="form.policyNo" placeholder=" " class="w-full peer/fl-polyno" :loading="policyNoChecking" :disabled="isReconciled" @blur="checkPolicyNoDuplicate" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-polyno:top-0 peer-focus-within/fl-polyno:-translate-y-1/2 peer-focus-within/fl-polyno:text-xs peer-focus-within/fl-polyno:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-polyno:top-0 peer-has-[input:not(:placeholder-shown)]/fl-polyno:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-polyno:text-xs peer-has-[input:not(:placeholder-shown)]/fl-polyno:text-[var(--ui-text-highlighted)]">Poliçe Numarası <span class="text-[var(--ui-error)]">*</span></label>
            </div>
            <p v-if="policyNoError && !showZeyilConfirm" class="mt-1 text-[0.7rem] text-[var(--ui-error)]">{{ policyNoError }}</p>
          </UFormField>

          <!-- İş Türü | Kaynak (sadece Yeni İş) | Satış Temsilcisi -->
          <UFormField name="businessType" :class="[form.businessType && referenceSourceOptions.length > 0 ? 'col-span-4' : 'col-span-6', pdfHighlight('businessType')]">
            <template #label />
            <div class="relative fl-select-form [&_.truncate]:!font-semibold">
              <USelect v-model="form.businessType" :items="businessTypeOptions" placeholder=" " class="w-full" :disabled="isReconciled || form.isCancelled" @update:model-value="form.referenceSource = undefined" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.businessType ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">İş Türü</label>
            </div>
          </UFormField>
          <UFormField v-if="form.businessType && referenceSourceOptions.length > 0" name="referenceSource" :class="['col-span-4', pdfHighlight('referenceSource')]">
            <template #label />
            <div class="relative fl-select-form [&_.truncate]:!font-semibold">
              <USelectMenu v-model="form.referenceSource" :items="referenceSourceOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" :disabled="isReconciled || form.isCancelled" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.referenceSource ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Kaynak</label>
            </div>
          </UFormField>
          <UFormField name="soldBy" :class="[form.businessType && referenceSourceOptions.length > 0 ? 'col-span-4' : 'col-span-6', pdfHighlight('soldBy')]">
            <template #label />
            <div class="relative fl-select-form [&_.truncate]:!font-semibold">
              <USelectMenu v-model="form.soldBy" :items="userOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" :disabled="isReconciled || form.isCancelled" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.soldBy ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Satış Temsilcisi</label>
            </div>
          </UFormField>

          <!-- Sigorta Ettiren | Sigorta Ettiren Telefon (koşullu) -->
          <template v-if="isFieldEnabled('policy_insured_name') || isFieldEnabled('policy_insured_no')">
            <UFormField v-if="isFieldEnabled('policy_insured_name')" :class="isFieldEnabled('policy_insured_no') ? 'col-span-6' : 'col-span-12'">
              <template #label />
              <div class="relative fl-form">
                <UInput v-model="form.insuredName" placeholder=" " class="w-full peer/fl-insured" :disabled="isReconciled" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-insured:top-0 peer-focus-within/fl-insured:-translate-y-1/2 peer-focus-within/fl-insured:text-xs peer-focus-within/fl-insured:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-insured:top-0 peer-has-[input:not(:placeholder-shown)]/fl-insured:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-insured:text-xs peer-has-[input:not(:placeholder-shown)]/fl-insured:text-[var(--ui-text-highlighted)]">Sigorta Ettiren</label>
              </div>
            </UFormField>
            <UFormField v-if="isFieldEnabled('policy_insured_no')" label="Sigorta Ettiren Telefonu" :class="isFieldEnabled('policy_insured_name') ? 'col-span-6' : 'col-span-12'">
              <PhoneInput v-model="form.insuredNo" :disabled="isReconciled" />
            </UFormField>
          </template>

          <UFormField name="issuedAt" :class="['col-span-4', pdfHighlight('issuedAt')]">
            <template #label />
            <div class="relative fl-form">
              <UInput :model-value="issuedAtDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-issued" :disabled="isReconciled" @keydown="preventNonDigitKey" @update:model-value="onDateInput('issuedAt', $event)">
                <template #trailing>
                  <UPopover v-model:open="issuedAtPopoverOpen">
                    <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" :disabled="isReconciled" />
                    <template #content>
                      <UCalendar locale="tr-TR" v-model="issuedAtCalendar" class="p-2" @update:model-value="onCalendarChange('issuedAt', $event)" />
                    </template>
                  </UPopover>
                </template>
              </UInput>
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-issued:top-0 peer-focus-within/fl-issued:-translate-y-1/2 peer-focus-within/fl-issued:text-xs peer-focus-within/fl-issued:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-issued:top-0 peer-has-[input:not(:placeholder-shown)]/fl-issued:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-issued:text-xs peer-has-[input:not(:placeholder-shown)]/fl-issued:text-[var(--ui-text-highlighted)]">Tanzim Tarihi <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </UFormField>
          <UFormField name="startsAt" :class="['col-span-4', pdfHighlight('startsAt')]">
            <template #label />
            <div class="relative fl-form">
              <UInput :model-value="startsAtDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-starts" :disabled="isReconciled" @keydown="preventNonDigitKey" @update:model-value="onDateInput('startsAt', $event)">
                <template #trailing>
                  <UPopover v-model:open="startsAtPopoverOpen">
                    <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" :disabled="isReconciled" />
                    <template #content>
                      <UCalendar locale="tr-TR" v-model="startsAtCalendar" class="p-2" @update:model-value="onCalendarChange('startsAt', $event)" />
                    </template>
                  </UPopover>
                </template>
              </UInput>
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-starts:top-0 peer-focus-within/fl-starts:-translate-y-1/2 peer-focus-within/fl-starts:text-xs peer-focus-within/fl-starts:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-starts:top-0 peer-has-[input:not(:placeholder-shown)]/fl-starts:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-starts:text-xs peer-has-[input:not(:placeholder-shown)]/fl-starts:text-[var(--ui-text-highlighted)]">Başlangıç Tarihi <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </UFormField>
          <UFormField name="expiresAt" :class="['col-span-4', pdfHighlight('expiresAt')]">
            <template #label />
            <div class="relative fl-form">
              <UInput :model-value="expiresAtDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-expires" :disabled="isReconciled" @keydown="preventNonDigitKey" @update:model-value="onDateInput('expiresAt', $event)">
                <template #trailing>
                  <UPopover v-model:open="expiresAtPopoverOpen">
                    <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" :disabled="isReconciled" />
                    <template #content>
                      <UCalendar locale="tr-TR" v-model="expiresAtCalendar" class="p-2" @update:model-value="onCalendarChange('expiresAt', $event)" />
                    </template>
                  </UPopover>
                </template>
              </UInput>
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-expires:top-0 peer-focus-within/fl-expires:-translate-y-1/2 peer-focus-within/fl-expires:text-xs peer-focus-within/fl-expires:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-expires:top-0 peer-has-[input:not(:placeholder-shown)]/fl-expires:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-expires:text-xs peer-has-[input:not(:placeholder-shown)]/fl-expires:text-[var(--ui-text-highlighted)]">Bitiş Tarihi <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </UFormField>

          <!-- ─── Araç Bilgileri (TRAFFIC) ─── -->
          <template v-if="selectedInsuranceKey === 'TRAFFIC'">
            <div class="col-span-12 flex items-center gap-3 pt-1">
              <span class="text-xs font-semibold text-muted uppercase tracking-wider">Araç Bilgileri</span>
              <div class="flex-1 h-px bg-[var(--ui-border)]"></div>
            </div>
            <div :class="['relative col-span-6 fl-form', pdfHighlight('plateNo')]">
              <UInput :model-value="form.plateNo" placeholder=" " class="w-full peer/fl-plate" @update:model-value="onPlateInput" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-plate:top-0 peer-focus-within/fl-plate:-translate-y-1/2 peer-focus-within/fl-plate:text-xs peer-focus-within/fl-plate:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-plate:top-0 peer-has-[input:not(:placeholder-shown)]/fl-plate:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-plate:text-xs peer-has-[input:not(:placeholder-shown)]/fl-plate:text-[var(--ui-text-highlighted)]">Plaka</label>
            </div>
            <div :class="['relative col-span-6 fl-form', pdfHighlight('registrationNo')]">
              <UInput v-model="form.registrationNo" placeholder=" " class="w-full peer/fl-regno" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-regno:top-0 peer-focus-within/fl-regno:-translate-y-1/2 peer-focus-within/fl-regno:text-xs peer-focus-within/fl-regno:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-regno:top-0 peer-has-[input:not(:placeholder-shown)]/fl-regno:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-regno:text-xs peer-has-[input:not(:placeholder-shown)]/fl-regno:text-[var(--ui-text-highlighted)]">Ruhsat Seri No</label>
            </div>
            <div v-if="isFieldEnabled('chassis_no')" class="relative col-span-6 fl-form">
              <UInput v-model="form.chassisNo" placeholder=" " class="w-full peer/fl-chassis" :disabled="isReconciled" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-chassis:top-0 peer-focus-within/fl-chassis:-translate-y-1/2 peer-focus-within/fl-chassis:text-xs peer-focus-within/fl-chassis:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-chassis:top-0 peer-has-[input:not(:placeholder-shown)]/fl-chassis:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-chassis:text-xs peer-has-[input:not(:placeholder-shown)]/fl-chassis:text-[var(--ui-text-highlighted)]">Şasi No</label>
            </div>
            <div v-if="isFieldEnabled('engine_no')" class="relative col-span-6 fl-form">
              <UInput v-model="form.engineNo" placeholder=" " class="w-full peer/fl-engine" :disabled="isReconciled" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-engine:top-0 peer-focus-within/fl-engine:-translate-y-1/2 peer-focus-within/fl-engine:text-xs peer-focus-within/fl-engine:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-engine:top-0 peer-has-[input:not(:placeholder-shown)]/fl-engine:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-engine:text-xs peer-has-[input:not(:placeholder-shown)]/fl-engine:text-[var(--ui-text-highlighted)]">Motor No</label>
            </div>
            <div v-if="isFieldEnabled('policy_brand')" class="relative col-span-6 fl-form">
              <UInput v-model="form.vehicleBrand" placeholder=" " class="w-full peer/fl-brand" :disabled="isReconciled" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-brand:top-0 peer-focus-within/fl-brand:-translate-y-1/2 peer-focus-within/fl-brand:text-xs peer-focus-within/fl-brand:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-brand:top-0 peer-has-[input:not(:placeholder-shown)]/fl-brand:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-brand:text-xs peer-has-[input:not(:placeholder-shown)]/fl-brand:text-[var(--ui-text-highlighted)]">Marka</label>
            </div>
            <div v-if="isFieldEnabled('policy_model')" class="relative col-span-6 fl-form">
              <UInput v-model="form.vehicleModel" placeholder=" " class="w-full peer/fl-model" :disabled="isReconciled" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-model:top-0 peer-focus-within/fl-model:-translate-y-1/2 peer-focus-within/fl-model:text-xs peer-focus-within/fl-model:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-model:top-0 peer-has-[input:not(:placeholder-shown)]/fl-model:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-model:text-xs peer-has-[input:not(:placeholder-shown)]/fl-model:text-[var(--ui-text-highlighted)]">Model</label>
            </div>
            <div v-if="isFieldEnabled('vehicle_year')" :class="['relative fl-form', trafficFieldCount % 2 === 1 ? 'col-span-12' : 'col-span-6']">
              <UInput v-model="form.vehicleYear" placeholder=" " class="w-full peer/fl-year" :disabled="isReconciled" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-year:top-0 peer-focus-within/fl-year:-translate-y-1/2 peer-focus-within/fl-year:text-xs peer-focus-within/fl-year:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-year:top-0 peer-has-[input:not(:placeholder-shown)]/fl-year:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-year:text-xs peer-has-[input:not(:placeholder-shown)]/fl-year:text-[var(--ui-text-highlighted)]">Model Yılı</label>
            </div>
          </template>

          <!-- Konut (HOUSING) -->
          <template v-if="selectedInsuranceKey === 'HOUSING' && isFieldEnabled('policy_uavt')">
            <div class="col-span-12 flex items-center gap-3 pt-1">
              <span class="text-xs font-semibold text-muted uppercase tracking-wider">Konut Bilgileri</span>
              <div class="flex-1 h-px bg-[var(--ui-border)]"></div>
            </div>
            <div class="relative col-span-12 fl-form">
              <UInput v-model="form.uavtCode" placeholder=" " class="w-full peer/fl-uavt" :disabled="isReconciled" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-uavt:top-0 peer-focus-within/fl-uavt:-translate-y-1/2 peer-focus-within/fl-uavt:text-xs peer-focus-within/fl-uavt:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-uavt:top-0 peer-has-[input:not(:placeholder-shown)]/fl-uavt:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-uavt:text-xs peer-has-[input:not(:placeholder-shown)]/fl-uavt:text-[var(--ui-text-highlighted)]">UAVT Kodu</label>
            </div>
          </template>

          <!-- DASK -->
          <template v-if="selectedInsuranceKey === 'DASK'">
            <div class="col-span-12 flex items-center gap-3 pt-1">
              <span class="text-xs font-semibold text-muted uppercase tracking-wider">DASK Bilgileri</span>
              <div class="flex-1 h-px bg-[var(--ui-border)]"></div>
            </div>
            <div v-if="isFieldEnabled('policy_uavt')" :class="['relative fl-form', !isFieldEnabled('dask_no') ? 'col-span-12' : 'col-span-6']">
              <UInput v-model="form.uavtCode" placeholder=" " class="w-full peer/fl-uavt2" :disabled="isReconciled" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-uavt2:top-0 peer-focus-within/fl-uavt2:-translate-y-1/2 peer-focus-within/fl-uavt2:text-xs peer-focus-within/fl-uavt2:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-uavt2:top-0 peer-has-[input:not(:placeholder-shown)]/fl-uavt2:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-uavt2:text-xs peer-has-[input:not(:placeholder-shown)]/fl-uavt2:text-[var(--ui-text-highlighted)]">UAVT Kodu</label>
            </div>
            <div v-if="isFieldEnabled('dask_no')" :class="['relative fl-form', !isFieldEnabled('policy_uavt') ? 'col-span-12' : 'col-span-6']">
              <UInput v-model="form.daskNo" placeholder=" " class="w-full peer/fl-dask" :disabled="isReconciled" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-dask:top-0 peer-focus-within/fl-dask:-translate-y-1/2 peer-focus-within/fl-dask:text-xs peer-focus-within/fl-dask:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-dask:top-0 peer-has-[input:not(:placeholder-shown)]/fl-dask:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-dask:text-xs peer-has-[input:not(:placeholder-shown)]/fl-dask:text-[var(--ui-text-highlighted)]">DASK Poliçe No</label>
            </div>
          </template>

          <!-- Sağlık (HEALTH) -->
          <template v-if="selectedInsuranceKey === 'HEALTH'">
            <div class="col-span-12 flex items-center gap-3 pt-1">
              <span class="text-xs font-semibold text-muted uppercase tracking-wider">Sağlık Bilgileri</span>
              <div class="flex-1 h-px bg-[var(--ui-border)]"></div>
            </div>
            <UFormField v-if="isFieldEnabled('policy_network')" class="col-span-12">
              <template #label />
              <div class="relative fl-select-form [&_.truncate]:!font-semibold">
                <USelect v-model="form.network" :items="[{ label: 'Geniş', value: 'GENIS' }, { label: 'Dar', value: 'DAR' }]" value-key="value" placeholder=" " class="w-full" :disabled="isReconciled" />
                <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.network ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Network</label>
              </div>
            </UFormField>
            <UFormField label="Sigortalılar" class="col-span-12">
              <UTextarea v-model="form.additionalInsureds" :rows="3" placeholder="Sigortali kişileri girin..." class="w-full" :disabled="isReconciled" />
            </UFormField>
          </template>

          <UFormField name="grossPremium" :class="[showBranchCommField ? 'col-span-3' : 'col-span-4', pdfHighlight('grossPremium')]">
            <template #label />
            <div class="relative fl-form">
              <UInput :model-value="grossDisplay" placeholder=" " class="w-full peer/fl-gross" :disabled="isReconciled" @update:model-value="onGrossInput" @blur="onGrossBlur" @paste="onGrossPaste" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-gross:top-0 peer-focus-within/fl-gross:-translate-y-1/2 peer-focus-within/fl-gross:text-xs peer-focus-within/fl-gross:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-gross:top-0 peer-has-[input:not(:placeholder-shown)]/fl-gross:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-gross:text-xs peer-has-[input:not(:placeholder-shown)]/fl-gross:text-[var(--ui-text-highlighted)]">Brüt Prim <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </UFormField>
          <UFormField name="netPremium" :class="[showBranchCommField ? 'col-span-3' : 'col-span-4', pdfHighlight('netPremium')]">
            <template #label />
            <div class="relative fl-form">
              <UInput :model-value="netDisplay" placeholder=" " class="w-full peer/fl-net" :disabled="isReconciled" @update:model-value="onNetInput" @blur="onNetBlur" @paste="onNetPaste" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-net:top-0 peer-focus-within/fl-net:-translate-y-1/2 peer-focus-within/fl-net:text-xs peer-focus-within/fl-net:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-net:top-0 peer-has-[input:not(:placeholder-shown)]/fl-net:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-net:text-xs peer-has-[input:not(:placeholder-shown)]/fl-net:text-[var(--ui-text-highlighted)]">Net Prim <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </UFormField>
          <UFormField v-if="!commissionAsAmount" name="companyCommRate" :class="[showBranchCommField ? 'col-span-3' : 'col-span-4', pdfHighlight('companyCommRate')]">
            <template #label />
            <div class="relative fl-form">
              <UInput :model-value="form.companyCommRate || ''" type="number" step="0.01" :min="0" :max="100" placeholder=" " class="w-full peer/fl-comm" :disabled="isReconciled" @keydown="preventNumberFieldInvalidKey" @paste="onNumberPaste" @update:model-value="(v) => { form.companyCommRate = v === '' ? 0 : Number(v); syncCommFromRate() }" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-comm:top-0 peer-focus-within/fl-comm:-translate-y-1/2 peer-focus-within/fl-comm:text-xs peer-focus-within/fl-comm:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-comm:top-0 peer-has-[input:not(:placeholder-shown)]/fl-comm:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-comm:text-xs peer-has-[input:not(:placeholder-shown)]/fl-comm:text-[var(--ui-text-highlighted)]">Komisyon Oranı (%) <span class="text-[var(--ui-error)]">*</span></label>
            </div>
            <div v-if="form.companyCommRate > 0 && form.netPremium > 0" class="mt-1 text-xs text-muted flex items-center gap-1 whitespace-nowrap">
              Komisyon: <span class="text-primary">
                {{ formatTrCurrency(Math.round(form.netPremium * form.companyCommRate / 100 * 100) / 100) }}
              </span>
            </div>
          </UFormField>
          <UFormField v-else name="companyCommAmount" :class="showBranchCommField ? 'col-span-3' : 'col-span-4'">
            <template #label />
            <div class="relative fl-form">
              <UInput :model-value="form.companyCommAmount || ''" type="number" step="0.01" :min="form.isZeyil ? undefined : 0" placeholder=" " class="w-full peer/fl-commamt" :disabled="isReconciled" @keydown="preventNumberFieldInvalidKey" @paste="onNumberPaste" @update:model-value="(v) => { form.companyCommAmount = v === '' ? 0 : Number(v); syncCommFromAmount() }" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-commamt:top-0 peer-focus-within/fl-commamt:-translate-y-1/2 peer-focus-within/fl-commamt:text-xs peer-focus-within/fl-commamt:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-commamt:top-0 peer-has-[input:not(:placeholder-shown)]/fl-commamt:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-commamt:text-xs peer-has-[input:not(:placeholder-shown)]/fl-commamt:text-[var(--ui-text-highlighted)]">Komisyon Tutarı (₺) <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </UFormField>
          <!-- Tali Acente Komisyonu -->
          <UFormField v-if="showBranchCommField && !commissionAsAmount" name="branchCommRate" class="col-span-3">
            <template #label />
            <div class="relative fl-form">
              <UInput :model-value="form.branchCommRate || ''" type="number" step="0.01" :min="0" :max="100" placeholder=" " class="w-full peer/fl-bcomm" :disabled="isReconciled" @keydown="preventNumberFieldInvalidKey" @paste="onNumberPaste" @update:model-value="(v) => { form.branchCommRate = v === '' ? 0 : Number(v); syncBranchFromRate() }" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-bcomm:top-0 peer-focus-within/fl-bcomm:-translate-y-1/2 peer-focus-within/fl-bcomm:text-xs peer-focus-within/fl-bcomm:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-bcomm:top-0 peer-has-[input:not(:placeholder-shown)]/fl-bcomm:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-bcomm:text-xs peer-has-[input:not(:placeholder-shown)]/fl-bcomm:text-[var(--ui-text-highlighted)]">Tali Acente Kom. (%)</label>
            </div>
          </UFormField>
          <UFormField v-else-if="showBranchCommField" name="branchCommAmount" class="col-span-3">
            <template #label />
            <div class="relative fl-form">
              <UInput :model-value="form.branchCommAmount || ''" type="number" step="0.01" :min="form.isZeyil ? undefined : 0" placeholder=" " class="w-full peer/fl-bcommamt" :disabled="isReconciled" @keydown="preventNumberFieldInvalidKey" @paste="onNumberPaste" @update:model-value="(v) => { form.branchCommAmount = v === '' ? 0 : Number(v); syncBranchFromAmount() }" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-bcommamt:top-0 peer-focus-within/fl-bcommamt:-translate-y-1/2 peer-focus-within/fl-bcommamt:text-xs peer-focus-within/fl-bcommamt:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-bcommamt:top-0 peer-has-[input:not(:placeholder-shown)]/fl-bcommamt:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-bcommamt:text-xs peer-has-[input:not(:placeholder-shown)]/fl-bcommamt:text-[var(--ui-text-highlighted)]">Tali Acente Kom. (₺)</label>
            </div>
          </UFormField>

          <div v-if="isFieldEnabled('policy_zeyil_checkbox')" class="col-span-12 flex items-center gap-3">
            <UCheckbox v-model="form.isZeyil" label="Zeyil olarak kaydet" :disabled="isReconciled" />
            <div v-if="form.isZeyil" class="relative w-24 fl-form">
              <UInput v-model.number="form.endorsementNo" type="number" :min="2" placeholder=" " class="w-full peer/fl-zeyil" :disabled="isReconciled" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-zeyil:top-0 peer-focus-within/fl-zeyil:-translate-y-1/2 peer-focus-within/fl-zeyil:text-xs peer-focus-within/fl-zeyil:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-zeyil:top-0 peer-has-[input:not(:placeholder-shown)]/fl-zeyil:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-zeyil:text-xs peer-has-[input:not(:placeholder-shown)]/fl-zeyil:text-[var(--ui-text-highlighted)]">No</label>
            </div>
          </div>

          <div v-if="isFieldEnabled('policy_no_renewal_reminder')" class="col-span-12">
            <UCheckbox v-model="form.noRenewalReminder" label="Bu poliçeyi sonraki yenileme görevlerine ekleme" :disabled="isReconciled" />
          </div>

          <!-- Poliçe Dosyası -->
          <div class="col-span-12">
            <div
              v-if="!policyFile"
              class="border-2 border-dashed rounded-xl p-3 text-center cursor-pointer transition-all"
              :class="fileDragging
                ? 'border-primary bg-primary/5'
                : (formDisabled && !isEditMode ? 'opacity-40 pointer-events-none border-gray-300 dark:border-gray-700' : 'border-gray-300 dark:border-gray-700 hover:border-primary/50 hover:bg-primary/3')"
              @click="policyFileInput?.click()"
              @drop.prevent="onFileDrop"
              @dragover.prevent="fileDragging = true"
              @dragleave="fileDragging = false"
            >
              <div class="flex items-center justify-center gap-3">
                <div class="size-8 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                  <UIcon name="i-lucide-upload-cloud" class="size-4 text-primary" />
                </div>
                <div class="text-left">
                  <p class="text-sm font-medium">{{ fileDragging ? 'Dosyayı bırakın' : 'Poliçe dosyası yükleyin veya sürükleyin' }}</p>
                  <p class="text-xs text-muted">PDF, JPG, PNG — maks. 20MB<template v-if="pdfParsingEnabled"> · <UIcon name="i-lucide-sparkles" class="size-3 inline text-amber-500" /> <span class="text-amber-600 dark:text-amber-400">AI ile otomatik doldurulur</span></template></p>
                </div>
              </div>
              <input ref="policyFileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="onFileChange" />
            </div>
            <div v-else class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/5 border border-primary/20">
              <UIcon v-if="pdfParsing" name="i-lucide-loader-circle" class="size-5 text-primary shrink-0 animate-spin" />
              <UIcon v-else name="i-lucide-file-check" class="size-5 text-primary shrink-0" />
              <div class="flex-1 min-w-0">
                <span class="text-sm font-semibold truncate block">{{ buildDocumentName() }}</span>
                <span v-if="pdfParsing" class="text-xs text-primary">AI alanları dolduruyor...</span>
                <span v-else class="text-xs text-muted">{{ (policyFile.size / 1024).toFixed(0) }} KB</span>
              </div>
              <UButton icon="i-lucide-x" color="error" variant="ghost" size="xs" :disabled="pdfParsing" @click="removeFile" />
            </div>
          </div>

          <!-- AI Uyarıları -->
          <div v-if="pdfWarnings.length > 0" class="col-span-12">
            <div class="flex items-start gap-2 p-3 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40">
              <UIcon name="i-lucide-alert-triangle" class="size-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
              <div>
                <p class="text-sm font-medium text-amber-700 dark:text-amber-300">Lütfen doldurulan alanları kontrol edin</p>
                <ul class="mt-1 text-xs text-amber-600 dark:text-amber-400 space-y-0.5">
                  <li v-for="(w, i) in pdfWarnings" :key="i">• {{ w }}</li>
                </ul>
              </div>
            </div>
          </div>

          </fieldset>
          </template>

          <!-- Müşteri seçilmeden bilgi mesajı -->
          <div v-if="!form.customerId && !isEditMode" class="col-span-12 flex flex-col items-center justify-center py-10 gap-3 text-center">
            <UIcon name="i-lucide-user-search" class="size-10 text-gray-300 dark:text-gray-600" />
            <p class="text-sm text-muted">Devam etmek için önce müşteri seçiniz</p>
          </div>

          <button type="submit" class="hidden" />
        </div>
      </UForm>
    </template>
    <template #footer>
      <div class="w-full flex justify-between items-center">
        <div>
          <UButton v-if="isEditMode && !isReconciled && hasPermission('policies.delete')" label="Poliçe Sil" color="error" variant="ghost" size="md" icon="i-heroicons-trash" :loading="deleting" :disabled="saving || deleting" @click="confirmDeletePolicy" />
        </div>
        <div class="flex items-center gap-3">
          <UButton label="İptal" color="neutral" variant="outline" size="md" class="w-32 justify-center" :disabled="saving" @click="isOpen = false" />
          <UButton :label="isEditMode ? 'Güncelle' : 'Kaydet'" size="md" class="w-32 justify-center" :loading="saving" :disabled="saving || pdfParsing" @click="onFormSubmit" />
        </div>
      </div>
    </template>
  </UModal>

  <CustomerFormModal v-model:open="isNewCustomerOpen" @saved="onNewCustomerSaved" />
</template>

<style scoped>
:deep(input[type=number]::-webkit-inner-spin-button),
:deep(input[type=number]::-webkit-outer-spin-button) {
  -webkit-appearance: none;
  margin: 0;
}
:deep(input[type=number]) {
  -moz-appearance: textfield;
}
.policy-form :deep(p:not(.text-amber-700):not(.text-amber-300)) {
  font-size: 0.7rem !important;
}
.pdf-unfilled :deep(input),
.pdf-unfilled :deep(button[role="combobox"]),
.pdf-unfilled :deep(select),
.pdf-unfilled :deep(button[role="listbox"]),
.pdf-unfilled :deep(button[type="button"]),
.pdf-unfilled :deep([data-part="trigger"]) {
  border-color: #f59e0b !important;
  border-width: 2px !important;
  background-color: #fefce8 !important;
  box-shadow: 0 0 0 3px #f59e0b22;
  animation: pulse-amber 2s ease-in-out infinite;
}
:root.dark .pdf-unfilled :deep(input),
:root.dark .pdf-unfilled :deep(button[role="combobox"]),
:root.dark .pdf-unfilled :deep(select),
:root.dark .pdf-unfilled :deep(button[role="listbox"]),
:root.dark .pdf-unfilled :deep(button[type="button"]),
:root.dark .pdf-unfilled :deep([data-part="trigger"]) {
  background-color: #451a0333 !important;
}
@keyframes pulse-amber {
  0%, 100% { box-shadow: 0 0 0 3px #f59e0b22; }
  50% { box-shadow: 0 0 0 5px #f59e0b33; }
}
</style>
