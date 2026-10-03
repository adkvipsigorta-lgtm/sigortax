<script setup lang="ts">
definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Lead Yönetimi' })

const toast = useToast()
const { get, post, del } = useApi()
const { user } = useAuth()
const { can } = usePermissions()

interface Lead {
  id: number
  sourceId: number | null
  sourceName: string | null
  sourceColor: string | null
  productId: number | null
  productName: string | null
  productColor: string | null
  assignedTo: number | null
  assignedName: string | null
  status: 'ACIK' | 'DEVAM' | 'KAZANILDI' | 'KAYBEDILDI'
  fullName: string | null
  tcNo: string | null
  birthDate: string | null
  phone: string
  lostReason: string | null
  closedAt: string | null
  assignedAt: string | null
  remainingMinutes?: number | null
  createdAt: string
}

interface LeadSource { id: number; name: string; color: string | null; isActive?: boolean }
interface LeadProduct { id: number; name: string; color: string | null; isActive?: boolean; requiresFile?: boolean }
interface User { id: number; name: string; isActive: boolean; isSalesRep: boolean }

// Referans verileri
const sources = ref<LeadSource[]>([])
const products = ref<LeadProduct[]>([])
const users = ref<User[]>([])

onMounted(async () => {
  const [srcRes, prdRes, usrRes] = await Promise.all([
    get('lead-sources?all=1').catch(() => ({ data: [] })),
    get('lead-products?all=1').catch(() => ({ data: [] })),
    get('users?all=1').catch(() => ({ data: [] })),
  ])
  sources.value = srcRes.data || []
  products.value = prdRes.data || []
  const all = usrRes.data || []
  users.value = all.filter((u: User) => u.isSalesRep && u.isActive)
})

// Aktif tab
const activeTab = ref<'acik' | 'devam' | 'kapatilan'>('acik')
const tabCounts = ref({ acik: 0, devam: 0, kapatilan: 0, kazanildi: 0, kaybedildi: 0 })
const closedFilter = ref<'all' | 'KAZANILDI' | 'KAYBEDILDI'>('all')

// Lead verileri
const leads = ref<Lead[]>([])
const loading = ref(false)
const pagination = ref({ page: 1, limit: 100, total: 0, totalPages: 0 })


async function fetchLeads() {
  loading.value = true
  try {
    const params = new URLSearchParams({
      tab: activeTab.value,
      page: String(pagination.value.page),
      limit: String(pagination.value.limit),
    })

    const res = await get(`leads?${params}`)
    leads.value = res.data || []
    tabCounts.value = res.counts || { acik: 0, devam: 0, kapatilan: 0 }
    if (res.pagination) {
      pagination.value.total = res.pagination.total
      pagination.value.totalPages = res.pagination.totalPages
    }
  } catch {
    toast.add({ title: 'Lead\'ler yüklenemedi', color: 'error' })
  }
  loading.value = false
}

watch(activeTab, () => {
  leads.value = []
  pagination.value.page = 1
  closedFilter.value = 'all'
  fetchLeads()
})

onMounted(() => {
  fetchLeads()
  fetchNoteCounts()
})

// ---- Lead Notları ----
const expandedNoteLeadId = ref<number | null>(null)
const leadNotes = ref<Record<number, any[]>>({})
const leadNoteCounts = ref<Record<number, number>>({})
const leadNotesLoading = ref<Record<number, boolean>>({})
const newLeadNoteText = ref('')
const addingLeadNote = ref(false)

async function fetchNoteCounts() {
  try {
    const res = await get('leads/note-counts')
    leadNoteCounts.value = res.data || {}
  } catch {}
}

async function toggleLeadNotes(leadId: number) {
  if (expandedNoteLeadId.value === leadId) {
    expandedNoteLeadId.value = null
    return
  }
  expandedNoteLeadId.value = leadId
  newLeadNoteText.value = ''
  await fetchLeadNotes(leadId)
}

async function fetchLeadNotes(leadId: number) {
  leadNotesLoading.value[leadId] = true
  try {
    const res = await get(`leads/${leadId}/notes`)
    leadNotes.value[leadId] = res.data || []
  } catch {
    leadNotes.value[leadId] = []
  } finally {
    leadNotesLoading.value[leadId] = false
  }
}

async function submitLeadNote(leadId: number) {
  const text = newLeadNoteText.value.trim()
  if (!text) return
  addingLeadNote.value = true
  try {
    const res = await post(`leads/${leadId}/notes`, { note: text })
    if (!leadNotes.value[leadId]) leadNotes.value[leadId] = []
    leadNotes.value[leadId].unshift(res.data)
    leadNoteCounts.value[leadId] = (leadNoteCounts.value[leadId] || 0) + 1
    newLeadNoteText.value = ''
    toast.add({ title: 'Not eklendi', color: 'success' })
  } catch (e: any) {
    toast.add({ title: 'Not eklenemedi', color: 'error' })
  } finally {
    addingLeadNote.value = false
  }
}

async function deleteLeadNote(noteId: number, leadId: number) {
  try {
    const { token } = useAuth()
    await fetch(`/api/leads/${leadId}/notes/${noteId}`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${token.value}` },
    })
    if (leadNotes.value[leadId]) {
      leadNotes.value[leadId] = leadNotes.value[leadId].filter((n: any) => n.id !== noteId)
    }
    leadNoteCounts.value[leadId] = Math.max(0, (leadNoteCounts.value[leadId] || 1) - 1)
    toast.add({ title: 'Not silindi', color: 'success' })
  } catch {
    toast.add({ title: 'Not silinemedi', color: 'error' })
  }
}

function formatNoteDate(d: string): string {
  if (!d) return ''
  const dt = new Date(d)
  return dt.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' + dt.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' })
}

// Kolon filtreleri
const colFilters = ref({
  createdAt: '',
  fullName: '',
  product: [] as string[],
  source: [] as string[],
  assigned: [] as string[],
})

const productFilterOptions = computed(() => {
  const set = new Set<string>()
  leads.value.forEach(l => { if (l.productName) set.add(l.productName) })
  return Array.from(set).sort((a, b) => a.localeCompare(b, 'tr'))
})

const sourceFilterOptions = computed(() => {
  const set = new Set<string>()
  leads.value.forEach(l => { if (l.sourceName) set.add(l.sourceName) })
  return Array.from(set).sort((a, b) => a.localeCompare(b, 'tr'))
})

const assignedFilterOptions = computed(() => {
  const set = new Set<string>()
  leads.value.forEach(l => { if (l.assignedName) set.add(l.assignedName) })
  return Array.from(set).sort((a, b) => a.localeCompare(b, 'tr'))
})

// Filtrelenmiş leadler
const filteredLeads = computed(() => {
  let list = leads.value

  // Kapatılan tab filtresi
  if (activeTab.value === 'kapatilan' && closedFilter.value !== 'all') {
    list = list.filter(l => l.status === closedFilter.value)
  }

  // Tarih filtresi
  if (colFilters.value.createdAt) {
    const [startStr, endStr] = colFilters.value.createdAt.split('~')
    if (startStr) {
      const start = new Date(startStr.trim())
      start.setHours(0, 0, 0, 0)
      list = list.filter(l => new Date(l.createdAt) >= start)
    }
    if (endStr) {
      const end = new Date(endStr.trim())
      end.setHours(23, 59, 59, 999)
      list = list.filter(l => new Date(l.createdAt) <= end)
    }
  }

  // Ad Soyad filtresi
  if (colFilters.value.fullName) {
    const q = colFilters.value.fullName.toLocaleLowerCase('tr')
    list = list.filter(l => (l.fullName || '').toLocaleLowerCase('tr').includes(q))
  }

  // Ürün filtresi
  if (colFilters.value.product.length) {
    list = list.filter(l => colFilters.value.product.includes(l.productName || ''))
  }

  // Kaynak filtresi
  if (colFilters.value.source.length) {
    list = list.filter(l => colFilters.value.source.includes(l.sourceName || ''))
  }

  // Atanan filtresi
  if (colFilters.value.assigned.length) {
    list = list.filter(l => colFilters.value.assigned.includes(l.assignedName || ''))
  }

  return list
})

// Kalan süre gösterimi (backend'den gelen mesai dakikası bazlı)
function formatRemaining(minutes: number | null | undefined): { text: string; urgent: boolean } {
  if (minutes === null || minutes === undefined) return { text: '', urgent: false }
  if (minutes <= 0) return { text: '0dk', urgent: true }
  if (minutes < 60) return { text: `${minutes}dk`, urgent: minutes <= 5 }
  const saat = Math.floor(minutes / 60)
  const dk = minutes % 60
  return { text: dk > 0 ? `${saat}s ${dk}dk` : `${saat}s`, urgent: false }
}

// 3 Nokta Menü (düzenle/sil)
const deleteModalOpen = ref(false)
const deletingLeadId = ref<number | null>(null)

function getRowActions(lead: Lead) {
  const actions: any[][] = []
  if (can('leads.manage')) {
    actions.push([{ label: 'Düzenle', icon: 'i-lucide-pencil', onSelect: () => openEditModal(lead) }])
  }
  if (lead.status === 'KAYBEDILDI' && can('leads.manage')) {
    actions.push([{ label: 'Kazanıldı', icon: 'i-lucide-check-circle', color: 'success' as const, onSelect: () => markAsWon(lead.id) }])
  }
  if (can('leads.delete')) {
    actions.push([{ label: 'Sil', icon: 'i-lucide-trash-2', color: 'error' as const, onSelect: () => confirmDelete(lead.id) }])
  }
  return actions
}

async function markAsWon(leadId: number) {
  try {
    const { token } = useAuth()
    // Durumu KAZANILDI yap
    await fetch(`/api/leads/${leadId}/reopen-won`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token.value}` },
    })
    toast.add({ title: 'Lead kazanıldı olarak güncellendi', color: 'success' })
    fetchLeads()
  } catch {
    toast.add({ title: 'İşlem başarısız', color: 'error' })
  }
}

function confirmDelete(id: number) {
  deletingLeadId.value = id
  deleteModalOpen.value = true
}

async function doDelete() {
  if (!deletingLeadId.value) return
  try {
    await del(`leads/${deletingLeadId.value}`)
    toast.add({ title: 'Lead silindi', color: 'success' })
    fetchLeads()
    fetchNoteCounts()
  } catch {
    toast.add({ title: 'Silinemedi', color: 'error' })
  }
  deleteModalOpen.value = false
  deletingLeadId.value = null
}

// Düzenleme modalı
const editModalOpen = ref(false)
const editingLead = ref<Lead | null>(null)
const editForm = ref({ fullName: '', tcNo: '', birthDate: '', phone: '', sourceId: undefined as number | undefined, productId: undefined as number | undefined })
const savingEdit = ref(false)

// Düzenleme doğum tarihi (takvimli)
const editBirthDisplay = ref('')
const editBirthCal = ref<any>()
const editBirthOpen = ref(false)

function editAutoFormat(raw: string): string {
  const digits = raw.replace(/\D/g, '').slice(0, 8)
  if (digits.length <= 2) return digits
  if (digits.length <= 4) return `${digits.slice(0, 2)}.${digits.slice(2)}`
  return `${digits.slice(0, 2)}.${digits.slice(2, 4)}.${digits.slice(4)}`
}
function editParseToIso(display: string): string | null {
  const digits = display.replace(/\D/g, '')
  if (digits.length !== 8) return null
  const day = parseInt(digits.slice(0, 2))
  const month = parseInt(digits.slice(2, 4))
  const year = parseInt(digits.slice(4, 8))
  if (day < 1 || day > 31 || month < 1 || month > 12 || year < 1900) return null
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}
function editPreventNonDigit(e: KeyboardEvent) {
  if (e.ctrlKey || e.metaKey || e.altKey) return
  if (['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab', 'Home', 'End'].includes(e.key)) return
  if (!/^\d$/.test(e.key)) e.preventDefault()
}
function onEditBirthInput(val: string) {
  const formatted = editAutoFormat(val)
  editBirthDisplay.value = formatted
  const iso = editParseToIso(formatted)
  if (iso) editForm.value.birthDate = iso
}
function onEditBirthChange(val: any) {
  editBirthCal.value = val
  const iso = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  editForm.value.birthDate = iso
  editBirthDisplay.value = `${String(val.day).padStart(2, '0')}.${String(val.month).padStart(2, '0')}.${val.year}`
  editBirthOpen.value = false
}
function isoToEditDisplay(iso: string): string {
  if (!iso) return ''
  const [y, m, d] = iso.split('-')
  return `${d}.${m}.${y}`
}

function openEditModal(lead: Lead) {
  editingLead.value = lead
  editForm.value = {
    fullName: lead.fullName || '',
    tcNo: lead.tcNo || '',
    birthDate: lead.birthDate || '',
    phone: lead.phone || '',
    sourceId: lead.sourceId ?? undefined,
    productId: lead.productId ?? undefined,
  }
  editBirthDisplay.value = isoToEditDisplay(lead.birthDate || '')
  editBirthCal.value = undefined
  editModalOpen.value = true
}

async function saveEdit() {
  if (savingEdit.value || !editingLead.value) return
  savingEdit.value = true
  try {
    const { token } = useAuth()
    const res = await fetch(`/api/leads/${editingLead.value.id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token.value}` },
      body: JSON.stringify(editForm.value),
    })
    const data = await res.json()
    if (!res.ok) {
      toast.add({ title: data.message || 'Güncellenemedi', color: 'error' })
      savingEdit.value = false
      return
    }
    toast.add({ title: 'Lead güncellendi', color: 'success' })
    editModalOpen.value = false
    fetchLeads()
  } catch {
    toast.add({ title: 'Bağlantı hatası', color: 'error' })
  }
  savingEdit.value = false
}

// ---- Hızlı Lead ----
const quickModalOpen = ref(false)
const quickForm = ref({ phone: '', productId: undefined as number | undefined })
const quickFiles = ref<File[]>([])
const quickDragging = ref(false)
const savingQuick = ref(false)

function openQuickModal() {
  quickForm.value = { phone: '', productId: undefined }
  quickFiles.value = []
  quickModalOpen.value = true
}

function onQuickFileDrop(e: DragEvent) {
  quickDragging.value = false
  const fl = e.dataTransfer?.files
  if (fl) addQuickFiles(fl)
}

function onQuickFileSelect(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files) addQuickFiles(input.files)
  input.value = ''
}

function addQuickFiles(fileList: FileList) {
  const allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
  for (const f of Array.from(fileList)) {
    if (!allowed.includes(f.type)) { toast.add({ title: `${f.name}: Sadece JPG, PNG, WebP ve PDF`, color: 'error' }); continue }
    if (f.size > 10 * 1024 * 1024) { toast.add({ title: `${f.name}: Maks. 10MB`, color: 'error' }); continue }
    quickFiles.value.push(f)
  }
}

function removeQuickFile(idx: number) { quickFiles.value.splice(idx, 1) }

function onQuickPaste(e: ClipboardEvent) {
  const items = e.clipboardData?.items
  if (!items) return
  for (const item of Array.from(items)) {
    if (item.type.startsWith('image/')) {
      const file = item.getAsFile()
      if (file) {
        const named = new File([file], 'yapistirilan-' + Date.now() + '.png', { type: file.type })
        quickFiles.value.push(named)
      }
      e.preventDefault()
    }
  }
}

async function saveQuickLead() {
  if (savingQuick.value) return
  if (!quickForm.value.phone?.trim()) { toast.add({ title: 'Telefon numarası zorunludur', color: 'error' }); return }
  if (!quickForm.value.productId) { toast.add({ title: 'Ürün seçimi zorunludur', color: 'error' }); return }
  if (!quickFiles.value.length) { toast.add({ title: 'Ruhsat fotoğrafı zorunludur', color: 'error' }); return }

  savingQuick.value = true
  try {
    // Lead oluştur (sadece telefon + ürün, hızlı mod)
    const res = await post('leads', {
      quick: true,
      phone: quickForm.value.phone.trim(),
      productId: quickForm.value.productId || undefined,
      sourceId: sources.value.find(s => s.name === 'Manuel')?.id || undefined,
    })
    const leadId = res.data?.id

    // Ruhsat dosyasını yükle
    let fileErrors = 0
    if (leadId && quickFiles.value.length) {
      const { token } = useAuth()
      for (const file of quickFiles.value) {
        try {
          const fd = new FormData()
          fd.append('file', file)
          const fRes = await fetch(`/api/leads/${leadId}/upload-file`, {
            method: 'POST',
            headers: { Authorization: `Bearer ${token.value}` },
            body: fd,
          })
          if (!fRes.ok) fileErrors++
        } catch { fileErrors++ }
      }
    }

    if (fileErrors > 0) {
      toast.add({ title: 'Lead oluşturuldu ancak bazı dosyalar yüklenemedi', color: 'warning' })
    } else {
      toast.add({ title: 'Hızlı lead oluşturuldu', color: 'success' })
    }
    quickModalOpen.value = false
    fetchLeads()
    fetchNoteCounts()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  savingQuick.value = false
}

// Telefon formatlama: 05334755344 -> 0 (533) 475 53 44
function formatPhone(phone: string): string {
  if (!phone) return ''
  const d = phone.replace(/\D/g, '')
  if (d.length === 12 && d.startsWith('90')) {
    return `+90 ${d.slice(2, 5)} ${d.slice(5, 8)} ${d.slice(8, 10)} ${d.slice(10, 12)}`
  }
  if (d.length === 11 && d.startsWith('0')) {
    return `+90 ${d.slice(1, 4)} ${d.slice(4, 7)} ${d.slice(7, 9)} ${d.slice(9, 11)}`
  }
  if (d.length === 10) {
    return `+90 ${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6, 8)} ${d.slice(8, 10)}`
  }
  return phone
}

// Tarih formatlama: 1990-05-15 -> 15.05.1990
function formatDate(date: string | null): string {
  if (!date) return ''
  const d = new Date(date)
  if (isNaN(d.getTime())) return ''
  return d.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

// Tarih+Saat formatlama: 2026-09-18 14:30:00 -> 18.09.2026 14:30
function formatDateTime(date: string | null): string {
  if (!date) return ''
  const d = new Date(date)
  if (isNaN(d.getTime())) return ''
  return d.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' + d.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' })
}

// Süreci Al
const processingId = ref<number | null>(null)
async function startProcess(lead: Lead) {
  if (processingId.value) return
  if (lead.assignedTo && lead.assignedTo !== user.value?.id) {
    toast.add({ title: 'Bu lead size atanmamış, süreci alamazsınız', color: 'error' })
    return
  }
  processingId.value = lead.id
  try {
    await post(`leads/${lead.id}/start-process`, {})
    toast.add({ title: 'Lead sürece alındı', color: 'success' })
    navigateTo(`/leadler/${lead.id}`)
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  processingId.value = null
}

// Durum Güncelleme Pop-up
const statusModalOpen = ref(false)
const statusLead = ref<Lead | null>(null)
const statusStep = ref<'choose' | 'lost_reason'>('choose')
const lostReason = ref('')
const closingLead = ref(false)

function openStatusModal(lead: Lead) {
  statusLead.value = lead
  statusStep.value = 'choose'
  lostReason.value = ''
  statusModalOpen.value = true
}

async function closeAsWon() {
  if (!statusLead.value || closingLead.value) return
  closingLead.value = true
  try {
    await post(`leads/${statusLead.value.id}/close-process`, { result: 'KAZANILDI' })
    toast.add({ title: 'Satış tamamlandı', color: 'success' })
    statusModalOpen.value = false
    fetchLeads()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  closingLead.value = false
}

async function closeAsLost() {
  if (!statusLead.value || closingLead.value) return
  if (!lostReason.value.trim()) {
    toast.add({ title: 'Açıklama zorunludur', color: 'error' })
    return
  }
  closingLead.value = true
  try {
    await post(`leads/${statusLead.value.id}/close-process`, {
      result: 'KAYBEDILDI',
      lostReason: lostReason.value.trim()
    })
    toast.add({ title: 'Lead kapatıldı', color: 'success' })
    statusModalOpen.value = false
    fetchLeads()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  closingLead.value = false
}

// Yeni Lead Ekleme
const addModalOpen = ref(false)
const addForm = ref({
  fullName: '',
  tcNo: '',
  birthDate: '',
  phone: '',
  sourceId: undefined as number | undefined,
  productId: undefined as number | undefined,
  assignedTo: undefined as number | undefined,
})

const sourceOptions = computed(() => sources.value.filter(s => s.isActive !== false).map(s => ({ label: s.name, value: s.id })))
const productOptions = computed(() => products.value.filter(p => p.isActive !== false).map(p => ({ label: p.name, value: p.id })))
const userOptions = computed(() => [
  { label: 'Otomatik Ata', value: -1 },
  ...users.value.map(u => ({ label: u.name, value: u.id }))
])

// Kaynak "havuza at" gerektiren kaynaklar (Allianz vb.)
function isPoolSource(srcId: number | undefined): boolean {
  if (!srcId) return false
  const src = sources.value.find(s => s.id === srcId)
  if (!src) return false
  // Allianz kontrolü: Türkçe İ ve i sorununu aş
  const name = src.name.normalize('NFC')
  return /all[iİ]anz/i.test(name)
}

watch(() => addForm.value.sourceId, (srcId, oldSrcId) => {
  console.log('[Lead] Kaynak değişti:', oldSrcId, '->', srcId, 'isPool:', isPoolSource(srcId))
  if (isPoolSource(srcId)) {
    console.log('[Lead] Allianz tespit edildi, assignedTo temizleniyor')
    addForm.value.assignedTo = undefined
  }
})

const isAllianzSource = computed(() => isPoolSource(addForm.value.sourceId))

// Seçili ürün dosya gerektiriyor mu
const selectedProductRequiresFile = computed(() => {
  if (!addForm.value.productId) return false
  const p = products.value.find(x => x.id === addForm.value.productId)
  return p?.requiresFile ?? false
})

// Dosya yükleme
const uploadFiles = ref<File[]>([])
const isDragging = ref(false)

function onFileDrop(e: DragEvent) {
  isDragging.value = false
  const files = e.dataTransfer?.files
  if (files) addFiles(files)
}

function onFileSelect(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files) addFiles(input.files)
  input.value = ''
}

function addFiles(fileList: FileList) {
  const allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
  for (const f of Array.from(fileList)) {
    if (!allowed.includes(f.type)) {
      toast.add({ title: `${f.name}: Sadece JPG, PNG, WebP ve PDF yüklenebilir`, color: 'error' })
      continue
    }
    if (f.size > 10 * 1024 * 1024) {
      toast.add({ title: `${f.name}: Dosya 10MB'dan büyük olamaz`, color: 'error' })
      continue
    }
    uploadFiles.value.push(f)
  }
}

function removeFile(index: number) {
  uploadFiles.value.splice(index, 1)
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function openAddModal() {
  addForm.value = { fullName: '', tcNo: '', birthDate: '', phone: '', sourceId: undefined, productId: undefined, assignedTo: undefined }
  birthDateDisplay.value = ''
  birthDateCal.value = undefined
  uploadFiles.value = []
  addModalOpen.value = true
}

// Doğum tarihi (takvimli floating label)
const birthDateCal = ref<any>()
const birthDateDisplay = ref('')
const birthDateOpen = ref(false)

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
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}
function preventNonDigitKey(e: KeyboardEvent) {
  if (e.ctrlKey || e.metaKey || e.altKey) return
  if (['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab', 'Home', 'End'].includes(e.key)) return
  if (!/^\d$/.test(e.key)) e.preventDefault()
}
function onBirthDateInput(val: string) {
  const formatted = autoFormatDateInput(val)
  birthDateDisplay.value = formatted
  const iso = parseDisplayToIso(formatted)
  if (iso) addForm.value.birthDate = iso
}
function onBirthDateChange(val: any) {
  birthDateCal.value = val
  const iso = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  addForm.value.birthDate = iso
  birthDateDisplay.value = `${String(val.day).padStart(2, '0')}.${String(val.month).padStart(2, '0')}.${val.year}`
  birthDateOpen.value = false
}

// TC Kimlik input
function onTcInput(val: string) {
  addForm.value.tcNo = val.replace(/\D/g, '').slice(0, 11)
}

const savingLead = ref(false)
async function saveLead() {
  if (savingLead.value) return
  const f = addForm.value
  if (!f.tcNo || f.tcNo.length < 11) { toast.add({ title: 'TC Kimlik No zorunludur (11 hane)', color: 'error' }); return }
  if (!f.fullName?.trim()) { toast.add({ title: 'Ad Soyad zorunludur', color: 'error' }); return }
  if (!f.birthDate) { toast.add({ title: 'Doğum Tarihi zorunludur', color: 'error' }); return }
  if (!f.phone?.trim()) { toast.add({ title: 'Telefon numarası zorunludur', color: 'error' }); return }
  if (!f.productId) { toast.add({ title: 'Ürün seçimi zorunludur', color: 'error' }); return }
  if (!f.sourceId) { toast.add({ title: 'Kaynak seçimi zorunludur', color: 'error' }); return }
  savingLead.value = true
  try {
    const payload = { ...addForm.value, assignedTo: addForm.value.assignedTo === -1 ? undefined : addForm.value.assignedTo }
    const res = await post('leads', payload)
    const leadId = res.data?.id

    // Dosyaları yükle
    let fileErrors = 0
    if (leadId && uploadFiles.value.length) {
      const { token } = useAuth()
      for (const file of uploadFiles.value) {
        try {
          const fd = new FormData()
          fd.append('file', file)
          const fRes = await fetch(`/api/leads/${leadId}/upload-file`, {
            method: 'POST',
            headers: { Authorization: `Bearer ${token.value}` },
            body: fd,
          })
          if (!fRes.ok) fileErrors++
        } catch { fileErrors++ }
      }
    }

    if (fileErrors > 0) {
      toast.add({ title: 'Lead oluşturuldu ancak bazı dosyalar yüklenemedi', color: 'warning' })
    } else {
      toast.add({ title: 'Lead oluşturuldu', color: 'success' })
    }
    addModalOpen.value = false
    fetchLeads()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  savingLead.value = false
}
</script>

<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-xl">Lead Yönetimi</h1>
      <p class="text-sm text-muted mt-1">Gelen lead'leri takip edin ve yönetin.</p>
    </div>

    <UCard>
      <template #header>
        <div class="flex items-center justify-between flex-wrap gap-2">
          <div class="flex flex-wrap items-center gap-1.5 w-full sm:w-auto">
            <UButton
              size="xl"
              :color="activeTab === 'acik' ? 'primary' : 'neutral'"
              :variant="activeTab === 'acik' ? 'solid' : 'outline'"
              class="flex-1 sm:flex-none sm:min-w-[120px] justify-center"
              @click="activeTab = 'acik'"
            >
              Açık Leadler
              <UBadge v-if="tabCounts.acik" :label="String(tabCounts.acik)" size="sm" color="neutral" variant="subtle" class="ml-1" />
            </UButton>
            <UButton
              size="xl"
              :color="activeTab === 'devam' ? 'primary' : 'neutral'"
              :variant="activeTab === 'devam' ? 'solid' : 'outline'"
              class="flex-1 sm:flex-none sm:min-w-[120px] justify-center"
              @click="activeTab = 'devam'"
            >
              Devam Eden
              <UBadge v-if="tabCounts.devam" :label="String(tabCounts.devam)" size="sm" color="neutral" variant="subtle" class="ml-1" />
            </UButton>
            <UButton
              size="xl"
              :color="activeTab === 'kapatilan' ? 'primary' : 'neutral'"
              :variant="activeTab === 'kapatilan' ? 'solid' : 'outline'"
              class="flex-1 sm:flex-none sm:min-w-[120px] justify-center"
              @click="activeTab = 'kapatilan'"
            >
              Kapatılan
              <UBadge v-if="tabCounts.kapatilan" :label="String(tabCounts.kapatilan)" size="sm" color="neutral" variant="subtle" class="ml-1" />
            </UButton>
          </div>
        </div>
      </template>

      <!-- Kapatılan tab istatistikleri -->
      <div v-if="activeTab === 'kapatilan' && !loading && leads.length" class="grid grid-cols-3 gap-3 mb-4">
        <div
          class="rounded-lg border p-3 text-center cursor-pointer transition-all"
          :class="closedFilter === 'KAZANILDI' ? 'border-green-500 ring-2 ring-green-500/30 bg-green-50 dark:bg-green-900/30' : 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 hover:border-green-400'"
          @click="closedFilter = closedFilter === 'KAZANILDI' ? 'all' : 'KAZANILDI'"
        >
          <p class="text-2xl font-bold text-green-600">{{ tabCounts.kazanildi || 0 }}</p>
          <p class="text-xs font-medium text-green-600/80">Kazanıldı</p>
        </div>
        <div
          class="rounded-lg border p-3 text-center cursor-pointer transition-all"
          :class="closedFilter === 'KAYBEDILDI' ? 'border-red-500 ring-2 ring-red-500/30 bg-red-50 dark:bg-red-900/30' : 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 hover:border-red-400'"
          @click="closedFilter = closedFilter === 'KAYBEDILDI' ? 'all' : 'KAYBEDILDI'"
        >
          <p class="text-2xl font-bold text-red-500">{{ tabCounts.kaybedildi || 0 }}</p>
          <p class="text-xs font-medium text-red-500/80">Kaybedildi</p>
        </div>
        <div
          class="rounded-lg border p-3 text-center cursor-pointer transition-all"
          :class="closedFilter === 'all' ? 'border-primary ring-2 ring-primary/30 bg-gray-50 dark:bg-gray-800/50' : 'border-default bg-gray-50 dark:bg-gray-800/50 hover:border-gray-400'"
          @click="closedFilter = 'all'"
        >
          <p class="text-2xl font-bold text-muted">{{ tabCounts.kapatilan || 0 }}</p>
          <p class="text-xs font-medium text-muted">Toplam</p>
        </div>
      </div>

      <div v-if="loading && !leads.length" class="py-2">
        <SkeletonTable :rows="5" :cols="8" />
      </div>
      <div v-else-if="!leads.length" class="text-center py-8 text-muted">Bu kategoride lead bulunmuyor</div>
      <div v-else>
        <div class="hidden sm:block border border-default rounded-lg overflow-hidden" style="min-width:0">
          <table class="leads-table text-xs w-full table-fixed">
            <thead class="sticky top-0 z-10">
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[12%]">
                  <ColumnFilter v-model="colFilters.createdAt" label="Tarih" type="date" />
                </th>
                <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[13%]">
                  <ColumnFilter v-model="colFilters.fullName" label="Ad Soyad" type="text" />
                </th>
                <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[10%] hidden lg:table-cell">TC Kimlik No</th>
                <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[9%] hidden lg:table-cell">Doğum Tarihi</th>
                <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[12%]">Telefon</th>
                <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[10%] hidden lg:table-cell">
                  <ColumnFilter v-model="colFilters.product" label="Ürün" type="multiselect" :options="productFilterOptions" />
                </th>
                <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[11%] hidden lg:table-cell">
                  <ColumnFilter v-model="colFilters.source" label="Kaynak" type="multiselect" :options="sourceFilterOptions" />
                </th>
                <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[9%] hidden lg:table-cell">
                  <ColumnFilter v-model="colFilters.assigned" label="Atanan" type="multiselect" :options="assignedFilterOptions" />
                </th>
                <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted w-[14%]">
                  {{ activeTab === 'kapatilan' ? 'Sonuç' : 'İşlem' }}
                </th>
              </tr>
            </thead>
              <tbody>
                <template v-for="lead in filteredLeads" :key="lead.id">
                <tr
                  class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/50"
                >
                  <!-- Geliş Tarihi -->
                  <td class="py-2 px-3 tabular-nums">{{ formatDateTime(lead.createdAt) }}</td>

                  <!-- Ad Soyad -->
                  <td class="py-2 px-3">
                    <NuxtLink v-if="activeTab !== 'acik'" :to="`/leadler/${lead.id}`" class="hover:text-primary transition-colors cursor-pointer">{{ lead.fullName || 'İsimsiz' }}</NuxtLink>
                    <span v-else>{{ lead.fullName || '' }}</span>
                  </td>

                  <!-- TC Kimlik No -->
                  <td class="py-2 px-3 tabular-nums hidden lg:table-cell">{{ lead.tcNo || '' }}</td>

                  <!-- Doğum Tarihi -->
                  <td class="py-2 px-3 tabular-nums hidden lg:table-cell">{{ formatDate(lead.birthDate) }}</td>

                  <!-- Telefon -->
                  <td class="py-2 px-3 tabular-nums">{{ formatPhone(lead.phone) }}</td>

                  <!-- Ürün -->
                  <td class="py-2 px-3 hidden sm:table-cell">
                    <span
                      v-if="lead.productName"
                      class="lead-badge"
                      :style="{ backgroundColor: (lead.productColor || '#8b5cf6') + '1a', color: lead.productColor || '#8b5cf6' }"
                    >{{ lead.productName }}</span>
                  </td>

                  <!-- Kaynak -->
                  <td class="py-2 px-3 hidden sm:table-cell">
                    <span
                      v-if="lead.sourceName"
                      class="lead-badge"
                      :style="{ backgroundColor: (lead.sourceColor || '#6b7280') + '1a', color: lead.sourceColor || '#6b7280' }"
                    >{{ lead.sourceName }}</span>
                  </td>

                  <!-- Atanan -->
                  <td class="py-2 px-3 hidden lg:table-cell">
                    <span v-if="lead.assignedName" class="font-medium">{{ lead.assignedName }}</span>
                    <span v-else class="text-muted">-</span>
                  </td>

                  <!-- İşlem / Sonuç -->
                  <td class="py-2 px-3">
                    <div class="flex items-center gap-1">
                      <!-- Durum butonları -->
                      <template v-if="activeTab === 'acik'">
                        <button
                          class="lead-result-badge lead-badge-acik"
                          :disabled="processingId !== null"
                          @click="startProcess(lead)"
                        >Süreci Al</button>
                        <UTooltip v-if="lead.remainingMinutes !== null && lead.remainingMinutes !== undefined" :text="`Sürece almak için ${formatRemaining(lead.remainingMinutes).text} kaldı`">
                          <span
                            class="lead-time-badge"
                            :class="formatRemaining(lead.remainingMinutes).urgent ? 'lead-time-urgent' : ''"
                          >{{ formatRemaining(lead.remainingMinutes).text }}</span>
                        </UTooltip>
                      </template>
                      <template v-else-if="activeTab === 'devam'">
                        <button class="lead-result-badge lead-badge-pending" @click="openStatusModal(lead)">Güncelle</button>
                      </template>
                      <template v-else>
                        <span v-if="lead.status === 'KAZANILDI'" class="lead-result-badge lead-badge-won">Kazanıldı</span>
                        <UTooltip v-else-if="lead.status === 'KAYBEDILDI'" :text="lead.lostReason || 'Neden belirtilmedi'">
                          <span class="lead-result-badge lead-badge-lost">Kaybedildi</span>
                        </UTooltip>
                      </template>
                      <!-- Not Butonu (sadece devam eden ve kapatılan) -->
                      <button
                        v-if="activeTab !== 'acik'"
                        type="button"
                        class="relative size-7 flex items-center justify-center rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                        :title="activeTab === 'devam' ? 'Not Ekle' : 'Notları Gör'"
                        @click="toggleLeadNotes(lead.id)"
                      >
                        <UIcon name="i-lucide-message-square-text" class="size-4" :class="expandedNoteLeadId === lead.id ? 'text-primary' : 'text-gray-400'" />
                        <span
                          v-if="leadNoteCounts[lead.id]"
                          class="absolute -top-1 -right-1 min-w-[15px] h-[15px] flex items-center justify-center rounded-full bg-red-500 text-white text-[9px] font-bold leading-none px-0.5"
                        >{{ leadNoteCounts[lead.id] }}</span>
                      </button>
                      <!-- 3 Nokta Menü (izne göre) -->
                      <UDropdownMenu v-if="can('leads.manage') || can('leads.delete')" :items="getRowActions(lead)">
                        <UButton icon="i-lucide-ellipsis-vertical" color="neutral" variant="ghost" size="xs" />
                      </UDropdownMenu>
                    </div>
                  </td>
                </tr>
                <!-- Satır Altı Not Alanı (sadece devam eden ve kapatılan) -->
                <tr v-if="expandedNoteLeadId === lead.id && activeTab !== 'acik'">
                  <td colspan="9" class="p-0">
                    <div class="bg-gray-50 dark:bg-gray-800/60 border-b border-default px-6 py-4">
                      <!-- Not Ekleme (sadece devam eden) -->
                      <div v-if="activeTab === 'devam'" class="flex gap-2 items-center mb-4 w-full">
                        <UInput
                          v-model="newLeadNoteText"
                          placeholder="Not ekle..."
                          size="md"
                          class="flex-1"
                          @keyup.enter="submitLeadNote(lead.id)"
                        />
                        <UButton
                          icon="i-lucide-send"
                          size="md"
                          color="primary"
                          :loading="addingLeadNote"
                          :disabled="!newLeadNoteText.trim()"
                          @click="submitLeadNote(lead.id)"
                        />
                      </div>
                      <!-- Notlar Listesi -->
                      <div v-if="leadNotesLoading[lead.id]" class="text-sm text-muted py-3 text-center">Yükleniyor...</div>
                      <div v-else-if="!leadNotes[lead.id]?.length" class="text-sm text-muted py-3 text-center">Henüz not eklenmemiş.</div>
                      <div v-else class="space-y-2 max-h-[240px] overflow-y-auto">
                        <div
                          v-for="note in leadNotes[lead.id]"
                          :key="note.id"
                          class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden"
                        >
                          <div class="px-3 py-1.5 bg-gray-50 dark:bg-gray-800/50 border-b border-default flex items-center justify-between">
                            <span class="text-xs">
                              Notu Ekleyen: <span class="text-primary">{{ note.createdByName }}</span>
                              <span class="mx-1 text-muted">/</span>
                              <span class="text-muted">{{ formatNoteDate(note.createdAt) }}</span>
                            </span>
                            <UButton v-if="activeTab === 'devam'" icon="i-lucide-trash-2" size="2xs" color="error" variant="ghost" @click="deleteLeadNote(note.id, lead.id)" />
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
              </tbody>
            </table>
          </div>

          <!-- Sayfalama -->
          <div v-if="pagination.totalPages > 1" class="flex items-center justify-between pt-3 mt-1 px-1">
            <span class="text-xs text-muted">Toplam {{ pagination.total }} lead</span>
            <div class="flex items-center gap-1">
              <UButton
                icon="i-lucide-chevron-left"
                size="xs"
                color="neutral"
                variant="ghost"
                :disabled="pagination.page <= 1"
                @click="pagination.page--; fetchLeads()"
              />
              <span class="text-xs tabular-nums px-2">{{ pagination.page }} / {{ pagination.totalPages }}</span>
              <UButton
                icon="i-lucide-chevron-right"
                size="xs"
                color="neutral"
                variant="ghost"
                :disabled="pagination.page >= pagination.totalPages"
                @click="pagination.page++; fetchLeads()"
              />
            </div>
          </div>
        </div>
    </UCard>

    <!-- Hızlı Lead Modalı -->
    <UModal v-model:open="quickModalOpen" title="Hızlı Lead" class="sm:max-w-md" :dismissible="false" :ui="{ content: 'flex flex-col max-h-[90vh]', body: 'flex-1 overflow-y-auto min-h-0 relative' }">
      <template #body>
        <div v-if="savingQuick" class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 bg-white/85 dark:bg-gray-900/85 rounded-xl backdrop-blur-sm">
          <UIcon name="i-lucide-loader-circle" class="size-10 animate-spin text-primary" />
          <p class="text-sm">Kaydediliyor...</p>
        </div>
        <div class="flex flex-col gap-4">
          <!-- Ruhsat Yükleme -->
          <div class="space-y-2" tabindex="0" @paste="onQuickPaste">
            <p class="text-sm font-medium">Ruhsat Fotoğrafı <span class="text-[var(--ui-error)]">*</span></p>
            <div
              class="relative border-2 border-dashed rounded-lg p-6 text-center transition-colors cursor-pointer"
              :class="quickDragging ? 'border-primary bg-primary/5' : 'border-gray-300 dark:border-gray-600 hover:border-primary/50'"
              @dragover.prevent="quickDragging = true"
              @dragleave.prevent="quickDragging = false"
              @drop.prevent="onQuickFileDrop"
              @click="($refs.quickFileInput as HTMLInputElement)?.click()"
            >
              <input ref="quickFileInput" type="file" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" class="hidden" @change="onQuickFileSelect" />
              <UIcon name="i-lucide-upload-cloud" class="size-8 text-muted mx-auto mb-2" />
              <p class="text-sm text-muted">Ruhsatı sürükleyip bırakın, <span class="text-primary font-medium">tıklayarak seçin</span> veya <span class="text-primary font-medium">Ctrl+V ile yapıştırın</span></p>
              <p class="text-xs text-muted mt-1">JPG, PNG, WebP, PDF — Maks. 10MB</p>
            </div>
            <div v-if="quickFiles.length" class="space-y-1">
              <div v-for="(file, idx) in quickFiles" :key="idx" class="flex items-center justify-between py-1.5 px-3 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                <div class="flex items-center gap-2 min-w-0">
                  <UIcon :name="file.type === 'application/pdf' ? 'i-lucide-file-text' : 'i-lucide-image'" class="size-4 text-muted shrink-0" />
                  <span class="text-xs font-medium truncate">{{ file.name }}</span>
                </div>
                <UButton icon="i-lucide-x" color="error" variant="ghost" size="xs" @click="removeQuickFile(idx)" />
              </div>
            </div>
          </div>

          <!-- Telefon -->
          <PhoneInput v-model="quickForm.phone" label="Telefon No" :required="true" />

          <!-- Ürün (opsiyonel) -->
          <div class="relative fl-select [&_.truncate]:!font-semibold">
            <USelectMenu v-model="quickForm.productId" :items="productOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
            <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', quickForm.productId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Ürün <span class="text-[var(--ui-error)]">*</span></label>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="w-full flex justify-end items-center gap-3">
          <UButton label="İptal" color="neutral" variant="outline" size="xl" class="w-36 justify-center" :disabled="savingQuick" @click="quickModalOpen = false" />
          <UButton label="Lead Oluştur" icon="i-lucide-zap" color="primary" size="xl" class="w-36 justify-center" :loading="savingQuick" :disabled="savingQuick" @click="saveQuickLead" />
        </div>
      </template>
    </UModal>

    <!-- Silme Onayı -->
    <UModal :dismissible="false" v-model:open="deleteModalOpen" title="Lead Sil">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-error/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-triangle-alert" class="size-5 text-error" />
          </div>
          <div>
            <p class="font-medium">Bu lead'i silmek istediğinize emin misiniz?</p>
            <p class="text-sm text-muted mt-1">Bu işlem geri alınamaz.</p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" size="xl"  @click="deleteModalOpen = false" />
          <UButton label="Sil" color="error" icon="i-lucide-trash-2" size="xl"  @click="doDelete" />
        </div>
      </template>
    </UModal>

    <!-- Düzenleme Modalı -->
    <UModal v-model:open="editModalOpen" title="Lead Düzenle" class="sm:max-w-2xl" :dismissible="false" :ui="{ content: 'flex flex-col max-h-[90vh]', body: 'flex-1 overflow-y-auto min-h-0 relative' }">
      <template #body>
        <div v-if="savingEdit" class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 bg-white/85 dark:bg-gray-900/85 rounded-xl backdrop-blur-sm">
          <UIcon name="i-lucide-loader-circle" class="size-10 animate-spin text-primary" />
          <p class="text-sm">Güncelleniyor...</p>
        </div>
        <div class="flex flex-col gap-4 [&_input]:!font-semibold">
          <!-- TC Kimlik No -->
          <div class="relative fl-input">
            <UInput :model-value="editForm.tcNo" placeholder=" " class="w-full peer/fl-etc" @update:model-value="(v: string) => editForm.tcNo = v.replace(/\D/g, '').slice(0, 11)" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-etc:top-0 peer-focus-within/fl-etc:-translate-y-1/2 peer-focus-within/fl-etc:text-xs peer-focus-within/fl-etc:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-etc:top-0 peer-has-[input:not(:placeholder-shown)]/fl-etc:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-etc:text-xs peer-has-[input:not(:placeholder-shown)]/fl-etc:text-[var(--ui-text-highlighted)]">TC Kimlik No <span class="text-[var(--ui-error)]">*</span></label>
          </div>

          <!-- Ad Soyad + Doğum Tarihi -->
          <div class="grid grid-cols-2 gap-3">
            <div class="relative fl-input">
              <UInput v-model="editForm.fullName" placeholder=" " class="w-full peer/fl-ename" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-ename:top-0 peer-focus-within/fl-ename:-translate-y-1/2 peer-focus-within/fl-ename:text-xs peer-focus-within/fl-ename:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-ename:top-0 peer-has-[input:not(:placeholder-shown)]/fl-ename:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-ename:text-xs peer-has-[input:not(:placeholder-shown)]/fl-ename:text-[var(--ui-text-highlighted)]">Ad Soyad <span class="text-[var(--ui-error)]">*</span></label>
            </div>
            <div class="relative fl-input">
              <UInput :model-value="editBirthDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-ebd" @keydown="editPreventNonDigit" @update:model-value="onEditBirthInput">
                <template #trailing>
                  <UPopover v-model:open="editBirthOpen">
                    <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" />
                    <template #content>
                      <UCalendar locale="tr-TR" v-model="editBirthCal" class="p-2" @update:model-value="onEditBirthChange" />
                    </template>
                  </UPopover>
                </template>
              </UInput>
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-ebd:top-0 peer-focus-within/fl-ebd:-translate-y-1/2 peer-focus-within/fl-ebd:text-xs peer-focus-within/fl-ebd:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-ebd:top-0 peer-has-[input:not(:placeholder-shown)]/fl-ebd:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-ebd:text-xs peer-has-[input:not(:placeholder-shown)]/fl-ebd:text-[var(--ui-text-highlighted)]">Doğum Tarihi <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </div>

          <!-- Telefon -->
          <PhoneInput v-model="editForm.phone" label="Telefon No" />

          <!-- Ürün + Kaynak -->
          <div class="grid grid-cols-2 gap-3">
            <div class="relative fl-select [&_.truncate]:!font-semibold">
              <USelectMenu v-model="editForm.productId" :items="productOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', editForm.productId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Ürün <span class="text-[var(--ui-error)]">*</span></label>
            </div>
            <div class="relative fl-select [&_.truncate]:!font-semibold">
              <USelectMenu v-model="editForm.sourceId" :items="sourceOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', editForm.sourceId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Kaynak <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="w-full flex justify-end items-center gap-3">
          <UButton label="İptal" color="neutral" variant="outline" size="xl" class="w-36 justify-center" :disabled="savingEdit" @click="editModalOpen = false" />
          <UButton label="Güncelle" icon="i-lucide-check" color="primary" size="xl" class="w-36 justify-center" :loading="savingEdit" :disabled="savingEdit" @click="saveEdit" />
        </div>
      </template>
    </UModal>

    <!-- Durum Güncelleme Modalı -->
    <UModal v-model:open="statusModalOpen" title="Durum Güncellemesi" class="sm:max-w-sm">
      <template #body>
        <!-- Seçim aşaması -->
        <div v-if="statusStep === 'choose'" class="space-y-3">
          <p class="text-sm text-muted">Bu lead için sonucu seçin:</p>
          <div class="flex flex-col gap-2">
            <UButton
              label="Satış Tamamlandı"
              icon="i-lucide-check-circle"
              color="primary"
              variant="soft"
              block
              size="xl"
              
              :loading="closingLead"
              @click="closeAsWon"
            />
            <UButton
              label="Satış Tamamlanamadı"
              icon="i-lucide-x-circle"
              color="error"
              variant="soft"
              block
              size="xl"
              
              :disabled="closingLead"
              @click="statusStep = 'lost_reason'"
            />
          </div>
        </div>

        <!-- Kaybedilme nedeni -->
        <div v-else class="space-y-3">
          <p class="text-sm text-muted">Satışın tamamlanamama nedenini yazınız:</p>
          <UTextarea
            v-model="lostReason"
            placeholder="Neden tamamlanamadı..."
            :rows="3"
            autofocus
            class="w-full"
          />
          <div class="flex justify-end gap-2">
            <UButton label="Geri" color="neutral" variant="outline" size="xl"  :disabled="closingLead" @click="statusStep = 'choose'" />
            <UButton label="Kaydet" color="error" size="xl"  icon="i-lucide-check" :loading="closingLead" :disabled="closingLead || !lostReason.trim()" @click="closeAsLost" />
          </div>
        </div>
      </template>
    </UModal>

    <!-- Yeni Lead Modalı -->
    <UModal v-model:open="addModalOpen" title="Yeni Lead" class="sm:max-w-2xl" :dismissible="false" :ui="{ content: 'flex flex-col max-h-[90vh]', body: 'flex-1 overflow-y-auto min-h-0 relative' }">
      <template #body>
        <div v-if="savingLead" class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 bg-white/85 dark:bg-gray-900/85 rounded-xl backdrop-blur-sm">
          <UIcon name="i-lucide-loader-circle" class="size-10 animate-spin text-primary" />
          <p class="text-sm">Kaydediliyor...</p>
        </div>
        <div class="flex flex-col gap-4 [&_input]:!font-semibold">

          <!-- TC Kimlik No -->
          <div class="relative fl-input">
            <UInput :model-value="addForm.tcNo" placeholder=" " class="w-full peer/fl-tc" @update:model-value="onTcInput" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-tc:top-0 peer-focus-within/fl-tc:-translate-y-1/2 peer-focus-within/fl-tc:text-xs peer-focus-within/fl-tc:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-tc:top-0 peer-has-[input:not(:placeholder-shown)]/fl-tc:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-tc:text-xs peer-has-[input:not(:placeholder-shown)]/fl-tc:text-[var(--ui-text-highlighted)]">TC Kimlik No <span class="text-[var(--ui-error)]">*</span></label>
          </div>

          <!-- Ad Soyad + Doğum Tarihi -->
          <div class="grid grid-cols-2 gap-3">
            <div class="relative fl-input">
              <UInput v-model="addForm.fullName" placeholder=" " class="w-full peer/fl-name" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-name:top-0 peer-focus-within/fl-name:-translate-y-1/2 peer-focus-within/fl-name:text-xs peer-focus-within/fl-name:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-name:top-0 peer-has-[input:not(:placeholder-shown)]/fl-name:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-name:text-xs peer-has-[input:not(:placeholder-shown)]/fl-name:text-[var(--ui-text-highlighted)]">Ad Soyad <span class="text-[var(--ui-error)]">*</span></label>
            </div>
            <div class="relative fl-input">
              <UInput :model-value="birthDateDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-bd" @keydown="preventNonDigitKey" @update:model-value="onBirthDateInput">
                <template #trailing>
                  <UPopover v-model:open="birthDateOpen">
                    <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" />
                    <template #content>
                      <UCalendar locale="tr-TR" v-model="birthDateCal" class="p-2" @update:model-value="onBirthDateChange" />
                    </template>
                  </UPopover>
                </template>
              </UInput>
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-bd:top-0 peer-focus-within/fl-bd:-translate-y-1/2 peer-focus-within/fl-bd:text-xs peer-focus-within/fl-bd:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-bd:top-0 peer-has-[input:not(:placeholder-shown)]/fl-bd:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-bd:text-xs peer-has-[input:not(:placeholder-shown)]/fl-bd:text-[var(--ui-text-highlighted)]">Doğum Tarihi <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </div>

          <!-- Telefon -->
          <PhoneInput v-model="addForm.phone" label="Telefon No" :required="true" />

          <!-- Ürün + Kaynak -->
          <div class="grid grid-cols-2 gap-3">
            <div class="relative fl-select [&_.truncate]:!font-semibold">
              <USelectMenu
                v-model="addForm.productId"
                :items="productOptions"
                value-key="value"
                label-key="label"
                placeholder=" "
                searchable
                :search-input="{ placeholder: 'Ara...' }"
                :search-attributes="['label']"
                class="w-full"
              />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', addForm.productId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Ürün <span class="text-[var(--ui-error)]">*</span></label>
            </div>
            <div class="relative fl-select [&_.truncate]:!font-semibold">
              <USelectMenu
                v-model="addForm.sourceId"
                :items="sourceOptions"
                value-key="value"
                label-key="label"
                placeholder=" "
                searchable
                :search-input="{ placeholder: 'Ara...' }"
                :search-attributes="['label']"
                class="w-full"
              />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', addForm.sourceId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Kaynak <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </div>

          <!-- Atanan Kişi -->
          <div class="relative fl-select [&_.truncate]:!font-semibold">
            <template v-if="isAllianzSource">
              <UInput model-value="Havuza At (Atanmamış)" disabled class="w-full" />
            </template>
            <template v-else>
              <USelectMenu
                v-model="addForm.assignedTo"
                :items="userOptions"
                value-key="value"
                label-key="label"
                placeholder=" "
                searchable
                :search-input="{ placeholder: 'Ara...' }"
                :search-attributes="['label']"
                class="w-full"
              />
            </template>
            <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', (addForm.assignedTo || isAllianzSource) ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Atanan Kişi</label>
          </div>

          <!-- Dosya Yükleme (Sürükle & Bırak) -->
          <div v-if="selectedProductRequiresFile" class="space-y-2">
            <p class="text-sm font-medium">Belge Yükle</p>
            <div
              class="relative border-2 border-dashed rounded-lg p-6 text-center transition-colors cursor-pointer"
              :class="isDragging ? 'border-primary bg-primary/5' : 'border-gray-300 dark:border-gray-600 hover:border-primary/50'"
              @dragover.prevent="isDragging = true"
              @dragleave.prevent="isDragging = false"
              @drop.prevent="onFileDrop"
              @click="($refs.fileInput as HTMLInputElement)?.click()"
            >
              <input ref="fileInput" type="file" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" class="hidden" @change="onFileSelect" />
              <UIcon name="i-lucide-upload-cloud" class="size-8 text-muted mx-auto mb-2" />
              <p class="text-sm text-muted">Dosyaları sürükleyip bırakın veya <span class="text-primary font-medium">tıklayarak seçin</span></p>
              <p class="text-xs text-muted mt-1">JPG, PNG, WebP, PDF — Maks. 10MB</p>
            </div>

            <!-- Yüklenen dosyalar listesi -->
            <div v-if="uploadFiles.length" class="space-y-1">
              <div
                v-for="(file, idx) in uploadFiles"
                :key="idx"
                class="flex items-center justify-between py-1.5 px-3 rounded-lg bg-gray-50 dark:bg-gray-800/50"
              >
                <div class="flex items-center gap-2 min-w-0">
                  <UIcon :name="file.type === 'application/pdf' ? 'i-lucide-file-text' : 'i-lucide-image'" class="size-4 text-muted shrink-0" />
                  <span class="text-xs font-medium truncate">{{ file.name }}</span>
                  <span class="text-xs text-muted shrink-0">{{ formatFileSize(file.size) }}</span>
                </div>
                <UButton icon="i-lucide-x" color="error" variant="ghost" size="xs" @click="removeFile(idx)" />
              </div>
            </div>
          </div>

        </div>
      </template>
      <template #footer>
        <div class="w-full flex justify-end items-center gap-3">
          <UButton label="İptal" color="neutral" variant="outline" size="xl" class="w-36 justify-center" :disabled="savingLead" @click="addModalOpen = false" />
          <UButton label="Lead Kaydet" icon="i-lucide-user-plus" color="primary" size="xl" class="w-36 justify-center" :loading="savingLead" :disabled="savingLead" @click="saveLead" />
        </div>
      </template>
    </UModal>
  </div>
</template>

<style scoped>
.leads-table td,
.leads-table th {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: clip;
}

.lead-badge {
  display: inline-block;
  width: 110px;
  padding: 2px 6px;
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

.lead-result-badge {
  display: inline-block;
  width: 90px;
  padding: 4px 6px;
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

.lead-badge-won {
  background: rgb(34 197 94 / 0.1);
  color: #22c55e;
}

.lead-badge-lost {
  background: rgb(239 68 68 / 0.1);
  color: #ef4444;
  cursor: help;
}

.lead-badge-pending {
  background: rgb(245 158 11 / 0.1);
  color: #f59e0b;
  cursor: pointer;
  transition: background 0.15s;
}
.lead-badge-pending:hover {
  background: rgb(245 158 11 / 0.2);
}

.lead-badge-acik {
  background: rgb(59 130 246 / 0.1);
  color: #3b82f6;
  cursor: pointer;
  transition: background 0.15s;
}
.lead-badge-acik:hover {
  background: rgb(59 130 246 / 0.2);
}
.lead-badge-acik:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.lead-time-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 24px;
  padding: 0 4px;
  border-radius: 9999px;
  font-size: 10px;
  font-weight: 700;
  line-height: 1;
  background: rgb(107 114 128 / 0.1);
  color: #6b7280;
  white-space: nowrap;
  tabular-nums: true;
}

.lead-time-urgent {
  background: rgb(239 68 68 / 0.1);
  color: #ef4444;
}
</style>
