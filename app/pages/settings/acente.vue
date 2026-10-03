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
  agency_name: z.string().min(2, 'Acente adı en az 2 karakter olmalıdir'),
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
watch(() => form.agency_country_id, () => {
  if (skipLocationWatch) return
  form.agency_city_id = ''
  form.agency_district_id = ''
  allCities.value = []
  allDistricts.value = []
  if (form.agency_country_id) fetchCities(Number(form.agency_country_id))
})
watch(() => form.agency_city_id, (val) => {
  if (skipLocationWatch) return
  form.agency_district_id = ''
  if (val) fetchDistricts(Number(val))
  else allDistricts.value = []
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
    // Load cities/districts after form populated
    skipLocationWatch = true
    if (form.agency_country_id) await fetchCities(Number(form.agency_country_id))
    if (form.agency_city_id) await fetchDistricts(Number(form.agency_city_id))
    nextTick(() => { skipLocationWatch = false })
  } catch (error: any) {
    toast.add({ title: error.message || 'Bilgiler yüklenemedi', color: 'error' })
  } finally {
    loading.value = false
  }
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
    setAgency({
      name: form.agency_name || 'Sigorta Takip',
      logo: form.agency_logo || null,
      description: form.agency_description || ''
    })
    toast.add({ title: 'Acente bilgileri kaydedildi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Kaydedilemedi', color: 'error' })
  } finally {
    saving.value = false
  }
}

// Logo upload
const uploadingLogo = ref(false)

async function handleLogoUpload(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  if (file.size > 2 * 1024 * 1024) {
    toast.add({ title: 'Logo 2MB\'den buyuk olamaz', color: 'error' })
    return
  }

  uploadingLogo.value = true
  try {
    const formData = new FormData()
    formData.append('logo', file)

    const { token } = useAuth()
    const response = await fetch('/api/companies/upload-logo', {
      method: 'POST',
      headers: { Authorization: `Bearer ${token.value}` },
      body: formData
    })
    const data = await response.json()
    if (data.success && data.data?.url) {
      form.agency_logo = data.data.url
      toast.add({ title: 'Logo yüklendi', color: 'success' })
    } else {
      toast.add({ title: data.message || 'Logo yüklenemedi', color: 'error' })
    }
  } catch {
    toast.add({ title: 'Logo yüklenirken hata olustu', color: 'error' })
  } finally {
    uploadingLogo.value = false
    input.value = ''
  }
}

function removeLogo() {
  form.agency_logo = ''
}

onMounted(fetchOptions)
</script>

<template>
  <div class="space-y-4">
    <UForm :schema="agencySchema" :state="form" @submit="saveOptions">
      <!-- Başlık -->
      <div class="flex items-center justify-between mb-4">
        <div>
          <h3 class="font-semibold">Acente Bilgileri</h3>
          <p class="text-xs text-muted">Acentenizin genel bilgilerini ve iletişim detaylarini yonetin.</p>
        </div>
        <UButton v-if="isAdmin" label="Kaydet" size="xs" :loading="saving" type="submit" />
      </div>

      <!-- Loading -->
      <div v-if="loading" class="space-y-4">
        <SkeletonCard>
          <div class="flex items-start gap-4">
            <div class="size-20 rounded-lg bg-gray-200 dark:bg-gray-700 shrink-0" />
            <div class="flex-1 space-y-3">
              <div class="h-5 bg-gray-200 dark:bg-gray-700 rounded w-48" />
              <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-64" />
              <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-40" />
            </div>
          </div>
        </SkeletonCard>
        <SkeletonCard>
          <div class="space-y-3">
            <div class="h-9 bg-gray-200 dark:bg-gray-700 rounded w-full" />
            <div class="h-9 bg-gray-200 dark:bg-gray-700 rounded w-full" />
            <div class="h-9 bg-gray-200 dark:bg-gray-700 rounded w-full" />
          </div>
        </SkeletonCard>
      </div>

      <template v-else>
        <!-- Logo & Genel Bilgiler -->
        <UCard class="mb-4">
          <template #header>
            <h3 class="font-semibold">Genel Bilgiler</h3>
          </template>

          <div class="space-y-0 divide-y divide-default">
            <!-- Logo -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 first:pt-0">
              <div class="min-w-48">
                <p class="text-sm font-medium">Logo</p>
                <p class="text-xs text-muted">Giriş ekrani ve sidebar'da gorunur.</p>
              </div>
              <div class="flex items-center gap-4">
                <div class="size-16 rounded-lg border border-default bg-gray-50 dark:bg-gray-800 flex items-center justify-center overflow-hidden">
                  <img
                    v-if="form.agency_logo"
                    :src="form.agency_logo"
                    alt="Logo"
                    class="size-full object-contain p-1"
                  >
                  <UIcon v-else name="i-lucide-building" class="size-8 text-muted" />
                </div>
                <div class="flex flex-col gap-1.5">
                  <label class="cursor-pointer">
                    <UButton
                      :label="form.agency_logo ? 'Değiştir' : 'Yükle'"
                      icon="i-lucide-upload"
                      color="neutral"
                      variant="outline"
                      size="xs"
                      :loading="uploadingLogo"
                      as="span"
                    />
                    <input
                      type="file"
                      accept="image/*"
                      class="hidden"
                      :disabled="!isAdmin"
                      @change="handleLogoUpload"
                    >
                  </label>
                  <UButton
                    v-if="form.agency_logo"
                    label="Kaldir"
                    icon="i-lucide-trash-2"
                    color="error"
                    variant="ghost"
                    size="xs"
                    @click="removeLogo"
                  />
                </div>
              </div>
            </div>

            <!-- Acente Adi -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
              <div class="min-w-48">
                <p class="text-sm font-medium">Acente Adi</p>
                <p class="text-xs text-muted">Resmi acente unvani.</p>
              </div>
              <UFormField name="agency_name" class="w-full max-w-sm">
                <UInput v-model="form.agency_name" :disabled="!isAdmin" class="w-full" placeholder="Örnek Sigorta Acenteligi" />
              </UFormField>
            </div>

            <!-- Açıklama -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 last:pb-0">
              <div class="min-w-48">
                <p class="text-sm font-medium">Açıklama</p>
                <p class="text-xs text-muted">Giriş ekraninda görünecek kisa tanitim.</p>
              </div>
              <UFormField name="agency_description" class="w-full max-w-sm">
                <UInput v-model="form.agency_description" :disabled="!isAdmin" class="w-full" placeholder="Hesabınıza giriş yapın" />
              </UFormField>
            </div>
          </div>
        </UCard>

        <!-- İletişim Bilgileri -->
        <UCard class="mb-4">
          <template #header>
            <h3 class="font-semibold">İletişim Bilgileri</h3>
          </template>

          <div class="space-y-0 divide-y divide-default">
            <!-- Telefon -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 first:pt-0">
              <div class="min-w-48">
                <p class="text-sm font-medium">Telefon</p>
                <p class="text-xs text-muted">Ana iletişim hatti.</p>
              </div>
              <UFormField name="agency_phone" class="w-full max-w-sm">
                <PhoneInput v-model="form.agency_phone" :disabled="!isAdmin" />
              </UFormField>
            </div>

            <!-- E-posta -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
              <div class="min-w-48">
                <p class="text-sm font-medium">E-posta</p>
                <p class="text-xs text-muted">Genel iletişim adresi.</p>
              </div>
              <UFormField name="agency_email" class="w-full max-w-sm">
                <UInput v-model="form.agency_email" :disabled="!isAdmin" type="email" class="w-full" placeholder="info@acenteniz.com" />
              </UFormField>
            </div>

            <!-- Website -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
              <div class="min-w-48">
                <p class="text-sm font-medium">Web Sitesi</p>
              </div>
              <UFormField name="agency_website" class="w-full max-w-sm">
                <UInput v-model="form.agency_website" :disabled="!isAdmin" class="w-full" placeholder="www.acenteniz.com" />
              </UFormField>
            </div>

            <!-- Ulke -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
              <div class="min-w-48">
                <p class="text-sm font-medium">Ulke</p>
              </div>
              <UFormField name="agency_country_id" class="w-full max-w-sm">
                <USelectMenu v-model="locationCountryId" :items="countryOptions" value-key="value" label-key="label" placeholder="Ulke arayiniz..." searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" :disabled="!isAdmin" class="w-full" @update:search-term="(v: string) => countrySearch = v" />
              </UFormField>
            </div>

            <!-- Şehir -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
              <div class="min-w-48">
                <p class="text-sm font-medium">Şehir</p>
              </div>
              <UFormField name="agency_city_id" class="w-full max-w-sm">
                <USelectMenu v-model="locationCityId" :items="cityOptions" value-key="value" label-key="label" placeholder="Şehir arayiniz..." searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" :disabled="!isAdmin || !form.agency_country_id" class="w-full" @update:search-term="(v: string) => citySearch = v" />
              </UFormField>
            </div>

            <!-- Ilce -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
              <div class="min-w-48">
                <p class="text-sm font-medium">Ilce</p>
              </div>
              <UFormField name="agency_district_id" class="w-full max-w-sm">
                <USelectMenu v-model="locationDistrictId" :items="districtOptions" value-key="value" label-key="label" placeholder="Ilce arayiniz..." searchable :search-input="{ placeholder: 'Ara...' }" :search-attributes="['label']" :disabled="!isAdmin || !form.agency_city_id" class="w-full" @update:search-term="(v: string) => districtSearch = v" />
              </UFormField>
            </div>

            <!-- Adres -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 last:pb-0">
              <div class="min-w-48">
                <p class="text-sm font-medium">Adres</p>
                <p class="text-xs text-muted">Acente adresi.</p>
              </div>
              <UFormField name="agency_address" class="w-full max-w-sm">
                <UTextarea v-model="form.agency_address" :disabled="!isAdmin" :rows="2" class="w-full" placeholder="Açık adres" />
              </UFormField>
            </div>
          </div>
        </UCard>

        <!-- Resmi Bilgiler -->
        <UCard>
          <template #header>
            <h3 class="font-semibold">Resmi Bilgiler</h3>
          </template>

          <div class="space-y-0 divide-y divide-default">
            <!-- Vergi Dairesi -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 first:pt-0">
              <div class="min-w-48">
                <p class="text-sm font-medium">Vergi Dairesi</p>
              </div>
              <UFormField name="agency_tax_office" class="w-full max-w-sm">
                <UInput v-model="form.agency_tax_office" :disabled="!isAdmin" class="w-full" placeholder="Kadikoy V.D." />
              </UFormField>
            </div>

            <!-- Vergi No -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
              <div class="min-w-48">
                <p class="text-sm font-medium">Vergi Numarasi</p>
              </div>
              <UFormField name="agency_tax_number" class="w-full max-w-sm">
                <UInput v-model="form.agency_tax_number" :disabled="!isAdmin" class="w-full" placeholder="1234567890" />
              </UFormField>
            </div>

            <!-- MERSIS No -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
              <div class="min-w-48">
                <p class="text-sm font-medium">MERSIS No</p>
                <p class="text-xs text-muted">Merkezi Sicil Kayit Sistemi numarası.</p>
              </div>
              <UFormField name="agency_mersis_no" class="w-full max-w-sm">
                <UInput v-model="form.agency_mersis_no" :disabled="!isAdmin" class="w-full" />
              </UFormField>
            </div>

            <!-- TOBB No -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 last:pb-0">
              <div class="min-w-48">
                <p class="text-sm font-medium">TOBB Sicil No</p>
                <p class="text-xs text-muted">Levha sicil numarası.</p>
              </div>
              <UFormField name="agency_tobb_no" class="w-full max-w-sm">
                <UInput v-model="form.agency_tobb_no" :disabled="!isAdmin" class="w-full" />
              </UFormField>
            </div>
          </div>
        </UCard>

        <!-- AI Entegrasyonu -->
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-sparkles" class="size-4 text-amber-500" />
              <div>
                <h3 class="font-semibold">AI Entegrasyonu</h3>
                <p class="text-xs text-muted">PDF okuma ve otomatik form doldurma için Gemini API ayarları.</p>
              </div>
            </div>
          </template>
          <div class="divide-y divide-default">
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 first:pt-0 last:pb-0">
              <div class="min-w-48">
                <p class="text-sm font-medium">Gemini API Anahtarı</p>
                <p class="text-xs text-muted">Google AI Studio'dan alınan API anahtarı. Girilmezse PDF okuma özelliği devre dışı kalır.</p>
                <button type="button" class="text-xs text-primary hover:underline mt-1 cursor-pointer" @click="showApiKeyHelp = true">
                  <UIcon name="i-lucide-help-circle" class="size-3 inline" /> Nasıl alınır?
                </button>
              </div>
              <div class="w-full max-w-sm space-y-2">
                <UFormField name="gemini_api_key">
                  <UInput v-model="form.gemini_api_key" :disabled="!isAdmin" type="password" class="w-full font-mono" placeholder="AIza..." />
                </UFormField>
                <div class="flex items-center gap-2">
                  <UBadge v-if="form.gemini_api_key && !form.gemini_api_key.includes('***')" color="success" variant="subtle" size="xs">Yeni anahtar girildi</UBadge>
                  <UBadge v-else-if="form.gemini_api_key && form.gemini_api_key.includes('***')" color="success" variant="subtle" size="xs">PDF okuma aktif</UBadge>
                  <UBadge v-else color="neutral" variant="subtle" size="xs">PDF okuma kapalı</UBadge>
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
                <p class="text-xs text-muted">Temsilcilere akıllı satış önerileri sunar. Gemini API anahtarı gereklidir.</p>
              </div>
              <div class="w-full max-w-sm space-y-2">
                <div class="flex items-center gap-3">
                  <USwitch v-model="form.ai_coach_enabled" size="xs" :disabled="!isAdmin || !form.gemini_api_key" />
                  <span class="text-sm" :class="form.ai_coach_enabled ? 'text-green-600 font-medium' : 'text-muted'">{{ form.ai_coach_enabled ? 'Aktif' : 'Pasif' }}</span>
                </div>
                <p v-if="!form.gemini_api_key" class="text-xs text-amber-600">Önce Gemini API anahtarını giriniz</p>
              </div>
            </div>
          </div>

        </UCard>

        <!-- Müşteri Portalı -->
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-globe" class="size-4 text-blue-500" />
              <div>
                <h3 class="font-semibold">Müşteri Portalı</h3>
                <p class="text-xs text-muted">Müşterilerinizin poliçelerini görüntüleyebildiği dış erişim paneli.</p>
              </div>
            </div>
          </template>
          <div class="divide-y divide-default">
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 first:pt-0 last:pb-0">
              <div class="min-w-48">
                <p class="text-sm font-medium">Portal Durumu</p>
                <p class="text-xs text-muted">Aktif edildiğinde musteri.sigortax.net üzerinden müşteriler giriş yapabilir.</p>
              </div>
              <div class="w-full max-w-sm space-y-2">
                <div class="flex items-center gap-3">
                  <USwitch v-model="form.customer_portal_enabled" size="xs" :disabled="!isAdmin" />
                  <span class="text-sm" :class="form.customer_portal_enabled ? 'text-green-600 font-medium' : 'text-muted'">{{ form.customer_portal_enabled ? 'Aktif' : 'Pasif' }}</span>
                </div>
                <p class="text-xs text-muted">Müşteri kullanıcıları Takım Yönetimi sayfasından "Müşteri (Portal)" rolüyle oluşturulur.</p>
              </div>
            </div>
          </div>
        </UCard>

        <!-- Netgsm SMS Entegrasyonu -->
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-message-square" class="size-4 text-green-500" />
              <div>
                <h3 class="font-semibold">Netgsm SMS Entegrasyonu</h3>
                <p class="text-xs text-muted">SMS doğrulama ve bildirim göndermek için Netgsm API ayarları.</p>
              </div>
            </div>
          </template>
          <div class="divide-y divide-default">
            <!-- Durum -->
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
                <p v-if="!form.netgsm_usercode || !form.netgsm_password" class="text-xs text-amber-600">Önce API bilgilerini giriniz</p>
              </div>
            </div>
            <!-- Abone No -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
              <div class="min-w-48">
                <p class="text-sm font-medium">Abone No (Usercode)</p>
                <p class="text-xs text-muted">Netgsm panelindeki abone numaranız.</p>
              </div>
              <UFormField name="netgsm_usercode" class="w-full max-w-sm">
                <UInput v-model="form.netgsm_usercode" :disabled="!isAdmin" class="w-full" placeholder="85XXXXXXXX" />
              </UFormField>
            </div>
            <!-- Şifre -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
              <div class="min-w-48">
                <p class="text-sm font-medium">API Şifresi</p>
                <p class="text-xs text-muted">Netgsm hesap şifreniz.</p>
              </div>
              <UFormField name="netgsm_password" class="w-full max-w-sm">
                <UInput v-model="form.netgsm_password" :disabled="!isAdmin" type="password" class="w-full" placeholder="••••••••" />
              </UFormField>
            </div>
            <!-- Mesaj Başlığı -->
            <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 last:pb-0">
              <div class="min-w-48">
                <p class="text-sm font-medium">Mesaj Başlığı</p>
                <p class="text-xs text-muted">SMS gönderiminde görünecek başlık (Netgsm'den onaylı).</p>
              </div>
              <UFormField name="netgsm_msgheader" class="w-full max-w-sm">
                <UInput v-model="form.netgsm_msgheader" :disabled="!isAdmin" class="w-full" placeholder="ADKVIPSIGOR" />
              </UFormField>
            </div>
          </div>
        </UCard>

        <!-- API Key Yardım Modalı -->
        <UModal v-model:open="showApiKeyHelp" title="Gemini API Anahtarı Nasıl Alınır?" class="sm:max-w-lg">
            <template #body>
              <div class="space-y-4">
                <div class="flex items-start gap-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                  <UIcon name="i-lucide-info" class="size-4 text-amber-600 shrink-0 mt-0.5" />
                  <p class="text-sm text-amber-700 dark:text-amber-300">Gemini API ücretsiz kullanılabilir. Yüksek kullanım için ücretli plana geçiş gerekebilir.</p>
                </div>

                <ol class="space-y-3 text-sm">
                  <li class="flex gap-3">
                    <span class="size-6 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0 text-xs font-bold">1</span>
                    <div>
                      <p class="font-medium">Google AI Studio'ya gidin</p>
                      <a href="https://aistudio.google.com/apikey" target="_blank" class="text-primary hover:underline text-xs">aistudio.google.com/apikey</a>
                    </div>
                  </li>
                  <li class="flex gap-3">
                    <span class="size-6 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0 text-xs font-bold">2</span>
                    <div>
                      <p class="font-medium">Google hesabınızla giriş yapın</p>
                      <p class="text-xs text-muted">Gmail hesabınız yeterli</p>
                    </div>
                  </li>
                  <li class="flex gap-3">
                    <span class="size-6 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0 text-xs font-bold">3</span>
                    <div>
                      <p class="font-medium">"Create API Key" butonuna tıklayın</p>
                      <p class="text-xs text-muted">Yeni bir proje oluşturun veya mevcut projeyi seçin</p>
                    </div>
                  </li>
                  <li class="flex gap-3">
                    <span class="size-6 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0 text-xs font-bold">4</span>
                    <div>
                      <p class="font-medium">Oluşturulan anahtarı kopyalayın</p>
                      <p class="text-xs text-muted">Anahtar "AIza..." ile başlar</p>
                    </div>
                  </li>
                  <li class="flex gap-3">
                    <span class="size-6 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0 text-xs font-bold">5</span>
                    <div>
                      <p class="font-medium">Yukarıdaki alana yapıştırın ve kaydedin</p>
                      <p class="text-xs text-muted">PDF okuma özelliği otomatik aktif olur</p>
                    </div>
                  </li>
                </ol>
              </div>
            </template>
            <template #footer>
              <div class="flex justify-end">
                <UButton label="Tamam" @click="showApiKeyHelp = false" />
              </div>
            </template>
          </UModal>
      </template>
    </UForm>
  </div>
</template>
