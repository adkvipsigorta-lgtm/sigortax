<script setup lang="ts">
import type { Customer } from '~/types'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

const route = useRoute()
const toast = useToast()
const { get, del: apiDel } = useApi()
const { formatCurrency, getStatusColor, getStatusLabel } = usePolicyHelpers()
const { getCategoryById, fetchCategories } = useCustomerCategories()
const { agency } = useAgency()

const customer = ref<any>(null)
const loading = ref(true)
const activeTab = ref('aktif')
const isDeleteModalOpen = ref(false)
const { openCustomerModal, openPolicyModal } = useGlobalModals()

// Poliçe arama
const policySearch = ref('')
const policyViewMode = ref<'card' | 'table'>((typeof localStorage !== 'undefined' && localStorage.getItem('policyViewMode') as 'card' | 'table') || 'card')
watch(policyViewMode, (val) => localStorage.setItem('policyViewMode', val))
const policyTypeFilter = ref('')

// Mevcut tablardaki benzersiz poliçe türleri
const availablePolicyTypes = computed(() => {
  const policies = customer.value?.policies || []
  const types = [...new Set(policies.map((p: any) => p.insuranceName).filter(Boolean))]
  return types.sort()
})

function searchFilter(list: any[]) {
  let filtered = list
  if (policyTypeFilter.value) {
    filtered = filtered.filter((p: any) => p.insuranceName === policyTypeFilter.value)
  }
  if (!policySearch.value) return filtered
  const s = policySearch.value.toLowerCase()
  return filtered.filter((p: any) =>
    (p.policyNo || '').toLowerCase().includes(s) ||
    (p.insuranceName || '').toLowerCase().includes(s) ||
    (p.companyName || '').toLowerCase().includes(s) ||
    (p.plateNo || '').toLowerCase().includes(s)
  )
}

const activePolicies = computed(() => searchFilter((customer.value?.policies || []).filter((p: any) => p.status === 'ACTIVE')))
const expiredPolicies = computed(() => searchFilter((customer.value?.policies || []).filter((p: any) => p.status === 'EXPIRED')))
const cancelledPolicies = computed(() => searchFilter((customer.value?.policies || []).filter((p: any) => p.status === 'CANCELLED')))

const tabPolicies = computed(() => {
  const tabMap: Record<string, any[]> = {
    aktif: activePolicies.value,
    dolmus: expiredPolicies.value,
    iptal: cancelledPolicies.value,
  }
  return tabMap[activeTab.value] || []
})

const tabMeta: Record<string, { icon: string; color: string; bg: string; emptyText: string }> = {
  aktif: { icon: 'i-lucide-shield-check', color: 'text-green-500', bg: 'hover:bg-green-50/50 dark:hover:bg-green-900/10', emptyText: 'Aktif poliçe bulunmuyor' },
  dolmus: { icon: 'i-lucide-clock', color: 'text-orange-500', bg: 'hover:bg-orange-50/50 dark:hover:bg-orange-900/10', emptyText: 'Sonlanan poliçe bulunmuyor' },
  iptal: { icon: 'i-lucide-x-circle', color: 'text-red-500', bg: 'hover:bg-red-50/50 dark:hover:bg-red-900/10', emptyText: 'İptal edilmiş poliçe bulunmuyor' },
}

const currentTabMeta = computed(() => tabMeta[activeTab.value])

// Teklifler (OFFER type tasks)
const customerOffers = ref<any[]>([])
const offersLoading = ref(false)

async function fetchCustomerOffers() {
  offersLoading.value = true
  try {
    const [offerRes, renewalRes] = await Promise.all([
      get(`tasks?customerId=${route.params.id}&type=OFFER&limit=100`),
      get(`tasks?customerId=${route.params.id}&type=RENEWAL&limit=100`)
    ])
    const offers = offerRes.data?.data || offerRes.data || []
    const renewals = renewalRes.data?.data || renewalRes.data || []
    const merged = [...offers, ...renewals]
    customerOffers.value = merged.filter((t, i, arr) => arr.findIndex(x => x.id === t.id) === i)
  } catch {
    customerOffers.value = []
  } finally {
    offersLoading.value = false
  }
}

watch(activeTab, (val) => {
  if (val === 'teklifler') fetchCustomerOffers()
})

const offerStatusLabels: Record<string, string> = { PENDING: 'Bekliyor', IN_PROGRESS: 'Devam Ediyor', COMPLETED: 'Tamamlandı', CANCELLED: 'İptal', EXPIRED: 'Süresi Doldu' }
const offerStatusColors: Record<string, string> = { PENDING: 'warning', IN_PROGRESS: 'primary', COMPLETED: 'success', CANCELLED: 'error', EXPIRED: 'neutral' }
const offerResultLabels: Record<string, string> = { RENEWED: 'Yenilendi', NOT_RENEWED: 'Yenilenmedi', OFFER_APPROVED: 'Onaylandı', OFFER_REJECTED: 'Reddedildi', DONE: 'Olumlu', FAILED: 'Olumsuz', CALLED: 'Ulaşıldı', NOT_REACHED: 'Ulaşılamadı', NOT_AVAILABLE: 'Müsait Değil' }

const today = new Date().toISOString().slice(0, 10)
function isOfferActive(offer: any): boolean {
  if (offer.status === 'COMPLETED' || offer.status === 'CANCELLED' || offer.status === 'EXPIRED') return false
  if (offer.type === 'RENEWAL') {
    return !offer.deadline || offer.deadline.slice(0, 10) >= today
  }
  if (offer.offerData?.expiresAt && offer.offerData.expiresAt < today) return false
  return true
}

function getOfferInsuranceName(o: any): string {
  return o.offerData?.insuranceName || o.insuranceName || ''
}
function getOfferPlateNo(o: any): string {
  return o.offerData?.plateNo || o.plateNo || ''
}
function getOfferExpiresAt(o: any): string {
  return o.type === 'RENEWAL' ? (o.deadline?.slice(0, 10) || o.policyExpiresAt || '') : (o.offerData?.expiresAt || '')
}
const offerSearch = ref('')
const offerTypeFilter = ref('')
const offerSubTab = ref<'active' | 'past'>('active')

const availableOfferTypes = computed(() => {
  const types = [...new Set(customerOffers.value.map((o: any) => o.offerData?.insuranceName || o.insuranceName).filter(Boolean))]
  return types.sort()
})

function offerSearchFilter(list: any[]) {
  let filtered = list
  if (offerTypeFilter.value) {
    filtered = filtered.filter((o: any) => (o.offerData?.insuranceName || o.insuranceName) === offerTypeFilter.value)
  }
  if (!offerSearch.value) return filtered
  const s = offerSearch.value.toLowerCase()
  return filtered.filter((o: any) =>
    (o.offerData?.insuranceName || o.insuranceName || '').toLowerCase().includes(s) ||
    (o.offerData?.plateNo || o.plateNo || '').toLowerCase().includes(s) ||
    (o.offerData?.offerNumber || o.policyNo || '').toLowerCase().includes(s) ||
    (o.assignedToName || '').toLowerCase().includes(s)
  )
}

const activeOffers = computed(() => offerSearchFilter(customerOffers.value.filter(isOfferActive)))
const pastOffers = computed(() => offerSearchFilter(customerOffers.value.filter(o => !isOfferActive(o))))

// Teklif detay slideover
const isOfferDetailOpen = ref(false)
const selectedOffer = ref<any>(null)

function openOfferDetail(offer: any) {
  selectedOffer.value = offer
  isOfferDetailOpen.value = true
}

// Teklif düzenleme
const editOfferTask = ref<any>(null)
const showEditOfferModal = ref(false)

function openEditOffer(offer: any) {
  isOfferDetailOpen.value = false
  editOfferTask.value = offer
  showEditOfferModal.value = true
}

function onEditOfferSaved() {
  showEditOfferModal.value = false
  editOfferTask.value = null
  fetchCustomerOffers()
}

// Teklif silme
const isDeleteOfferOpen = ref(false)
const deletingOfferId = ref<number | null>(null)
const deletingOfferLoading = ref(false)

function confirmDeleteOffer(offer: any) {
  deletingOfferId.value = offer.id
  isDeleteOfferOpen.value = true
}

async function doDeleteOffer() {
  if (!deletingOfferId.value) return
  deletingOfferLoading.value = true
  try {
    await apiDel(`tasks/${deletingOfferId.value}`)
    toast.add({ title: 'Teklif silindi', color: 'success' })
    isDeleteOfferOpen.value = false
    isOfferDetailOpen.value = false
    selectedOffer.value = null
    fetchCustomerOffers()
  } catch (e: any) {
    toast.add({ title: e?.message || 'Silinemedi', color: 'error' })
  } finally {
    deletingOfferLoading.value = false
  }
}

const offerDetailFields = computed(() => {
  const t = selectedOffer.value
  if (!t) return []
  const fields: { label: string; value: string; icon: string }[] = []

  if (t.type === 'RENEWAL') {
    if (t.insuranceName) fields.push({ label: 'Sigorta Türü', value: t.insuranceName, icon: 'i-lucide-shield' })
    if (t.companyName) fields.push({ label: 'Şirket', value: t.companyName, icon: 'i-lucide-building' })
    if (t.policyNo) fields.push({ label: 'Poliçe No', value: t.policyNo, icon: 'i-lucide-hash' })
    if (t.assignedToName) fields.push({ label: 'Atanan Kişi', value: t.assignedToName, icon: 'i-lucide-user' })
    if (t.policyExpiresAt) fields.push({ label: 'Poliçe Bitiş', value: formatDate(t.policyExpiresAt), icon: 'i-lucide-calendar-x' })
    if (t.deadline) fields.push({ label: 'Görev Tarihi', value: formatDate(t.deadline), icon: 'i-lucide-calendar' })
    if (t.createdAt) fields.push({ label: 'Oluşturulma', value: formatDate(t.createdAt), icon: 'i-lucide-clock' })
    if (t.plateNo) fields.push({ label: 'Plaka', value: t.plateNo, icon: 'i-lucide-car' })
    return fields
  }

  const o = t.offerData || {}
  if (o.insuranceName) fields.push({ label: 'Sigorta Türü', value: o.insuranceName, icon: 'i-lucide-shield' })
  if (o.offerNumber) fields.push({ label: 'Teklif No', value: o.offerNumber, icon: 'i-lucide-hash' })
  if (t.assignedToName) fields.push({ label: 'Atanan Kişi', value: t.assignedToName, icon: 'i-lucide-user' })
  if (o.expiresAt) fields.push({ label: 'Bitiş Tarihi', value: formatDate(o.expiresAt), icon: 'i-lucide-calendar-x' })
  if (t.createdAt) fields.push({ label: 'Oluşturulma', value: formatDate(t.createdAt), icon: 'i-lucide-clock' })
  if (o.plateNo) fields.push({ label: 'Plaka', value: o.plateNo, icon: 'i-lucide-car' })
  if (o.vehicleBrand || o.vehicleModel) fields.push({ label: 'Araç', value: `${o.vehicleBrand || ''} ${o.vehicleModel || ''}`.trim(), icon: 'i-lucide-car' })
  if (o.vehicleYear) fields.push({ label: 'Model Yılı', value: o.vehicleYear, icon: 'i-lucide-calendar' })
  if (o.registrationNo) fields.push({ label: 'Tescil No', value: o.registrationNo, icon: 'i-lucide-file-text' })
  if (o.uavtCode) fields.push({ label: 'UAVT Kodu', value: o.uavtCode, icon: 'i-lucide-home' })
  if (o.network) fields.push({ label: 'Network', value: o.network, icon: 'i-lucide-globe' })
  return fields
})

// Poliçe detay & zeyil slideover
const isDetailOpen = ref(false)
const detailPolicyId = ref<number | null>(null)
const isZeyilListOpen = ref(false)
const zeyilList = ref<any[]>([])
const zeyilLoading = ref(false)
const zeyilPolicyNo = ref('')

async function onPolicyClick(p: any) {
  if (p.zeyilCount > 0) {
    // Zeyili var → zeyil listesini ac
    zeyilPolicyNo.value = p.policyNo
    zeyilLoading.value = true
    isZeyilListOpen.value = true
    try {
      const res = await get(`policies/zeyil/${encodeURIComponent(p.policyNo)}`)
      zeyilList.value = res.data || []
    } catch {
      toast.add({ title: 'Zeyil listesi yüklenemedi', color: 'error' })
      zeyilList.value = []
    } finally {
      zeyilLoading.value = false
    }
  } else {
    // Zeyili yok → direkt detay ac
    cameFromZeyilList.value = false
    openPolicyDetail(p.id)
  }
}

function openPolicyDetail(id: number) {
  detailPolicyId.value = id
  isDetailOpen.value = true
}

const cameFromZeyilList = ref(false)

function onZeyilClick(z: any) {
  isZeyilListOpen.value = false
  cameFromZeyilList.value = true
  openPolicyDetail(z.id)
}

function backToZeyilList() {
  isDetailOpen.value = false
  cameFromZeyilList.value = false
  isZeyilListOpen.value = true
}

function onPolicyEdit(policy: any) {
  isDetailOpen.value = false
  openPolicyModal({ customerId: customer.value?.id, policy, onSaved: fetchCustomer })
}


async function fetchCustomer() {
  loading.value = true
  try {
    const res = await get(`customers/${route.params.id}`)
    customer.value = res.data || res
  } catch {
    toast.add({ title: 'Müşteri bulunamadı', color: 'error' })
    navigateTo('/musteriler')
  } finally {
    loading.value = false
  }
}

// Excel export
function exportPolicies() {
  const policies = activeTab.value === 'teklifler' ? customerOffers.value : tabPolicies.value
  if (!policies.length) {
    toast.add({ title: 'Aktarılacak veri yok', color: 'warning' })
    return
  }

  const { token } = useAuth()

  if (activeTab.value === 'teklifler') {
    // Teklifler: backend task export endpoint'ini kullan (OFFER + RENEWAL)
    const params = new URLSearchParams()
    params.set('customerId', String(route.params.id))
    params.set('type', 'OFFER')
    const url = `/api/tasks/export?${params.toString()}`
    fetch(url, { headers: { Authorization: `Bearer ${token.value}` } })
      .then(res => {
        const disposition = res.headers.get('Content-Disposition') || ''
        const match = disposition.match(/filename="?(.+?)"?$/)
        const filename = match ? match[1] : 'teklifler.xlsx'
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
  } else {
    const params = new URLSearchParams()
    params.set('customerId', String(route.params.id))
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
}

// Müşteri notu/yorum
const { post } = useApi()
const newComment = ref('')
const savingComment = ref(false)
const isEditingNote = ref(false)
const editNoteText = ref('')

async function saveComment() {
  if (!newComment.value.trim()) return
  savingComment.value = true
  try {
    await post(`customers/${route.params.id}/note`, { note: newComment.value.trim() })
    customer.value.note = newComment.value.trim()
    newComment.value = ''
    toast.add({ title: 'Not kaydedildi', color: 'success' })
  } catch {
    toast.add({ title: 'Not kaydedilemedi', color: 'error' })
  } finally {
    savingComment.value = false
  }
}

function startEditNote() {
  editNoteText.value = customer.value?.note || ''
  isEditingNote.value = true
}

function cancelEditNote() {
  isEditingNote.value = false
  editNoteText.value = ''
}

async function updateNote() {
  if (!editNoteText.value.trim()) return
  savingComment.value = true
  try {
    await post(`customers/${route.params.id}/note`, { note: editNoteText.value.trim() })
    customer.value.note = editNoteText.value.trim()
    isEditingNote.value = false
    toast.add({ title: 'Not güncellendi', color: 'success' })
  } catch {
    toast.add({ title: 'Not güncellenemedi', color: 'error' })
  } finally {
    savingComment.value = false
  }
}

async function deleteNote() {
  savingComment.value = true
  try {
    await post(`customers/${route.params.id}/note`, { note: '' })
    customer.value.note = null
    toast.add({ title: 'Not silindi', color: 'success' })
  } catch {
    toast.add({ title: 'Not silinemedi', color: 'error' })
  } finally {
    savingComment.value = false
  }
}

// Müşteri notları / arama geçmişi
interface CustomerNote {
  id: number
  customerId: number
  type: 'CALL' | 'NOTE' | 'SYSTEM'
  note: string
  taskId: number | null
  policyId: number | null
  policyNo: string | null
  insuranceName: string | null
  createdBy: number
  createdByName: string | null
  createdAt: string
}

const customerNotes = ref<CustomerNote[]>([])
const loadingNotes = ref(false)
const newCustomerNote = ref('')
const savingCustomerNote = ref(false)
const notesTab = ref<'notlar' | 'gecmis'>('notlar')

const manualNotes = computed(() => customerNotes.value.filter(cn => cn.type === 'NOTE'))
const activityNotes = computed(() => customerNotes.value.filter(cn => cn.type === 'CALL' || cn.type === 'TASK'))

// Backend'de resultLabels ile yazılan olumsuz sonuç ifadeleri
const NEGATIVE_TASK_KEYWORDS = ['Yenilenmedi', 'Teklif onaylanmadi', 'Satış Yapılamadı']
function isNegativeTaskNote(note: string): boolean {
  return NEGATIVE_TASK_KEYWORDS.some(kw => note.includes(kw))
}

// Görev tipi etiketleri
const TASK_TYPE_LABELS: Record<string, string> = {
  RENEWAL: 'Yenileme Görevi',
  FOLLOW_UP_CALL: 'Takip Araması',
  OFFER: 'Teklif',
  CROSS_SELL: 'Çapraz Satış',
  OTHER: 'Diğer Görev',
  REFERENCE: 'Referans',
}

// Görev bazlı gruplama
const expandedTaskKeys = ref<Set<string>>(new Set())
const showAllTasks = ref(false)
const TASK_PREVIEW_COUNT = 5

function toggleTaskGroup(key: string) {
  if (expandedTaskKeys.value.has(key)) {
    expandedTaskKeys.value.delete(key)
  } else {
    expandedTaskKeys.value.add(key)
  }
  expandedTaskKeys.value = new Set(expandedTaskKeys.value)
}

const groupedActivityNotes = computed(() => {
  const groups: Record<string, any> = {}
  for (const cn of activityNotes.value) {
    const key = cn.taskId ? `task-${cn.taskId}` : `note-${cn.id}`
    if (!groups[key]) {
      groups[key] = {
        key,
        taskId: cn.taskId,
        type: cn.type,
        taskType: cn.taskType,
        stageLabel: cn.stageLabel,
        insuranceName: cn.insuranceName,
        policyNo: cn.policyNo,
        plateNo: cn.plateNo,
        assignedToName: cn.assignedToName,
        taskStatus: cn.taskStatus,
        taskResult: cn.taskResult,
        notes: [],
        lastDate: cn.createdAt,
      }
    }
    groups[key].notes.push(cn)
    if (cn.createdAt > groups[key].lastDate) groups[key].lastDate = cn.createdAt
  }
  return Object.values(groups).sort((a: any, b: any) => b.lastDate.localeCompare(a.lastDate))
})

const visibleTaskGroups = computed(() =>
  showAllTasks.value ? groupedActivityNotes.value : groupedActivityNotes.value.slice(0, TASK_PREVIEW_COUNT)
)

// Otomatik üretilen notu parse et: "... Sonuç: X. Not: Y."
function parseAutoNote(note: string): { result: string; userNote: string } | null {
  const match = note.match(/Sonuç:\s*([^.]+)\.\s*Not:\s*(.+)/s)
  if (!match) return null
  return { result: match[1].trim(), userNote: match[2].trim() }
}

function taskStatusBadge(status: string | null, result: string | null) {
  if (!status) return null
  if (status === 'COMPLETED') {
    if (result === 'DONE') return { label: 'Satış Yapıldı', color: 'success' }
    if (result === 'FAILED') return { label: 'Olumsuz', color: 'error' }
    return { label: 'Tamamlandı', color: 'neutral' }
  }
  if (status === 'CANCELLED') return { label: 'İptal', color: 'warning' }
  if (status === 'IN_PROGRESS') return { label: 'Devam Ediyor', color: 'primary' }
  return { label: 'Bekliyor', color: 'neutral' }
}

async function fetchCustomerNotes() {
  loadingNotes.value = true
  try {
    const res = await get(`customers/${route.params.id}/notes`)
    customerNotes.value = res.data || []
  } catch {}
  loadingNotes.value = false
}

async function addCustomerNote() {
  if (!newCustomerNote.value.trim()) return
  savingCustomerNote.value = true
  try {
    await post(`customers/${route.params.id}/notes`, { note: newCustomerNote.value.trim() })
    newCustomerNote.value = ''
    await fetchCustomerNotes()
    toast.add({ title: 'Not eklendi', color: 'success' })
  } catch {
    toast.add({ title: 'Not eklenemedi', color: 'error' })
  } finally {
    savingCustomerNote.value = false
  }
}

onMounted(() => {
  fetchCustomer()
  fetchCategories()
  fetchCustomerOffers()
  fetchCustomerNotes()
})

useSeoMeta({ title: computed(() => customer.value?.name || 'Müşteri Detay') })

const isIndividual = computed(() => customer.value?.customerType === 'INDIVIDUAL')

const category = computed(() => getCategoryById(customer.value?.categoryId))

const stats = computed(() => {
  const s = customer.value?.stats
  return [
    { label: 'Aktif Prim', value: formatCurrency(activePolicies.value.reduce((sum: number, p: any) => sum + (p.totalGrossPremium ?? p.grossPremium ?? 0), 0)), icon: 'i-lucide-banknote', color: 'text-purple-500', bg: 'bg-purple-50 dark:bg-purple-900/30' },
    { label: 'Aktif Poliçe', value: String(s?.activePolicies ?? 0), icon: 'i-lucide-shield-check', color: 'text-green-500', bg: 'bg-green-50 dark:bg-green-900/30' },
    { label: 'Sonlanan Poliçe', value: String(s?.expiredPolicies ?? 0), icon: 'i-lucide-clock', color: 'text-orange-500', bg: 'bg-orange-50 dark:bg-orange-900/30', mobileHide: true },
    { label: 'İptal Poliçe', value: String(s?.cancelledPolicies ?? 0), icon: 'i-lucide-x-circle', color: 'text-red-500', bg: 'bg-red-50 dark:bg-red-900/30', mobileHide: true },
    { label: 'Teklifler', value: String(customerOffers.value.length), icon: 'i-lucide-file-text', color: 'text-blue-500', bg: 'bg-blue-50 dark:bg-blue-900/30' }
  ]
})

// --- Portal Grup Şirketi ---
const selectedGroupCustomer = ref<any>(null)
const addingGroupMember = ref(false)
const removingGroupMember = ref<number | null>(null)
const groupSearchQuery = ref('')
const groupSearchResults = ref<any[]>([])
let groupSearchTimeout: any = null

function onGroupSearch() {
  clearTimeout(groupSearchTimeout)
  const q = groupSearchQuery.value.trim()
  if (q.length < 2) { groupSearchResults.value = []; return }
  groupSearchTimeout = setTimeout(async () => {
    try {
      const res = await get('customers/search', { q, limit: 10 })
      const data = res.data || res || []
      groupSearchResults.value = data.filter((c: any) =>
        c.id !== Number(route.params.id) &&
        !customer.value?.portalGroupMembers?.some((m: any) => m.id === c.id)
      )
    } catch { groupSearchResults.value = [] }
  }, 300)
}

function selectGroupCustomer(item: any) {
  selectedGroupCustomer.value = item
  groupSearchQuery.value = item.name
  groupSearchResults.value = []
}

async function addGroupMember() {
  if (!selectedGroupCustomer.value?.id) return
  addingGroupMember.value = true
  try {
    const { post } = useApi()
    await post(`customers/${route.params.id}/portal-group`, { customerId: selectedGroupCustomer.value.id })
    toast.add({ title: 'Grup şirketi eklendi', color: 'success' })
    selectedGroupCustomer.value = null
    groupSearchQuery.value = ''
    await fetchCustomer()
  } catch (err: any) {
    toast.add({ title: err?.data?.message || 'Eklenemedi', color: 'error' })
  }
  addingGroupMember.value = false
}

async function removeGroupMember(memberId: number) {
  removingGroupMember.value = memberId
  try {
    await apiDel(`customers/${route.params.id}/portal-group/${memberId}`)
    toast.add({ title: 'Müşteri gruptan çıkarıldı', color: 'success' })
    await fetchCustomer()
  } catch (err: any) {
    toast.add({ title: err?.data?.message || 'Çıkarılamadı', color: 'error' })
  }
  removingGroupMember.value = null
}

// Bilgi gridi
const infoFields = computed(() => {
  if (!customer.value) return []
  const c = customer.value
  const fields: { label: string; value: string; icon?: string }[] = []

  if (isIndividual.value) {
    fields.push({ label: 'TC Kimlik No', value: c.identityNo, icon: 'i-lucide-fingerprint' })
  } else {
    fields.push({ label: 'Vergi Numarasi', value: c.identityNo, icon: 'i-lucide-hash' })
  }

  fields.push({ label: 'Telefon', value: c.phone, icon: 'i-lucide-phone' })

  if (c.phoneAlt) fields.push({ label: 'İkinci Telefon', value: c.phoneAlt, icon: 'i-lucide-phone' })
  if (c.email) fields.push({ label: 'E-posta', value: c.email, icon: 'i-lucide-mail' })

  if (isIndividual.value) {
    if (c.birthDate) fields.push({ label: 'Doğum Tarihi', value: formatDate(c.birthDate), icon: 'i-lucide-cake' })
    if (c.job) fields.push({ label: 'Meslek', value: c.job, icon: 'i-lucide-briefcase' })
    if (c.maritalStatus) fields.push({ label: 'Medeni Durum', value: c.maritalStatus, icon: 'i-lucide-heart' })
    if (c.dependentsCount != null) fields.push({ label: 'Çocuk Sayısı', value: String(c.dependentsCount), icon: 'i-lucide-baby' })
  } else {
    if (c.taxOffice) fields.push({ label: 'Vergi Dairesi', value: c.taxOffice, icon: 'i-lucide-landmark' })
    if (c.contactPerson) fields.push({ label: 'Yetkili Kişi', value: c.contactPerson, icon: 'i-lucide-contact' })
    if (c.sector) fields.push({ label: 'Sektor', value: c.sector, icon: 'i-lucide-factory' })
    if (c.dependentsCount != null) fields.push({ label: 'Calisan Sayısı', value: String(c.dependentsCount), icon: 'i-lucide-users' })
  }

  // Araç durumu
  const vehicleLabels: Record<string, string> = { YES: 'Var', NO: 'Yok', UNKNOWN: 'Bilinmiyor' }
  const vehicleLabel = vehicleLabels[c.hasVehicle] || 'Bilinmiyor'
  const vehicleInfo = c.hasVehicleCheckedAt ? vehicleLabel + ' (' + formatDate(c.hasVehicleCheckedAt) + ')' : vehicleLabel
  fields.push({ label: 'Araç Durumu', value: vehicleInfo, icon: 'i-lucide-car' })

  if (c.address) fields.push({ label: 'Adres', value: c.address, icon: 'i-lucide-map-pin', multiline: true })
  if (c.createdAt) fields.push({ label: 'Kayıt Tarihi', value: formatDate(c.createdAt), icon: 'i-lucide-calendar' })

  return fields
})

function getRemainingDays(expiresAt: string): number | null {
  if (!expiresAt) return null
  const now = new Date()
  now.setHours(0, 0, 0, 0)
  const exp = new Date(expiresAt)
  exp.setHours(0, 0, 0, 0)
  return Math.ceil((exp.getTime() - now.getTime()) / (1000 * 60 * 60 * 24))
}

function getRemainingColor(days: number): string {
  if (days <= 0) return 'error'
  if (days <= 30) return 'warning'
  if (days <= 90) return 'primary'
  return 'success'
}

function truncateText(text: string, max: number = 30): string {
  if (!text || text.length <= max) return text
  return text.slice(0, max) + '...'
}

function formatDate(d: string) {
  if (!d) return '-'
  try {
    return new Date(d).toLocaleDateString('tr-TR')
  } catch {
    return d
  }
}

async function deleteCustomer() {
  if (!customer.value) return
  try {
    await apiDel(`customers/${customer.value.id}`)
    toast.add({ title: 'Müşteri silindi', color: 'success' })
    navigateTo('/musteriler')
  } catch {
    toast.add({ title: 'Silinemedi', color: 'error' })
  }
  isDeleteModalOpen.value = false
}
</script>

<template>
  <div v-if="loading" class="space-y-5">
    <SkeletonCard>
      <div class="flex items-start gap-4">
        <div class="size-16 rounded-full bg-gray-200 dark:bg-gray-700 shrink-0" />
        <div class="flex-1 space-y-3">
          <div class="h-5 bg-gray-200 dark:bg-gray-700 rounded w-48" />
          <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-32" />
          <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-64" />
        </div>
      </div>
    </SkeletonCard>
    <SkeletonCard>
      <SkeletonTable :rows="4" :cols="6" />
    </SkeletonCard>
  </div>

  <div v-else-if="customer" class="space-y-5">
    <!-- Profil Karti -->
    <UCard>
      <div class="flex items-start justify-between gap-4">
        <!-- Avatar + Bilgi -->
        <div class="flex items-center gap-3 min-w-0">
          <div class="size-12 rounded-2xl flex items-center justify-center shrink-0" :class="isIndividual ? 'bg-primary/10' : 'bg-amber-500/10'">
            <UIcon :name="isIndividual ? 'i-lucide-user' : 'i-lucide-building-2'" class="size-6" :class="isIndividual ? 'text-primary' : 'text-amber-500'" />
          </div>
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-lg font-bold leading-tight">{{ customer.name }}</h2>
              <UBadge :color="isIndividual ? 'primary' : 'warning'" variant="solid" size="sm">
                {{ isIndividual ? 'Bireysel' : 'Kurumsal' }}
              </UBadge>
              <UBadge v-if="category" variant="solid" size="sm" :style="{ backgroundColor: category.color + '20', color: category.color }">
                {{ category.name }}
              </UBadge>
            </div>
            <div class="flex flex-wrap items-center gap-3 mt-1.5 text-sm text-muted">
              <span class="flex items-center gap-1">
                <UIcon name="i-lucide-fingerprint" class="size-3.5 shrink-0" />
                {{ customer.identityNo }}
              </span>
              <span v-if="isIndividual && customer.birthDate" class="flex items-center gap-1">
                <UIcon name="i-lucide-cake" class="size-3.5 shrink-0" />
                {{ formatDate(customer.birthDate) }}
              </span>
              <span v-if="customer.phone" class="flex items-center gap-1">
                <UIcon name="i-lucide-phone" class="size-3.5 shrink-0" />
                {{ customer.phone }}
              </span>
              <span v-if="customer.email" class="hidden sm:flex items-center gap-1">
                <UIcon name="i-lucide-mail" class="size-3.5 shrink-0" />
                {{ customer.email }}
              </span>
            </div>
          </div>
        </div>

        <!-- Aksiyonlar -->
        <div class="hidden sm:flex items-center gap-1.5 shrink-0">
          <UButton icon="i-lucide-download" size="sm" variant="outline" color="neutral" @click="exportPolicies()">
            <span class="hidden sm:inline">Excel</span>
          </UButton>
          <UButton icon="i-lucide-pencil" size="sm" variant="outline" color="neutral" @click="openCustomerModal({ customer: customer, onSaved: fetchCustomer })">
            <span class="hidden sm:inline">Düzenle</span>
          </UButton>
          <UButton icon="i-lucide-trash-2" size="sm" color="error" variant="ghost" @click="isDeleteModalOpen = true" />
        </div>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 mt-4 pt-4 border-t border-default [&>*:last-child:nth-child(odd)]:col-span-2 sm:[&>*:last-child:nth-child(odd)]:col-span-1">
        <div
          v-for="stat in stats"
          :key="stat.label"
          class="flex items-center gap-2.5 p-2.5 rounded-xl border border-default bg-gray-50 dark:bg-gray-800/40"
          :class="stat.mobileHide ? 'hidden sm:flex' : 'flex'"
        >
          <div class="size-8 rounded-lg flex items-center justify-center shrink-0" :class="stat.bg">
            <UIcon :name="stat.icon" :class="[stat.color, 'size-4']" />
          </div>
          <div class="min-w-0">
            <p class="text-sm font-bold leading-tight" :class="stat.color">{{ stat.value }}</p>
            <p class="text-xs text-muted leading-tight mt-0.5">{{ stat.label }}</p>
          </div>
        </div>
      </div>
    </UCard>

    <!-- Tablar -->
    <!-- Mobil Tabs -->
    <UTabs
      class="sm:hidden"
      :items="[
        { label: `Aktif (${customer.stats?.activePolicies ?? 0})`, value: 'aktif', icon: 'i-lucide-shield-check' },
        { label: `Sonlanan (${customer.stats?.expiredPolicies ?? 0})`, value: 'dolmus', icon: 'i-lucide-clock' },
        { label: `İptal (${customer.stats?.cancelledPolicies ?? 0})`, value: 'iptal', icon: 'i-lucide-x-circle' },
        { label: `Teklifler (${customerOffers.length})`, value: 'teklifler', icon: 'i-lucide-file-text' },
        { label: 'Dosyalar', value: 'dosyalar', icon: 'i-lucide-folder' },
      ]"
      :model-value="activeTab === 'genel' ? 'aktif' : activeTab"
      @update:model-value="activeTab = $event as string"
      :ui="{ list: 'overflow-x-auto flex-nowrap [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden', trigger: 'shrink-0' }"
    />
    <!-- Masaüstü Tabs (tümü) -->
    <UTabs
      class="hidden sm:block"
      :items="[
        { label: 'Genel', value: 'genel', icon: 'i-lucide-user' },
        { label: `Aktif (${customer.stats?.activePolicies ?? 0})`, value: 'aktif', icon: 'i-lucide-shield-check' },
        { label: `Sonlanan (${customer.stats?.expiredPolicies ?? 0})`, value: 'dolmus', icon: 'i-lucide-clock' },
        { label: `İptal (${customer.stats?.cancelledPolicies ?? 0})`, value: 'iptal', icon: 'i-lucide-x-circle' },
        { label: `Teklifler (${customerOffers.length})`, value: 'teklifler', icon: 'i-lucide-file-text' },
        { label: 'Dosyalar', value: 'dosyalar', icon: 'i-lucide-folder' },
      ]"
      :model-value="activeTab"
      @update:model-value="activeTab = $event as string"
    />

    <!-- Dosyalar sekmesi -->
    <UCard v-if="activeTab === 'dosyalar'">
      <template #header>
        <div class="flex items-center gap-2">
          <UIcon name="i-lucide-folder" class="size-4 text-primary" />
          <h3 class="font-semibold">Müşteri Dosyaları</h3>
        </div>
      </template>
      <DocumentsSection :customer-id="Number(route.params.id)" />
    </UCard>

    <!-- Genel Bilgiler -->
    <UCard v-if="activeTab === 'genel'">
      <template #header>
        <div class="flex items-center gap-2">
          <UIcon name="i-lucide-info" class="size-4 text-primary" />
          <h3 class="font-semibold">{{ isIndividual ? 'Kişisel Bilgiler' : 'Firma Bilgileri' }}</h3>
        </div>
      </template>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="field in infoFields"
          :key="field.label"
          class="flex items-start gap-3 p-3 rounded-lg bg-gray-50 dark:bg-gray-800/50"
        >
          <UIcon v-if="field.icon" :name="field.icon" class="size-4 text-muted mt-0.5 shrink-0" />
          <div class="min-w-0">
            <p class="text-xs text-muted">{{ field.label }}</p>
            <p class="font-medium text-sm" :class="field.multiline ? 'whitespace-pre-line' : ''">{{ field.value || '-' }}</p>
          </div>
        </div>
      </div>

      <!-- Açıklama / Yorum -->
      <div class="mt-5 pt-4 border-t border-default">
        <div class="flex items-center gap-2 mb-2">
          <UIcon name="i-lucide-message-square" class="size-4 text-muted" />
          <p class="text-sm text-muted font-medium">Açıklama / Yorum</p>
        </div>

        <!-- Mevcut not: goruntule veya duzenle modu -->
        <div v-if="customer.note && !isEditingNote" class="group relative bg-gray-50 dark:bg-gray-800/50 p-3 rounded-lg mb-3">
          <p class="text-sm whitespace-pre-wrap pr-16">{{ customer.note }}</p>
          <div class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
            <UButton
              icon="i-lucide-pencil"
              size="xs"
              color="neutral"
              variant="ghost"
              :disabled="savingComment"
              @click="startEditNote()"
            />
            <UButton
              icon="i-lucide-trash-2"
              size="xs"
              color="error"
              variant="ghost"
              :loading="savingComment"
              @click="deleteNote()"
            />
          </div>
        </div>

        <!-- Duzenleme modu -->
        <div v-else-if="isEditingNote" class="mb-3">
          <UTextarea v-model="editNoteText" :rows="3" placeholder="Notu düzenle..." class="w-full" />
          <div class="flex gap-2 mt-2 justify-end">
            <UButton label="Vazgeç" size="xs" color="neutral" variant="outline" :disabled="savingComment" @click="cancelEditNote()" />
            <UButton label="Kaydet" size="xs" color="primary" :loading="savingComment" :disabled="!editNoteText.trim()" @click="updateNote()" />
          </div>
        </div>

        <!-- Yeni not ekleme (not yoksa goster) -->
        <div v-if="!customer.note && !isEditingNote" class="flex gap-2">
          <UTextarea v-model="newComment" :rows="2" placeholder="Yorum ekle..." class="flex-1" />
          <UButton
            icon="i-lucide-send"
            :loading="savingComment"
            :disabled="!newComment.trim()"
            size="sm"
            class="self-end"
            @click="saveComment()"
          />
        </div>
      </div>
    </UCard>

    <!-- Portal Grup Şirketleri (sadece Genel Bilgiler tabında) -->
    <UCard v-if="activeTab === 'genel'">
      <template #header>
        <div class="flex items-center gap-2">
          <UIcon name="i-lucide-building-2" class="size-4 text-primary" />
          <h3 class="font-semibold">Grup Şirketi (Portal)</h3>
          <span class="text-xs text-muted font-normal">Portalda birlikte görünecek firmalar</span>
        </div>
      </template>

      <!-- Eklenen grup üyeleri -->
      <div v-if="customer.portalGroupMembers?.length" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
        <div
          v-for="member in customer.portalGroupMembers"
          :key="member.id"
          class="group flex items-center gap-3 p-3 rounded-xl border border-default bg-white dark:bg-gray-900 hover:shadow-sm transition-shadow"
        >
          <div class="size-9 rounded-lg flex items-center justify-center shrink-0" :class="member.customerType === 'CORPORATE' ? 'bg-blue-50 dark:bg-blue-900/20' : 'bg-green-50 dark:bg-green-900/20'">
            <UIcon :name="member.customerType === 'CORPORATE' ? 'i-lucide-building' : 'i-lucide-user'" class="size-4" :class="member.customerType === 'CORPORATE' ? 'text-blue-500' : 'text-green-500'" />
          </div>
          <div class="min-w-0 flex-1">
            <NuxtLink :to="`/musteriler/${member.id}`" class="text-sm font-medium text-primary hover:underline block truncate">{{ member.name }}</NuxtLink>
            <p v-if="member.identityNo" class="text-xs text-muted truncate">{{ member.identityNo }}</p>
          </div>
          <UButton
            icon="i-lucide-trash-2"
            size="2xs"
            color="error"
            variant="ghost"
            title="Gruptan çıkar"
            class="opacity-0 group-hover:opacity-100 transition-opacity shrink-0"
            :loading="removingGroupMember === member.id"
            @click="removeGroupMember(member.id)"
          />
        </div>
      </div>

      <!-- Arama ve ekleme -->
      <div class="flex gap-2 items-center">
        <div class="flex-1 max-w-sm">
          <UInput
            v-model="groupSearchQuery"
            placeholder="Firma adı veya TC/VKN yazarak ara..."
            icon="i-lucide-search"
            size="md"
            @input="onGroupSearch"
          />
        </div>
        <UButton
          icon="i-lucide-plus"
          label="Gruba Ekle"
          size="md"
          :disabled="!selectedGroupCustomer"
          :loading="addingGroupMember"
          @click="addGroupMember"
        />
      </div>
      <!-- Arama sonuçları (kart dışında sabit pozisyonda değil, inline liste) -->
      <div v-if="groupSearchResults.length" class="mt-2 max-w-sm border border-default rounded-xl bg-white dark:bg-gray-900 max-h-56 overflow-y-auto">
        <button
          v-for="item in groupSearchResults"
          :key="item.id"
          class="w-full text-left px-4 py-3 hover:bg-primary/5 dark:hover:bg-primary/10 border-b border-default last:border-0 transition-colors"
          @click="selectGroupCustomer(item)"
        >
          <div class="flex items-center gap-3">
            <div class="size-8 rounded-lg flex items-center justify-center shrink-0" :class="item.identityNo && item.identityNo.length === 11 ? 'bg-green-50 dark:bg-green-900/20' : 'bg-blue-50 dark:bg-blue-900/20'">
              <UIcon :name="item.identityNo && item.identityNo.length === 11 ? 'i-lucide-user' : 'i-lucide-building'" class="size-3.5" :class="item.identityNo && item.identityNo.length === 11 ? 'text-green-500' : 'text-blue-500'" />
            </div>
            <div class="min-w-0">
              <p class="text-sm font-medium truncate">{{ item.name }}</p>
              <p v-if="item.identityNo" class="text-[11px] text-muted">{{ item.identityNo }}</p>
            </div>
          </div>
        </button>
      </div>
      <p v-if="selectedGroupCustomer" class="mt-2 text-sm text-primary flex items-center gap-1.5">
        <UIcon name="i-lucide-check-circle" class="size-3.5" />
        <span class="font-medium">{{ selectedGroupCustomer.name }}</span> seçildi
      </p>
      <p v-else-if="!customer.portalGroupMembers?.length && !groupSearchResults.length" class="mt-2 text-xs text-muted">Grup şirketi eklemek için yukarıdan müşteri arayın.</p>
    </UCard>

    <!-- Notlar & Aktivite Geçmişi (sadece Genel Bilgiler tabında) -->
    <UCard v-if="activeTab === 'genel'">
      <template #header>
        <div class="flex items-center justify-between">
          <!-- Sekmeler -->
          <div class="flex gap-1">
            <button
              class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
              :class="notesTab === 'notlar' ? 'bg-primary text-white' : 'text-muted hover:bg-gray-100 dark:hover:bg-gray-800'"
              @click="notesTab = 'notlar'"
            >
              <UIcon name="i-lucide-message-square" class="size-3.5" />
              Müşteri Notları
              <UBadge v-if="manualNotes.length > 0" :label="String(manualNotes.length)" color="neutral" variant="solid" size="xs" class="ml-0.5" />
            </button>
            <button
              class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
              :class="notesTab === 'gecmis' ? 'bg-primary text-white' : 'text-muted hover:bg-gray-100 dark:hover:bg-gray-800'"
              @click="notesTab = 'gecmis'"
            >
              <UIcon name="i-lucide-history" class="size-3.5" />
              Görev & Arama Geçmişi
              <UBadge v-if="activityNotes.length > 0" :label="String(activityNotes.length)" color="neutral" variant="solid" size="xs" class="ml-0.5" />
            </button>
          </div>
        </div>
      </template>

      <div v-if="loadingNotes" class="py-4 text-center text-muted text-sm">Yükleniyor...</div>

      <!-- Müşteri Notları sekmesi -->
      <template v-else-if="notesTab === 'notlar'">
        <div class="flex gap-2 mb-3">
          <UInput v-model="newCustomerNote" placeholder="Not ekle..." class="flex-1" size="sm" @keydown.enter="addCustomerNote" />
          <UButton
            icon="i-lucide-plus"
            size="sm"
            :loading="savingCustomerNote"
            :disabled="!newCustomerNote.trim()"
            @click="addCustomerNote"
          />
        </div>
        <div v-if="manualNotes.length === 0" class="py-4 text-center text-muted text-sm">
          Henüz müşteri notu bulunmuyor.
        </div>
        <div v-else class="space-y-2 max-h-64 overflow-y-auto">
          <div
            v-for="cn in manualNotes"
            :key="cn.id"
            class="flex items-start gap-2.5 p-2.5 rounded-lg border border-default bg-gray-50/50 dark:bg-gray-800/30"
          >
            <UIcon name="i-lucide-message-square" class="size-4 mt-0.5 shrink-0 text-muted" />
            <div class="flex-1 min-w-0">
              <p class="text-sm leading-relaxed">{{ cn.note }}</p>
              <div class="flex items-center gap-2 mt-1 text-xs text-muted">
                <span>{{ cn.createdByName }}</span>
                <span>&middot;</span>
                <span>{{ new Date(cn.createdAt).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</span>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- Görev & Arama Geçmişi sekmesi -->
      <template v-else>
        <div v-if="groupedActivityNotes.length === 0" class="py-4 text-center text-muted text-sm">
          Henüz görev veya arama kaydı bulunmuyor.
        </div>
        <div v-else class="space-y-2">
          <div v-for="grp in visibleTaskGroups" :key="grp.key" class="rounded-lg border border-default overflow-hidden">
            <!-- Görev başlık satırı -->
            <button
              class="w-full grid items-center gap-x-3 px-3 py-2.5 text-left hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors h-14"
              style="grid-template-columns: 1.25rem 1fr 5.5rem 5.5rem 6rem 5.5rem 3rem 1.25rem"
              @click="toggleTaskGroup(grp.key)"
            >
              <!-- İkon -->
              <UIcon
                :name="grp.type === 'CALL' ? 'i-lucide-phone' : 'i-lucide-clipboard-list'"
                class="size-4"
                :class="grp.type === 'CALL' ? 'text-blue-500' : 'text-primary'"
              />
              <!-- Görev tipi + aşama -->
              <div class="min-w-0">
                <span class="text-sm font-semibold truncate block">
                  {{ grp.taskType ? TASK_TYPE_LABELS[grp.taskType] || grp.taskType : (grp.type === 'CALL' ? 'Arama Kaydı' : 'Görev Notu') }}
                </span>
                <span v-if="grp.stageLabel" class="text-[11px] text-muted truncate block">{{ grp.stageLabel }}</span>
              </div>
              <!-- Branş -->
              <span v-if="grp.insuranceName" class="text-xs px-1.5 py-0.5 rounded bg-primary/10 text-primary font-medium truncate text-center">{{ grp.insuranceName }}</span>
              <span v-else class="text-xs text-muted text-center">—</span>
              <!-- Plaka -->
              <span v-if="grp.plateNo" class="text-xs text-muted font-mono truncate">{{ grp.plateNo }}</span>
              <span v-else class="text-xs text-muted text-center">—</span>
              <!-- Temsilci -->
              <span class="text-xs text-muted truncate">{{ grp.assignedToName || '—' }}</span>
              <!-- Durum -->
              <div class="flex flex-col items-end gap-0.5">
                <UBadge
                  v-if="taskStatusBadge(grp.taskStatus, grp.taskResult)"
                  :label="taskStatusBadge(grp.taskStatus, grp.taskResult)!.label"
                  :color="taskStatusBadge(grp.taskStatus, grp.taskResult)!.color as any"
                  variant="soft" size="xs"
                />
                <span class="text-[11px] text-muted whitespace-nowrap">{{ new Date(grp.lastDate).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' }) }}</span>
              </div>
              <!-- Not sayısı -->
              <span class="text-xs text-muted text-center">{{ grp.notes.length }} not</span>
              <!-- Chevron -->
              <UIcon
                :name="expandedTaskKeys.has(grp.key) ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                class="size-4 text-muted"
              />
            </button>
            <!-- Notlar -->
            <div v-if="expandedTaskKeys.has(grp.key)" class="border-t border-default divide-y divide-default">
              <div
                v-for="cn in grp.notes"
                :key="cn.id"
                class="flex items-start gap-2 px-3 py-2 border-l-2"
                :class="parseAutoNote(cn.note)?.result.includes('Satış')
                  ? 'border-green-400 bg-green-50/40 dark:bg-green-950/20'
                  : parseAutoNote(cn.note)?.result && (parseAutoNote(cn.note)!.result.includes('Yapılamadı') || parseAutoNote(cn.note)!.result.includes('Olumsuz'))
                    ? 'border-red-400 bg-red-50/40 dark:bg-red-950/20'
                    : 'border-gray-200 dark:border-gray-700'"
              >
                <div class="flex-1 min-w-0">
                  <p class="text-sm">
                    {{ parseAutoNote(cn.note)?.userNote || cn.note }}
                  </p>
                  <p class="text-xs text-muted mt-0.5">
                    {{ cn.createdByName }} · {{ new Date(cn.createdAt).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
          <!-- Tümünü Gör / Gizle -->
          <button
            v-if="groupedActivityNotes.length > TASK_PREVIEW_COUNT"
            class="w-full mt-2 py-2 text-xs text-primary hover:underline flex items-center justify-center gap-1"
            @click="showAllTasks = !showAllTasks"
          >
            <UIcon :name="showAllTasks ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-3.5" />
            {{ showAllTasks ? 'Daha az göster' : `Tüm geçmişi gör (${groupedActivityNotes.length} görev)` }}
          </button>
      </template>
    </UCard>

    <!-- Poliçe Tablari (Aktif / Dolmus / İptal) -->
    <div v-if="activeTab === 'aktif' || activeTab === 'dolmus' || activeTab === 'iptal'" class="space-y-4">
      <!-- Arama + Ekle -->
      <div class="flex flex-wrap items-center gap-2">
        <UInput
          v-model="policySearch"
          icon="i-lucide-search"
          placeholder="Poliçe no, şirket veya plaka ara..."
          size="sm"
          class="flex-1 min-w-0"
        />
        <select
          v-model="policyTypeFilter"
          class="border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-900 text-sm px-2.5 py-1.5 text-gray-700 dark:text-gray-300 flex-1 sm:w-44 sm:flex-none focus:outline-none focus:ring-2 focus:ring-primary/50"
        >
          <option value="">Tüm Poliçeler</option>
          <option v-for="t in availablePolicyTypes" :key="t" :value="t">{{ t }}</option>
        </select>
        <div class="flex items-center border border-default rounded-lg overflow-hidden ml-auto">
          <button class="p-1.5 transition-colors" :class="policyViewMode === 'card' ? 'bg-primary text-white' : 'text-muted hover:bg-gray-100 dark:hover:bg-gray-800'" @click="policyViewMode = 'card'">
            <UIcon name="i-lucide-layout-grid" class="size-4" />
          </button>
          <button class="p-1.5 transition-colors" :class="policyViewMode === 'table' ? 'bg-primary text-white' : 'text-muted hover:bg-gray-100 dark:hover:bg-gray-800'" @click="policyViewMode = 'table'">
            <UIcon name="i-lucide-list" class="size-4" />
          </button>
        </div>
      </div>

      <!-- Bos durum -->
      <UCard v-if="!tabPolicies.length">
        <div class="text-center py-12 text-muted">
          <UIcon :name="currentTabMeta?.icon || 'i-lucide-shield-off'" class="size-10 mx-auto mb-3 opacity-40" />
          <p>{{ currentTabMeta?.emptyText || 'Poliçe bulunamadı' }}</p>
        </div>
      </UCard>

      <!-- Poliçe listesi - Kart görünümü -->
      <div v-else-if="policyViewMode === 'card'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <UCard
          v-for="p in tabPolicies"
          :key="p.id"
          class="cursor-pointer hover:ring-1 hover:ring-primary/30 transition-all"
          @click="onPolicyClick(p)"
        >
          <div class="space-y-3">
            <!-- Ust: Poliçe No + Plaka -->
            <div class="flex items-center justify-between">
              <span class="font-semibold text-sm">{{ p.policyNo }}</span>
              <span
                v-if="p.plateNo && (p.branchGroup === 'TRAFİK' || p.branchGroup === 'KASKO')"
                class="inline-flex items-center justify-center min-w-[120px] px-2.5 py-1 rounded border-2 font-mono font-bold text-sm tracking-wide whitespace-nowrap shrink-0"
                :class="p.branchGroup === 'TRAFİK' ? 'border-blue-600 bg-blue-50 text-blue-800 dark:border-blue-400 dark:bg-blue-950 dark:text-blue-200' : 'border-red-600 bg-red-50 text-red-800 dark:border-red-400 dark:bg-red-950 dark:text-red-200'"
              >
                {{ p.plateNo }}
              </span>
            </div>

            <!-- Sigorta & Şirket -->
            <div class="space-y-1 text-sm overflow-hidden">
              <div v-if="p.insuranceName" class="flex items-center gap-2 text-muted min-w-0">
                <UIcon name="i-lucide-shield" class="size-3.5 shrink-0" />
                <span class="truncate font-semibold text-default">{{ p.insuranceName }}</span>
              </div>
              <div v-if="p.companyName" class="flex items-center gap-2 text-muted min-w-0">
                <UIcon name="i-lucide-building" class="size-3.5 shrink-0" />
                <span class="truncate">{{ p.companyName }}</span>
              </div>
              <div class="flex items-center gap-2 text-muted min-w-0">
                <UIcon :name="p.branchName ? 'i-lucide-git-branch' : 'i-lucide-home'" class="size-3.5 shrink-0" />
                <span class="truncate">{{ p.branchName || agency.name }}</span>
              </div>
            </div>

            <!-- Alt: Tarih & Kalan Gün & Prim -->
            <div class="flex items-center justify-between pt-2 border-t border-default">
              <div class="flex items-center gap-1.5 text-xs text-muted">
                <UIcon name="i-lucide-calendar" class="size-3.5" />
                <span>{{ formatDate(p.startsAt) }} - {{ formatDate(p.effectiveExpiresAt || p.expiresAt) }}</span>
                <UBadge
                  v-if="getRemainingDays(p.effectiveExpiresAt || p.expiresAt) !== null"
                  :color="p.status === 'CANCELLED' ? 'error' : getRemainingColor(getRemainingDays(p.effectiveExpiresAt || p.expiresAt)!)"
                  variant="solid"
                  size="md"
                  class="font-semibold"
                >
                  {{ p.status === 'CANCELLED' ? 'Bitti' : (getRemainingDays(p.effectiveExpiresAt || p.expiresAt)! > 0 ? getRemainingDays(p.effectiveExpiresAt || p.expiresAt) + ' gün' : 'Bitti') }}
                </UBadge>
              </div>
              <p class="font-bold text-sm">{{ formatCurrency(p.totalGrossPremium ?? p.grossPremium) }}</p>
            </div>
          </div>
        </UCard>
      </div>

      <!-- Poliçe listesi - Tablo görünümü -->
      <UCard v-else-if="tabPolicies.length">
        <div class="border border-default rounded-lg overflow-hidden">
          <table class="text-xs w-full table-fixed">
            <thead class="sticky top-0 z-10">
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                <th class="hidden sm:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:15%">Poliçe No</th>
                <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:12%">Poliçe Türü</th>
                <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:12%">Şirket</th>
                <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:10%">Plaka</th>
                <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:10%">Başlangıç</th>
                <th class="hidden sm:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:10%">Bitiş</th>
                <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:10%">Kalan</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:12%">Brüt Prim</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="p in tabPolicies"
                :key="p.id"
                class="border-b border-default last:border-0 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors"
                @click="onPolicyClick(p)"
              >
                <td class="hidden sm:table-cell py-2 px-3 font-semibold tabular-nums">{{ p.policyNo }}</td>
                <td class="py-2 px-3">
                  {{ p.insuranceName }}
                  <div v-if="p.plateNo && (p.branchGroup === 'TRAFİK' || p.branchGroup === 'KASKO')" class="md:hidden font-mono font-bold text-muted mt-0.5">{{ p.plateNo }}</div>
                </td>
                <td class="hidden md:table-cell py-2 px-3 text-muted">{{ p.companyName }}</td>
                <td class="hidden md:table-cell py-2 px-3">
                  <span v-if="p.plateNo" class="font-mono font-bold tracking-wide">{{ p.plateNo }}</span>
                </td>
                <td class="hidden md:table-cell py-2 px-3 tabular-nums text-muted">{{ formatDate(p.startsAt) }}</td>
                <td class="hidden sm:table-cell py-2 px-3 tabular-nums text-muted">{{ formatDate(p.effectiveExpiresAt || p.expiresAt) }}</td>
                <td class="py-2 px-3 text-center">
                  <span
                    v-if="getRemainingDays(p.effectiveExpiresAt || p.expiresAt) !== null"
                    class="badge-cell"
                    :class="p.status === 'CANCELLED' ? 'badge-error' : getRemainingColor(getRemainingDays(p.effectiveExpiresAt || p.expiresAt)!) === 'error' ? 'badge-error' : getRemainingColor(getRemainingDays(p.effectiveExpiresAt || p.expiresAt)!) === 'warning' ? 'badge-warning' : 'badge-info'"
                  >
                    {{ p.status === 'CANCELLED' ? 'Bitti' : (getRemainingDays(p.effectiveExpiresAt || p.expiresAt)! > 0 ? getRemainingDays(p.effectiveExpiresAt || p.expiresAt) + ' gün' : 'Bitti') }}
                  </span>
                </td>
                <td class="py-2 px-3 text-right font-bold tabular-nums">{{ formatCurrency(p.totalGrossPremium ?? p.grossPremium) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </UCard>
    </div>

    <!-- Poliçe Detay Slideover -->
    <PolicyDetailSlideover
      v-model:open="isDetailOpen"
      :policy-id="detailPolicyId"
      :show-back-button="cameFromZeyilList"
      @edit="onPolicyEdit"
      @back="backToZeyilList"
    />

    <!-- Zeyil Listesi Slideover -->
    <USlideover v-model:open="isZeyilListOpen" :title="`Zeyil Geçmişi: ${zeyilPolicyNo}`" class="sm:max-w-lg">
      <template #body>
        <div v-if="zeyilLoading" class="animate-pulse space-y-3 py-4">
          <div v-for="i in 3" :key="i" class="h-16 bg-gray-200 dark:bg-gray-700 rounded-lg" />
        </div>

        <div v-else-if="zeyilList.length" class="space-y-2">
          <div
            v-for="z in zeyilList"
            :key="z.id"
            class="p-3 rounded-lg border border-default hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors cursor-pointer"
            @click="onZeyilClick(z)"
          >
            <div class="flex items-center justify-between mb-2">
              <div class="flex items-center gap-2">
                <UBadge v-if="z.endorsementNo === 0 || z.endorsementNo === 1" color="primary" variant="solid" size="sm">
                  Ana Poliçe
                </UBadge>
                <UBadge v-else variant="outline" size="sm" color="neutral">
                  Zeyil #{{ z.endorsementNo }}
                </UBadge>
                <UBadge v-if="z.isCancelled" color="error" variant="solid" size="sm">
                  İptal
                </UBadge>
              </div>
              <UIcon name="i-lucide-chevron-right" class="size-4 text-muted" />
            </div>

            <div class="flex items-center justify-between">
              <div class="text-xs text-muted space-y-0.5">
                <div class="flex items-center gap-1">
                  <UIcon name="i-lucide-calendar" class="size-3" />
                  <span>{{ formatDate(z.startsAt) }} - {{ formatDate(z.expiresAt) }}</span>
                </div>
                <div v-if="z.issuedAt" class="flex items-center gap-1">
                  <UIcon name="i-lucide-calendar-check" class="size-3" />
                  <span>Tanzim: {{ formatDate(z.issuedAt) }}</span>
                </div>
              </div>
              <div class="text-right">
                <p class="font-bold text-sm">{{ formatCurrency(z.grossPremium) }}</p>
                <p class="text-xs text-muted">{{ formatCurrency(z.netPremium) }} net</p>
              </div>
            </div>
          </div>
        </div>

        <div v-else class="text-center py-16 text-muted">
          <p>Zeyil bulunamadı</p>
        </div>
      </template>
    </USlideover>

    <!-- Teklifler Tab -->
    <div v-if="activeTab === 'teklifler'" class="space-y-4">
      <UCard v-if="offersLoading">
        <SkeletonTable :rows="3" :cols="5" />
      </UCard>

      <UCard v-else-if="!customerOffers.length">
        <div class="text-center py-12 text-muted">
          <UIcon name="i-lucide-file-x" class="size-10 mx-auto mb-3 opacity-40" />
          <p>Bu müşteriye ait teklif bulunmuyor</p>
        </div>
      </UCard>

      <template v-else>
        <!-- Alt Tab -->
        <UTabs
          :items="[
            { label: `Aktif (${activeOffers.length})`, value: 'active', icon: 'i-lucide-clock' },
            { label: `Geçmiş (${pastOffers.length})`, value: 'past', icon: 'i-lucide-archive' },
          ]"
          :model-value="offerSubTab"
          @update:model-value="offerSubTab = $event as 'active' | 'past'"
        />

        <!-- Arama + Filtre + Görünüm -->
        <div class="flex flex-wrap items-center gap-2 mt-3">
          <UInput
            v-model="offerSearch"
            icon="i-lucide-search"
            placeholder="Sigorta türü, plaka veya teklif no ara..."
            size="sm"
            class="flex-1 min-w-0"
          />
          <select
            v-model="offerTypeFilter"
            class="border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-900 text-sm px-2.5 py-1.5 text-gray-700 dark:text-gray-300 flex-1 sm:w-44 sm:flex-none focus:outline-none focus:ring-2 focus:ring-primary/50"
          >
            <option value="">Tüm Türler</option>
            <option v-for="t in availableOfferTypes" :key="t" :value="t">{{ t }}</option>
          </select>
          <div class="flex items-center border border-default rounded-lg overflow-hidden ml-auto">
            <button class="p-1.5 transition-colors" :class="policyViewMode === 'card' ? 'bg-primary text-white' : 'text-muted hover:bg-gray-100 dark:hover:bg-gray-800'" @click="policyViewMode = 'card'">
              <UIcon name="i-lucide-layout-grid" class="size-4" />
            </button>
            <button class="p-1.5 transition-colors" :class="policyViewMode === 'table' ? 'bg-primary text-white' : 'text-muted hover:bg-gray-100 dark:hover:bg-gray-800'" @click="policyViewMode = 'table'">
              <UIcon name="i-lucide-list" class="size-4" />
            </button>
          </div>
        </div>

        <!-- Aktif / Geçmiş içerik -->
        <div v-for="tab in (['active', 'past'] as const)" :key="tab">
          <div v-if="offerSubTab === tab">
            <template v-if="(tab === 'active' ? activeOffers : pastOffers).length">
              <!-- Kart görünümü -->
              <div v-if="policyViewMode === 'card'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <UCard
                  v-for="offer in (tab === 'active' ? activeOffers : pastOffers)"
                  :key="offer.id"
                  :class="['cursor-pointer hover:ring-1 hover:ring-primary/30 transition-all', tab === 'past' ? 'opacity-70' : '']"
                  @click="openOfferDetail(offer)"
                >
                  <div class="space-y-3">
                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <UBadge v-if="offer.type === 'RENEWAL'" color="info" variant="solid" size="sm">Yenileme</UBadge>
                        <UBadge :color="offerStatusColors[offer.status] || 'neutral'" variant="solid" size="sm">
                          {{ offerStatusLabels[offer.status] || offer.status }}
                        </UBadge>
                        <UBadge v-if="offer.result" :color="offer.result === 'OFFER_APPROVED' ? 'success' : 'error'" variant="solid" size="sm">
                          {{ offerResultLabels[offer.result] || offer.result }}
                        </UBadge>
                      </div>
                      <span
                        v-if="getOfferPlateNo(offer)"
                        class="inline-flex items-center justify-center min-w-[120px] px-2.5 py-1 rounded border-2 font-mono font-bold text-sm tracking-wide whitespace-nowrap shrink-0 border-blue-600 bg-blue-50 text-blue-800 dark:border-blue-400 dark:bg-blue-950 dark:text-blue-200"
                      >
                        {{ getOfferPlateNo(offer) }}
                      </span>
                    </div>
                    <div class="space-y-1 text-sm overflow-hidden">
                      <div v-if="getOfferInsuranceName(offer)" class="flex items-center gap-2 text-muted min-w-0">
                        <UIcon name="i-lucide-shield" class="size-3.5 shrink-0" />
                        <span class="truncate font-semibold text-default">{{ getOfferInsuranceName(offer) }}</span>
                      </div>
                      <div v-if="offer.type === 'RENEWAL' && offer.companyName" class="flex items-center gap-2 text-muted min-w-0">
                        <UIcon name="i-lucide-building" class="size-3.5 shrink-0" />
                        <span class="truncate">{{ offer.companyName }}</span>
                      </div>
                      <div v-else class="flex items-center gap-2 text-muted min-w-0">
                        <UIcon name="i-lucide-home" class="size-3.5 shrink-0" />
                        <span class="truncate">{{ agency.name }}</span>
                      </div>
                      <div v-if="offer.assignedToName" class="flex items-center gap-2 text-muted min-w-0">
                        <UIcon name="i-lucide-user" class="size-3.5 shrink-0" />
                        <span class="truncate">{{ offer.assignedToName }}</span>
                      </div>
                    </div>
                    <div class="flex items-center justify-between pt-2 border-t border-default">
                      <div v-if="getOfferExpiresAt(offer)" class="flex items-center gap-1 text-xs text-muted">
                        <UIcon name="i-lucide-calendar" class="size-3.5" />
                        <span>{{ offer.type === 'RENEWAL' ? 'Görev: ' : 'Bitiş: ' }}{{ formatDate(getOfferExpiresAt(offer)) }}</span>
                      </div>
                      <div v-if="offer.createdAt" class="text-xs text-muted">
                        {{ formatDate(offer.createdAt) }}
                      </div>
                    </div>
                  </div>
                </UCard>
              </div>

              <!-- Tablo görünümü -->
              <UCard v-else>
                <div class="border border-default rounded-lg overflow-hidden">
                  <table class="text-xs w-full table-fixed">
                    <thead class="sticky top-0 z-10">
                      <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                        <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:20%">Poliçe Türü</th>
                        <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:12%">Plaka</th>
                        <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:12%">Durum</th>
                        <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:12%">Sonuç</th>
                        <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:12%">Temsilci</th>
                        <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:12%">Bitiş</th>
                        <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted" style="width:12%">Oluşturma</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr
                        v-for="offer in (tab === 'active' ? activeOffers : pastOffers)"
                        :key="offer.id"
                        :class="['border-b border-default last:border-0 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors', tab === 'past' ? 'opacity-70' : '']"
                        @click="openOfferDetail(offer)"
                      >
                        <td class="py-2 px-3 font-semibold">
                          <div class="flex items-center gap-1.5">
                            <span v-if="offer.type === 'RENEWAL'" class="badge-cell badge-info">Yenileme</span>
                            {{ getOfferInsuranceName(offer) || '-' }}
                          </div>
                        </td>
                        <td class="hidden md:table-cell py-2 px-3">
                          <span v-if="getOfferPlateNo(offer)" class="font-mono font-bold tracking-wide">{{ getOfferPlateNo(offer) }}</span>
                          <span v-else class="text-muted">-</span>
                        </td>
                        <td class="py-2 px-3">
                          <span class="badge-cell" :class="'badge-' + (offerStatusColors[offer.status] || 'neutral')">
                            {{ offerStatusLabels[offer.status] || offer.status }}
                          </span>
                        </td>
                        <td class="py-2 px-3">
                          <span v-if="offer.result" class="badge-cell" :class="offer.result === 'OFFER_APPROVED' ? 'badge-success' : 'badge-error'">
                            {{ offerResultLabels[offer.result] || offer.result }}
                          </span>
                          <span v-else class="text-muted">-</span>
                        </td>
                        <td class="hidden md:table-cell py-2 px-3 text-muted">{{ offer.assignedToName || '-' }}</td>
                        <td class="hidden md:table-cell py-2 px-3 tabular-nums text-muted">{{ getOfferExpiresAt(offer) ? formatDate(getOfferExpiresAt(offer)) : '-' }}</td>
                        <td class="hidden md:table-cell py-2 px-3 tabular-nums text-muted">{{ offer.createdAt ? formatDate(offer.createdAt) : '-' }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </UCard>
            </template>

            <!-- Boş durum -->
            <UCard v-else>
              <div class="text-center py-8 text-muted">
                <UIcon :name="tab === 'active' ? 'i-lucide-check-circle' : 'i-lucide-archive'" class="size-8 mx-auto mb-2 opacity-40" />
                <p class="text-sm">{{ tab === 'active' ? 'Aktif teklif bulunmuyor' : 'Geçmiş teklif bulunmuyor' }}</p>
              </div>
            </UCard>
          </div>
        </div>
      </template>
    </div>

    <!-- Teklif Detay Slideover -->
    <USlideover v-model:open="isOfferDetailOpen" :title="selectedOffer?.title || 'Teklif Detay'" class="sm:max-w-lg">
      <template #header>
        <div class="flex items-center justify-between w-full">
          <span class="font-semibold">{{ selectedOffer?.title || 'Teklif Detay' }}</span>
          <div class="flex items-center gap-1.5">
            <UButton
              v-if="selectedOffer && selectedOffer.status !== 'COMPLETED' && selectedOffer.status !== 'CANCELLED'"
              label="Düzenle"
              icon="i-lucide-pencil"
              color="primary"
              variant="soft"
              size="xs"
              @click="openEditOffer(selectedOffer)"
            />
            <UButton
              v-if="selectedOffer"
              icon="i-lucide-trash-2"
              color="error"
              variant="ghost"
              size="xs"
              title="Teklifi Sil"
              @click="confirmDeleteOffer(selectedOffer)"
            />
          </div>
        </div>
      </template>
      <template #body>
        <div v-if="selectedOffer" class="space-y-4">
          <!-- Durum -->
          <div class="flex items-center gap-2 flex-wrap">
            <UBadge :color="offerStatusColors[selectedOffer.status] || 'neutral'" variant="solid">
              {{ offerStatusLabels[selectedOffer.status] || selectedOffer.status }}
            </UBadge>
            <UBadge v-if="selectedOffer.result" :color="selectedOffer.result === 'OFFER_APPROVED' ? 'success' : 'error'" variant="solid">
              {{ offerResultLabels[selectedOffer.result] || selectedOffer.result }}
            </UBadge>
            <UBadge :color="{ LOW: 'neutral', MEDIUM: 'primary', HIGH: 'warning', URGENT: 'error' }[selectedOffer.priority as string] || 'neutral'" variant="outline" size="sm">
              {{ { LOW: 'Düşük', MEDIUM: 'Normal', HIGH: 'Yüksek', URGENT: 'Acil' }[selectedOffer.priority as string] || selectedOffer.priority }}
            </UBadge>
          </div>

          <!-- Teklif bilgileri -->
          <div class="grid grid-cols-2 gap-3">
            <div
              v-for="field in offerDetailFields"
              :key="field.label"
              class="flex items-start gap-2 min-w-0 overflow-hidden"
            >
              <UIcon :name="field.icon" class="size-3.5 text-muted mt-0.5 shrink-0" />
              <div class="min-w-0">
                <p class="text-xs text-muted">{{ field.label }}</p>
                <p class="text-sm font-medium">{{ truncateText(field.value, 25) }}</p>
              </div>
            </div>
          </div>

          <!-- Araç bilgileri ayri section (varsa) -->
          <div v-if="selectedOffer.offerData?.plateNo || selectedOffer.offerData?.vehicleBrand" class="pt-3 border-t border-default">
            <div class="flex items-center gap-2 mb-2">
              <UIcon name="i-lucide-car" class="size-4 text-muted" />
              <p class="text-xs text-muted font-medium">Araç Bilgileri</p>
            </div>
            <div class="grid grid-cols-2 gap-3 bg-gray-50 dark:bg-gray-800/50 p-3 rounded-lg">
              <div v-if="selectedOffer.offerData.plateNo">
                <p class="text-xs text-muted">Plaka</p>
                <p class="text-sm font-bold">{{ selectedOffer.offerData.plateNo }}</p>
              </div>
              <div v-if="selectedOffer.offerData.vehicleBrand || selectedOffer.offerData.vehicleModel">
                <p class="text-xs text-muted">Marka / Model</p>
                <p class="text-sm font-medium">{{ selectedOffer.offerData.vehicleBrand }} {{ selectedOffer.offerData.vehicleModel }}</p>
              </div>
              <div v-if="selectedOffer.offerData.vehicleYear">
                <p class="text-xs text-muted">Model Yili</p>
                <p class="text-sm font-medium">{{ selectedOffer.offerData.vehicleYear }}</p>
              </div>
              <div v-if="selectedOffer.offerData.registrationNo">
                <p class="text-xs text-muted">Tescil No</p>
                <p class="text-sm font-medium">{{ selectedOffer.offerData.registrationNo }}</p>
              </div>
            </div>
          </div>

          <!-- Konut / DASK bilgileri (varsa) -->
          <div v-if="selectedOffer.offerData?.uavtCode" class="pt-3 border-t border-default">
            <div class="flex items-center gap-2 mb-2">
              <UIcon name="i-lucide-home" class="size-4 text-muted" />
              <p class="text-xs text-muted font-medium">Konut Bilgileri</p>
            </div>
            <div class="grid grid-cols-2 gap-3 bg-gray-50 dark:bg-gray-800/50 p-3 rounded-lg">
              <div>
                <p class="text-xs text-muted">UAVT Kodu</p>
                <p class="text-sm font-medium">{{ selectedOffer.offerData.uavtCode }}</p>
              </div>
              <div v-if="selectedOffer.offerData.network">
                <p class="text-xs text-muted">Network</p>
                <p class="text-sm font-medium">{{ selectedOffer.offerData.network }}</p>
              </div>
            </div>
          </div>

          <!-- Ek sigortalilar -->
          <div v-if="selectedOffer.offerData?.additionalInsureds" class="pt-3 border-t border-default">
            <p class="text-xs text-muted mb-1">Ek Sigortalilar</p>
            <p class="text-sm bg-gray-50 dark:bg-gray-800/50 p-3 rounded-lg">{{ selectedOffer.offerData.additionalInsureds }}</p>
          </div>

          <!-- Açıklama / Not -->
          <div v-if="selectedOffer.description || selectedOffer.offerData?.note" class="pt-3 border-t border-default">
            <p class="text-xs text-muted mb-1">Not</p>
            <p class="text-sm bg-gray-50 dark:bg-gray-800/50 p-3 rounded-lg">{{ selectedOffer.offerData?.note || selectedOffer.description }}</p>
          </div>

          <!-- Sonuç -->
          <div v-if="selectedOffer.result" class="pt-3 border-t border-default">
            <p class="text-xs text-muted mb-1">Sonuç</p>
            <UBadge :color="selectedOffer.result === 'OFFER_APPROVED' ? 'success' : 'error'" variant="solid" size="sm" class="mb-1">
              {{ offerResultLabels[selectedOffer.result] || selectedOffer.result }}
            </UBadge>
            <p v-if="selectedOffer.resultReason" class="text-sm mt-1">Sebep: {{ selectedOffer.resultReason }}</p>
            <p v-if="selectedOffer.resultNote" class="text-sm mt-1">{{ selectedOffer.resultNote }}</p>
            <p v-if="selectedOffer.completedAt" class="text-xs text-muted mt-1">Tamamlanma: {{ formatDate(selectedOffer.completedAt) }}</p>
          </div>
        </div>
      </template>
    </USlideover>

    <!-- Teklif Silme Onay -->
    <UModal :dismissible="false" v-model:open="isDeleteOfferOpen" title="Teklifi Sil" class="sm:max-w-sm">
      <template #body>
        <p class="text-sm">Bu teklifi silmek istediğinize emin misiniz?</p>
        <p class="text-xs text-muted mt-1">Bu işlem geri alınamaz.</p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" size="xs" @click="isDeleteOfferOpen = false" />
          <UButton label="Sil" color="error" size="xs" :loading="deletingOfferLoading" @click="doDeleteOffer" />
        </div>
      </template>
    </UModal>

    <!-- Silme Onay -->
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Müşteri Sil">
      <template #body>
        <p><strong>{{ customer.name }}</strong> müşterisini silmek istediginize emin misiniz? Bu islem geri alinamaz.</p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" @click="isDeleteModalOpen = false" />
          <UButton label="Sil" color="error" @click="deleteCustomer" />
        </div>
      </template>
    </UModal>

    <!-- Teklif Düzenle Modal -->
    <TaskFormModal v-model:open="showEditOfferModal" :task="editOfferTask" @saved="onEditOfferSaved" />

    <!-- Mobil Sabit Müşteriyi Ara Butonu -->
    <div v-if="customer?.phone" class="sm:hidden fixed bottom-4 left-0 right-0 flex justify-center z-30 pointer-events-none">
      <a
        :href="`tel:${customer.phone}`"
        class="pointer-events-auto inline-flex items-center gap-2 px-6 py-3 rounded-full bg-primary text-white font-semibold shadow-lg active:scale-95 transition-transform"
      >
        Müşteriyi Ara
        <UIcon name="i-lucide-phone" class="size-5" />
      </a>
    </div>

  </div>
</template>

<style scoped>
table td { overflow: hidden; text-overflow: clip; white-space: nowrap; }

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
