<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Alan Ayarları' })

const toast = useToast()
const { user } = useAuth()
const { settings, fetchFieldSettings, saveFieldSettings } = useFieldSettings()

const isAdmin = computed(() => user.value?.role === 'admin')
const loading = ref(true)
const saving = ref(false)

const individualFields = [
  { key: 'individual_phone_2', label: 'İkinci Telefon', description: 'Bireysel müşteriler için ikinci telefon alanı' },
  { key: 'individual_marital_status', label: 'Medeni Durum', description: 'Medeni durum seçimi (Bekar/Evli/Bosanmis)' },
  { key: 'individual_job', label: 'Meslek', description: 'Müşteri meslek bilgisi' },
  { key: 'individual_number_of_children', label: 'Çocuk Sayısı', description: 'Çocuk sayısı alanı' },
  { key: 'individual_address', label: 'Adres', description: 'Açık adres alanı' },
  { key: 'individual_city', label: 'Şehir / Ilce', description: 'Şehir ve ilce seçimi' },
  { key: 'individual_note', label: 'Not', description: 'Ek notlar alanı' },
  { key: 'allow_passport_id', label: 'Pasaport / Özel Kimlik Kabul Et', description: 'Açıksa TC/VKN format kontrolü gevşer, pasaport vb. kabul edilir' }
]

const companyFields = [
  { key: 'company_phone_2', label: 'İkinci Telefon', description: 'Kurumsal müşteriler için ikinci telefon' },
  { key: 'company_sector', label: 'Sektor', description: 'Firma sektor bilgisi' },
  { key: 'company_number_of_employees', label: 'Calisan Sayısı', description: 'Firmadaki calisan sayısı' },
  { key: 'company_address', label: 'Adres', description: 'Firma açık adresi' },
  { key: 'company_city', label: 'Şehir / Ilce', description: 'Şehir ve ilce seçimi' },
  { key: 'company_note', label: 'Not', description: 'Ek notlar alanı' }
]

const importFields = [
  { key: 'import_production_type', label: 'Üretim Tipi', description: 'Import tablosunda Acentem / Tali Gelen / Tali Giden seçimi kolonu' },
  { key: 'import_branch', label: 'Acente Seçimi', description: 'Import tablosunda tali gelen/giden için acente seçim kolonu' }
]

const policyFields = [
  { key: 'policy_insured_name', label: 'Sigorta Ettiren', description: 'Sigorta ettirenin ad/soyad ya da unvan bilgisi' },
  { key: 'policy_insured_no', label: 'Sigorta Ettiren Telefonu', description: 'Sigorta ettirenin telefon numarası' },
  { key: 'chassis_no', label: 'Sasi Numarasi', description: 'Araç sasi numarası (Trafik/Kasko)' },
  { key: 'engine_no', label: 'Motor Numarasi', description: 'Araç motor numarası (Trafik/Kasko)' },
  { key: 'policy_brand', label: 'Marka', description: 'Araç markasi (Trafik/Kasko)' },
  { key: 'policy_model', label: 'Model', description: 'Araç modeli (Trafik/Kasko)' },
  { key: 'vehicle_year', label: 'Model Yili', description: 'Araç model yılı (Trafik/Kasko)' },
  { key: 'policy_uavt', label: 'UAVT Kodu', description: 'Konut adres kodu (Konut/DASK)' },
  { key: 'dask_no', label: 'DASK Poliçe No', description: 'DASK poliçe numarası (DASK)' },
  { key: 'policy_network', label: 'Network', description: 'Sağlık sigortasi network bilgisi (Sağlık)' },
  { key: 'policy_no_renewal_reminder', label: 'Yenileme Hatırlatma Kapat', description: 'Police formunda "bu poliçeyi sonraki yenileme görevlerine ekleme" checkbox\'ını göster' },
  { key: 'commission_as_amount', label: 'Komisyonu Tutar Olarak Gir', description: 'Açıksa poliçe formunda komisyon oranı yerine tutar (₺) girilir, oran otomatik hesaplanır. Kapalıysa oran (%) girilir.' },
  { key: 'branch_commission_input', label: 'Tali Acente Komisyonu Elle Girilsin', description: 'Açıksa poliçe formunda ve Allianz import\'ta tali acente komisyonu poliçe bazında değiştirilebilir. Kapalıysa alan gizlenir ve acentenin sistemde tanımlı oranı kullanılır.' },
  { key: 'policy_zeyil_checkbox', label: 'Zeyil Olarak Kaydet', description: 'Açıksa poliçe formunda "Zeyil olarak kaydet" checkbox\'ı gösterilir. Kapalıysa gizlenir (zeyil otomatik tespit edilir).' }
]

async function load() {
  loading.value = true
  await fetchFieldSettings()
  loading.value = false
}

async function save() {
  saving.value = true
  try {
    await saveFieldSettings()
    toast.add({ title: 'Alan ayarları kaydedildi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Kaydedilemedi', color: 'error' })
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="flex items-center justify-between pb-4 border-b border-default">
      <div>
        <h1 class="text-xl">Alan Ayarları</h1>
        <p class="text-sm text-muted mt-1">Müşteri ve poliçe formlarında hangi alanların görüneceğini ayarlayın.</p>
      </div>
      <UButton v-if="isAdmin" label="Kaydet" icon="i-lucide-check" size="xl"  :loading="saving" @click="save" />
    </div>

    <!-- Loading -->
    <div v-if="loading" class="space-y-4">
      <SkeletonCard v-for="i in 3" :key="i">
        <div class="space-y-3">
          <div class="h-4 bg-neutral-200 rounded w-36" />
          <div class="h-9 bg-neutral-200 rounded w-full" />
          <div class="h-9 bg-neutral-200 rounded w-full" />
        </div>
      </SkeletonCard>
    </div>

    <template v-else>
      <!-- Bireysel Müşteri Alanları -->
      <UCard>
        <template #header>
          <div>
            <h3 >Bireysel Müşteri Alanları</h3>
            <p class="text-xs text-muted">Bireysel müşteri formunda görünecek opsiyonel alanları belirleyin.</p>
          </div>
        </template>

        <div class="space-y-0 divide-y divide-default">
          <div
            v-for="field in individualFields"
            :key="field.key"
            class="flex items-center justify-between py-3 first:pt-0 last:pb-0"
          >
            <div>
              <p class="text-sm font-medium">{{ field.label }}</p>
              <p class="text-xs text-muted">{{ field.description }}</p>
            </div>
            <USwitch v-model="settings[field.key]" :disabled="!isAdmin" />
          </div>
        </div>
      </UCard>

      <!-- Kurumsal Müşteri Alanları -->
      <UCard>
        <template #header>
          <div>
            <h3 >Kurumsal Müşteri Alanları</h3>
            <p class="text-xs text-muted">Kurumsal müşteri formunda görünecek opsiyonel alanları belirleyin.</p>
          </div>
        </template>

        <div class="space-y-0 divide-y divide-default">
          <div
            v-for="field in companyFields"
            :key="field.key"
            class="flex items-center justify-between py-3 first:pt-0 last:pb-0"
          >
            <div>
              <p class="text-sm font-medium">{{ field.label }}</p>
              <p class="text-xs text-muted">{{ field.description }}</p>
            </div>
            <USwitch v-model="settings[field.key]" :disabled="!isAdmin" />
          </div>
        </div>
      </UCard>

      <!-- Poliçe Form Alanları -->
      <UCard>
        <template #header>
          <div>
            <h3 >Poliçe Form Alanları</h3>
            <p class="text-xs text-muted">Poliçe formunda görünecek opsiyonel alanları belirleyin. Bu alanlar ilgili sigorta türüne göre gösterilir.</p>
          </div>
        </template>

        <div class="space-y-0 divide-y divide-default">
          <div
            v-for="field in policyFields"
            :key="field.key"
            class="flex items-center justify-between py-3 first:pt-0 last:pb-0"
          >
            <div>
              <p class="text-sm font-medium">{{ field.label }}</p>
              <p class="text-xs text-muted">{{ field.description }}</p>
            </div>
            <USwitch v-model="settings[field.key]" :disabled="!isAdmin" />
          </div>
        </div>
      </UCard>

      <!-- Import Ayarları -->
      <UCard>
        <template #header>
          <div>
            <h3 >Import Ayarları</h3>
            <p class="text-xs text-muted">Allianz / Excel import sayfalarında görünecek opsiyonel kolonları belirleyin.</p>
          </div>
        </template>

        <div class="space-y-0 divide-y divide-default">
          <div
            v-for="field in importFields"
            :key="field.key"
            class="flex items-center justify-between py-3 first:pt-0 last:pb-0"
          >
            <div>
              <p class="text-sm font-medium">{{ field.label }}</p>
              <p class="text-xs text-muted">{{ field.description }}</p>
            </div>
            <USwitch v-model="settings[field.key]" :disabled="!isAdmin" />
          </div>
        </div>
      </UCard>

      <!-- Görev Ayarları -->
      <UCard>
        <template #header>
          <div>
            <h3 >Görev Ayarları</h3>
            <p class="text-xs text-muted">Görev tamamlama kurallarını belirleyin.</p>
          </div>
        </template>

        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium">Görüşme Notu Zorunlu</p>
            <p class="text-xs text-muted">Açıksa görev tamamlarken en az 10 karakterlik görüşme notu girilmesi zorunlu olur. Kapalıysa not opsiyoneldir.</p>
          </div>
          <USwitch v-model="settings['require_task_note']" :disabled="!isAdmin" />
        </div>
      </UCard>
    </template>
  </div>
</template>
