<script setup lang="ts">
import { CalendarDate } from '@internationalized/date'

const emit = defineEmits<{
  saved: []
}>()

const isOpen = defineModel<boolean>('open', { default: false })

const toast = useToast()
const { get, post } = useApi()
const { insurances, subcategories, fetchInsurances } = useInsuranceTypes()
const { isFieldEnabled, fetchFieldSettings } = useFieldSettings()

// Data sources
const customers = ref<{ label: string; value: number }[]>([])
const users = ref<{ label: string; value: number }[]>([])
const loaded = ref(false)

async function loadData() {
  if (loaded.value) return
  loaded.value = true
  await fetchInsurances()
  try {
    const [custRes, userRes] = await Promise.all([
      get<any>('customers/list-all'),
      get<any>('users?dropdown=1')
    ])
    customers.value = (custRes.data || []).map((c: any) => ({ label: c.identityNo ? `${c.name} (${c.identityNo})` : c.name, value: c.id }))
    users.value = (userRes.data || []).map((u: any) => ({ label: u.name, value: u.id }))
  } catch {}
}

watch(isOpen, (val) => {
  if (val) {
    loadData()
    fetchFieldSettings()
  }
})

const insuranceOptions = computed(() =>
  subcategories.value
    .sort((a, b) => a.name.localeCompare(b.name, 'tr'))
    .map(i => ({ label: i.name, value: i.id }))
)

const networkOptions = [
  { label: 'Geniş', value: 'Geniş' },
  { label: 'Dar', value: 'Dar' },
  { label: 'Devlet', value: 'Devlet' }
]

// Form
const form = ref({
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
  priority: 'MEDIUM' as string,
  assignedTo: undefined as number | undefined,
})

const finishDate = ref<CalendarDate>()
const finishDateDisplay = ref('')
const finishDateOpen = ref(false)

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
  if (iso) {
    const [y, m, d] = iso.split('-')
    finishDate.value = new CalendarDate(parseInt(y), parseInt(m), parseInt(d))
  }
}
function onFinishDateChange(val: any) {
  finishDate.value = val
  const iso = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  const [y, m, d] = iso.split('-')
  finishDateDisplay.value = `${d}.${m}.${y}`
  finishDateOpen.value = false
}

const customerSearchTerm = ref('')
const filteredCustomers = computed(() => {
  const term = customerSearchTerm.value.toLowerCase().trim()
  const filtered = term
    ? customers.value.filter(c => c.label.toLowerCase().includes(term))
    : customers.value
  const list = filtered.slice(0, 200)
  if (form.value.customerId && !list.some(c => c.value === form.value.customerId)) {
    const selected = customers.value.find(c => c.value === form.value.customerId)
    if (selected) list.unshift(selected)
  }
  return list
})

// Conditional fields based on insurance type
const selectedInsuranceCode = computed(() => {
  if (!form.value.insuranceId) return null
  const ins = insurances.value.find(i => i.id === form.value.insuranceId)
  return ins?.code || null
})

// Plate auto-format
function formatPlate(raw: string): string {
  const upper = raw.toUpperCase().replace(/[^A-Z0-9]/g, '')
  const match = upper.match(/^(\d{2})([A-Z]{1,3})(\d{0,4})$/)
  if (match) return `${match[1]} ${match[2]}${match[3] ? ' ' + match[3] : ''}`.trim()
  return upper
}
function onPlateInput(val: string) { form.value.plateNo = formatPlate(val) }

const trafficFieldCount = computed(() => {
  let count = 2
  if (isFieldEnabled('chassis_no')) count++
  if (isFieldEnabled('engine_no')) count++
  if (isFieldEnabled('policy_brand')) count++
  if (isFieldEnabled('policy_model')) count++
  if (isFieldEnabled('vehicle_year')) count++
  return count
})

const priorityOptions = [
  { label: 'Düşük', value: 'LOW' },
  { label: 'Orta', value: 'MEDIUM' },
  { label: 'Yüksek', value: 'HIGH' },
  { label: 'Acil', value: 'URGENT' }
]

function calendarDateToStr(d: any): string {
  if (!d) return ''
  return `${d.year}-${String(d.month).padStart(2, '0')}-${String(d.day).padStart(2, '0')}`
}

function calendarDateLabel(d: any): string {
  if (!d) return ''
  return `${String(d.day).padStart(2, '0')}/${String(d.month).padStart(2, '0')}/${d.year}`
}

const saving = ref(false)

async function saveOffer() {
  if (!form.value.customerId || !form.value.insuranceId) {
    toast.add({ title: 'Müşteri ve sigorta türü zorunludur', color: 'error' })
    return
  }

  saving.value = true
  try {
    await post('tasks', {
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
      priority: form.value.priority,
      assignedTo: form.value.assignedTo || undefined,
    })

    toast.add({ title: 'Teklif oluşturuldu', color: 'success' })
    isOpen.value = false
    resetForm()
    emit('saved')
  } catch (e: any) {
    toast.add({ title: e?.data?.message || 'Teklif oluşturulamadı', color: 'error' })
  } finally {
    saving.value = false
  }
}

function resetForm() {
  form.value = {
    customerId: undefined,
    insuranceId: undefined,
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
    priority: 'MEDIUM',
    assignedTo: undefined,
  }
  finishDate.value = undefined
  finishDateDisplay.value = ''
  customerSearchTerm.value = ''
}
</script>

<template>
  <UModal v-model:open="isOpen" title="Yeni Teklif Oluştur" class="sm:max-w-xl" :dismissible="false" :ui="{ content: 'flex flex-col max-h-[90vh]', body: 'flex-1 overflow-y-auto min-h-0 relative' }">
    <template #body>
      <!-- Kaydetme overlay -->
      <div v-if="saving" class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 bg-white/85 dark:bg-gray-900/85 rounded-xl backdrop-blur-sm">
        <UIcon name="i-lucide-loader-circle" class="size-10 animate-spin text-primary" />
        <p class="text-sm">Teklif oluşturuluyor...</p>
      </div>

      <div class="modal-form flex flex-col [&_input]:!font-semibold">
        <!-- Müşteri -->
        <div class="relative fl-select-form [&_.truncate]:!font-semibold">
          <USelectMenu
            v-model="form.customerId"
            :items="filteredCustomers"
            value-key="value"
            label-key="label"
            placeholder=" "
            searchable
            :search-input="{ placeholder: 'Ara...' }"
            ignore-filter
            class="w-full"
            @update:search-term="(t: string) => customerSearchTerm = t"
          />
          <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.customerId ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Müşteri Adı Soyadı <span class="text-[var(--ui-error)]">*</span></label>
        </div>

        <!-- Sigorta Türü + Bitiş Tarihi -->
        <div class="grid grid-cols-2 gap-4">
          <div class="relative fl-select-form [&_.truncate]:!font-semibold">
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
          <div class="relative fl-form">
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

        <!-- Araç Bilgileri (TRAFFIC) -->
        <template v-if="selectedInsuranceCode === 'TRAFFIC'">
          <div class="flex items-center gap-3 pt-1">
            <span class="text-xs font-semibold text-muted uppercase tracking-wider">Araç Bilgileri</span>
            <div class="flex-1 h-px bg-[var(--ui-border)]"></div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div class="relative fl-form">
              <UInput :model-value="form.plateNo" placeholder=" " class="w-full peer/fl-plate" @update:model-value="onPlateInput" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-plate:top-0 peer-focus-within/fl-plate:-translate-y-1/2 peer-focus-within/fl-plate:text-xs peer-focus-within/fl-plate:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-plate:top-0 peer-has-[input:not(:placeholder-shown)]/fl-plate:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-plate:text-xs peer-has-[input:not(:placeholder-shown)]/fl-plate:text-[var(--ui-text-highlighted)]">Plaka</label>
            </div>
            <div class="relative fl-form">
              <UInput v-model="form.registrationNo" placeholder=" " class="w-full peer/fl-regno" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-regno:top-0 peer-focus-within/fl-regno:-translate-y-1/2 peer-focus-within/fl-regno:text-xs peer-focus-within/fl-regno:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-regno:top-0 peer-has-[input:not(:placeholder-shown)]/fl-regno:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-regno:text-xs peer-has-[input:not(:placeholder-shown)]/fl-regno:text-[var(--ui-text-highlighted)]">Ruhsat Seri No</label>
            </div>
            <div v-if="isFieldEnabled('chassis_no')" class="relative fl-form">
              <UInput v-model="form.chassisNo" placeholder=" " class="w-full peer/fl-chassis" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-chassis:top-0 peer-focus-within/fl-chassis:-translate-y-1/2 peer-focus-within/fl-chassis:text-xs peer-focus-within/fl-chassis:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-chassis:top-0 peer-has-[input:not(:placeholder-shown)]/fl-chassis:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-chassis:text-xs peer-has-[input:not(:placeholder-shown)]/fl-chassis:text-[var(--ui-text-highlighted)]">Şasi No</label>
            </div>
            <div v-if="isFieldEnabled('engine_no')" class="relative fl-form">
              <UInput v-model="form.engineNo" placeholder=" " class="w-full peer/fl-engine" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-engine:top-0 peer-focus-within/fl-engine:-translate-y-1/2 peer-focus-within/fl-engine:text-xs peer-focus-within/fl-engine:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-engine:top-0 peer-has-[input:not(:placeholder-shown)]/fl-engine:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-engine:text-xs peer-has-[input:not(:placeholder-shown)]/fl-engine:text-[var(--ui-text-highlighted)]">Motor No</label>
            </div>
            <div v-if="isFieldEnabled('policy_brand')" class="relative fl-form">
              <UInput v-model="form.vehicleBrand" placeholder=" " class="w-full peer/fl-brand" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-brand:top-0 peer-focus-within/fl-brand:-translate-y-1/2 peer-focus-within/fl-brand:text-xs peer-focus-within/fl-brand:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-brand:top-0 peer-has-[input:not(:placeholder-shown)]/fl-brand:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-brand:text-xs peer-has-[input:not(:placeholder-shown)]/fl-brand:text-[var(--ui-text-highlighted)]">Marka</label>
            </div>
            <div v-if="isFieldEnabled('policy_model')" class="relative fl-form">
              <UInput v-model="form.vehicleModel" placeholder=" " class="w-full peer/fl-model" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-model:top-0 peer-focus-within/fl-model:-translate-y-1/2 peer-focus-within/fl-model:text-xs peer-focus-within/fl-model:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-model:top-0 peer-has-[input:not(:placeholder-shown)]/fl-model:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-model:text-xs peer-has-[input:not(:placeholder-shown)]/fl-model:text-[var(--ui-text-highlighted)]">Model</label>
            </div>
            <div v-if="isFieldEnabled('vehicle_year')" :class="['relative fl-form', trafficFieldCount % 2 === 1 ? 'col-span-2' : '']">
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
          <div class="relative fl-form">
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
          <div class="grid gap-4" :class="(isFieldEnabled('policy_uavt') && isFieldEnabled('dask_no')) ? 'grid-cols-2' : 'grid-cols-1'">
            <div v-if="isFieldEnabled('policy_uavt')" class="relative fl-form">
              <UInput v-model="form.uavtCode" placeholder=" " class="w-full peer/fl-uavt2" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-uavt2:top-0 peer-focus-within/fl-uavt2:-translate-y-1/2 peer-focus-within/fl-uavt2:text-xs peer-focus-within/fl-uavt2:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-uavt2:top-0 peer-has-[input:not(:placeholder-shown)]/fl-uavt2:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-uavt2:text-xs peer-has-[input:not(:placeholder-shown)]/fl-uavt2:text-[var(--ui-text-highlighted)]">UAVT Kodu</label>
            </div>
            <div v-if="isFieldEnabled('dask_no')" class="relative fl-form">
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
          <div v-if="isFieldEnabled('policy_network')" class="relative fl-select-form [&_.truncate]:!font-semibold">
            <USelectMenu v-model="form.network" :items="networkOptions" value-key="value" placeholder=" " class="w-full" />
            <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.network ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Network</label>
          </div>
          <div class="relative fl-form">
            <UTextarea v-model="form.insureds" placeholder=" " :rows="2" class="w-full peer/fl-insureds" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-4 peer-focus-within/fl-insureds:top-0 peer-focus-within/fl-insureds:-translate-y-1/2 peer-focus-within/fl-insureds:text-xs peer-focus-within/fl-insureds:text-[var(--ui-primary)] peer-has-[textarea:not(:placeholder-shown)]/fl-insureds:top-0 peer-has-[textarea:not(:placeholder-shown)]/fl-insureds:-translate-y-1/2 peer-has-[textarea:not(:placeholder-shown)]/fl-insureds:text-xs peer-has-[textarea:not(:placeholder-shown)]/fl-insureds:text-[var(--ui-text-highlighted)]">Sigortalılar</label>
          </div>
        </template>

        <!-- Teklif Notu -->
        <div class="relative fl-form">
          <UTextarea v-model="form.offerNote" placeholder=" " :rows="2" class="w-full peer/fl-note" />
          <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-4 peer-focus-within/fl-note:top-0 peer-focus-within/fl-note:-translate-y-1/2 peer-focus-within/fl-note:text-xs peer-focus-within/fl-note:text-[var(--ui-primary)] peer-has-[textarea:not(:placeholder-shown)]/fl-note:top-0 peer-has-[textarea:not(:placeholder-shown)]/fl-note:-translate-y-1/2 peer-has-[textarea:not(:placeholder-shown)]/fl-note:text-xs peer-has-[textarea:not(:placeholder-shown)]/fl-note:text-[var(--ui-text-highlighted)]">Teklif Notu</label>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div class="relative fl-select-form [&_.truncate]:!font-semibold">
            <USelect v-model="form.priority" :items="priorityOptions" class="w-full" />
            <label :class="['pointer-events-none select-none absolute left-3 z-10 transition-all duration-150 ease-in-out', form.priority ? 'bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2' : 'text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2']">Öncelik</label>
          </div>
          <div class="relative fl-select-form [&_.truncate]:!font-semibold">
            <USelectMenu
              v-model="form.assignedTo"
              :items="users"
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
    </template>
    <template #footer>
      <div class="w-full flex justify-end items-center gap-3">
        <UButton label="İptal" color="neutral" variant="outline" size="md" class="w-32 justify-center" :disabled="saving" @click="isOpen = false" />
        <UButton
          label="Teklif Oluştur"
          icon="i-lucide-file-plus"
          color="primary"
          size="md"
          class="w-32 justify-center"
          :loading="saving"
          @click="saveOffer"
        />
      </div>
    </template>
  </UModal>
</template>

<style scoped>
:deep(input[type=number]::-webkit-inner-spin-button),
:deep(input[type=number]::-webkit-outer-spin-button) {
  -webkit-appearance: none;
  margin: 0;
}
</style>
