<script setup lang="ts">
definePageMeta({ layout: 'default', middleware: 'auth' })

const route = useRoute()
const toast = useToast()
const { get, post } = useApi()
const { user } = useAuth()

const leadId = computed(() => Number(route.params.id))

interface LeadDetail {
  id: number
  sourceName: string | null
  sourceColor: string | null
  productName: string | null
  productColor: string | null
  assignedTo: number | null
  assignedName: string | null
  status: string
  fullName: string | null
  tcNo: string | null
  birthDate: string | null
  phone: string
  lostReason: string | null
  closedAt: string | null
  createdAt: string
  createdByName: string
  activities: { id: number; type: string; content: string | null; oldValue: string | null; newValue: string | null; userName: string; createdAt: string }[]
}

interface LeadFile {
  id: number
  originalName: string
  filePath: string
  fileSize: number
  mimeType: string
  uploadedBy: string
  createdAt: string
}

const lead = ref<LeadDetail | null>(null)
const files = ref<LeadFile[]>([])
const loading = ref(true)

async function fetchLead() {
  loading.value = true
  try {
    const res = await get(`leads/${leadId.value}`)
    lead.value = res.data
    useSeoMeta({ title: lead.value?.fullName || 'Lead Detay' })
  } catch {
    toast.add({ title: 'Lead yüklenemedi', color: 'error' })
  }
  loading.value = false
}

async function fetchFiles() {
  try {
    const res = await get(`leads/${leadId.value}/files`)
    files.value = res.data || []
  } catch {}
}

onMounted(() => {
  Promise.all([fetchLead(), fetchFiles()])
})

// Telefon formatlama
function formatPhone(phone: string): string {
  if (!phone) return ''
  const d = phone.replace(/\D/g, '')
  if (d.length === 12 && d.startsWith('90')) return `+90 ${d.slice(2, 5)} ${d.slice(5, 8)} ${d.slice(8, 10)} ${d.slice(10, 12)}`
  if (d.length === 11 && d.startsWith('0')) return `+90 ${d.slice(1, 4)} ${d.slice(4, 7)} ${d.slice(7, 9)} ${d.slice(9, 11)}`
  if (d.length === 10) return `+90 ${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6, 8)} ${d.slice(8, 10)}`
  return phone
}

function formatDate(date: string | null): string {
  if (!date) return '-'
  const d = new Date(date)
  if (isNaN(d.getTime())) return '-'
  return d.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatDateTime(date: string | null): string {
  if (!date) return '-'
  const d = new Date(date)
  if (isNaN(d.getTime())) return '-'
  return d.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' + d.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' })
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

// Durum Güncelleme
const statusModalOpen = ref(false)
const statusStep = ref<'choose' | 'lost_reason'>('choose')
const lostReason = ref('')
const closingLead = ref(false)

function openStatusModal() {
  statusStep.value = 'choose'
  lostReason.value = ''
  statusModalOpen.value = true
}

async function closeAsWon() {
  if (closingLead.value) return
  closingLead.value = true
  try {
    await post(`leads/${leadId.value}/close-process`, { result: 'KAZANILDI' })
    toast.add({ title: 'Satış tamamlandı', color: 'success' })
    statusModalOpen.value = false
    fetchLead()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  closingLead.value = false
}

async function closeAsLost() {
  if (closingLead.value || !lostReason.value.trim()) return
  closingLead.value = true
  try {
    await post(`leads/${leadId.value}/close-process`, { result: 'KAYBEDILDI', lostReason: lostReason.value.trim() })
    toast.add({ title: 'Lead kapatıldı', color: 'success' })
    statusModalOpen.value = false
    fetchLead()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  closingLead.value = false
}

// Dosya yükleme (sürükle-bırak)
const isDragging = ref(false)
const uploading = ref(false)

async function uploadFile(file: File) {
  const allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
  if (!allowed.includes(file.type)) { toast.add({ title: 'Sadece JPG, PNG, WebP ve PDF yüklenebilir', color: 'error' }); return }
  if (file.size > 10 * 1024 * 1024) { toast.add({ title: 'Dosya 10MB\'dan büyük olamaz', color: 'error' }); return }

  uploading.value = true
  try {
    const { token } = useAuth()
    const fd = new FormData()
    fd.append('file', file)
    const res = await fetch(`/api/leads/${leadId.value}/upload-file`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token.value}` },
      body: fd,
    })
    if (!res.ok) throw new Error('Yükleme başarısız')
    toast.add({ title: 'Dosya yüklendi', color: 'success' })
    fetchFiles()
  } catch {
    toast.add({ title: 'Dosya yüklenemedi', color: 'error' })
  }
  uploading.value = false
}

function onFileDrop(e: DragEvent) {
  isDragging.value = false
  const fileList = e.dataTransfer?.files
  if (fileList) for (const f of Array.from(fileList)) uploadFile(f)
}

function onFileSelect(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files) for (const f of Array.from(input.files)) uploadFile(f)
  input.value = ''
}

const deleteFileModalOpen = ref(false)
const deletingFileId = ref<number | null>(null)

function confirmDeleteFile(fileId: number) {
  deletingFileId.value = fileId
  deleteFileModalOpen.value = true
}

async function doDeleteFile() {
  if (!deletingFileId.value) return
  try {
    const { token } = useAuth()
    const res = await fetch(`/api/leads/${leadId.value}/files/${deletingFileId.value}`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${token.value}` },
    })
    if (!res.ok) throw new Error()
    toast.add({ title: 'Dosya silindi', color: 'success' })
    fetchFiles()
  } catch {
    toast.add({ title: 'Dosya silinemedi', color: 'error' })
  }
  deleteFileModalOpen.value = false
  deletingFileId.value = null
}

// Düzenleme modalı
const editModalOpen = ref(false)
const editForm = ref({ fullName: '', tcNo: '', birthDate: '', phone: '', sourceId: undefined as number | undefined, productId: undefined as number | undefined })
const savingEdit = ref(false)

// Dropdown verileri
const leadSources = ref<{ label: string; value: number }[]>([])
const leadProducts = ref<{ label: string; value: number }[]>([])

onMounted(async () => {
  try {
    const [srcRes, prdRes] = await Promise.all([
      get('lead-sources?all=1').catch(() => ({ data: [] })),
      get('lead-products?all=1').catch(() => ({ data: [] })),
    ])
    leadSources.value = (srcRes.data || []).filter((s: any) => s.isActive).map((s: any) => ({ label: s.name, value: s.id }))
    leadProducts.value = (prdRes.data || []).filter((p: any) => p.isActive).map((p: any) => ({ label: p.name, value: p.id }))
  } catch {}
})

// Doğum tarihi (takvimli)
const editBirthDateDisplay = ref('')
const editBirthDateCal = ref<any>()
const editBirthDateOpen = ref(false)

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
  editBirthDateDisplay.value = formatted
  const iso = editParseToIso(formatted)
  if (iso) editForm.value.birthDate = iso
}
function onEditBirthChange(val: any) {
  editBirthDateCal.value = val
  const iso = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  editForm.value.birthDate = iso
  editBirthDateDisplay.value = `${String(val.day).padStart(2, '0')}.${String(val.month).padStart(2, '0')}.${val.year}`
  editBirthDateOpen.value = false
}
function isoToDisplay(iso: string): string {
  if (!iso) return ''
  const [y, m, d] = iso.split('-')
  return `${d}.${m}.${y}`
}

function openEditModal() {
  if (!lead.value) return
  editForm.value = {
    fullName: lead.value.fullName || '',
    tcNo: lead.value.tcNo || '',
    birthDate: lead.value.birthDate || '',
    phone: lead.value.phone || '',
    sourceId: lead.value.sourceId ?? undefined,
    productId: lead.value.productId ?? undefined,
  }
  editBirthDateDisplay.value = isoToDisplay(lead.value.birthDate || '')
  editBirthDateCal.value = undefined
  editModalOpen.value = true
}

async function saveEdit() {
  if (savingEdit.value) return
  savingEdit.value = true
  try {
    const { token } = useAuth()
    const res = await fetch(`/api/leads/${leadId.value}`, {
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
    fetchLead()
  } catch {
    toast.add({ title: 'Bağlantı hatası', color: 'error' })
  }
  savingEdit.value = false
}

// Görsel önizleme
const previewOpen = ref(false)
const previewUrl = ref('')
const previewName = ref('')
const previewZoom = ref(1)

function openPreview(file: LeadFile) {
  if (file.mimeType === 'application/pdf') {
    window.open(`/api/uploads/${file.filePath}`, '_blank')
    return
  }
  previewUrl.value = `/api/uploads/${file.filePath}`
  previewName.value = file.originalName
  previewZoom.value = 1
  panOffset.value = { x: 0, y: 0 }
  previewOpen.value = true
}

function zoomIn() { previewZoom.value = Math.min(previewZoom.value + 0.25, 4) }
function zoomOut() { previewZoom.value = Math.max(previewZoom.value - 0.25, 0.25) }
function zoomReset() { previewZoom.value = 1 }
function onPreviewWheel(e: WheelEvent) {
  e.preventDefault()
  if (e.deltaY < 0) zoomIn()
  else zoomOut()
}

// Sürükleme (pan)
const isDraggingImage = ref(false)
const dragStart = ref({ x: 0, y: 0 })
const panOffset = ref({ x: 0, y: 0 })
const panStartOffset = ref({ x: 0, y: 0 })

function onImageMouseDown(e: MouseEvent) {
  if (previewZoom.value <= 1) return
  isDraggingImage.value = true
  dragStart.value = { x: e.clientX, y: e.clientY }
  panStartOffset.value = { ...panOffset.value }
  e.preventDefault()
}

function onImageMouseMove(e: MouseEvent) {
  if (!isDraggingImage.value) return
  panOffset.value = {
    x: panStartOffset.value.x + (e.clientX - dragStart.value.x),
    y: panStartOffset.value.y + (e.clientY - dragStart.value.y),
  }
}

function onImageMouseUp() {
  isDraggingImage.value = false
}

// Zoom sıfırlandığında pan'i de sıfırla
watch(previewZoom, (val) => {
  if (val <= 1) panOffset.value = { x: 0, y: 0 }
})

// Aktivite tipi label
function activityLabel(type: string): string {
  const map: Record<string, string> = { NOT: 'Not', ARAMA: 'Arama', DURUM: 'Durum Değişikliği', ATAMA: 'Atama' }
  return map[type] || type
}

function activityIcon(type: string): string {
  const map: Record<string, string> = { NOT: 'i-lucide-message-square', ARAMA: 'i-lucide-phone', DURUM: 'i-lucide-git-branch', ATAMA: 'i-lucide-user-check' }
  return map[type] || 'i-lucide-activity'
}

// Durum badge
function statusLabel(s: string): string {
  const map: Record<string, string> = { ACIK: 'Açık', DEVAM: 'Devam Ediyor', KAZANILDI: 'Kazanıldı', KAYBEDILDI: 'Kaybedildi' }
  return map[s] || s
}

function statusColor(s: string): string {
  const map: Record<string, string> = { ACIK: 'bg-blue-100 text-blue-700', DEVAM: 'bg-amber-100 text-amber-700', KAZANILDI: 'bg-green-100 text-green-700', KAYBEDILDI: 'bg-red-100 text-red-700' }
  return map[s] || 'bg-gray-100 text-gray-700'
}
</script>

<template>
  <div v-if="loading" class="py-12 text-center text-muted">Yükleniyor...</div>
  <div v-else-if="!lead" class="py-12 text-center text-muted">Lead bulunamadı</div>
  <div v-else class="space-y-4">

    <!-- Üst Bar -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" size="sm" to="/leadler" />
        <div>
          <h2 class="text-lg">{{ lead.fullName || 'İsimsiz Lead' }}</h2>
          <p class="text-xs text-muted">{{ formatPhone(lead.phone) }}</p>
        </div>
        <span class="rounded-md px-2.5 py-1 text-xs" :class="statusColor(lead.status)">{{ statusLabel(lead.status) }}</span>
      </div>
      <UButton
        v-if="lead.status === 'DEVAM'"
        label="Durumu Güncelle"
        icon="i-lucide-edit"
        size="sm"
        color="primary"
        @click="openStatusModal"
      />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

      <!-- Sol: Lead Bilgileri -->
      <div class="lg:col-span-2 space-y-4">
        <UCard :ui="{ body: 'p-4' }">
          <template #header>
            <div class="flex items-center justify-between">
              <h3 class="text-sm">Lead Bilgileri</h3>
              <UButton label="Düzenle" icon="i-lucide-pencil" size="xs" color="neutral" variant="outline" @click="openEditModal" />
            </div>
          </template>
          <div class="grid grid-cols-2 gap-y-3 gap-x-6 text-sm">
            <div>
              <p class="text-xs text-muted">TC Kimlik No</p>
              <p class="tabular-nums">{{ lead.tcNo || '-' }}</p>
            </div>
            <div>
              <p class="text-xs text-muted">Doğum Tarihi</p>
              <p class="tabular-nums">{{ formatDate(lead.birthDate) }}</p>
            </div>
            <div>
              <p class="text-xs text-muted">Telefon</p>
              <p class="tabular-nums">{{ formatPhone(lead.phone) }}</p>
            </div>
            <div>
              <p class="text-xs text-muted">Ürün</p>
              <span v-if="lead.productName" class="inline-block rounded-md px-2 py-0.5 text-xs" :style="{ backgroundColor: (lead.productColor || '#8b5cf6') + '1a', color: lead.productColor || '#8b5cf6' }">{{ lead.productName }}</span>
              <p v-else class="text-muted">-</p>
            </div>
            <div>
              <p class="text-xs text-muted">Kaynak</p>
              <span v-if="lead.sourceName" class="inline-block rounded-md px-2 py-0.5 text-xs" :style="{ backgroundColor: (lead.sourceColor || '#6b7280') + '1a', color: lead.sourceColor || '#6b7280' }">{{ lead.sourceName }}</span>
              <p v-else class="text-muted">-</p>
            </div>
            <div>
              <p class="text-xs text-muted">Atanan</p>
              <p >{{ lead.assignedName || '-' }}</p>
            </div>
            <div>
              <p class="text-xs text-muted">Oluşturan</p>
              <p >{{ lead.createdByName || '-' }}</p>
            </div>
            <div>
              <p class="text-xs text-muted">Oluşturulma</p>
              <p class="tabular-nums">{{ formatDateTime(lead.createdAt) }}</p>
            </div>
            <div v-if="lead.closedAt">
              <p class="text-xs text-muted">Kapanma</p>
              <p class="tabular-nums">{{ formatDateTime(lead.closedAt) }}</p>
            </div>
            <div v-if="lead.lostReason" class="col-span-2">
              <p class="text-xs text-muted">Kaybedilme Nedeni</p>
              <p class="text-red-600">{{ lead.lostReason }}</p>
            </div>
          </div>
        </UCard>

        <!-- Belgeler -->
        <UCard :ui="{ body: 'p-4' }">
          <template #header>
            <div class="flex items-center justify-between">
              <h3 class="text-sm">Belgeler</h3>
              <span class="text-xs text-muted">{{ files.length }} dosya</span>
            </div>
          </template>

          <!-- Dosya Listesi -->
          <div v-if="files.length" class="space-y-1">
            <div
              v-for="f in files"
              :key="f.id"
              class="flex items-center justify-between py-2 px-3 rounded-lg bg-gray-50 dark:bg-gray-800/50 group"
            >
              <div class="flex items-center gap-2 min-w-0">
                <UIcon :name="f.mimeType === 'application/pdf' ? 'i-lucide-file-text' : 'i-lucide-image'" class="size-4 text-muted shrink-0" />
                <button class="text-xs font-medium text-primary hover:underline overflow-hidden whitespace-nowrap text-left" @click="openPreview(f)">{{ f.originalName }}</button>
                <span class="text-[11px] text-muted shrink-0">{{ formatFileSize(f.fileSize) }}</span>
              </div>
              <div class="flex items-center gap-1">
                <UButton icon="i-lucide-eye" color="neutral" variant="ghost" size="xs" @click="openPreview(f)" />
                <UButton
                  v-if="lead.status === 'DEVAM'"
                  icon="i-lucide-trash-2"
                  color="error"
                  variant="ghost"
                  size="xs"
                  class="opacity-0 group-hover:opacity-100 transition-opacity"
                  @click="confirmDeleteFile(f.id)"
                />
              </div>
            </div>
          </div>
          <p v-else-if="lead.status !== 'DEVAM'" class="text-xs text-muted text-center py-4">Yüklü belge bulunmuyor</p>
        </UCard>
      </div>

      <!-- Sağ: Aktivite Zaman Çizelgesi -->
      <div>
        <UCard :ui="{ body: 'p-4' }">
          <template #header>
            <h3 class="text-sm">Aktivite Geçmişi</h3>
          </template>
          <div v-if="lead.activities?.length" class="space-y-4">
            <div v-for="a in lead.activities" :key="a.id" class="flex gap-3">
              <div class="flex flex-col items-center">
                <div class="size-7 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0">
                  <UIcon :name="activityIcon(a.type)" class="size-3.5 text-muted" />
                </div>
                <div class="flex-1 w-px bg-gray-200 dark:bg-gray-700 mt-1" />
              </div>
              <div class="pb-4 min-w-0">
                <p class="text-xs">{{ activityLabel(a.type) }}</p>
                <p v-if="a.content" class="text-xs text-muted mt-0.5">{{ a.content }}</p>
                <p v-if="a.oldValue || a.newValue" class="text-xs text-muted mt-0.5">{{ a.oldValue || '—' }} → {{ a.newValue }}</p>
                <p class="text-[11px] text-muted mt-1">{{ a.userName || 'Sistem' }} · {{ formatDateTime(a.createdAt) }}</p>
              </div>
            </div>
          </div>
          <p v-else class="text-xs text-muted text-center py-4">Henüz aktivite yok</p>
        </UCard>
      </div>
    </div>

    <!-- Durum Güncelleme Modalı -->
    <UModal v-model:open="statusModalOpen" title="Durum Güncellemesi" class="sm:max-w-sm">
      <template #body>
        <div v-if="statusStep === 'choose'" class="space-y-3">
          <p class="text-sm text-muted">Bu lead için sonucu seçin:</p>
          <div class="flex flex-col gap-2">
            <UButton label="Satış Tamamlandı" icon="i-lucide-check-circle" color="primary" variant="soft" block size="lg" :loading="closingLead" @click="closeAsWon" />
            <UButton label="Satış Tamamlanamadı" icon="i-lucide-x-circle" color="error" variant="soft" block size="lg" :disabled="closingLead" @click="statusStep = 'lost_reason'" />
          </div>
        </div>
        <div v-else class="space-y-3">
          <p class="text-sm text-muted">Satışın tamamlanamama nedenini yazınız:</p>
          <UTextarea v-model="lostReason" placeholder="Neden tamamlanamadı..." :rows="3" autofocus class="w-full" />
          <div class="flex justify-end gap-2">
            <UButton label="Geri" color="neutral" variant="outline" size="sm" :disabled="closingLead" @click="statusStep = 'choose'" />
            <UButton label="Kaydet" color="error" size="sm" icon="i-lucide-check" :loading="closingLead" :disabled="closingLead || !lostReason.trim()" @click="closeAsLost" />
          </div>
        </div>
      </template>
    </UModal>

    <!-- Dosya Silme Onayı -->
    <UModal :dismissible="false" v-model:open="deleteFileModalOpen" title="Dosyayı Sil">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-error/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-triangle-alert" class="size-5 text-error" />
          </div>
          <div>
            <p class="font-medium">Bu dosyayı silmek istediğinize emin misiniz?</p>
            <p class="text-sm text-muted mt-1">Bu işlem geri alınamaz.</p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" @click="deleteFileModalOpen = false" />
          <UButton label="Sil" color="error" icon="i-lucide-trash-2" @click="doDeleteFile" />
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
        <div class="modal-form flex flex-col [&_input]:!font-semibold">
          <!-- TC Kimlik No -->
          <div class="relative fl-form">
            <UInput :model-value="editForm.tcNo" placeholder=" " class="w-full peer/fl-etc" @update:model-value="(v: string) => editForm.tcNo = v.replace(/\D/g, '').slice(0, 11)" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-etc:top-0 peer-focus-within/fl-etc:-translate-y-1/2 peer-focus-within/fl-etc:text-xs peer-focus-within/fl-etc:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-etc:top-0 peer-has-[input:not(:placeholder-shown)]/fl-etc:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-etc:text-xs peer-has-[input:not(:placeholder-shown)]/fl-etc:text-[var(--ui-text-highlighted)]">TC Kimlik No</label>
          </div>

          <!-- Ad Soyad + Doğum Tarihi -->
          <div class="grid grid-cols-2 gap-3">
            <div class="relative fl-form">
              <UInput v-model="editForm.fullName" placeholder=" " class="w-full peer/fl-ename" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-ename:top-0 peer-focus-within/fl-ename:-translate-y-1/2 peer-focus-within/fl-ename:text-xs peer-focus-within/fl-ename:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-ename:top-0 peer-has-[input:not(:placeholder-shown)]/fl-ename:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-ename:text-xs peer-has-[input:not(:placeholder-shown)]/fl-ename:text-[var(--ui-text-highlighted)]">Ad Soyad</label>
            </div>
            <div class="relative fl-form">
              <UInput :model-value="editBirthDateDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-ebd" @keydown="editPreventNonDigit" @update:model-value="onEditBirthInput">
                <template #trailing>
                  <UPopover v-model:open="editBirthDateOpen">
                    <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" />
                    <template #content>
                      <UCalendar locale="tr-TR" v-model="editBirthDateCal" class="p-2" @update:model-value="onEditBirthChange" />
                    </template>
                  </UPopover>
                </template>
              </UInput>
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-ebd:top-0 peer-focus-within/fl-ebd:-translate-y-1/2 peer-focus-within/fl-ebd:text-xs peer-focus-within/fl-ebd:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-ebd:top-0 peer-has-[input:not(:placeholder-shown)]/fl-ebd:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-ebd:text-xs peer-has-[input:not(:placeholder-shown)]/fl-ebd:text-[var(--ui-text-highlighted)]">Doğum Tarihi</label>
            </div>
          </div>

          <!-- Telefon -->
          <PhoneInput v-model="editForm.phone" label="Telefon No" modal />

          <!-- Ürün + Kaynak -->
          <div class="grid grid-cols-2 gap-3">
            <div class="relative fl-select-form [&_.truncate]:!font-semibold">
              <USelectMenu v-model="editForm.productId" :items="leadProducts" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', editForm.productId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Ürün</label>
            </div>
            <div class="relative fl-select-form [&_.truncate]:!font-semibold">
              <USelectMenu v-model="editForm.sourceId" :items="leadSources" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', editForm.sourceId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Kaynak</label>
            </div>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="w-full flex justify-end items-center gap-3">
          <UButton label="İptal" color="neutral" variant="outline" size="md" class="w-32 justify-center" :disabled="savingEdit" @click="editModalOpen = false" />
          <UButton label="Güncelle" icon="i-lucide-check" color="primary" size="md" class="w-32 justify-center" :loading="savingEdit" :disabled="savingEdit" @click="saveEdit" />
        </div>
      </template>
    </UModal>

    <!-- Görsel Önizleme Modalı -->
    <UModal v-model:open="previewOpen" :title="previewName" class="sm:max-w-4xl" :ui="{ content: 'flex flex-col max-h-[95vh]', body: 'flex-1 overflow-auto min-h-0 p-0 relative' }">
      <template #body>
        <div class="flex flex-col items-center bg-gray-950/5 dark:bg-gray-950/50 min-h-[300px]">
          <!-- Zoom kontrolleri -->
          <div class="sticky top-0 z-10 flex items-center gap-2 py-2 px-4 bg-white/90 dark:bg-gray-900/90 backdrop-blur-sm w-full justify-center border-b border-default">
            <UButton icon="i-lucide-zoom-out" size="xs" color="neutral" variant="ghost" @click="zoomOut" :disabled="previewZoom <= 0.25" />
            <button class="text-xs font-semibold w-12 text-center" @click="zoomReset">{{ Math.round(previewZoom * 100) }}%</button>
            <UButton icon="i-lucide-zoom-in" size="xs" color="neutral" variant="ghost" @click="zoomIn" :disabled="previewZoom >= 4" />
            <a :href="previewUrl" download class="ml-2">
              <UButton icon="i-lucide-download" size="xs" color="neutral" variant="ghost" title="İndir" />
            </a>
          </div>
          <!-- Görsel -->
          <div
            class="flex-1 flex items-center justify-center p-4 overflow-hidden w-full"
            :class="previewZoom > 1 ? 'cursor-grab' : ''"
            @wheel.prevent="onPreviewWheel"
            @mousedown="onImageMouseDown"
            @mousemove="onImageMouseMove"
            @mouseup="onImageMouseUp"
            @mouseleave="onImageMouseUp"
          >
            <img
              :src="previewUrl"
              :alt="previewName"
              class="max-w-none transition-transform duration-100 select-none"
              :class="isDraggingImage ? 'cursor-grabbing' : ''"
              :style="{ transform: `scale(${previewZoom}) translate(${panOffset.x / previewZoom}px, ${panOffset.y / previewZoom}px)`, transformOrigin: 'center center' }"
              draggable="false"
            />
          </div>
        </div>
      </template>
    </UModal>
  </div>
</template>

<style scoped>
</style>
