<script setup lang="ts">
import { z } from 'zod'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Acente Bilgileri' })

const toast = useToast()
const { get, post } = useApi()
const { agency, setAgency } = useAgency()
const { user } = useAuth()

const isAdmin = computed(() => user.value?.role === 'admin')

const agencySchema = z.object({
  agency_name: z.string().min(2, 'Acente adı en az 2 karakter olmalıdır'),
  agency_description: z.string().optional().or(z.literal('')),
  agency_logo: z.string().optional().or(z.literal('')),
  agency_phone: z.string().min(10, 'Geçerli telefon giriniz').optional().or(z.literal('')),
  agency_email: z.string().email('Geçerli e-posta giriniz').optional().or(z.literal('')),
  agency_address: z.string().optional().or(z.literal('')),
  agency_country_id: z.string().optional().or(z.literal('')),
  agency_city_id: z.string().optional().or(z.literal('')),
  agency_district_id: z.string().optional().or(z.literal('')),
  agency_tax_office: z.string().optional().or(z.literal('')),
  agency_tax_number: z.string().optional().or(z.literal('')),
  agency_mersis_no: z.string().optional().or(z.literal('')),
  agency_tobb_no: z.string().optional().or(z.literal('')),
  agency_website: z.string().optional().or(z.literal('')),
})

const loading = ref(true)
const saving = ref(false)
const showApiKeyHelp = ref(false)

const apiKeySteps = [
  { title: 'Google AI Studio\u2019ya gidin', desc: 'aistudio.google.com/apikey', link: 'https://aistudio.google.com/apikey' },
  { title: 'Google hesabınızla giriş yapın', desc: 'Gmail hesabınız yeterli' },
  { title: '"Create API Key" butonuna tıklayın', desc: 'Yeni bir proje oluşturun veya mevcut projeyi seçin' },
  { title: 'Oluşturulan anahtarı kopyalayın', desc: 'Anahtar "AIza..." ile başlar' },
  { title: 'Yukarıdaki alana yapıştırın ve kaydedin', desc: 'PDF okuma özelliği otomatik aktif olur' }
]

// Accordion state — ilk açılışta Genel Bilgiler açık
const openSections = ref<Set<string>>(new Set(['genel']))

function toggleSection(key: string) {
  if (openSections.value.has(key)) {
    openSections.value.delete(key)
  } else {
    openSections.value.add(key)
  }
}

const form = reactive({
  agency_name: '',
  agency_description: '',
  agency_logo: '',
  agency_phone: '',
  agency_email: '',
  agency_address: '',
  agency_country_id: '',
  agency_city_id: '',
  agency_district_id: '',
  agency_tax_office: '',
  agency_tax_number: '',
  agency_mersis_no: '',
  agency_tobb_no: '',
  agency_website: '',
  gemini_api_key: '',
  ai_coach_enabled: false,
  customer_portal_enabled: false,
  netgsm_usercode: '',
  netgsm_password: '',
  netgsm_msgheader: '',
  netgsm_enabled: false,
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
  const selected = form.agency_country_id ? Number(form.agency_country_id) : null
  if (selected && !list.some(c => c.value === selected)) {
    const sel = allCountries.value.find(c => c.value === selected)
    if (sel) list.unshift(sel)
  }
  return list
})
const cityOptions = computed(() => {
  if (!form.agency_country_id) return []
  const term = citySearch.value.toLowerCase().trim()
  const filtered = term ? allCities.value.filter(c => c.label.toLowerCase().includes(term)) : allCities.value
  const list = filtered.slice(0, 10)
  const selected = form.agency_city_id ? Number(form.agency_city_id) : null
  if (selected && !list.some(c => c.value === selected)) {
    const sel = allCities.value.find(c => c.value === selected)
    if (sel) list.unshift(sel)
  }
  return list
})
const districtOptions = computed(() => {
  if (!form.agency_city_id) return []
  const term = districtSearch.value.toLowerCase().trim()
  const filtered = term ? allDistricts.value.filter(d => d.label.toLowerCase().includes(term)) : allDistricts.value
  const list = filtered.slice(0, 10)
  const selected = form.agency_district_id ? Number(form.agency_district_id) : null
  if (selected && !list.some(d => d.value === selected)) {
    const sel = allDistricts.value.find(d => d.value === selected)
    if (sel) list.unshift(sel)
  }
  return list
})

const locationCountryId = computed({
  get: () => form.agency_country_id ? Number(form.agency_country_id) : undefined,
  set: (v: number | undefined) => { form.agency_country_id = v ? String(v) : '' }
})
const locationCityId = computed({
  get: () => form.agency_city_id ? Number(form.agency_city_id) : undefined,
  set: (v: number | undefined) => { form.agency_city_id = v ? String(v) : '' }
})
const locationDistrictId = computed({
  get: () => form.agency_district_id ? Number(form.agency_district_id) : undefined,
  set: (v: number | undefined) => { form.agency_district_id = v ? String(v) : '' }
})

async function fetchCountries() {
  if (allCountries.value.length) return
  try { const res = await get('countries'); allCountries.value = (res.data || []).map((c: any) => ({ label: c.name, value: c.id })) } catch {}
}
async function fetchCities(countryId: number) {
  try { const res = await get(`cities?countryId=${countryId}`); allCities.value = (res.data || []).map((c: any) => ({ label: c.name, value: c.id })) } catch {}
}
async function fetchDistricts(cityId: number) {
  try { const res = await get(`cities/${cityId}/districts`); allDistricts.value = (res.data || []).map((d: any) => ({ label: d.name, value: d.id })) } catch {}
}

let skipLocationWatch = false
watch(() => form.agency_country_id, () => {
  if (skipLocationWatch) return
  form.agency_city_id = ''; form.agency_district_id = ''; allCities.value = []; allDistricts.value = []
  if (form.agency_country_id) fetchCities(Number(form.agency_country_id))
})
watch(() => form.agency_city_id, (val) => {
  if (skipLocationWatch) return
  form.agency_district_id = ''
  if (val) fetchDistricts(Number(val)); else allDistricts.value = []
})

async function fetchOptions() {
  loading.value = true
  try {
    await fetchCountries()
    const res = await get('settings')
    const data = res.data || {}
    Object.keys(form).forEach(key => {
      if (data[key] !== undefined) {
        if (key === 'ai_coach_enabled' || key === 'customer_portal_enabled' || key === 'netgsm_enabled') {
          (form as any)[key] = data[key] === '1' || data[key] === 1 || data[key] === true || data[key] === 'true'
        } else {
          (form as any)[key] = data[key] || ''
        }
      }
    })
    skipLocationWatch = true
    if (form.agency_country_id) await fetchCities(Number(form.agency_country_id))
    if (form.agency_city_id) await fetchDistricts(Number(form.agency_city_id))
    nextTick(() => { skipLocationWatch = false })
  } catch (error: any) {
    toast.add({ title: error.message || 'Bilgiler yüklenemedi', color: 'error' })
  } finally { loading.value = false }
}

async function saveOptions() {
  saving.value = true
  try {
    await post('settings', {
      ...form,
      ai_coach_enabled: form.ai_coach_enabled ? '1' : '0',
      customer_portal_enabled: form.customer_portal_enabled ? 'true' : 'false',
      netgsm_enabled: form.netgsm_enabled ? 'true' : 'false',
      netgsm_password: form.netgsm_password?.includes('***') ? undefined : form.netgsm_password,
    })
    setAgency({ name: form.agency_name || 'Sigorta Takip', logo: form.agency_logo || null, description: form.agency_description || '' })
    toast.add({ title: 'Acente bilgileri kaydedildi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Kaydedilemedi', color: 'error' })
  } finally { saving.value = false }
}

// Logo upload
const uploadingLogo = ref(false)

async function handleLogoUpload(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (file.size > 2 * 1024 * 1024) { toast.add({ title: 'Logo 2MB\'den büyük olamaz', color: 'error' }); return }
  uploadingLogo.value = true
  try {
    const formData = new FormData()
    formData.append('logo', file)
    const { token } = useAuth()
    const response = await fetch('/api/companies/upload-logo', { method: 'POST', headers: { Authorization: `Bearer ${token.value}` }, body: formData })
    const data = await response.json()
    if (data.success && data.data?.url) { form.agency_logo = data.data.url; toast.add({ title: 'Logo yüklendi', color: 'success' }) }
    else { toast.add({ title: data.message || 'Logo yüklenemedi', color: 'error' }) }
  } catch { toast.add({ title: 'Logo yüklenirken hata oluştu', color: 'error' }) }
  finally { uploadingLogo.value = false; input.value = '' }
}

function removeLogo() { form.agency_logo = '' }

onMounted(fetchOptions)
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <!-- Sayfa Başlığı -->
    <div class="mb-6 pb-4 border-b border-default">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-xl font-semibold">Acente Bilgileri</h1>
          <p class="text-sm text-muted mt-1">Acentenizin genel bilgilerini ve entegrasyon ayarlarını yönetin.</p>
        </div>
        <UButton v-if="isAdmin" label="Kaydet" icon="i-lucide-check" size="xl" class="font-semibold" :loading="saving" @click="saveOptions" />
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="space-y-4">
      <SkeletonCard v-for="i in 3" :key="i">
        <div class="space-y-3">
          <div class="h-5 bg-neutral-200 rounded w-48" />
          <div class="h-4 bg-neutral-200 rounded w-64" />
          <div class="h-4 bg-neutral-200 rounded w-40" />
        </div>
      </SkeletonCard>
    </div>

    <UForm v-else :schema="agencySchema" :state="form" class="space-y-4" @submit="saveOptions">

      <!-- ═══ Genel Bilgiler ═══ -->
      <UCard>
        <template #header>
          <button type="button" class="w-full flex items-center justify-between cursor-pointer" @click="toggleSection('genel')">
            <h2 class="font-semibold">Genel Bilgiler</h2>
            <UIcon :name="openSections.has('genel') ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-5 text-muted" />
          </button>
        </template>
        <div v-show="openSections.has('genel')" class="space-y-0 divide-y divide-default">
          <!-- Logo -->
          <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 first:pt-0">
            <div class="min-w-48">
              <p class="text-sm font-medium">Logo</p>
              <p class="text-xs text-muted">Giriş ekranı ve sidebar'da görünür.</p>
            </div>
            <div class="flex items-center gap-4">
              <div class="size-16 rounded-lg border border-default bg-neutral-50 flex items-center justify-center overflow-hidden">
                <img v-if="form.agency_logo" :src="form.agency_logo" alt="Logo" class="size-full object-contain p-1">
                <UIcon v-else name="i-lucide-building" class="size-8 text-muted" />
              </div>
              <div class="flex flex-col gap-1.5">
                <label class="cursor-pointer">
                  <UButton :label="form.agency_logo ? 'Değiştir' : 'Yükle'" icon="i-lucide-upload" color="neutral" variant="outline" size="xl" class="font-semibold" :loading="uploadingLogo" as="span" />
                  <input type="file" accept="image/*" class="hidden" :disabled="!isAdmin" @change="handleLogoUpload">
                </label>
                <UButton v-if="form.agency_logo" label="Kaldır" icon="i-lucide-trash-2" color="error" variant="ghost" size="xl" class="font-semibold" @click="removeLogo" />
              </div>
            </div>
          </div>
          <!-- Acente Adı -->
          <div class="py-4">
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="form.agency_name" placeholder=" " :disabled="!isAdmin" class="w-full peer/fl-aname" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-aname:top-0 peer-focus-within/fl-aname:-translate-y-1/2 peer-focus-within/fl-aname:text-xs peer-focus-within/fl-aname:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-aname:top-0 peer-has-[input:not(:placeholder-shown)]/fl-aname:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-aname:text-xs peer-has-[input:not(:placeholder-shown)]/fl-aname:text-[var(--ui-text-highlighted)]">Acente Adı</label>
            </div>
          </div>
          <!-- Açıklama -->
          <div class="py-4 last:pb-0">
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="form.agency_description" placeholder=" " :disabled="!isAdmin" class="w-full peer/fl-adesc" data-no-uppercase />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-adesc:top-0 peer-focus-within/fl-adesc:-translate-y-1/2 peer-focus-within/fl-adesc:text-xs peer-focus-within/fl-adesc:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-adesc:top-0 peer-has-[input:not(:placeholder-shown)]/fl-adesc:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-adesc:text-xs peer-has-[input:not(:placeholder-shown)]/fl-adesc:text-[var(--ui-text-highlighted)]">Açıklama</label>
            </div>
          </div>
        </div>
      </UCard>

      <!-- ═══ İletişim Bilgileri ═══ -->
      <UCard>
        <template #header>
          <button type="button" class="w-full flex items-center justify-between cursor-pointer" @click="toggleSection('iletisim')">
            <h2 class="font-semibold">İletişim Bilgileri</h2>
            <UIcon :name="openSections.has('iletisim') ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-5 text-muted" />
          </button>
        </template>
        <div v-show="openSections.has('iletisim')" class="space-y-0 divide-y divide-default">
          <div class="py-4 first:pt-0">
            <PhoneInput v-model="form.agency_phone" :disabled="!isAdmin" />
          </div>
          <div class="py-4">
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="form.agency_email" type="email" placeholder=" " :disabled="!isAdmin" class="w-full peer/fl-aemail" data-no-uppercase />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-aemail:top-0 peer-focus-within/fl-aemail:-translate-y-1/2 peer-focus-within/fl-aemail:text-xs peer-focus-within/fl-aemail:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-aemail:top-0 peer-has-[input:not(:placeholder-shown)]/fl-aemail:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-aemail:text-xs peer-has-[input:not(:placeholder-shown)]/fl-aemail:text-[var(--ui-text-highlighted)]">E-posta</label>
            </div>
          </div>
          <div class="py-4">
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="form.agency_website" placeholder=" " :disabled="!isAdmin" class="w-full peer/fl-aweb" data-no-uppercase />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-aweb:top-0 peer-focus-within/fl-aweb:-translate-y-1/2 peer-focus-within/fl-aweb:text-xs peer-focus-within/fl-aweb:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-aweb:top-0 peer-has-[input:not(:placeholder-shown)]/fl-aweb:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-aweb:text-xs peer-has-[input:not(:placeholder-shown)]/fl-aweb:text-[var(--ui-text-highlighted)]">Web Sitesi</label>
            </div>
          </div>
        </div>
      </UCard>

      <!-- ═══ Resmi Bilgiler ═══ -->
      <UCard>
        <template #header>
          <button type="button" class="w-full flex items-center justify-between cursor-pointer" @click="toggleSection('resmi')">
            <h2 class="font-semibold">Resmi Bilgiler</h2>
            <UIcon :name="openSections.has('resmi') ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-5 text-muted" />
          </button>
        </template>
        <div v-show="openSections.has('resmi')" class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-5 pt-2">
          <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput v-model="form.agency_tax_office" placeholder=" " :disabled="!isAdmin" class="w-full peer/fl-atax" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-atax:top-0 peer-focus-within/fl-atax:-translate-y-1/2 peer-focus-within/fl-atax:text-xs peer-focus-within/fl-atax:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-atax:top-0 peer-has-[input:not(:placeholder-shown)]/fl-atax:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-atax:text-xs peer-has-[input:not(:placeholder-shown)]/fl-atax:text-[var(--ui-text-highlighted)]">Vergi Dairesi</label>
          </div>
          <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput v-model="form.agency_tax_number" placeholder=" " :disabled="!isAdmin" class="w-full peer/fl-ataxno" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-ataxno:top-0 peer-focus-within/fl-ataxno:-translate-y-1/2 peer-focus-within/fl-ataxno:text-xs peer-focus-within/fl-ataxno:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-ataxno:top-0 peer-has-[input:not(:placeholder-shown)]/fl-ataxno:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-ataxno:text-xs peer-has-[input:not(:placeholder-shown)]/fl-ataxno:text-[var(--ui-text-highlighted)]">Vergi Numarası</label>
          </div>
          <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput v-model="form.agency_mersis_no" placeholder=" " :disabled="!isAdmin" class="w-full peer/fl-amersis" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-amersis:top-0 peer-focus-within/fl-amersis:-translate-y-1/2 peer-focus-within/fl-amersis:text-xs peer-focus-within/fl-amersis:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-amersis:top-0 peer-has-[input:not(:placeholder-shown)]/fl-amersis:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-amersis:text-xs peer-has-[input:not(:placeholder-shown)]/fl-amersis:text-[var(--ui-text-highlighted)]">MERSİS No</label>
          </div>
          <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput v-model="form.agency_tobb_no" placeholder=" " :disabled="!isAdmin" class="w-full peer/fl-atobb" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-atobb:top-0 peer-focus-within/fl-atobb:-translate-y-1/2 peer-focus-within/fl-atobb:text-xs peer-focus-within/fl-atobb:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-atobb:top-0 peer-has-[input:not(:placeholder-shown)]/fl-atobb:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-atobb:text-xs peer-has-[input:not(:placeholder-shown)]/fl-atobb:text-[var(--ui-text-highlighted)]">TOBB Sicil No</label>
          </div>
        </div>
      </UCard>

      <!-- ═══ AI Entegrasyonu ═══ -->
      <UCard>
        <template #header>
          <button type="button" class="w-full flex items-center justify-between cursor-pointer" @click="toggleSection('ai')">
            <h2 class="font-semibold">AI Entegrasyonu</h2>
            <UIcon :name="openSections.has('ai') ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-5 text-muted" />
          </button>
        </template>
        <div v-show="openSections.has('ai')">
          <div class="divide-y divide-default">
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 first:pt-0">
              <div class="min-w-48">
                <p class="text-sm font-medium">Gemini API Anahtarı</p>
                <p class="text-xs text-muted">Google AI Studio'dan alınan API anahtarı.</p>
                <button type="button" class="text-xs text-primary hover:underline mt-1 cursor-pointer" @click="showApiKeyHelp = true">
                  <UIcon name="i-lucide-help-circle" class="size-3 inline" /> Nasıl alınır?
                </button>
              </div>
              <div class="w-full max-w-sm space-y-2">
                <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
                  <UInput v-model="form.gemini_api_key" :disabled="!isAdmin" type="password" placeholder=" " class="w-full font-mono peer/fl-agemini" />
                  <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-agemini:top-0 peer-focus-within/fl-agemini:-translate-y-1/2 peer-focus-within/fl-agemini:text-xs peer-focus-within/fl-agemini:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-agemini:top-0 peer-has-[input:not(:placeholder-shown)]/fl-agemini:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-agemini:text-xs peer-has-[input:not(:placeholder-shown)]/fl-agemini:text-[var(--ui-text-highlighted)]">Gemini API Anahtarı</label>
                </div>
                <div class="flex items-center gap-2">
                  <UBadge v-if="form.gemini_api_key && !form.gemini_api_key.includes('***')" color="success" variant="subtle" size="sm">Yeni anahtar girildi</UBadge>
                  <UBadge v-else-if="form.gemini_api_key && form.gemini_api_key.includes('***')" color="success" variant="subtle" size="sm">PDF okuma aktif</UBadge>
                  <UBadge v-else color="neutral" variant="subtle" size="sm">PDF okuma kapalı</UBadge>
                  <UButton v-if="form.gemini_api_key && isAdmin" size="xs" color="error" variant="ghost" @click="form.gemini_api_key = ''">Anahtarı Kaldır</UButton>
                </div>
              </div>
            </div>
          </div>
          <!-- AI Satış Koçu -->
          <div class="border-t border-default mt-4 pt-4">
            <div class="flex max-sm:flex-col justify-between items-start gap-4">
              <div class="min-w-48">
                <p class="text-sm font-medium">AI Satış Koçu</p>
                <p class="text-xs text-muted">Temsilcilere akıllı satış önerileri sunar.</p>
              </div>
              <div class="w-full max-w-sm space-y-2">
                <div class="flex items-center gap-3">
                  <USwitch v-model="form.ai_coach_enabled" size="xs" :disabled="!isAdmin || !form.gemini_api_key" />
                  <span class="text-sm" :class="form.ai_coach_enabled ? 'text-green-600 font-medium' : 'text-muted'">{{ form.ai_coach_enabled ? 'Aktif' : 'Pasif' }}</span>
                </div>
                <p v-if="!form.gemini_api_key" class="text-xs text-warning-600">Önce Gemini API anahtarını giriniz.</p>
              </div>
            </div>
          </div>
        </div>
      </UCard>

      <!-- ═══ Müşteri Portalı ═══ -->
      <UCard>
        <template #header>
          <button type="button" class="w-full flex items-center justify-between cursor-pointer" @click="toggleSection('portal')">
            <h2 class="font-semibold">Müşteri Portalı</h2>
            <UIcon :name="openSections.has('portal') ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-5 text-muted" />
          </button>
        </template>
        <div v-show="openSections.has('portal')">
          <div class="flex max-sm:flex-col justify-between items-start gap-4">
            <div class="min-w-48">
              <p class="text-sm font-medium">Portal Durumu</p>
              <p class="text-xs text-muted">Aktif edildiğinde müşteriler portaldan giriş yapabilir.</p>
            </div>
            <div class="w-full max-w-sm space-y-2">
              <div class="flex items-center gap-3">
                <USwitch v-model="form.customer_portal_enabled" size="xs" :disabled="!isAdmin" />
                <span class="text-sm" :class="form.customer_portal_enabled ? 'text-green-600 font-medium' : 'text-muted'">{{ form.customer_portal_enabled ? 'Aktif' : 'Pasif' }}</span>
              </div>
              <p class="text-xs text-muted">Müşteri kullanıcıları Takım Yönetimi sayfasından oluşturulur.</p>
            </div>
          </div>
        </div>
      </UCard>

      <!-- ═══ Netgsm SMS ═══ -->
      <UCard>
        <template #header>
          <button type="button" class="w-full flex items-center justify-between cursor-pointer" @click="toggleSection('sms')">
            <h2 class="font-semibold">Netgsm SMS Entegrasyonu</h2>
            <UIcon :name="openSections.has('sms') ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-5 text-muted" />
          </button>
        </template>
        <div v-show="openSections.has('sms')" class="divide-y divide-default">
          <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 first:pt-0">
            <div class="min-w-48">
              <p class="text-sm font-medium">SMS Durumu</p>
              <p class="text-xs text-muted">Aktif edildiğinde SMS gönderimi yapılabilir.</p>
            </div>
            <div class="w-full max-w-sm space-y-2">
              <div class="flex items-center gap-3">
                <USwitch v-model="form.netgsm_enabled" size="xs" :disabled="!isAdmin || !form.netgsm_usercode || !form.netgsm_password" />
                <span class="text-sm" :class="form.netgsm_enabled ? 'text-green-600 font-medium' : 'text-muted'">{{ form.netgsm_enabled ? 'Aktif' : 'Pasif' }}</span>
              </div>
              <p v-if="!form.netgsm_usercode || !form.netgsm_password" class="text-xs text-warning-600">Önce API bilgilerini giriniz.</p>
            </div>
          </div>
          <div class="py-4">
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="form.netgsm_usercode" placeholder=" " :disabled="!isAdmin" class="w-full peer/fl-nuser" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-nuser:top-0 peer-focus-within/fl-nuser:-translate-y-1/2 peer-focus-within/fl-nuser:text-xs peer-focus-within/fl-nuser:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-nuser:top-0 peer-has-[input:not(:placeholder-shown)]/fl-nuser:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-nuser:text-xs peer-has-[input:not(:placeholder-shown)]/fl-nuser:text-[var(--ui-text-highlighted)]">Abone No (Usercode)</label>
            </div>
          </div>
          <div class="py-4">
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="form.netgsm_password" type="password" placeholder=" " :disabled="!isAdmin" class="w-full peer/fl-npass" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-npass:top-0 peer-focus-within/fl-npass:-translate-y-1/2 peer-focus-within/fl-npass:text-xs peer-focus-within/fl-npass:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-npass:top-0 peer-has-[input:not(:placeholder-shown)]/fl-npass:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-npass:text-xs peer-has-[input:not(:placeholder-shown)]/fl-npass:text-[var(--ui-text-highlighted)]">API Şifresi</label>
            </div>
          </div>
          <div class="py-4 last:pb-0">
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="form.netgsm_msgheader" placeholder=" " :disabled="!isAdmin" class="w-full peer/fl-nmsg" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-nmsg:top-0 peer-focus-within/fl-nmsg:-translate-y-1/2 peer-focus-within/fl-nmsg:text-xs peer-focus-within/fl-nmsg:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-nmsg:top-0 peer-has-[input:not(:placeholder-shown)]/fl-nmsg:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-nmsg:text-xs peer-has-[input:not(:placeholder-shown)]/fl-nmsg:text-[var(--ui-text-highlighted)]">Mesaj Başlığı</label>
            </div>
          </div>
        </div>
      </UCard>

      <!-- API Key Yardım Modalı -->
      <UModal v-model:open="showApiKeyHelp" title="Gemini API Anahtarı Nasıl Alınır?" class="sm:max-w-lg">
        <template #body>
          <div class="space-y-4">
            <div class="flex items-start gap-3 p-3 rounded-lg bg-warning-50 border border-warning-200">
              <UIcon name="i-lucide-info" class="size-4 text-warning-600 shrink-0 mt-0.5" />
              <p class="text-sm text-warning-700">Gemini API ücretsiz kullanılabilir. Yüksek kullanım için ücretli plana geçiş gerekebilir.</p>
            </div>
            <ol class="space-y-3 text-sm">
              <li v-for="(step, i) in apiKeySteps" :key="i" class="flex gap-3">
                <span class="size-6 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0 text-xs font-bold">{{ i + 1 }}</span>
                <div>
                  <p class="font-medium">{{ step.title }}</p>
                  <a v-if="step.link" :href="step.link" target="_blank" class="text-primary hover:underline text-xs">{{ step.desc }}</a>
                  <p v-else class="text-xs text-muted">{{ step.desc }}</p>
                </div>
              </li>
            </ol>
          </div>
        </template>
        <template #footer>
          <div class="flex justify-end">
            <UButton label="Tamam" size="xl" class="font-semibold" @click="showApiKeyHelp = false" />
          </div>
        </template>
      </UModal>
    </UForm>
  </div>
</template>

<style scoped>
.select-fl :deep(button) {
  min-height: 50px !important;
  height: auto !important;
}
</style>
