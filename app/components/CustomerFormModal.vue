<script setup lang="ts">
import { z } from 'zod'
import { CalendarDate } from '@internationalized/date'
import type { Customer, CustomerType } from '~/types'

const props = defineProps<{
  customer?: Customer | null
}>()

const emit = defineEmits<{
  saved: [customer?: { id: number, name: string, identityNo: string }]
}>()

const isOpen = defineModel<boolean>('open', { default: false })

const toast = useToast()
const { post, put, get } = useApi()
const { isFieldEnabled, fetchFieldSettings } = useFieldSettings()

const customerSchema = computed(() => {
  const passportAllowed = isFieldEnabled('allow_passport_id')
  const isIndividual = form.type === 'INDIVIDUAL'

  // Identity validation: pasaport acik degilse strict format
  const identityValidator = passportAllowed
    ? z.string({ required_error: 'Kimlik/Vergi/Pasaport no zorunludur' }).min(5, 'En az 5 karakter')
    : isIndividual
      ? z.string({ required_error: 'TC Kimlik No zorunludur' })
          .regex(/^\d{11}$/, 'TC Kimlik No 11 haneli rakam olmalıdır')
      : z.string({ required_error: 'Vergi No zorunludur' })
          .regex(/^\d{10}$/, 'Vergi No 10 haneli rakam olmalıdır')

  const nameValidator = isIndividual
    ? z.string({ required_error: 'Ad Soyad zorunludur' })
        .min(2, 'En az 2 karakter')
        .refine(val => val === val.trim(), 'Ad Soyad başında veya sonunda boşluk olamaz')
        .refine(val => !/\d/.test(val), 'Ad Soyad rakam içeremez')
    : z.string({ required_error: 'Firma unvanı zorunludur' })
        .min(2, 'En az 2 karakter')
        .refine(val => val === val.trim(), 'Firma unvanı başında veya sonunda boşluk olamaz')

  return z.object({
  type: z.enum(['INDIVIDUAL', 'CORPORATE']),
  name: nameValidator,
  identityNo: identityValidator,
  phone: z.string({ required_error: 'Telefon zorunludur' }).min(10, 'Geçerli telefon giriniz'),
  email: z.string().email('Geçerli e-posta giriniz').optional().or(z.literal('')),
  taxOffice: z.string().optional().or(z.literal('')),
  birthDate: z.string().optional().or(z.literal('')).refine(
    val => !val || val === '' || (() => { const p = val.split('-').map(Number); const d = new Date(p[0], p[1]-1, p[2]); return d.getFullYear()===p[0] && d.getMonth()===p[1]-1 && d.getDate()===p[2] })(),
    'Geçersiz doğum tarihi (ör: Şubatta 31 gün yoktur)'
  ),
  phoneAlt: z.string().optional().or(z.literal('')),
  contactPerson: z.string().optional().or(z.literal('')),
  maritalStatus: z.string().optional().or(z.literal('')),
  job: z.string().optional().or(z.literal('')),
  dependentsCount: z.union([z.number(), z.nan(), z.undefined()]).optional(),
  sector: z.string().optional().or(z.literal('')),
  countryId: z.union([z.number(), z.undefined()]).optional(),
  city: z.union([z.number(), z.undefined()]).optional(),
  district: z.union([z.number(), z.undefined()]).optional(),
  address: z.string().optional().or(z.literal('')),
  note: z.string().optional().or(z.literal(''))
  })
})

const defaultForm = {
  type: 'INDIVIDUAL' as CustomerType,
  name: '',
  identityNo: '',
  phone: '',
  email: '',
  taxOffice: '',
  birthDate: '',
  phoneAlt: '',
  contactPerson: '',
  maritalStatus: '',
  job: '',
  dependentsCount: undefined as number | undefined,
  sector: '',
  countryId: undefined as number | undefined,
  city: undefined as number | undefined,
  district: undefined as number | undefined,
  address: '',
  note: ''
}

const form = reactive({ ...defaultForm })
const formRef = ref()
const submitBtnRef = ref<HTMLButtonElement>()
const saving = ref(false)

// Identity field label/placeholder/maxlength — toggle ve müşteri tipine göre dinamik
const identityLabel = computed(() => {
  const passportAllowed = isFieldEnabled('allow_passport_id')
  if (form.type === 'INDIVIDUAL') {
    return passportAllowed ? 'TC Kimlik / Pasaport No' : 'TC Kimlik No'
  }
  return passportAllowed ? 'Vergi / Pasaport No' : 'Vergi Numarası'
})
const identityPlaceholder = computed(() => {
  const passportAllowed = isFieldEnabled('allow_passport_id')
  if (form.type === 'INDIVIDUAL') {
    return passportAllowed ? '11 haneli TC veya pasaport (örn: U12345678)' : '12345678901'
  }
  return passportAllowed ? '10 haneli VKN veya pasaport' : '1234567890'
})
const identityMaxLength = computed(() => {
  if (isFieldEnabled('allow_passport_id')) return 20
  return form.type === 'INDIVIDUAL' ? 11 : 10
})
const isIndividual = computed(() => form.type === 'INDIVIDUAL')

// Birth date helpers
const birthDateDisplay = ref('')
const birthDateCalendar = ref<InstanceType<typeof CalendarDate> | undefined>()
const birthDatePopoverOpen = ref(false)
let birthDateSyncing = false

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
function onBirthDateInput(val: string) {
  const formatted = autoFormatDateInput(val)
  birthDateDisplay.value = formatted
  const iso = parseDisplayToIso(formatted)
  if (iso) {
    birthDateSyncing = true
    form.birthDate = iso
    birthDateCalendar.value = isoToCalendar(iso)
    nextTick(() => { birthDateSyncing = false })
  } else if (formatted.replace(/\D/g, '').length === 8) {
    birthDateSyncing = true
    form.birthDate = 'INVALID_DATE'
    nextTick(() => { birthDateSyncing = false })
  }
}
function onNameInput(val: string) {
  form.name = isIndividual.value ? val.replace(/[0-9]/g, '') : val
}
function onIdentityInput(val: string) {
  const passportAllowed = isFieldEnabled('allow_passport_id')
  form.identityNo = passportAllowed ? val.replace(/\s/g, '') : val.replace(/\D/g, '')
}
function onBirthDateCalendar(val: any) {
  if (!val) return
  birthDateSyncing = true
  birthDateCalendar.value = val
  form.birthDate = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  birthDateDisplay.value = `${String(val.day).padStart(2, '0')}.${String(val.month).padStart(2, '0')}.${val.year}`
  nextTick(() => { birthDateSyncing = false })
}
watch(() => form.birthDate, (v) => {
  if (!birthDateSyncing && v) {
    birthDateDisplay.value = isoToDisplay(v)
    birthDateCalendar.value = isoToCalendar(v)
  }
})

// Location
const allCountries = ref<{ label: string; value: number }[]>([])
const allCities = ref<{ label: string; value: number }[]>([])
const allDistricts = ref<{ label: string; value: number }[]>([])
const countrySearch = ref('')
const citySearch = ref('')
const districtSearch = ref('')

const countryOptions = computed(() => {
  const term = countrySearch.value.toLowerCase().trim()
  const filtered = term ? allCountries.value.filter(c => c.label.toLowerCase().includes(term)) : allCountries.value
  const list = filtered.slice(0, 10)
  if (form.countryId && !list.some(c => c.value === form.countryId)) {
    const sel = allCountries.value.find(c => c.value === form.countryId)
    if (sel) list.unshift(sel)
  }
  return list
})
const cityOptions = computed(() => {
  if (!form.countryId) return []
  const term = citySearch.value.toLowerCase().trim()
  const filtered = term ? allCities.value.filter(c => c.label.toLowerCase().includes(term)) : allCities.value
  const list = filtered.slice(0, 10)
  if (form.city && !list.some(c => c.value === form.city)) {
    const sel = allCities.value.find(c => c.value === form.city)
    if (sel) list.unshift(sel)
  }
  return list
})
const districtOptions = computed(() => {
  if (!form.city) return []
  const term = districtSearch.value.toLowerCase().trim()
  const filtered = term ? allDistricts.value.filter(d => d.label.toLowerCase().includes(term)) : allDistricts.value
  const list = filtered.slice(0, 10)
  if (form.district && !list.some(d => d.value === form.district)) {
    const sel = allDistricts.value.find(d => d.value === form.district)
    if (sel) list.unshift(sel)
  }
  return list
})

async function fetchCountries() {
  if (allCountries.value.length) return
  try {
    const res = await get('countries')
    allCountries.value = (res.data || []).map((c: any) => ({ label: c.name, value: c.id }))
  } catch {}
}
async function fetchCities(countryId: number) {
  try {
    const res = await get(`cities?countryId=${countryId}`)
    allCities.value = (res.data || []).map((c: any) => ({ label: c.name, value: c.id }))
  } catch {}
}
async function fetchDistricts(cityId: number) {
  try {
    const res = await get(`cities/${cityId}/districts`)
    allDistricts.value = (res.data || []).map((d: any) => ({ label: d.name, value: d.id }))
  } catch {}
}

let skipLocationWatch = false
watch(() => form.countryId, () => {
  if (skipLocationWatch) return
  form.city = undefined
  form.district = undefined
  allCities.value = []
  allDistricts.value = []
  if (form.countryId) fetchCities(form.countryId)
})
watch(() => form.city, (val) => {
  if (skipLocationWatch) return
  form.district = undefined
  if (val) fetchDistricts(val)
  else allDistricts.value = []
})

const maritalOptions = [
  { label: 'Bekar', value: 'Bekar' },
  { label: 'Evli', value: 'Evli' },
  { label: 'Bosanmis', value: 'Bosanmis' }
]

// Field visibility helpers
function showField(key: string): boolean {
  const prefix = isIndividual.value ? 'individual_' : 'company_'
  return isFieldEnabled(prefix + key)
}

watch(isOpen, (open) => {
  if (open) fetchFieldSettings()
})

// Populate form when customer prop changes (edit mode)
watch(() => props.customer, async (c) => {
  if (!c) {
    // Add mode
    skipLocationWatch = true
    Object.assign(form, { ...defaultForm })
    allCities.value = []
    allDistricts.value = []
    birthDateDisplay.value = ''
    birthDateCalendar.value = undefined
    fetchCountries()
    nextTick(() => { skipLocationWatch = false })
    return
  }
  // Edit mode
  fetchCountries()
  const countryId = c.countryId ? Number(c.countryId) : undefined
  if (countryId) await fetchCities(countryId)
  const cityId = c.cityId ? Number(c.cityId) : undefined
  if (cityId) await fetchDistricts(cityId)
  skipLocationWatch = true
  Object.assign(form, {
    type: c.customerType,
    name: (c.name || '').trim(),
    identityNo: (c.identityNo || '').trim(),
    phone: c.phone || '',
    email: c.email || '',
    taxOffice: c.taxOffice || '',
    birthDate: c.birthDate || '',
    phoneAlt: c.phoneAlt || '',
    contactPerson: c.contactPerson || '',
    maritalStatus: c.maritalStatus || '',
    job: c.job || '',
    dependentsCount: c.dependentsCount ?? undefined,
    sector: c.sector || '',
    countryId: countryId,
    city: cityId,
    district: c.districtId ? Number(c.districtId) : undefined,
    address: c.address || '',
    note: c.note || ''
  })
  nextTick(() => { skipLocationWatch = false })
}, { immediate: true })

async function saveCustomer() {
  if (saving.value) return // çift submit koruması
  saving.value = true
  try {
    const data: Record<string, any> = { ...form }
    data.customerType = data.type
    delete data.type
    for (const key of Object.keys(data)) {
      if (data[key] === '') data[key] = undefined
    }

    if (props.customer) {
      await put(`customers/${props.customer.id}`, data)
      toast.add({ title: 'Müşteri güncellendi', color: 'success' })
      isOpen.value = false
      emit('saved')
    } else {
      const res = await post<any>('customers', data)
      toast.add({ title: 'Yeni müşteri eklendi', color: 'success' })
      isOpen.value = false
      emit('saved', res?.data?.id ? { id: res.data.id, name: form.name, identityNo: form.identityNo || '' } : undefined)
    }
  } catch (error: any) {
    toast.add({ title: error.message || 'Müşteri kaydedilemedi', color: 'error' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UModal v-model:open="isOpen" :title="customer ? 'Müşteri Düzenle' : 'Yeni Müşteri'" class="sm:max-w-2xl" :ui="{ content: 'flex flex-col max-h-[90vh]', body: 'flex-1 overflow-y-auto min-h-0' }">
    <template #body>
      <UForm ref="formRef" :schema="customerSchema" :state="form" :validate-on="['blur', 'change', 'submit']" @submit="saveCustomer">
        <div class="grid grid-cols-12 gap-x-4 gap-y-3 [&_input]:!font-semibold">
          <UFormField label="Müşteri Türü" name="type" class="col-span-12">
            <div class="grid grid-cols-2 gap-4">
              <button
                type="button"
                class="flex flex-col items-center gap-2 p-4 rounded-lg border-2 transition-all cursor-pointer"
                :class="form.type === 'INDIVIDUAL' ? 'border-primary bg-primary/5' : 'border-default hover:border-muted'"
                @click="form.type = 'INDIVIDUAL'"
              >
                <UIcon name="i-lucide-user" class="size-8" :class="form.type === 'INDIVIDUAL' ? 'text-primary' : 'text-muted'" />
                <span class="text-sm font-medium" :class="form.type === 'INDIVIDUAL' ? 'text-primary' : 'text-muted'">Bireysel</span>
              </button>
              <button
                type="button"
                class="flex flex-col items-center gap-2 p-4 rounded-lg border-2 transition-all cursor-pointer"
                :class="form.type === 'CORPORATE' ? 'border-primary bg-primary/5' : 'border-default hover:border-muted'"
                @click="form.type = 'CORPORATE'"
              >
                <UIcon name="i-lucide-building-2" class="size-8" :class="form.type === 'CORPORATE' ? 'text-primary' : 'text-muted'" />
                <span class="text-sm font-medium" :class="form.type === 'CORPORATE' ? 'text-primary' : 'text-muted'">Kurumsal</span>
              </button>
            </div>
          </UFormField>

          <UFormField name="name" class="col-span-12">
            <template #label />
            <!-- pt-5 (20px) → label-metin arası ~12px boşluk; pb-2.5 (10px) → alt denge -->
            <div class="relative fl-form">
              <UInput
                :model-value="form.name"
                placeholder=" "
                class="w-full peer/fl-name"
                @update:model-value="onNameInput"
              />
              <label class="
                pointer-events-none select-none
                absolute left-3 z-10
                bg-[var(--ui-bg)] px-1
                transition-all duration-150 ease-in-out
                text-sm text-[var(--ui-text-muted)]
                top-1/2 -translate-y-1/2
                peer-focus-within/fl-name:top-0
                peer-focus-within/fl-name:-translate-y-1/2
                peer-focus-within/fl-name:text-xs
                peer-focus-within/fl-name:text-[var(--ui-primary)]
                peer-has-[input:not(:placeholder-shown)]/fl-name:top-0
                peer-has-[input:not(:placeholder-shown)]/fl-name:-translate-y-1/2
                peer-has-[input:not(:placeholder-shown)]/fl-name:text-xs
                peer-has-[input:not(:placeholder-shown)]/fl-name:text-[var(--ui-text-highlighted)]
              ">
                {{ isIndividual ? 'Ad Soyad' : 'Firma Unvanı' }}
                <span class="text-[var(--ui-error)]">*</span>
              </label>
            </div>
          </UFormField>

          <UFormField name="identityNo" class="col-span-6">
            <template #label />
            <div class="relative fl-form">
              <UInput :model-value="form.identityNo" placeholder=" " :maxlength="identityMaxLength" class="w-full peer/fl-identity" @update:model-value="onIdentityInput" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-identity:top-0 peer-focus-within/fl-identity:-translate-y-1/2 peer-focus-within/fl-identity:text-xs peer-focus-within/fl-identity:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-identity:top-0 peer-has-[input:not(:placeholder-shown)]/fl-identity:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-identity:text-xs peer-has-[input:not(:placeholder-shown)]/fl-identity:text-[var(--ui-text-highlighted)]">
                {{ identityLabel }} <span class="text-[var(--ui-error)]">*</span>
              </label>
            </div>
          </UFormField>

          <UFormField :name="isIndividual ? 'birthDate' : 'taxOffice'" class="col-span-6">
            <template #label />
            <!-- Doğum Tarihi: takvim butonu UInput #trailing içinde -->
            <div v-if="isIndividual" class="relative fl-form">
              <UInput
                :model-value="birthDateDisplay"
                placeholder=" "
                maxlength="10"
                class="w-full peer/fl-birth"
                @update:model-value="onBirthDateInput($event)"
              >
                <template #trailing>
                  <UPopover v-model:open="birthDatePopoverOpen">
                    <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" />
                    <template #content>
                      <UCalendar locale="tr-TR" v-model="birthDateCalendar" class="p-2" @update:model-value="(v: any) => { onBirthDateCalendar(v); birthDatePopoverOpen = false }" />
                    </template>
                  </UPopover>
                </template>
              </UInput>
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-birth:top-0 peer-focus-within/fl-birth:-translate-y-1/2 peer-focus-within/fl-birth:text-xs peer-focus-within/fl-birth:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-birth:top-0 peer-has-[input:not(:placeholder-shown)]/fl-birth:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-birth:text-xs peer-has-[input:not(:placeholder-shown)]/fl-birth:text-[var(--ui-text-highlighted)]">
                Doğum Tarihi
              </label>
            </div>
            <!-- Vergi Dairesi: düz floating label -->
            <div v-else class="relative fl-form">
              <UInput v-model="form.taxOffice" placeholder=" " class="w-full peer/fl-taxoffice" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-taxoffice:top-0 peer-focus-within/fl-taxoffice:-translate-y-1/2 peer-focus-within/fl-taxoffice:text-xs peer-focus-within/fl-taxoffice:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-taxoffice:top-0 peer-has-[input:not(:placeholder-shown)]/fl-taxoffice:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-taxoffice:text-xs peer-has-[input:not(:placeholder-shown)]/fl-taxoffice:text-[var(--ui-text-highlighted)]">
                Vergi Dairesi
              </label>
            </div>
          </UFormField>

          <UFormField name="email" class="col-span-6">
            <template #label />
            <div class="relative fl-form">
              <UInput :model-value="form.email" type="email" placeholder=" " class="w-full peer/fl-email" @update:model-value="(v) => form.email = String(v).toLowerCase()" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-email:top-0 peer-focus-within/fl-email:-translate-y-1/2 peer-focus-within/fl-email:text-xs peer-focus-within/fl-email:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-email:top-0 peer-has-[input:not(:placeholder-shown)]/fl-email:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-email:text-xs peer-has-[input:not(:placeholder-shown)]/fl-email:text-[var(--ui-text-highlighted)]">
                E-posta
              </label>
            </div>
          </UFormField>

          <UFormField v-if="isIndividual ? showField('job') : true" :name="isIndividual ? 'job' : 'contactPerson'" class="col-span-6">
            <template #label />
            <div class="relative fl-form">
              <UInput v-if="isIndividual" v-model="form.job" placeholder=" " class="w-full peer/fl-job" />
              <UInput v-else v-model="form.contactPerson" placeholder=" " class="w-full peer/fl-job" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-job:top-0 peer-focus-within/fl-job:-translate-y-1/2 peer-focus-within/fl-job:text-xs peer-focus-within/fl-job:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-job:top-0 peer-has-[input:not(:placeholder-shown)]/fl-job:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-job:text-xs peer-has-[input:not(:placeholder-shown)]/fl-job:text-[var(--ui-text-highlighted)]">
                {{ isIndividual ? 'Meslek' : 'Yetkili Kişi' }}
              </label>
            </div>
          </UFormField>

          <UFormField name="phone" class="col-span-6">
            <template #label />
            <PhoneInput v-model="form.phone" label="Telefon" :required="true" />
          </UFormField>

          <UFormField v-if="showField('phone_2')" name="phoneAlt" class="col-span-6">
            <template #label />
            <PhoneInput v-model="form.phoneAlt" label="İkinci Telefon" />
          </UFormField>

          <UFormField v-if="isIndividual ? showField('marital_status') : showField('sector')" :label="isIndividual ? 'Medeni Durum' : 'Sektor'" :name="isIndividual ? 'maritalStatus' : 'sector'" class="col-span-6">
            <USelect v-if="isIndividual" v-model="form.maritalStatus" :items="maritalOptions" value-key="value" placeholder="Seçiniz..." class="w-full" />
            <UInput v-else v-model="form.sector" placeholder="Insaat" class="w-full" />
          </UFormField>

          <UFormField v-if="isIndividual ? showField('number_of_children') : showField('number_of_employees')" :label="isIndividual ? 'Çocuk Sayısı' : 'Calisan Sayısı'" name="dependentsCount" class="col-span-6">
            <UInput v-model.number="form.dependentsCount" type="number" :min="0" class="w-full" />
          </UFormField>

          <template v-if="showField('city')">
            <div class="col-span-12 flex items-center gap-3 pt-1">
              <span class="text-xs font-semibold text-muted uppercase tracking-wider">Konum Bilgileri</span>
              <div class="flex-1 h-px bg-[var(--ui-border)]"></div>
            </div>

            <UFormField label="Ulke" name="countryId" class="col-span-6">
              <USelectMenu v-model="form.countryId" :items="countryOptions" value-key="value" label-key="label" placeholder="Ulke arayiniz..." searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" @update:search-term="(v: string) => countrySearch = v" />
            </UFormField>

            <UFormField label="Şehir" name="city" class="col-span-6">
              <USelectMenu v-model="form.city" :items="cityOptions" value-key="value" label-key="label" placeholder="Şehir arayiniz..." searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" :disabled="!form.countryId" class="w-full" @update:search-term="(v: string) => citySearch = v" />
            </UFormField>

            <UFormField label="Ilce" name="district" class="col-span-6">
              <USelectMenu v-model="form.district" :items="districtOptions" value-key="value" label-key="label" placeholder="Ilce arayiniz..." searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" :disabled="!form.city" class="w-full" @update:search-term="(v: string) => districtSearch = v" />
            </UFormField>
          </template>

          <UFormField v-if="showField('address')" name="address" class="col-span-6">
            <template #label />
            <div class="relative fl-form">
              <UInput v-model="form.address" placeholder=" " class="w-full peer/fl-address" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-address:top-0 peer-focus-within/fl-address:-translate-y-1/2 peer-focus-within/fl-address:text-xs peer-focus-within/fl-address:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-address:top-0 peer-has-[input:not(:placeholder-shown)]/fl-address:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-address:text-xs peer-has-[input:not(:placeholder-shown)]/fl-address:text-[var(--ui-text-highlighted)]">
                Adres
              </label>
            </div>
          </UFormField>

          <button ref="submitBtnRef" type="submit" class="hidden" />
        </div>
      </UForm>
    </template>
    <template #footer>
      <div class="w-full flex justify-end items-center gap-3">
        <UButton label="İptal" color="neutral" variant="outline" size="xl" class="w-36 justify-center" :disabled="saving" @click="isOpen = false" />
        <UButton :label="customer ? 'Güncelle' : 'Kaydet'" size="xl" class="w-36 justify-center" :loading="saving" :disabled="saving" @click="submitBtnRef?.click()" />
      </div>
    </template>
  </UModal>
</template>
