<script setup lang="ts">
definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Lead Atama Ayarları' })

const toast = useToast()
const { get, post, del } = useApi()

// Yeniden atama süresi
const timeout = ref(30)
const savingTimeout = ref(false)

// Tatiller
interface Holiday {
  id: number
  name: string
  date: string
}
const holidays = ref<Holiday[]>([])
const loadingHolidays = ref(false)

// Yeni tatil formu
const addHolidayOpen = ref(false)
const holidayForm = ref({ name: '', date: '' })
const savingHoliday = ref(false)

// Webhook API Key
const webhookApiKey = ref('')
const webhookKeyCopied = ref(false)

function copyWebhookKey() {
  navigator.clipboard.writeText(webhookApiKey.value)
  webhookKeyCopied.value = true
  toast.add({ title: 'API anahtarı kopyalandı', color: 'success' })
  setTimeout(() => webhookKeyCopied.value = false, 2000)
}

async function regenerateWebhookKey() {
  try {
    const newKey = 'whk_' + Array.from(crypto.getRandomValues(new Uint8Array(16))).map(b => b.toString(16).padStart(2, '0')).join('')
    await post('settings', { webhook_api_key: newKey })
    webhookApiKey.value = newKey
    toast.add({ title: 'API anahtarı yenilendi', color: 'success' })
  } catch {
    toast.add({ title: 'Yenilenemedi', color: 'error' })
  }
}

onMounted(async () => {
  // Ayarları yükle
  try {
    const res = await get('settings')
    timeout.value = parseInt(res.data?.lead_reassign_timeout) || 30
    webhookApiKey.value = res.data?.webhook_api_key || ''
  } catch {}

  // Tatilleri yükle
  await fetchHolidays()
})

async function fetchHolidays() {
  loadingHolidays.value = true
  try {
    const res = await get('lead-holidays?all=1')
    holidays.value = res.data || []
  } catch {}
  loadingHolidays.value = false
}

async function saveTimeout() {
  if (savingTimeout.value) return
  savingTimeout.value = true
  try {
    await post('settings', { lead_reassign_timeout: String(timeout.value) })
    toast.add({ title: 'Süre güncellendi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  savingTimeout.value = false
}

async function addHoliday() {
  if (savingHoliday.value) return
  if (!holidayForm.value.name.trim() || !holidayForm.value.date) {
    toast.add({ title: 'Tatil adı ve tarih zorunludur', color: 'error' })
    return
  }
  savingHoliday.value = true
  try {
    await post('lead-holidays', holidayForm.value)
    toast.add({ title: 'Tatil eklendi', color: 'success' })
    addHolidayOpen.value = false
    holidayForm.value = { name: '', date: '' }
    fetchHolidays()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  savingHoliday.value = false
}

async function removeHoliday(id: number) {
  try {
    await del(`lead-holidays/${id}`)
    toast.add({ title: 'Tatil silindi', color: 'success' })
    fetchHolidays()
  } catch {
    toast.add({ title: 'Silinemedi', color: 'error' })
  }
}

function formatDate(date: string): string {
  const d = new Date(date + 'T00:00:00')
  return d.toLocaleDateString('tr-TR', { day: '2-digit', month: 'long', year: 'numeric', weekday: 'long' })
}
</script>

<template>
  <div class="space-y-4">
    <!-- Yeniden Atama Süresi -->
    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div>
          <h3 class="font-semibold">Yeniden Atama Süresi</h3>
          <p class="text-xs text-muted">Lead atandıktan sonra sürece alınmazsa, belirtilen süre sonunda başka bir personele atanır</p>
        </div>
      </template>

      <div class="space-y-4">
        <div class="flex items-end gap-3">
          <UFormField label="Süre (dakika)" class="w-[140px]">
            <UInput v-model.number="timeout" type="number" :min="5" :max="480" placeholder="30" class="w-full" />
          </UFormField>
          <UButton label="Kaydet" icon="i-lucide-check" size="sm" :loading="savingTimeout" @click="saveTimeout" />
        </div>

        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 text-xs space-y-1">
          <p class="font-semibold text-blue-700 dark:text-blue-300">Çalışma Kuralları</p>
          <ul class="text-blue-600 dark:text-blue-400 space-y-0.5 ml-3 list-disc">
            <li>Mesai saatleri: <strong>09:00 - 12:30</strong> ve <strong>13:30 - 18:00</strong></li>
            <li>Cumartesi ve Pazar günleri süre işlemez</li>
            <li>Resmi tatillerde süre işlemez</li>
            <li>Tüm personeller denendiyse başa döner</li>
          </ul>
        </div>
      </div>
    </UCard>

    <!-- Resmi Tatiller -->
    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex items-center justify-between">
          <div>
            <h3 class="font-semibold">Resmi Tatiller</h3>
            <p class="text-xs text-muted">Bu günlerde otomatik yeniden atama çalışmaz</p>
          </div>
          <UButton label="Tatil Ekle" icon="i-lucide-plus" size="xs" @click="addHolidayOpen = true" />
        </div>
      </template>

      <div v-if="loadingHolidays" class="py-6 text-center text-xs text-muted">Yükleniyor...</div>
      <div v-else-if="!holidays.length" class="py-6 text-center text-xs text-muted">Henüz tatil tanımlanmamış</div>
      <div v-else class="space-y-1">
        <div
          v-for="h in holidays"
          :key="h.id"
          class="flex items-center justify-between py-2 px-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 group"
        >
          <div>
            <span class="text-sm font-medium">{{ h.name }}</span>
            <span class="text-xs text-muted ml-2">{{ formatDate(h.date) }}</span>
          </div>
          <UButton
            icon="i-lucide-trash-2"
            color="error"
            variant="ghost"
            size="xs"
            class="opacity-0 group-hover:opacity-100 transition-opacity"
            @click="removeHoliday(h.id)"
          />
        </div>
      </div>
    </UCard>

    <!-- Webhook Entegrasyonu -->
    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div>
          <h3 class="font-semibold">Webhook Entegrasyonu</h3>
          <p class="text-xs text-muted">Dış sitelerden otomatik lead alma ayarları</p>
        </div>
      </template>

      <div class="space-y-4">
        <!-- API Key -->
        <div>
          <p class="text-sm font-medium mb-2">API Anahtarı</p>
          <div class="flex items-center gap-2">
            <UInput :model-value="webhookApiKey" readonly class="flex-1 font-mono text-xs" />
            <UButton :icon="webhookKeyCopied ? 'i-lucide-check' : 'i-lucide-copy'" size="sm" color="neutral" variant="outline" @click="copyWebhookKey" />
            <UButton icon="i-lucide-refresh-cw" size="sm" color="error" variant="outline" title="Yeni anahtar oluştur" @click="regenerateWebhookKey" />
          </div>
          <p class="text-xs text-muted mt-1">Bu anahtarı dış sitelerin form entegrasyonunda kullanın</p>
        </div>

        <!-- Kullanım Bilgisi -->
        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3 text-xs space-y-2">
          <p class="font-semibold">Kullanım</p>
          <div class="bg-white dark:bg-gray-900 rounded p-2 font-mono text-[11px] overflow-x-auto">
            <p class="text-muted">POST /api/leads/webhook</p>
            <p class="text-muted">Header: X-Webhook-Key: {{ webhookApiKey }}</p>
            <p class="text-muted mt-1">Body:</p>
            <pre class="text-muted">{
  "eventId": "benzersiz-id",
  "fullName": "Ad Soyad",
  "tcNo": "11111111111",
  "birthDate": "1990-01-15",
  "phone": "05321234567",
  "product": "Kasko",
  "source": "adkvipsigorta.com"
}</pre>
          </div>
          <ul class="text-muted space-y-0.5 ml-3 list-disc">
            <li><strong>eventId</strong>: Aynı event'in tekrar işlenmesini engeller (opsiyonel)</li>
            <li><strong>phone</strong>: Zorunlu alan</li>
            <li><strong>product/source</strong>: İsim veya ID ile gönderilebilir</li>
            <li>Aynı kişi farklı event ile birden fazla lead oluşturabilir</li>
          </ul>
        </div>
      </div>
    </UCard>

    <!-- Tatil Ekle Modal -->
    <UModal v-model:open="addHolidayOpen" title="Tatil Ekle" class="sm:max-w-sm">
      <template #body>
        <form @submit.prevent="addHoliday" class="space-y-4">
          <UFormField label="Tatil Adı" required>
            <UInput v-model="holidayForm.name" placeholder="Örneğin: 29 Ekim Cumhuriyet Bayramı" icon="i-lucide-calendar" class="w-full" />
          </UFormField>
          <UFormField label="Tarih" required>
            <UInput v-model="holidayForm.date" type="date" class="w-full" />
          </UFormField>
          <USeparator />
          <div class="flex justify-end gap-2">
            <UButton label="İptal" color="neutral" variant="outline" :disabled="savingHoliday" @click="addHolidayOpen = false" />
            <UButton label="Ekle" icon="i-lucide-check" type="submit" :loading="savingHoliday" :disabled="savingHoliday" />
          </div>
        </form>
      </template>
    </UModal>
  </div>
</template>
