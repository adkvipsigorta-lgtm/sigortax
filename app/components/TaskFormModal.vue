<script setup lang="ts">
import { CalendarDate } from '@internationalized/date'

const props = defineProps<{
  task?: any
}>()

const emit = defineEmits<{
  saved: []
}>()

const isOpen = defineModel<boolean>('open', { default: false })
const isEditMode = computed(() => !!props.task)

const toast = useToast()
const { get, post, put } = useApi()
const { user: currentUser } = useAuth()
const { insurances, subcategories, fetchInsurances } = useInsuranceTypes()
const { isFieldEnabled, fetchFieldSettings } = useFieldSettings()

// ---------- Data ----------
const customers = ref<{ label: string; value: number }[]>([])
const users = ref<{ label: string; value: number }[]>([])
const referenceSources = ref<{ label: string; value: number }[]>([])
const loaded = ref(false)
const isNewCustomerOpen = ref(false)

function onNewCustomerSaved(customer?: { id: number, name: string, identityNo: string }) {
  if (!customer) return
  customers.value.unshift({
    label: customer.identityNo ? `${customer.name} (${customer.identityNo})` : customer.name,
    value: customer.id
  })
  form.value.customerId = customer.id
}

// Lead dropdown verileri
const leadSources = ref<{ label: string; value: number }[]>([])
const leadProducts = ref<{ label: string; value: number; requiresFile?: boolean }[]>([])

async function loadData() {
  if (loaded.value) return
  loaded.value = true
  await fetchInsurances()
  try {
    const [custRes, userRes, refRes, lsRes, lpRes] = await Promise.all([
      get<any>('customers/list-all'),
      get<any>('users?dropdown=1'),
      get<any>('reference-sources?all=1'),
      get<any>('lead-sources?all=1'),
      get<any>('lead-products?all=1'),
    ])
    customers.value = (custRes.data || []).map((c: any) => ({
      label: c.identityNo ? `${c.name} (${c.identityNo})` : c.name,
      value: c.id
    }))
    users.value = (userRes.data || []).map((u: any) => ({ label: u.name, value: u.id }))
    referenceSources.value = (refRes.data || []).filter((r: any) => r.isActive).map((r: any) => ({ label: r.name, value: r.id }))
    leadSources.value = (lsRes.data || []).filter((s: any) => s.isActive).map((s: any) => ({ label: s.name, value: s.id }))
    leadProducts.value = (lpRes.data || []).filter((p: any) => p.isActive).map((p: any) => ({ label: p.name, value: p.id, requiresFile: p.requiresFile }))
  } catch {}
}

watch(isOpen, (val) => {
  if (val) {
    loadData()
    fetchFieldSettings()
    if (props.task) populateFromTask(props.task)
  }
  else nextTick(() => resetForm())
})

function populateFromTask(t: any) {
  const od = t.offerData || {}
  activeTab.value = 'OFFER'
  form.value.customerId = od.customerId || t.customerId
  form.value.insuranceId = od.insuranceId || undefined
  form.value.plateNo = od.plateNo || ''
  form.value.registrationNo = od.registrationNo || ''
  form.value.chassisNo = od.chassisNo || ''
  form.value.engineNo = od.engineNo || ''
  form.value.vehicleBrand = od.vehicleBrand || ''
  form.value.vehicleModel = od.vehicleModel || ''
  form.value.vehicleYear = od.vehicleYear || ''
  form.value.uavtCode = od.uavtCode || ''
  form.value.daskNo = od.daskNo || ''
  form.value.network = od.network || ''
  form.value.insureds = od.additionalInsureds || ''
  form.value.offerNote = od.note || ''
  form.value.priority = t.priority || 'MEDIUM'
  form.value.assignedTo = t.assignedTo || undefined
  if (od.expiresAt) {
    finishDateDisplay.value = isoToDisplay(od.expiresAt)
    finishDate.value = isoToCalendarDate(od.expiresAt)
  }
}

// ---------- Options ----------
const insuranceOptions = computed(() =>
  subcategories.value.sort((a, b) => a.name.localeCompare(b.name, 'tr')).map(i => ({ label: i.name, value: i.id }))
)

const networkOptions = [
  { label: 'Geniş', value: 'Geniş' },
  { label: 'Dar', value: 'Dar' },
  { label: 'Devlet', value: 'Devlet' }
]
const priorityOptions = [
  { label: 'Düşük', value: 'LOW' },
  { label: 'Orta', value: 'MEDIUM' },
  { label: 'Yüksek', value: 'HIGH' },
  { label: 'Acil', value: 'URGENT' }
]

// ---------- Tabs ----------
const activeTab = ref<'OFFER' | 'LEAD' | 'QUICK'>('OFFER')

// Lead atama seçenekleri (havuz + kullanıcılar)
const leadAssignOptions = computed(() => [
  { label: 'Havuza At (Atanmamış)', value: -1 },
  ...users.value,
])

// Tab seçildiğinde varsayılan atama: Lead → giren kişi, Quick → havuz
watch(activeTab, (tab) => {
  if (tab === 'LEAD' && currentUser.value?.id) {
    if (!form.value.assignedTo) form.value.assignedTo = currentUser.value.id
  } else if (tab === 'QUICK') {
    form.value.assignedTo = -1
  }
})

// ---------- Form ----------
const defaultForm = {
  // Ortak
  priority: 'MEDIUM' as 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT',
  assignedTo: undefined as number | undefined,
  // Teklif
  customerId: undefined as number | undefined,
  insuranceId: undefined as number | undefined,
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
  // Lead
  leadTcNo: '',
  leadFullName: '',
  leadBirthDate: '',
  leadPhone: '',
  leadProductId: undefined as number | undefined,
  leadSourceId: undefined as number | undefined,
  // Hızlı Lead
  quickPhone: '',
  quickProductId: undefined as number | undefined,
}

const form = ref({ ...defaultForm })

const customerSearchTerm = ref('')
const customerOptions = computed(() => {
  const term = customerSearchTerm.value.toLowerCase().trim()
  const filtered = term
    ? customers.value.filter(c => c.label.toLowerCase().includes(term))
    : customers.value.slice(0, 50)
  const list = filtered.slice(0, 100)
  if (form.value.customerId && !list.some(c => c.value === form.value.customerId)) {
    const selected = customers.value.find(c => c.value === form.value.customerId)
    if (selected) list.unshift(selected)
  }
  return list
})

// Tarih refs
const finishDate = ref<CalendarDate>()
const deadlineDate = ref<CalendarDate>()
const refBirthDateCal = ref<CalendarDate>()
const finishDateDisplay = ref('')
const deadlineDateDisplay = ref('')
const refBirthDateDisplay = ref('')
const finishDateOpen = ref(false)
const deadlineDateOpen = ref(false)
const refBirthDateOpen = ref(false)

// Tarih yardımcı fonksiyonları (PolicyFormModal ile aynı mantık)
function isoToDisplay(iso: string): string {
  if (!iso) return ''
  const [y, m, d] = iso.split('-')
  return `${d}.${m}.${y}`
}
function isoToCalendarDate(iso: string): InstanceType<typeof CalendarDate> | undefined {
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
function preventNonDigitKey(e: KeyboardEvent) {
  if (e.ctrlKey || e.metaKey || e.altKey) return
  if (['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Tab', 'Home', 'End'].includes(e.key)) return
  if (!/^\d$/.test(e.key)) e.preventDefault()
}
function onFinishDateInput(val: string) {
  const formatted = autoFormatDateInput(val)
  finishDateDisplay.value = formatted
  const iso = parseDisplayToIso(formatted)
  if (iso) finishDate.value = isoToCalendarDate(iso)
}
function onFinishDateChange(val: any) {
  finishDate.value = val
  const iso = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  finishDateDisplay.value = isoToDisplay(iso)
  finishDateOpen.value = false
}
function onDeadlineDateInput(val: string) {
  const formatted = autoFormatDateInput(val)
  deadlineDateDisplay.value = formatted
  const iso = parseDisplayToIso(formatted)
  if (iso) deadlineDate.value = isoToCalendarDate(iso)
}
function onDeadlineDateChange(val: any) {
  deadlineDate.value = val
  const iso = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  deadlineDateDisplay.value = isoToDisplay(iso)
  deadlineDateOpen.value = false
}
function onRefBirthDateInput(val: string) {
  const formatted = autoFormatDateInput(val)
  refBirthDateDisplay.value = formatted
  const iso = parseDisplayToIso(formatted)
  if (iso) {
    form.value.leadBirthDate = iso
    refBirthDateCal.value = isoToCalendarDate(iso)
  }
}
function onRefBirthDateChange(val: any) {
  refBirthDateCal.value = val
  const iso = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  form.value.leadBirthDate = iso
  refBirthDateDisplay.value = isoToDisplay(iso)
  refBirthDateOpen.value = false
}

function calendarDateToStr(d: any): string {
  if (!d) return ''
  return `${d.year}-${String(d.month).padStart(2, '0')}-${String(d.day).padStart(2, '0')}`
}
function calendarDateLabel(d: any): string {
  if (!d) return ''
  return `${String(d.day).padStart(2, '0')}/${String(d.month).padStart(2, '0')}/${d.year}`
}

// ---------- Koşullu alanlar (Teklif) ----------
const selectedInsuranceCode = computed(() => {
  if (!form.value.insuranceId) return null
  const ins = insurances.value.find(i => i.id === form.value.insuranceId)
  return (ins as any)?.code || null
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

// Plaka format
function formatPlate(raw: string): string {
  const upper = raw.toUpperCase().replace(/[^A-Z0-9]/g, '')
  const match = upper.match(/^(\d{2})([A-Z]{1,3})(\d{0,4})$/)
  if (match) return `${match[1]} ${match[2]}${match[3] ? ' ' + match[3] : ''}`.trim()
  return upper
}
function onPlateInput(val: string) { form.value.plateNo = formatPlate(val) }

// ---------- Kaydet ----------
const saving = ref(false)

const canSubmit = computed(() => {
  if (activeTab.value === 'OFFER') return !!form.value.customerId && !!form.value.insuranceId
  if (activeTab.value === 'LEAD') return (
    !!form.value.leadTcNo && form.value.leadTcNo.length >= 11 &&
    !!form.value.leadFullName.trim() &&
    !!form.value.leadBirthDate.trim() &&
    !!form.value.leadPhone.trim() &&
    !!form.value.leadProductId &&
    !!form.value.leadSourceId
  )
  if (activeTab.value === 'QUICK') return !!form.value.quickPhone.trim() && !!form.value.quickProductId && quickLeadFiles.value.length > 0
  return false
})

async function save() {
  if (saving.value || !canSubmit.value) return
  saving.value = true
  try {
    const payload: Record<string, any> = {
      priority: form.value.priority,
      assignedTo: form.value.assignedTo || undefined,
    }

    if (activeTab.value === 'OFFER') {
      Object.assign(payload, {
        type: 'OFFER',
        customerId: form.value.customerId,
        insuranceId: form.value.insuranceId,
        expiresAt: calendarDateToStr(finishDate.value) || undefined,
        plateNo: form.value.plateNo || undefined,
        registrationNo: form.value.registrationNo || undefined,
        chassisNo: form.value.chassisNo || undefined,
        engineNo: form.value.engineNo || undefined,
        vehicleBrand: form.value.vehicleBrand || undefined,
        vehicleModel: form.value.vehicleModel || undefined,
        vehicleYear: form.value.vehicleYear || undefined,
        uavtCode: form.value.uavtCode || undefined,
        daskNo: form.value.daskNo || undefined,
        network: form.value.network || undefined,
        additionalInsureds: form.value.insureds || undefined,
        offerNote: form.value.offerNote || undefined,
      })
      if (isEditMode.value && props.task?.id) {
        await put(`tasks/${props.task.id}`, payload)
        toast.add({ title: 'Teklif güncellendi', color: 'success' })
      } else {
        await post('tasks', payload)
        toast.add({ title: 'Teklif görevi oluşturuldu', color: 'success' })
      }
    } else if (activeTab.value === 'LEAD') {
      // Lead oluştur (lead API'sine)
      const leadPayload: Record<string, any> = {
        tcNo: form.value.leadTcNo,
        fullName: form.value.leadFullName,
        birthDate: form.value.leadBirthDate,
        phone: form.value.leadPhone,
        productId: form.value.leadProductId,
        sourceId: form.value.leadSourceId,
        assignedTo: form.value.assignedTo || undefined,
      }
      const leadRes = await post('leads', leadPayload)
      const leadId = leadRes.data?.id

      // Dosyalar yüklenmişse
      if (leadId && leadUploadFiles.value.length) {
        const { token } = useAuth()
        for (const file of leadUploadFiles.value) {
          const fd = new FormData()
          fd.append('file', file)
          await fetch(`/api/leads/${leadId}/upload-file`, {
            method: 'POST',
            headers: { Authorization: `Bearer ${token.value}` },
            body: fd,
          })
        }
      }

      toast.add({ title: 'Lead oluşturuldu', color: 'success' })
      isOpen.value = false
      if (leadRes.data?.selfAssigned) {
        navigateTo(`/leadler/${leadRes.data.id}`)
      } else {
        navigateTo('/leadler')
      }
      return
    } else if (activeTab.value === 'QUICK') {
      // Hızlı Lead
      const quickRes = await post('leads', {
        quick: true,
        phone: form.value.quickPhone.trim(),
        productId: form.value.quickProductId || undefined,
        assignedTo: form.value.assignedTo || undefined,
      })
      const quickId = quickRes.data?.id

      if (quickId && quickLeadFiles.value.length) {
        const { token } = useAuth()
        for (const file of quickLeadFiles.value) {
          const fd = new FormData()
          fd.append('file', file)
          await fetch(`/api/leads/${quickId}/upload-file`, {
            method: 'POST',
            headers: { Authorization: `Bearer ${token.value}` },
            body: fd,
          })
        }
      }

      toast.add({ title: 'Hızlı lead oluşturuldu', color: 'success' })
      isOpen.value = false
      if (quickRes.data?.selfAssigned) {
        navigateTo(`/leadler/${quickRes.data.id}`)
      } else {
        navigateTo('/leadler')
      }
      return
    }

    isOpen.value = false
    emit('saved')
  } catch (e: any) {
    toast.add({ title: e?.message || e?.data?.message || 'İşlem başarısız', color: 'error' })
  } finally {
    saving.value = false
  }
}

function resetForm() {
  form.value = { ...defaultForm }
  finishDate.value = undefined
  deadlineDate.value = undefined
  refBirthDateCal.value = undefined
  finishDateDisplay.value = ''
  deadlineDateDisplay.value = ''
  refBirthDateDisplay.value = ''
  activeTab.value = 'OFFER'
  customerSearchTerm.value = ''
  leadUploadFiles.value = []
  quickLeadFiles.value = []
}

const tabs = [
  { label: 'Teklif Oluştur', value: 'OFFER', icon: 'i-lucide-file-plus' },
  { label: 'Yeni Lead', value: 'LEAD', icon: 'i-lucide-target' },
  { label: 'Hızlı Lead', value: 'QUICK', icon: 'i-lucide-zap' },
]

// Lead dosya yükleme
const leadUploadFiles = ref<File[]>([])
const leadIsDragging = ref(false)

const selectedLeadProductRequiresFile = computed(() => {
  if (!form.value.leadProductId) return false
  const p = leadProducts.value.find(x => x.value === form.value.leadProductId)
  return p?.requiresFile ?? false
})

function onLeadFileDrop(e: DragEvent) {
  leadIsDragging.value = false
  const files = e.dataTransfer?.files
  if (files) addLeadFiles(files)
}

function onLeadFileSelect(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files) addLeadFiles(input.files)
  input.value = ''
}

function addLeadFiles(fileList: FileList) {
  const allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
  for (const f of Array.from(fileList)) {
    if (!allowed.includes(f.type)) { toast.add({ title: `${f.name}: Sadece JPG, PNG, WebP ve PDF`, color: 'error' }); continue }
    if (f.size > 10 * 1024 * 1024) { toast.add({ title: `${f.name}: Maks. 10MB`, color: 'error' }); continue }
    leadUploadFiles.value.push(f)
  }
}

function removeLeadFile(idx: number) { leadUploadFiles.value.splice(idx, 1) }

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function onLeadTcInput(val: string) { form.value.leadTcNo = val.replace(/\D/g, '').slice(0, 11) }

// Hızlı Lead dosya yükleme
const quickLeadFiles = ref<File[]>([])
const quickLeadDragging = ref(false)

function onQuickLeadFileDrop(e: DragEvent) {
  quickLeadDragging.value = false
  const files = e.dataTransfer?.files
  if (files) addQuickLeadFiles(files)
}

function onQuickLeadFileSelect(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files) addQuickLeadFiles(input.files)
  input.value = ''
}

function addQuickLeadFiles(fileList: FileList) {
  const allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
  for (const f of Array.from(fileList)) {
    if (!allowed.includes(f.type)) { toast.add({ title: `${f.name}: Sadece JPG, PNG, WebP ve PDF`, color: 'error' }); continue }
    if (f.size > 10 * 1024 * 1024) { toast.add({ title: `${f.name}: Maks. 10MB`, color: 'error' }); continue }
    quickLeadFiles.value.push(f)
  }
}

function removeQuickLeadFile(idx: number) { quickLeadFiles.value.splice(idx, 1) }

function onQuickLeadPaste(e: ClipboardEvent) {
  const items = e.clipboardData?.items
  if (!items) return
  for (const item of Array.from(items)) {
    if (item.type.startsWith('image/')) {
      const file = item.getAsFile()
      if (file) {
        const named = new File([file], 'yapistirilan-' + Date.now() + '.png', { type: file.type })
        quickLeadFiles.value.push(named)
        toast.add({ title: 'Görsel yapıştırıldı', color: 'success' })
      }
      e.preventDefault()
    }
  }
}
</script>

<template>
  <UModal v-model:open="isOpen" :title="isEditMode ? 'Teklif Düzenle' : 'Yeni Görev'" class="sm:max-w-2xl" :dismissible="false" :ui="{ content: 'flex flex-col max-h-[90vh]', body: 'flex-1 overflow-y-auto min-h-0 relative' }">
    <template #body>
      <div v-if="saving" class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 bg-white/85 dark:bg-gray-900/85 rounded-xl backdrop-blur-sm">
        <UIcon name="i-lucide-loader-circle" class="size-10 animate-spin text-primary" />
        <p class="text-sm">Kaydediliyor...</p>
      </div>
      <div class="flex flex-col gap-4">
        <!-- Tabs (edit modunda gizle) -->
        <div v-if="!isEditMode" class="grid grid-cols-3 gap-2">
          <button
            v-for="tab in tabs"
            :key="tab.value"
            type="button"
            :class="[
              'flex items-center justify-center gap-2 rounded-[var(--ui-radius)] border text-sm font-semibold transition-colors',
              activeTab === tab.value
                ? 'bg-[var(--ui-primary)] text-white border-[var(--ui-primary)]'
                : 'bg-transparent text-[var(--ui-text-muted)] border-[var(--ui-border)] hover:text-[var(--ui-text)]'
            ]"
            style="min-height:50px"
            @click="activeTab = tab.value as any"
          >
            <UIcon :name="tab.icon" class="size-4" />
            {{ tab.label }}
          </button>
        </div>

        <div class="flex flex-col gap-4 [&_input]:!font-semibold">

          <!-- ===== TEKLİF FORMU ===== -->
          <template v-if="activeTab === 'OFFER'">
            <div class="relative select-fl [&_.truncate]:!font-semibold">
              <USelectMenu
                v-model="form.customerId"
                :items="customerOptions"
                value-key="value"
                label-key="label"
                placeholder=" "
                searchable
                :search-input="{ placeholder: 'Ara...' }"
                ignore-filter
                class="w-full"
                @update:search-term="(v: string) => customerSearchTerm = v"
              >
                <template #empty>
                  <div class="flex flex-col items-center gap-2 py-3 px-2 text-center">
                    <span class="text-sm text-muted">Müşteri bulunamadı</span>
                    <UButton
                      size="sm"
                      variant="soft"
                      color="primary"
                      icon="i-lucide-user-plus"
                      label="Yeni Müşteri Oluştur"
                      @click="isNewCustomerOpen = true"
                    />
                  </div>
                </template>
              </USelectMenu>
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.customerId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Müşteri Adı Soyadı <span class="text-[var(--ui-error)]">*</span></label>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div class="relative select-fl [&_.truncate]:!font-semibold">
                <USelectMenu
                  v-model="form.insuranceId"
                  :items="insuranceOptions"
                  value-key="value"
                  label-key="label"
                  placeholder=" "
                  searchable
                  :search-input="{ placeholder: 'Ara...' }"
                  :search-attributes="['label']"
                  class="w-full"
                />
                <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.insuranceId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Sigorta Türü <span class="text-[var(--ui-error)]">*</span></label>
              </div>
              <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                <UInput :model-value="finishDateDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-finish" @keydown="preventNonDigitKey" @update:model-value="onFinishDateInput">
                  <template #trailing>
                    <UPopover v-model:open="finishDateOpen">
                      <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" />
                      <template #content>
                        <UCalendar locale="tr-TR" v-model="finishDate" class="p-2" @update:model-value="onFinishDateChange" />
                      </template>
                    </UPopover>
                  </template>
                </UInput>
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-finish:top-0 peer-focus-within/fl-finish:-translate-y-1/2 peer-focus-within/fl-finish:text-xs peer-focus-within/fl-finish:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-finish:top-0 peer-has-[input:not(:placeholder-shown)]/fl-finish:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-finish:text-xs peer-has-[input:not(:placeholder-shown)]/fl-finish:text-[var(--ui-text-highlighted)]">Bitiş Tarihi</label>
              </div>
            </div>

            <!-- Araç (TRAFFIC) -->
            <template v-if="selectedInsuranceCode === 'TRAFFIC'">
              <div class="flex items-center gap-3 pt-1">
                <span class="text-xs font-semibold text-muted uppercase tracking-wider">Araç Bilgileri</span>
                <div class="flex-1 h-px bg-[var(--ui-border)]"></div>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                  <UInput :model-value="form.plateNo" placeholder=" " class="w-full peer/fl-plate" @update:model-value="onPlateInput" />
                  <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-plate:top-0 peer-focus-within/fl-plate:-translate-y-1/2 peer-focus-within/fl-plate:text-xs peer-focus-within/fl-plate:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-plate:top-0 peer-has-[input:not(:placeholder-shown)]/fl-plate:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-plate:text-xs peer-has-[input:not(:placeholder-shown)]/fl-plate:text-[var(--ui-text-highlighted)]">Plaka</label>
                </div>
                <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                  <UInput v-model="form.registrationNo" placeholder=" " class="w-full peer/fl-regno" />
                  <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-regno:top-0 peer-focus-within/fl-regno:-translate-y-1/2 peer-focus-within/fl-regno:text-xs peer-focus-within/fl-regno:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-regno:top-0 peer-has-[input:not(:placeholder-shown)]/fl-regno:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-regno:text-xs peer-has-[input:not(:placeholder-shown)]/fl-regno:text-[var(--ui-text-highlighted)]">Ruhsat Seri No</label>
                </div>
                <div v-if="isFieldEnabled('chassis_no')" class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                  <UInput v-model="form.chassisNo" placeholder=" " class="w-full peer/fl-chassis" />
                  <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-chassis:top-0 peer-focus-within/fl-chassis:-translate-y-1/2 peer-focus-within/fl-chassis:text-xs peer-focus-within/fl-chassis:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-chassis:top-0 peer-has-[input:not(:placeholder-shown)]/fl-chassis:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-chassis:text-xs peer-has-[input:not(:placeholder-shown)]/fl-chassis:text-[var(--ui-text-highlighted)]">Şasi No</label>
                </div>
                <div v-if="isFieldEnabled('engine_no')" class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                  <UInput v-model="form.engineNo" placeholder=" " class="w-full peer/fl-engine" />
                  <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-engine:top-0 peer-focus-within/fl-engine:-translate-y-1/2 peer-focus-within/fl-engine:text-xs peer-focus-within/fl-engine:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-engine:top-0 peer-has-[input:not(:placeholder-shown)]/fl-engine:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-engine:text-xs peer-has-[input:not(:placeholder-shown)]/fl-engine:text-[var(--ui-text-highlighted)]">Motor No</label>
                </div>
                <div v-if="isFieldEnabled('policy_brand')" class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                  <UInput v-model="form.vehicleBrand" placeholder=" " class="w-full peer/fl-brand" />
                  <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-brand:top-0 peer-focus-within/fl-brand:-translate-y-1/2 peer-focus-within/fl-brand:text-xs peer-focus-within/fl-brand:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-brand:top-0 peer-has-[input:not(:placeholder-shown)]/fl-brand:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-brand:text-xs peer-has-[input:not(:placeholder-shown)]/fl-brand:text-[var(--ui-text-highlighted)]">Marka</label>
                </div>
                <div v-if="isFieldEnabled('policy_model')" class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                  <UInput v-model="form.vehicleModel" placeholder=" " class="w-full peer/fl-model" />
                  <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-model:top-0 peer-focus-within/fl-model:-translate-y-1/2 peer-focus-within/fl-model:text-xs peer-focus-within/fl-model:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-model:top-0 peer-has-[input:not(:placeholder-shown)]/fl-model:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-model:text-xs peer-has-[input:not(:placeholder-shown)]/fl-model:text-[var(--ui-text-highlighted)]">Model</label>
                </div>
                <div v-if="isFieldEnabled('vehicle_year')" :class="['relative [&_input]:!pt-5 [&_input]:!pb-2.5', trafficFieldCount % 2 === 1 ? 'col-span-2' : '']">
                  <UInput v-model="form.vehicleYear" placeholder=" " class="w-full peer/fl-year" />
                  <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-year:top-0 peer-focus-within/fl-year:-translate-y-1/2 peer-focus-within/fl-year:text-xs peer-focus-within/fl-year:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-year:top-0 peer-has-[input:not(:placeholder-shown)]/fl-year:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-year:text-xs peer-has-[input:not(:placeholder-shown)]/fl-year:text-[var(--ui-text-highlighted)]">Model Yılı</label>
                </div>
              </div>
            </template>

            <!-- Konut (HOUSING) -->
            <template v-if="selectedInsuranceCode === 'HOUSING' && isFieldEnabled('policy_uavt')">
              <div class="flex items-center gap-3 pt-1">
                <span class="text-xs font-semibold text-muted uppercase tracking-wider">Konut Bilgileri</span>
                <div class="flex-1 h-px bg-[var(--ui-border)]"></div>
              </div>
              <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                <UInput v-model="form.uavtCode" placeholder=" " class="w-full peer/fl-uavt" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-uavt:top-0 peer-focus-within/fl-uavt:-translate-y-1/2 peer-focus-within/fl-uavt:text-xs peer-focus-within/fl-uavt:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-uavt:top-0 peer-has-[input:not(:placeholder-shown)]/fl-uavt:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-uavt:text-xs peer-has-[input:not(:placeholder-shown)]/fl-uavt:text-[var(--ui-text-highlighted)]">UAVT Kodu</label>
              </div>
            </template>

            <!-- DASK -->
            <template v-if="selectedInsuranceCode === 'DASK' && (isFieldEnabled('policy_uavt') || isFieldEnabled('dask_no'))">
              <div class="flex items-center gap-3 pt-1">
                <span class="text-xs font-semibold text-muted uppercase tracking-wider">DASK Bilgileri</span>
                <div class="flex-1 h-px bg-[var(--ui-border)]"></div>
              </div>
              <div class="grid gap-3" :class="(isFieldEnabled('policy_uavt') && isFieldEnabled('dask_no')) ? 'grid-cols-2' : 'grid-cols-1'">
                <div v-if="isFieldEnabled('policy_uavt')" class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                  <UInput v-model="form.uavtCode" placeholder=" " class="w-full peer/fl-uavt2" />
                  <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-uavt2:top-0 peer-focus-within/fl-uavt2:-translate-y-1/2 peer-focus-within/fl-uavt2:text-xs peer-focus-within/fl-uavt2:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-uavt2:top-0 peer-has-[input:not(:placeholder-shown)]/fl-uavt2:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-uavt2:text-xs peer-has-[input:not(:placeholder-shown)]/fl-uavt2:text-[var(--ui-text-highlighted)]">UAVT Kodu</label>
                </div>
                <div v-if="isFieldEnabled('dask_no')" class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                  <UInput v-model="form.daskNo" placeholder=" " class="w-full peer/fl-dask" />
                  <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-dask:top-0 peer-focus-within/fl-dask:-translate-y-1/2 peer-focus-within/fl-dask:text-xs peer-focus-within/fl-dask:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-dask:top-0 peer-has-[input:not(:placeholder-shown)]/fl-dask:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-dask:text-xs peer-has-[input:not(:placeholder-shown)]/fl-dask:text-[var(--ui-text-highlighted)]">DASK Poliçe No</label>
                </div>
              </div>
            </template>

            <!-- Sağlık (HEALTH) -->
            <template v-if="selectedInsuranceCode === 'HEALTH'">
              <div class="flex items-center gap-3 pt-1">
                <span class="text-xs font-semibold text-muted uppercase tracking-wider">Sağlık Bilgileri</span>
                <div class="flex-1 h-px bg-[var(--ui-border)]"></div>
              </div>
              <div v-if="isFieldEnabled('policy_network')" class="relative select-fl [&_.truncate]:!font-semibold">
                <USelectMenu v-model="form.network" :items="networkOptions" value-key="value" placeholder=" " class="w-full" />
                <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.network ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Network</label>
              </div>
              <div class="relative [&_textarea]:!pt-7 [&_textarea]:!pb-2">
                <UTextarea v-model="form.insureds" placeholder=" " :rows="2" class="w-full peer/fl-insureds" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-4 peer-focus-within/fl-insureds:top-0 peer-focus-within/fl-insureds:-translate-y-1/2 peer-focus-within/fl-insureds:text-xs peer-focus-within/fl-insureds:text-[var(--ui-primary)] peer-has-[textarea:not(:placeholder-shown)]/fl-insureds:top-0 peer-has-[textarea:not(:placeholder-shown)]/fl-insureds:-translate-y-1/2 peer-has-[textarea:not(:placeholder-shown)]/fl-insureds:text-xs peer-has-[textarea:not(:placeholder-shown)]/fl-insureds:text-[var(--ui-text-highlighted)]">Sigortalılar</label>
              </div>
            </template>

            <div class="relative [&_textarea]:!pt-7 [&_textarea]:!pb-2">
              <UTextarea v-model="form.offerNote" placeholder=" " :rows="2" class="w-full peer/fl-note" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-4 peer-focus-within/fl-note:top-0 peer-focus-within/fl-note:-translate-y-1/2 peer-focus-within/fl-note:text-xs peer-focus-within/fl-note:text-[var(--ui-primary)] peer-has-[textarea:not(:placeholder-shown)]/fl-note:top-0 peer-has-[textarea:not(:placeholder-shown)]/fl-note:-translate-y-1/2 peer-has-[textarea:not(:placeholder-shown)]/fl-note:text-xs peer-has-[textarea:not(:placeholder-shown)]/fl-note:text-[var(--ui-text-highlighted)]">Teklif Notu</label>
            </div>
          </template>

          <!-- ===== YENİ LEAD FORMU ===== -->
          <template v-if="activeTab === 'LEAD'">
            <!-- TC Kimlik No -->
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput :model-value="form.leadTcNo" placeholder=" " class="w-full peer/fl-ltc" @update:model-value="onLeadTcInput" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-ltc:top-0 peer-focus-within/fl-ltc:-translate-y-1/2 peer-focus-within/fl-ltc:text-xs peer-focus-within/fl-ltc:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-ltc:top-0 peer-has-[input:not(:placeholder-shown)]/fl-ltc:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-ltc:text-xs peer-has-[input:not(:placeholder-shown)]/fl-ltc:text-[var(--ui-text-highlighted)]">TC Kimlik No <span class="text-[var(--ui-error)]">*</span></label>
            </div>

            <!-- Ad Soyad + Doğum Tarihi -->
            <div class="grid grid-cols-2 gap-3">
              <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                <UInput v-model="form.leadFullName" placeholder=" " class="w-full peer/fl-lname" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-lname:top-0 peer-focus-within/fl-lname:-translate-y-1/2 peer-focus-within/fl-lname:text-xs peer-focus-within/fl-lname:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-lname:top-0 peer-has-[input:not(:placeholder-shown)]/fl-lname:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-lname:text-xs peer-has-[input:not(:placeholder-shown)]/fl-lname:text-[var(--ui-text-highlighted)]">Ad Soyad <span class="text-[var(--ui-error)]">*</span></label>
              </div>
              <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                <UInput :model-value="refBirthDateDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-lbd" @keydown="preventNonDigitKey" @update:model-value="onRefBirthDateInput">
                  <template #trailing>
                    <UPopover v-model:open="refBirthDateOpen">
                      <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" />
                      <template #content>
                        <UCalendar locale="tr-TR" v-model="refBirthDateCal" class="p-2" @update:model-value="onRefBirthDateChange" />
                      </template>
                    </UPopover>
                  </template>
                </UInput>
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-lbd:top-0 peer-focus-within/fl-lbd:-translate-y-1/2 peer-focus-within/fl-lbd:text-xs peer-focus-within/fl-lbd:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-lbd:top-0 peer-has-[input:not(:placeholder-shown)]/fl-lbd:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-lbd:text-xs peer-has-[input:not(:placeholder-shown)]/fl-lbd:text-[var(--ui-text-highlighted)]">Doğum Tarihi <span class="text-[var(--ui-error)]">*</span></label>
              </div>
            </div>

            <!-- Telefon -->
            <PhoneInput v-model="form.leadPhone" label="Telefon No" :required="true" />

            <!-- Ürün + Kaynak -->
            <div class="grid grid-cols-2 gap-3">
              <div class="relative select-fl [&_.truncate]:!font-semibold">
                <USelectMenu v-model="form.leadProductId" :items="leadProducts" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
                <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.leadProductId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Ürün <span class="text-[var(--ui-error)]">*</span></label>
              </div>
              <div class="relative select-fl [&_.truncate]:!font-semibold">
                <USelectMenu v-model="form.leadSourceId" :items="leadSources" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
                <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.leadSourceId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Kaynak <span class="text-[var(--ui-error)]">*</span></label>
              </div>
            </div>

            <!-- Dosya Yükleme (Sürükle & Bırak) -->
            <div v-if="selectedLeadProductRequiresFile" class="space-y-2">
              <p class="text-sm font-medium">Belge Yükle</p>
              <div
                class="relative border-2 border-dashed rounded-lg p-5 text-center transition-colors cursor-pointer"
                :class="leadIsDragging ? 'border-primary bg-primary/5' : 'border-gray-300 dark:border-gray-600 hover:border-primary/50'"
                @dragover.prevent="leadIsDragging = true"
                @dragleave.prevent="leadIsDragging = false"
                @drop.prevent="onLeadFileDrop"
                @click="($refs.leadFileInput as HTMLInputElement)?.click()"
              >
                <input ref="leadFileInput" type="file" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" class="hidden" @change="onLeadFileSelect" />
                <UIcon name="i-lucide-upload-cloud" class="size-7 text-muted mx-auto mb-1" />
                <p class="text-xs text-muted">Sürükleyip bırakın veya <span class="text-primary font-medium">tıklayarak seçin</span></p>
                <p class="text-[11px] text-muted mt-0.5">JPG, PNG, WebP, PDF — Maks. 10MB</p>
              </div>
              <div v-if="leadUploadFiles.length" class="space-y-1">
                <div v-for="(file, idx) in leadUploadFiles" :key="idx" class="flex items-center justify-between py-1.5 px-3 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                  <div class="flex items-center gap-2 min-w-0">
                    <UIcon :name="file.type === 'application/pdf' ? 'i-lucide-file-text' : 'i-lucide-image'" class="size-4 text-muted shrink-0" />
                    <span class="text-xs font-medium truncate">{{ file.name }}</span>
                    <span class="text-xs text-muted shrink-0">{{ formatFileSize(file.size) }}</span>
                  </div>
                  <UButton icon="i-lucide-x" color="error" variant="ghost" size="xs" @click="removeLeadFile(idx)" />
                </div>
              </div>
            </div>
          </template>

          <!-- ===== HIZLI LEAD FORMU ===== -->
          <template v-if="activeTab === 'QUICK'">
            <!-- Ruhsat Yükleme -->
            <div class="space-y-2" tabindex="0" @paste="onQuickLeadPaste">
              <p class="text-sm font-medium">Ruhsat Fotoğrafı <span class="text-[var(--ui-error)]">*</span></p>
              <div
                class="relative border-2 border-dashed rounded-lg p-6 text-center transition-colors cursor-pointer"
                :class="quickLeadDragging ? 'border-primary bg-primary/5' : 'border-gray-300 dark:border-gray-600 hover:border-primary/50'"
                @dragover.prevent="quickLeadDragging = true"
                @dragleave.prevent="quickLeadDragging = false"
                @drop.prevent="onQuickLeadFileDrop"
                @click="($refs.quickLeadFileInput as HTMLInputElement)?.click()"
              >
                <input ref="quickLeadFileInput" type="file" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" class="hidden" @change="onQuickLeadFileSelect" />
                <UIcon name="i-lucide-upload-cloud" class="size-8 text-muted mx-auto mb-2" />
                <p class="text-sm text-muted">Ruhsatı sürükleyip bırakın, <span class="text-primary font-medium">tıklayarak seçin</span> veya <span class="text-primary font-medium">Ctrl+V ile yapıştırın</span></p>
                <p class="text-xs text-muted mt-1">JPG, PNG, WebP, PDF — Maks. 10MB</p>
              </div>
              <div v-if="quickLeadFiles.length" class="space-y-1">
                <div v-for="(file, idx) in quickLeadFiles" :key="idx" class="flex items-center justify-between py-1.5 px-3 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                  <div class="flex items-center gap-2 min-w-0">
                    <UIcon :name="file.type === 'application/pdf' ? 'i-lucide-file-text' : 'i-lucide-image'" class="size-4 text-muted shrink-0" />
                    <span class="text-xs font-medium truncate">{{ file.name }}</span>
                    <span class="text-xs text-muted shrink-0">{{ formatFileSize(file.size) }}</span>
                  </div>
                  <UButton icon="i-lucide-x" color="error" variant="ghost" size="xs" @click="removeQuickLeadFile(idx)" />
                </div>
              </div>
            </div>

            <!-- Telefon -->
            <PhoneInput v-model="form.quickPhone" label="Telefon No" :required="true" />

            <!-- Ürün -->
            <div class="relative select-fl [&_.truncate]:!font-semibold">
              <USelectMenu v-model="form.quickProductId" :items="leadProducts" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.quickProductId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Ürün <span class="text-[var(--ui-error)]">*</span></label>
            </div>

            <!-- Atanan Kişi -->
            <div class="relative select-fl [&_.truncate]:!font-semibold">
              <USelectMenu v-model="form.assignedTo" :items="leadAssignOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.assignedTo ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Atanan Kişi</label>
            </div>
          </template>

          <!-- Ortak: Öncelik (sadece teklif) + Atanan -->
          <div v-if="activeTab !== 'QUICK'" :class="activeTab === 'OFFER' ? 'grid grid-cols-2 gap-3' : ''">
            <div v-if="activeTab === 'OFFER'" class="relative select-fl [&_.truncate]:!font-semibold">
              <USelect v-model="form.priority" :items="priorityOptions" class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.priority ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Öncelik</label>
            </div>
            <div class="relative select-fl [&_.truncate]:!font-semibold">
              <USelectMenu
                v-model="form.assignedTo"
                :items="activeTab === 'LEAD' ? leadAssignOptions : users"
                value-key="value"
                label-key="label"
                placeholder=" "
                searchable
                :search-input="{ placeholder: 'Ara...' }"
                :search-attributes="['label']"
                class="w-full"
              />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.assignedTo ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Atanan Kişi</label>
            </div>
          </div>

        </div>
      </div>
    </template>
    <template #footer>
      <div class="w-full flex justify-end items-center gap-3">
        <UButton label="İptal" color="neutral" variant="outline" size="md" class="w-32 justify-center" :disabled="saving" @click="isOpen = false" />
        <UButton
          :label="isEditMode ? 'Güncelle' : (activeTab === 'OFFER' ? 'Teklif Oluştur' : activeTab === 'QUICK' ? 'Hızlı Lead' : 'Lead Kaydet')"
          :icon="isEditMode ? 'i-lucide-save' : (activeTab === 'OFFER' ? 'i-lucide-file-plus' : activeTab === 'QUICK' ? 'i-lucide-zap' : 'i-lucide-target')"
          color="primary"
          size="md"
          class="w-32 justify-center"
          :loading="saving"
          :disabled="!canSubmit"
          @click="save"
        />
      </div>
    </template>
  </UModal>

  <CustomerFormModal v-model:open="isNewCustomerOpen" @saved="onNewCustomerSaved" />
</template>

<style scoped>
.select-fl :deep(button) {
  min-height: 50px !important;
  height: auto !important;
}
</style>
