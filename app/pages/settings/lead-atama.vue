<script setup lang="ts">
import { CalendarDate } from '@internationalized/date'

definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Lead Atama Ayarları' })

const toast = useToast()
const { get, post, del } = useApi()

const timeout = ref(30)
const savingTimeout = ref(false)

interface Holiday { id: number; name: string; date: string }
const holidays = ref<Holiday[]>([])
const loadingHolidays = ref(false)

const addHolidayOpen = ref(false)
const holidayForm = ref({ name: '', date: '' })
const savingHoliday = ref(false)
const holidayDateDisplay = ref('')
const holidayDateCalendar = ref<InstanceType<typeof CalendarDate> | undefined>()
const holidayDatePopoverOpen = ref(false)

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
  if (e.key.length === 1 && !/\d/.test(e.key) && !e.ctrlKey && !e.metaKey) e.preventDefault()
}
function onHolidayDateInput(val: string | number) {
  const formatted = autoFormatDateInput(String(val))
  holidayDateDisplay.value = formatted
  const iso = parseDisplayToIso(formatted)
  if (iso) {
    holidayForm.value.date = iso
    const [y, m, d] = iso.split('-')
    holidayDateCalendar.value = new CalendarDate(parseInt(y), parseInt(m), parseInt(d))
  }
}
function onHolidayDateCalendar(val: any) {
  if (!val) return
  holidayDateCalendar.value = val
  holidayForm.value.date = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  holidayDateDisplay.value = `${String(val.day).padStart(2, '0')}.${String(val.month).padStart(2, '0')}.${val.year}`
  holidayDatePopoverOpen.value = false
}

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
  } catch { toast.add({ title: 'Yenilenemedi', color: 'error' }) }
}

onMounted(async () => {
  try {
    const res = await get('settings')
    timeout.value = parseInt(res.data?.lead_reassign_timeout) || 30
    webhookApiKey.value = res.data?.webhook_api_key || ''
  } catch {}
  await fetchHolidays()
})

async function fetchHolidays() {
  loadingHolidays.value = true
  try { const res = await get('lead-holidays?all=1'); holidays.value = res.data || [] } catch {}
  loadingHolidays.value = false
}

async function saveTimeout() {
  if (savingTimeout.value) return
  savingTimeout.value = true
  try {
    await post('settings', { lead_reassign_timeout: String(timeout.value) })
    toast.add({ title: 'Süre güncellendi', color: 'success' })
  } catch (error: any) { toast.add({ title: error.message || 'İşlem başarısız', color: 'error' }) }
  savingTimeout.value = false
}

async function addHoliday() {
  if (savingHoliday.value) return
  if (!holidayForm.value.name.trim() || !holidayForm.value.date) { toast.add({ title: 'Tatil adı ve tarih zorunludur', color: 'error' }); return }
  savingHoliday.value = true
  try {
    await post('lead-holidays', holidayForm.value)
    toast.add({ title: 'Tatil eklendi', color: 'success' })
    addHolidayOpen.value = false
    holidayForm.value = { name: '', date: '' }
    holidayDateDisplay.value = ''
    holidayDateCalendar.value = undefined
    fetchHolidays()
  } catch (error: any) { toast.add({ title: error.message || 'İşlem başarısız', color: 'error' }) }
  savingHoliday.value = false
}

async function removeHoliday(id: number) {
  try { await del(`lead-holidays/${id}`); toast.add({ title: 'Tatil silindi', color: 'success' }); fetchHolidays() }
  catch { toast.add({ title: 'Silinemedi', color: 'error' }) }
}

function formatDate(date: string): string {
  const d = new Date(date + 'T00:00:00')
  return d.toLocaleDateString('tr-TR', { day: '2-digit', month: 'long', year: 'numeric', weekday: 'long' })
}
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <!-- Sayfa Başlığı -->
    <div class="mb-6 pb-4 border-b border-default">
      <h1 class="text-2xl font-semibold">Lead Atama Ayarları</h1>
      <p class="text-sm text-muted mt-1">Otomatik lead atama ve yeniden atama kurallarını yönetin.</p>
    </div>

    <div class="space-y-6">
      <!-- Yeniden Atama Süresi -->
      <UCard>
        <template #header>
          <h2 >Yeniden Atama Süresi</h2>
          <p class="text-xs text-muted mt-0.5">Lead atandıktan sonra sürece alınmazsa, belirtilen süre sonunda başka bir personele atanır.</p>
        </template>

        <div class="space-y-4">
          <div class="flex flex-col sm:flex-row sm:items-end gap-3">
            <div class="relative flex-1 sm:max-w-[200px] fl-form">
              <UInput v-model.number="timeout" type="number" :min="5" :max="480" placeholder=" " class="w-full peer/fl-ltimeout" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-ltimeout:top-0 peer-focus-within/fl-ltimeout:-translate-y-1/2 peer-focus-within/fl-ltimeout:text-xs peer-focus-within/fl-ltimeout:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-ltimeout:top-0 peer-has-[input:not(:placeholder-shown)]/fl-ltimeout:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-ltimeout:text-xs peer-has-[input:not(:placeholder-shown)]/fl-ltimeout:text-[var(--ui-text-highlighted)]">Süre (dakika)</label>
            </div>
            <UButton label="Kaydet" icon="i-lucide-check"  :loading="savingTimeout" @click="saveTimeout" />
          </div>

          <div class="bg-primary-50 rounded-lg p-3 text-xs space-y-1">
            <p class="text-primary-700">Çalışma Kuralları</p>
            <ul class="text-primary-600 space-y-0.5 ml-3 list-disc">
              <li>Mesai saatleri: <strong>09:00 - 12:30</strong> ve <strong>13:30 - 18:00</strong></li>
              <li>Cumartesi ve Pazar günleri süre işlemez</li>
              <li>Resmî tatillerde süre işlemez</li>
              <li>Tüm personeller denendiyse başa döner</li>
            </ul>
          </div>
        </div>
      </UCard>

      <!-- Resmi Tatiller -->
      <UCard>
        <template #header>
          <div class="flex items-center justify-between">
            <div>
              <h2 >Resmî Tatiller</h2>
              <p class="text-xs text-muted mt-0.5">Bu günlerde otomatik yeniden atama çalışmaz.</p>
            </div>
            <UButton label="Tatil Ekle" icon="i-lucide-plus"  @click="addHolidayOpen = true" />
          </div>
        </template>

        <div v-if="loadingHolidays" class="py-6 text-center text-sm text-muted">Yükleniyor...</div>
        <div v-else-if="!holidays.length" class="py-6 text-center text-sm text-muted">Henüz tatil tanımlanmamış.</div>
        <div v-else class="space-y-1">
          <div v-for="h in holidays" :key="h.id" class="flex items-center justify-between py-2 px-3 rounded-lg hover:bg-neutral-50 group">
            <div>
              <span class="text-sm font-medium">{{ h.name }}</span>
              <span class="text-xs text-muted ml-2">{{ formatDate(h.date) }}</span>
            </div>
            <UButton icon="i-lucide-trash-2" color="error" variant="ghost" size="xs" class="opacity-0 group-hover:opacity-100 transition-opacity" @click="removeHoliday(h.id)" />
          </div>
        </div>
      </UCard>

      <!-- Webhook Entegrasyonu -->
      <UCard>
        <template #header>
          <h2 >Webhook Entegrasyonu</h2>
          <p class="text-xs text-muted mt-0.5">Dış sitelerden otomatik lead alma ayarları.</p>
        </template>

        <div class="space-y-4">
          <div>
            <p class="text-sm font-medium mb-2">API Anahtarı</p>
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
              <div class="relative flex-1 fl-form">
                <UInput :model-value="webhookApiKey" readonly class="w-full font-mono peer/fl-wkey" placeholder=" " />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-wkey:top-0 peer-focus-within/fl-wkey:-translate-y-1/2 peer-focus-within/fl-wkey:text-xs peer-focus-within/fl-wkey:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-wkey:top-0 peer-has-[input:not(:placeholder-shown)]/fl-wkey:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-wkey:text-xs peer-has-[input:not(:placeholder-shown)]/fl-wkey:text-[var(--ui-text-highlighted)]">Webhook API Key</label>
              </div>
              <div class="flex gap-2">
                <UButton :icon="webhookKeyCopied ? 'i-lucide-check' : 'i-lucide-copy'" label="Kopyala"  color="neutral" variant="outline" @click="copyWebhookKey" />
                <UButton icon="i-lucide-refresh-cw" label="Yenile"  color="error" variant="outline" @click="regenerateWebhookKey" />
              </div>
            </div>
            <p class="text-xs text-muted mt-1">Bu anahtarı dış sitelerin form entegrasyonunda kullanın.</p>
          </div>

          <div class="bg-neutral-50 rounded-lg p-3 text-xs space-y-2">
            <p >Kullanım</p>
            <div class="bg-white rounded p-2 font-mono text-[11px] overflow-x-auto">
              <p class="text-muted">POST /api/leads/webhook</p>
              <p class="text-muted">Header: X-Webhook-Key: {{ webhookApiKey }}</p>
              <p class="text-muted mt-1">Body:</p>
              <pre class="text-muted">{
" eventId": "benzersiz-id",
" fullName": "Ad Soyad",
" tcNo": "11111111111",
" birthDate": "1990-01-15",
" phone": "05321234567",
" product": "Kasko",
" source": "adkvipsigorta.com"
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
    </div>

    <!-- Tatil Ekle Modal -->
    <UModal v-model:open="addHolidayOpen" title="Tatil Ekle" class="sm:max-w-sm">
      <template #body>
        <form @submit.prevent="addHoliday" class="space-y-5">
          <div class="relative fl-form">
            <UInput v-model="holidayForm.name" placeholder=" " class="w-full peer/fl-hname" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-hname:top-0 peer-focus-within/fl-hname:-translate-y-1/2 peer-focus-within/fl-hname:text-xs peer-focus-within/fl-hname:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-hname:top-0 peer-has-[input:not(:placeholder-shown)]/fl-hname:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-hname:text-xs peer-has-[input:not(:placeholder-shown)]/fl-hname:text-[var(--ui-text-highlighted)]">Tatil Adı <span class="text-red-500">*</span></label>
          </div>
          <div class="relative fl-form">
            <UInput :model-value="holidayDateDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-hdate" @keydown="preventNonDigitKey" @update:model-value="onHolidayDateInput">
              <template #trailing>
                <UPopover v-model:open="holidayDatePopoverOpen">
                  <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" />
                  <template #content>
                    <UCalendar locale="tr-TR" v-model="holidayDateCalendar" class="p-2" @update:model-value="onHolidayDateCalendar" />
                  </template>
                </UPopover>
              </template>
            </UInput>
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-hdate:top-0 peer-focus-within/fl-hdate:-translate-y-1/2 peer-focus-within/fl-hdate:text-xs peer-focus-within/fl-hdate:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-hdate:top-0 peer-has-[input:not(:placeholder-shown)]/fl-hdate:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-hdate:text-xs peer-has-[input:not(:placeholder-shown)]/fl-hdate:text-[var(--ui-text-highlighted)]">Tarih <span class="text-red-500">*</span></label>
          </div>
          <USeparator />
          <div class="flex justify-end gap-2">
            <UButton label="İptal" color="neutral" variant="outline"  :disabled="savingHoliday" @click="addHolidayOpen = false" />
            <UButton label="Ekle" icon="i-lucide-check"  type="submit" :loading="savingHoliday" :disabled="savingHoliday" />
          </div>
        </form>
      </template>
    </UModal>
  </div>
</template>
