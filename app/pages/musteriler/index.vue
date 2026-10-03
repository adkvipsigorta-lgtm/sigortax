<script setup lang="ts">
import { z } from 'zod'
import { CalendarDate } from '@internationalized/date'
import type { Customer, CustomerType } from '~/types'

definePageMeta({
  layout: 'default',
  middleware: 'auth',
  keepalive: true
})

useSeoMeta({ title: 'Müşteriler' })

const route = useRoute()
const toast = useToast()
const { formatCurrency } = usePolicyHelpers()
const { categories, fetchCategories, getCategoryById } = useCustomerCategories()

// Paginated data
const customers = usePaginatedData<Customer>({
  endpoint: 'customers',
  defaultLimit: 15,
  defaultSort: 'id',
  defaultOrder: 'desc'
})

let musterilerActivated = false

onMounted(() => {
  customers.fetchData()
  fetchCategories()

  // Sayfa ilk acilista query kontrol
  if (route.query.categoryId) {
    categoryFilter.value = String(route.query.categoryId)
    customers.setFilter('categoryId', String(route.query.categoryId))
  }
  if (route.query.action === 'new') {
    nextTick(() => openAddModal())
    navigateTo('/musteriler', { replace: true })
  }
  if (route.query.action === 'edit' && route.query.id) {
    nextTick(async () => {
      try {
        const res = await get(`customers/${route.query.id}`)
        const c = res.data || res
        openEditModal(c)
      } catch {}
    })
    navigateTo('/musteriler', { replace: true })
  }
})

onActivated(() => {
  if (musterilerActivated) customers.fetchData()
  musterilerActivated = true
})

// Route query değişince
watch(() => route.query.categoryId, (val) => {
  categoryFilter.value = val ? String(val) : 'all'
  customers.setFilter('categoryId', val ? String(val) : '')
})

watch(() => route.query.action, (val) => {
  if (val === 'new') {
    openAddModal()
    navigateTo('/musteriler', { replace: true })
  }
})

// Search
const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    customers.setSearch(val)
  }, 400)
})

// Filters
const typeFilter = ref('all')
const categoryFilter = ref('all')

watch(typeFilter, (val) => {
  customers.setFilter('type', val === 'all' ? '' : val)
})
watch(categoryFilter, (val) => {
  customers.setFilter('categoryId', val === 'all' ? '' : val)
})

const typeOptions = [
  { label: 'Tümü', value: 'all' },
  { label: 'Bireysel', value: 'INDIVIDUAL' },
  { label: 'Kurumsal', value: 'CORPORATE' }
]

const categoryFilterOptions = computed(() => [
  { label: 'Tüm Tipler', value: 'all' },
  ...categories.value.map(c => ({ label: c.name, value: String(c.id) }))
])

// Sorting - frontend key -> API column mapping
const sortKeyMap: Record<string, string> = {
  id: 'created_at',
  name: 'name',
  phone: 'phone',
  birthDate: 'birth_date',
  totalGross: 'total_gross',
  activePolicies: 'active_policies',
}

const sorting = ref<{ id: string, desc: boolean }[]>([{ id: 'id', desc: true }])
watch(sorting, (val) => {
  if (val.length) {
    const apiKey = sortKeyMap[val[0].id] || val[0].id
    customers.setSort(apiKey, val[0].desc ? 'desc' : 'asc')
  } else {
    customers.setSort('created_at', 'desc')
  }
}, { deep: true })

function formatPhone(phone: string | null | undefined): string {
  if (!phone) return '-'
  // Sadece rakamlari al
  const digits = phone.replace(/\D/g, '')
  // 90 ile baslayan 12 haneli numara: +90 5XX XXX XX XX
  if (digits.length === 12 && digits.startsWith('90')) {
    return `+${digits.slice(0, 2)} ${digits.slice(2, 5)} ${digits.slice(5, 8)} ${digits.slice(8, 10)} ${digits.slice(10, 12)}`
  }
  // 0 ile baslayan 11 haneli numara: +90 5XX XXX XX XX
  if (digits.length === 11 && digits.startsWith('0')) {
    return `+90 ${digits.slice(1, 4)} ${digits.slice(4, 7)} ${digits.slice(7, 9)} ${digits.slice(9, 11)}`
  }
  // 10 haneli numara (basindasiz): +90 5XX XXX XX XX
  if (digits.length === 10) {
    return `+90 ${digits.slice(0, 3)} ${digits.slice(3, 6)} ${digits.slice(6, 8)} ${digits.slice(8, 10)}`
  }
  return phone
}

// Table columns
const columns = [
  { accessorKey: 'id', header: 'Müşteri No', enableSorting: true, size: 40 },
  { accessorKey: 'name', header: 'Ad/Soyad', enableSorting: true, size: 220 },
  { accessorKey: 'phone', header: 'Telefon', enableSorting: true, size: 160, minSize: 150, maxSize: 170 },
  { accessorKey: 'birthDate', header: 'Doğum Tarihi', enableSorting: true, size: 100 },
  { accessorKey: 'totalGross', header: 'Toplam Prim', enableSorting: true, size: 140, minSize: 130, maxSize: 150 },
  { accessorKey: 'activePolicies', header: 'Toplam Aktif Poliçe', enableSorting: true, size: 150, minSize: 140, maxSize: 160 },
  { accessorKey: 'category', header: 'Segment', enableSorting: false, size: 100 },
  { accessorKey: 'actions', header: 'İşlem', enableSorting: false, size: 90, minSize: 80, maxSize: 100 }
]

// Modal state
const isModalOpen = ref(false)
const isDeleteModalOpen = ref(false)
const editingCustomer = ref<Customer | null>(null)
const deletingCustomerId = ref<number | null>(null)

// Form schema
const customerSchema = computed(() => {
  const passportAllowed = isFieldEnabled('allow_passport_id')
  const isIndividual = form.type === 'INDIVIDUAL'

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
    val => !val || (() => { const p = val.split('-').map(Number); const d = new Date(p[0], p[1]-1, p[2]); return d.getFullYear()===p[0] && d.getMonth()===p[1]-1 && d.getDate()===p[2] })(),
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
const isIndividual = computed(() => form.type === 'INDIVIDUAL')

const { post, put, del, get } = useApi()
const { can } = usePermissions()
const { isFieldEnabled, fetchFieldSettings } = useFieldSettings()

// Identity field label/placeholder/maxlength — toggle + tip baglı
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

// Alan ayarlarina gore goster/gizle (individual_/company_ prefix)
function showField(key: string): boolean {
  const prefix = isIndividual.value ? 'individual_' : 'company_'
  return isFieldEnabled(prefix + key)
}

watch(isModalOpen, (open) => {
  if (open) fetchFieldSettings()
})

// Birth date helpers (dd.mm.yyyy text + UCalendar popover)
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
  // Gerçek takvim doğrulaması (31 Şubat gibi geçersiz günler)
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
  // Bireysel müşteride ad soyadda rakam olamaz; kurumsal unvanda rakam olabilir
  form.name = isIndividual.value ? val.replace(/[0-9]/g, '') : val
}
function onIdentityInput(val: string) {
  // Pasaport izni varsa harf+rakam, yoksa sadece rakam
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

// Location data (cached, searchable :search-input="{ placeholder: 'Ara...' }" with first 10 shown)
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

// Country değişince city sifirla, city değişince district sifirla
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
  if (val) {
    fetchDistricts(val)
  } else {
    allDistricts.value = []
  }
})

const customerTypeOptions = [
  { label: 'Bireysel', value: 'INDIVIDUAL' },
  { label: 'Kurumsal', value: 'CORPORATE' }
]

const maritalOptions = [
  { label: 'Bekar', value: 'Bekar' },
  { label: 'Evli', value: 'Evli' },
  { label: 'Bosanmis', value: 'Bosanmis' }
]

const categoryOptions = computed(() =>
  categories.value.map(c => ({ label: c.name, value: c.id }))
)

// Modal islemleri
function openAddModal() {
  editingCustomer.value = null
  skipLocationWatch = true
  Object.assign(form, { ...defaultForm })
  allCities.value = []
  allDistricts.value = []
  birthDateDisplay.value = ''
  birthDateCalendar.value = undefined
  fetchCountries()
  nextTick(() => { skipLocationWatch = false })
  isModalOpen.value = true
}

async function openEditModal(customer: Customer) {
  editingCustomer.value = customer
  fetchCountries()
  const countryId = customer.countryId ? Number(customer.countryId) : undefined
  if (countryId) await fetchCities(countryId)
  const cityId = customer.cityId ? Number(customer.cityId) : undefined
  if (cityId) {
    await fetchDistricts(cityId)
  }
  skipLocationWatch = true
  Object.assign(form, {
    type: customer.customerType,
    name: (customer.name || '').trim(),
    identityNo: (customer.identityNo || '').trim(),
    phone: customer.phone,
    email: customer.email || '',
    taxOffice: customer.taxOffice || '',
    birthDate: customer.birthDate || '',
    phoneAlt: customer.phoneAlt || '',
    contactPerson: customer.contactPerson || '',
    maritalStatus: customer.maritalStatus || '',
    job: customer.job || '',
    dependentsCount: customer.dependentsCount ?? undefined,
    sector: customer.sector || '',
    countryId: customer.countryId ? Number(customer.countryId) : undefined,
    city: cityId,
    district: customer.districtId ? Number(customer.districtId) : undefined,
    address: customer.address || '',
    note: customer.note || ''
  })
  nextTick(() => { skipLocationWatch = false })
  isModalOpen.value = true
}

async function saveCustomer() {
  if (saving.value) return // çift submit koruması
  saving.value = true
  try {
    const data: Record<string, any> = { ...form }
    // type -> customerType donusumu (API beklentisi)
    data.customerType = data.type
    delete data.type
    // Bos stringleri temizle
    for (const key of Object.keys(data)) {
      if (data[key] === '') data[key] = undefined
    }

    if (editingCustomer.value) {
      await put(`customers/${editingCustomer.value.id}`, data)
      toast.add({ title: 'Müşteri güncellendi', color: 'success' })
    } else {
      await post('customers', data)
      toast.add({ title: 'Yeni müşteri eklendi', color: 'success' })
    }
    isModalOpen.value = false
    customers.refresh()
  } catch (error: any) {
    toast.add({ title: error.message || 'Müşteri kaydedilemedi', color: 'error' })
  } finally {
    saving.value = false
  }
}

function confirmDelete(id: number) {
  deletingCustomerId.value = id
  isDeleteModalOpen.value = true
}

async function doDelete() {
  if (!deletingCustomerId.value || deletingCustomer.value) return
  deletingCustomer.value = true
  try {
    await del(`customers/${deletingCustomerId.value}`)
    toast.add({ title: 'Müşteri silindi', color: 'success' })
    customers.refresh()
    isDeleteModalOpen.value = false
    deletingCustomerId.value = null
  } catch (error: any) {
    toast.add({ title: error.message || 'Müşteri silinemedi', color: 'error' })
  }
  deletingCustomer.value = false
}

// Excel export
const deletingCustomer = ref(false)
const exporting = ref(false)

async function exportToExcel() {
  exporting.value = true
  try {
    const { token } = useAuth()
    const params = new URLSearchParams()
    if (searchInput.value) params.set('search', searchInput.value)
    if (typeFilter.value !== 'all') params.set('type', typeFilter.value)
    if (categoryFilter.value !== 'all') params.set('categoryId', categoryFilter.value)

    const url = `/api/customers/export?${params.toString()}`
    const res = await fetch(url, { headers: { Authorization: `Bearer ${token.value}` } })
    const disposition = res.headers.get('Content-Disposition') || ''
    const match = disposition.match(/filename="?(.+?)"?$/)
    const filename = match ? match[1] : 'musteriler.xlsx'
    const blob = await res.blob()
    const blobUrl = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = blobUrl
    a.download = filename
    a.click()
    URL.revokeObjectURL(blobUrl)

    toast.add({ title: 'Müşteriler Excel olarak aktarıldı', color: 'success' })
  } catch {
    toast.add({ title: 'Excel aktarimi başarısız', color: 'error' })
  }
  exporting.value = false
}
</script>

<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-xl">Müşteriler</h1>
      <p class="text-sm text-muted mt-1">Müşteri listesi ve yönetimi.</p>
    </div>

    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="flex flex-wrap items-center gap-2">
            <div class="relative w-[220px] [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="searchInput" placeholder=" " class="w-full peer/fl-csearch" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-csearch:top-0 peer-focus-within/fl-csearch:-translate-y-1/2 peer-focus-within/fl-csearch:text-xs peer-focus-within/fl-csearch:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-csearch:top-0 peer-has-[input:not(:placeholder-shown)]/fl-csearch:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-csearch:text-xs peer-has-[input:not(:placeholder-shown)]/fl-csearch:text-[var(--ui-text-highlighted)]">Müşteri Ara</label>
            </div>
            <div class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5 w-[180px]">
              <USelect v-model="typeFilter" :items="typeOptions" value-key="value" placeholder=" " class="w-full" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">Müşteri Tipi</label>
            </div>
            <div class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5 w-[180px]">
              <USelect v-model="categoryFilter" :items="categoryFilterOptions" value-key="value" placeholder=" " class="w-full" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">Kategori</label>
            </div>
          </div>
          <UButton v-if="can('customers.export')" label="Excel" icon="i-lucide-download" color="neutral" variant="outline" size="xl" class="hidden sm:flex shrink-0" :loading="exporting" @click="exportToExcel" />
        </div>
      </template>

      <SkeletonTable v-if="customers.loading.value && !customers.data.value.length" :rows="10" :cols="6" />
      <div v-else class="border border-default rounded-lg overflow-hidden">
      <UTable
        v-model:sorting="sorting"
        :data="customers.data.value"
        :columns="columns"
        :loading="customers.loading.value && !customers.data.value.length"
        :sorting-options="{ manualSorting: true }"
        :ui="{
          base: 'table-fixed min-w-full musteriler-table',
          thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10',
          th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap',
          td: 'py-2 px-3 text-xs whitespace-nowrap overflow-hidden text-ellipsis'
        }"
      >
        <template #id-header="{ column }">
          <SortableHeader label="Müşteri No" :column="column" />
        </template>
        <template #name-header="{ column }">
          <SortableHeader label="Ad/Soyad" :column="column" />
        </template>
        <template #phone-header="{ column }">
          <SortableHeader label="Telefon" :column="column" />
        </template>
        <template #birthDate-header="{ column }">
          <SortableHeader label="Doğum Tarihi" :column="column" />
        </template>
        <template #totalGross-header="{ column }">
          <SortableHeader label="Toplam Prim" :column="column" />
        </template>
        <template #activePolicies-header="{ column }">
          <SortableHeader label="Aktif Poliçe" :column="column" />
        </template>

        <template #id-cell="{ row }">
          <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ row.original.id }}</span>
        </template>

        <template #name-cell="{ row }">
          <div class="min-w-0">
            <NuxtLink
              :to="`/musteriler/${row.original.id}`"
              class="text-primary hover:underline truncate block"
              :title="row.original.name"
            >
              {{ row.original.name }}
            </NuxtLink>
            <span class="text-muted truncate block">{{ row.original.identityNo || '-' }}</span>
          </div>
        </template>

        <template #phone-cell="{ row }">
          <span class="text-xs tabular-nums whitespace-nowrap">{{ formatPhone(row.original.phone) }}</span>
        </template>

        <template #birthDate-cell="{ row }">
          <span class="text-xs tabular-nums whitespace-nowrap">{{ row.original.birthDate ? new Date(row.original.birthDate).toLocaleDateString('tr-TR') : '-' }}</span>
        </template>

        <template #totalGross-cell="{ row }">
          <span class="text-xs tabular-nums whitespace-nowrap">{{ (row.original.totalGross ?? 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
        </template>

        <template #activePolicies-cell="{ row }">
          <span class="text-xs tabular-nums">{{ row.original.activePolicies ?? 0 }}</span>
        </template>

        <template #category-cell="{ row }">
          <span
            v-if="getCategoryById(row.original.categoryId)"
            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
            :style="{
              backgroundColor: getCategoryById(row.original.categoryId)!.color + '20',
              color: getCategoryById(row.original.categoryId)!.color
            }"
          >
            <span class="size-1.5 rounded-full" :style="{ backgroundColor: getCategoryById(row.original.categoryId)!.color }" />
            {{ getCategoryById(row.original.categoryId)!.name }}
          </span>
          <span v-else class="text-muted">-</span>
        </template>

        <template #actions-cell="{ row }">
          <div class="flex items-center gap-1">
            <UTooltip text="Düzenle">
              <UButton icon="i-lucide-pencil" color="neutral" variant="ghost" size="xs" @click="openEditModal(row.original)" />
            </UTooltip>
            <UTooltip v-if="can('customers.delete')" text="Sil">
              <UButton icon="i-lucide-trash-2" color="error" variant="ghost" size="xs" @click="confirmDelete(row.original.id)" />
            </UTooltip>
          </div>
        </template>
      </UTable>
      </div>

      <!-- Pagination loading bar -->
      <div class="h-[2px] w-full overflow-hidden">
        <div v-if="customers.loading.value" class="h-full bg-primary nav-loading-bar" />
      </div>

      <!-- Sayfalama -->
      <div class="flex flex-col sm:flex-row items-center gap-2 py-3 sm:justify-between">
        <div class="flex items-center gap-2 text-muted">
          <span class="hidden sm:inline text-sm">Sayfa başına satır</span>
          <USelect
            :model-value="customers.limit.value"
            :items="[{ label: '10', value: 10 }, { label: '15', value: 15 }, { label: '25', value: 25 }, { label: '50', value: 50 }]"
            value-key="value"
            size="xs"
            class="w-16"
            @update:model-value="(v: any) => { customers.limit.value = v; customers.setPage(1) }"
          />
          <span class="text-xs sm:text-sm">{{ (customers.page.value - 1) * customers.limit.value + 1 }} - {{ Math.min(customers.page.value * customers.limit.value, customers.total.value) }} / {{ customers.total.value }}</span>
        </div>

        <div v-if="customers.total.value > customers.limit.value" class="flex items-center gap-1">
          <!-- Mobil: sadece önceki/sonraki + sayfa bilgisi -->
          <UButton icon="i-lucide-chevron-left" size="xs" color="neutral" variant="outline" :disabled="customers.page.value <= 1" class="sm:hidden" @click="customers.setPage(customers.page.value - 1)" />
          <span class="sm:hidden text-xs text-muted px-2">{{ customers.page.value }} / {{ Math.ceil(customers.total.value / customers.limit.value) }}</span>
          <UButton icon="i-lucide-chevron-right" size="xs" color="neutral" variant="outline" :disabled="customers.page.value >= Math.ceil(customers.total.value / customers.limit.value)" class="sm:hidden" @click="customers.setPage(customers.page.value + 1)" />
          <!-- Masaüstü: tam sayfalama -->
          <UPagination
            class="hidden sm:flex"
            :default-page="customers.page.value"
            :items-per-page="customers.limit.value"
            :total="customers.total.value"
            @update:page="customers.setPage"
          />
        </div>
      </div>
    </UCard>

    <!-- Yeni / Düzenle Modal -->
    <UModal :dismissible="false" v-model:open="isModalOpen" :title="editingCustomer ? 'Müşteri Düzenle' : 'Yeni Müşteri'" class="sm:max-w-2xl">
      <template #body>
        <UForm ref="formRef" :schema="customerSchema" :state="form" :validate-on="['blur', 'change', 'submit']" @submit="saveCustomer">
          <div class="grid grid-cols-2 gap-x-4 gap-y-3">
            <UFormField label="Müşteri Türü" name="type" class="col-span-2">
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
            <!-- Ad Soyad / Firma Unvanı -->
            <div class="col-span-2 relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput :model-value="form.name" placeholder=" " class="w-full peer/fl-mname" @update:model-value="onNameInput" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-mname:top-0 peer-focus-within/fl-mname:-translate-y-1/2 peer-focus-within/fl-mname:text-xs peer-focus-within/fl-mname:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-mname:top-0 peer-has-[input:not(:placeholder-shown)]/fl-mname:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-mname:text-xs peer-has-[input:not(:placeholder-shown)]/fl-mname:text-[var(--ui-text-highlighted)]">{{ isIndividual ? 'Ad Soyad' : 'Firma Unvanı' }} <span class="text-red-500">*</span></label>
            </div>
            <!-- TC / VKN -->
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput :model-value="form.identityNo" placeholder=" " :maxlength="identityMaxLength" class="w-full peer/fl-mid" @update:model-value="onIdentityInput" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-mid:top-0 peer-focus-within/fl-mid:-translate-y-1/2 peer-focus-within/fl-mid:text-xs peer-focus-within/fl-mid:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-mid:top-0 peer-has-[input:not(:placeholder-shown)]/fl-mid:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-mid:text-xs peer-has-[input:not(:placeholder-shown)]/fl-mid:text-[var(--ui-text-highlighted)]">{{ identityLabel }} <span class="text-red-500">*</span></label>
            </div>
            <!-- Doğum Tarihi / Vergi Dairesi -->
            <div v-if="isIndividual" class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput :model-value="birthDateDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-mbdate" @update:model-value="onBirthDateInput($event)">
                <template #trailing>
                  <UPopover v-model:open="birthDatePopoverOpen">
                    <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" />
                    <template #content>
                      <UCalendar locale="tr-TR" v-model="birthDateCalendar" class="p-2" @update:model-value="(v: any) => { onBirthDateCalendar(v); birthDatePopoverOpen = false }" />
                    </template>
                  </UPopover>
                </template>
              </UInput>
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-mbdate:top-0 peer-focus-within/fl-mbdate:-translate-y-1/2 peer-focus-within/fl-mbdate:text-xs peer-focus-within/fl-mbdate:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-mbdate:top-0 peer-has-[input:not(:placeholder-shown)]/fl-mbdate:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-mbdate:text-xs peer-has-[input:not(:placeholder-shown)]/fl-mbdate:text-[var(--ui-text-highlighted)]">Doğum Tarihi</label>
            </div>
            <div v-else class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="form.taxOffice" placeholder=" " class="w-full peer/fl-mtax" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-mtax:top-0 peer-focus-within/fl-mtax:-translate-y-1/2 peer-focus-within/fl-mtax:text-xs peer-focus-within/fl-mtax:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-mtax:top-0 peer-has-[input:not(:placeholder-shown)]/fl-mtax:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-mtax:text-xs peer-has-[input:not(:placeholder-shown)]/fl-mtax:text-[var(--ui-text-highlighted)]">Vergi Dairesi</label>
            </div>
            <!-- E-posta -->
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="form.email" type="email" placeholder=" " class="w-full peer/fl-memail" data-no-uppercase />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-memail:top-0 peer-focus-within/fl-memail:-translate-y-1/2 peer-focus-within/fl-memail:text-xs peer-focus-within/fl-memail:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-memail:top-0 peer-has-[input:not(:placeholder-shown)]/fl-memail:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-memail:text-xs peer-has-[input:not(:placeholder-shown)]/fl-memail:text-[var(--ui-text-highlighted)]">E-posta</label>
            </div>
            <!-- Meslek / Yetkili Kişi -->
            <div v-if="isIndividual ? showField('job') : true" class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-if="isIndividual" v-model="form.job" placeholder=" " class="w-full peer/fl-mjob" />
              <UInput v-else v-model="form.contactPerson" placeholder=" " class="w-full peer/fl-mjob" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-mjob:top-0 peer-focus-within/fl-mjob:-translate-y-1/2 peer-focus-within/fl-mjob:text-xs peer-focus-within/fl-mjob:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-mjob:top-0 peer-has-[input:not(:placeholder-shown)]/fl-mjob:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-mjob:text-xs peer-has-[input:not(:placeholder-shown)]/fl-mjob:text-[var(--ui-text-highlighted)]">{{ isIndividual ? 'Meslek' : 'Yetkili Kişi' }}</label>
            </div>
            <!-- Telefon -->
            <div>
              <PhoneInput v-model="form.phone" required />
            </div>
            <div v-if="showField('phone_2')">
              <PhoneInput v-model="form.phoneAlt" label="İkinci Telefon" />
            </div>
            <!-- Medeni Durum / Sektör -->
            <div v-if="isIndividual ? showField('marital_status') : showField('sector')">
              <div v-if="isIndividual" class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
                <USelect v-model="form.maritalStatus" :items="maritalOptions" value-key="value" placeholder=" " class="w-full" />
                <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', form.maritalStatus ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">Medeni Durum</label>
              </div>
              <div v-else class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                <UInput v-model="form.sector" placeholder=" " class="w-full peer/fl-msec" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-msec:top-0 peer-focus-within/fl-msec:-translate-y-1/2 peer-focus-within/fl-msec:text-xs peer-focus-within/fl-msec:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-msec:top-0 peer-has-[input:not(:placeholder-shown)]/fl-msec:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-msec:text-xs peer-has-[input:not(:placeholder-shown)]/fl-msec:text-[var(--ui-text-highlighted)]">Sektör</label>
              </div>
            </div>
            <!-- Çocuk / Çalışan Sayısı -->
            <div v-if="isIndividual ? showField('number_of_children') : showField('number_of_employees')" class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model.number="form.dependentsCount" type="number" :min="0" placeholder=" " class="w-full peer/fl-mdep" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-mdep:top-0 peer-focus-within/fl-mdep:-translate-y-1/2 peer-focus-within/fl-mdep:text-xs peer-focus-within/fl-mdep:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-mdep:top-0 peer-has-[input:not(:placeholder-shown)]/fl-mdep:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-mdep:text-xs peer-has-[input:not(:placeholder-shown)]/fl-mdep:text-[var(--ui-text-highlighted)]">{{ isIndividual ? 'Çocuk Sayısı' : 'Çalışan Sayısı' }}</label>
            </div>
            <!-- Konum -->
            <template v-if="showField('city')">
              <div class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
                <USelectMenu v-model="form.countryId" :items="countryOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" class="w-full" @update:search-term="(v: string) => countrySearch = v" />
                <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', form.countryId ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">Ülke</label>
              </div>
              <div class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
                <USelectMenu v-model="form.city" :items="cityOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" :disabled="!form.countryId" class="w-full" @update:search-term="(v: string) => citySearch = v" />
                <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', form.city ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">Şehir</label>
              </div>
              <div class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
                <USelectMenu v-model="form.district" :items="districtOptions" value-key="value" label-key="label" placeholder=" " searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" :disabled="!form.city" class="w-full" @update:search-term="(v: string) => districtSearch = v" />
                <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', form.district ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">İlçe</label>
              </div>
            </template>
            <!-- Adres -->
            <div v-if="showField('address')" class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="form.address" placeholder=" " class="w-full peer/fl-maddr" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-maddr:top-0 peer-focus-within/fl-maddr:-translate-y-1/2 peer-focus-within/fl-maddr:text-xs peer-focus-within/fl-maddr:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-maddr:top-0 peer-has-[input:not(:placeholder-shown)]/fl-maddr:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-maddr:text-xs peer-has-[input:not(:placeholder-shown)]/fl-maddr:text-[var(--ui-text-highlighted)]">Adres</label>
            </div>
            <!-- Not -->
            <div v-if="showField('note')" class="col-span-2 relative [&_textarea]:!pt-5 [&_textarea]:!pb-2.5">
              <UTextarea v-model="form.note" :rows="2" placeholder=" " class="w-full peer/fl-mnote" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', form.note ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-3 text-[var(--ui-text-muted)]']">Not</label>
            </div>
            <button ref="submitBtnRef" type="submit" class="hidden" />
          </div>
        </UForm>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="İptal" color="neutral" variant="outline" size="xl"  :disabled="saving" @click="isModalOpen = false" />
          <UButton :label="editingCustomer ? 'Güncelle' : 'Kaydet'" size="xl"  :loading="saving" :disabled="saving" @click="submitBtnRef?.click()" />
        </div>
      </template>
    </UModal>

    <!-- Silme Onay -->
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Müşteri Sil">
      <template #body>
        <p>Bu müşteriyi silmek istediğinize emin misiniz? Müşteriye ait tüm veriler de silinecektir.</p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" size="xl"  :disabled="deletingCustomer" @click="isDeleteModalOpen = false" />
          <UButton label="Sil" color="error" icon="i-lucide-trash-2" size="xl"  :loading="deletingCustomer" :disabled="deletingCustomer" @click="doDelete" />
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
/* Kolon genişliklerini table-fixed ile kilitler — sayfa değiştirince yer kayması olmaz */
:deep(.musteriler-table th:nth-child(1)) { width: 80px;  min-width: 80px;  max-width: 80px;  }
:deep(.musteriler-table th:nth-child(2)) { width: 200px; min-width: 200px; max-width: 200px; }
:deep(.musteriler-table th:nth-child(3)) { width: 155px; min-width: 155px; max-width: 155px; }
:deep(.musteriler-table th:nth-child(4)) { width: 105px; min-width: 105px; max-width: 105px; }
:deep(.musteriler-table th:nth-child(5)) { width: 120px; min-width: 120px; max-width: 120px; }
:deep(.musteriler-table th:nth-child(6)) { width: 100px; min-width: 100px; max-width: 100px; }
:deep(.musteriler-table th:nth-child(7)) { width: 120px; min-width: 120px; max-width: 120px; }
:deep(.musteriler-table th:nth-child(8)) { width: 80px;  min-width: 80px;  max-width: 80px;  }

:deep(.musteriler-table > tbody > tr > td) {
  overflow: hidden;
  text-overflow: clip;
  max-width: 0;
}

/* Mobil: sadece Ad/Soyad (2) ve Telefon (3) görünsün */
@media (max-width: 767px) {
  :deep(.musteriler-table th:nth-child(1)),
  :deep(.musteriler-table td:nth-child(1)),
  :deep(.musteriler-table th:nth-child(4)),
  :deep(.musteriler-table td:nth-child(4)),
  :deep(.musteriler-table th:nth-child(5)),
  :deep(.musteriler-table td:nth-child(5)),
  :deep(.musteriler-table th:nth-child(6)),
  :deep(.musteriler-table td:nth-child(6)),
  :deep(.musteriler-table th:nth-child(7)),
  :deep(.musteriler-table td:nth-child(7)),
  :deep(.musteriler-table th:nth-child(8)),
  :deep(.musteriler-table td:nth-child(8)) {
    display: none;
  }
}
</style>
