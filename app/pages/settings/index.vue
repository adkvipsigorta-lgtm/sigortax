<script setup lang="ts">
import { z } from 'zod'
import { CalendarDate } from '@internationalized/date'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Profil Ayarlari' })

const toast = useToast()
const { user, updateProfile } = useAuth()
const { put } = useApi()

const roleLabels: Record<string, string> = {
  admin: 'Yönetici',
  acente: 'Acente',
  kullanıcı: 'Kullanıcı'
}

const profileSchema = z.object({
  name: z.string().min(2, 'Ad Soyad en az 2 karakter olmalıdir'),
  email: z.string().email('Geçerli e-posta giriniz'),
  phone: z.string().min(10, 'Geçerli telefon giriniz').optional().or(z.literal('')),
  birthDate: z.string().optional().or(z.literal('')),
  address: z.string().optional().or(z.literal('')),
})

const saving = ref(false)

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
    profile.birthDate = iso
    birthDateCalendar.value = isoToCalendar(iso)
    nextTick(() => { birthDateSyncing = false })
  } else if (formatted.replace(/\D/g, '').length === 8) {
    profile.birthDate = ''
  }
}
function onBirthDateCalendar(val: any) {
  if (!val) return
  birthDateSyncing = true
  birthDateCalendar.value = val
  profile.birthDate = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  birthDateDisplay.value = `${String(val.day).padStart(2, '0')}.${String(val.month).padStart(2, '0')}.${val.year}`
  birthDatePopoverOpen.value = false
  nextTick(() => { birthDateSyncing = false })
}

const profile = reactive({
  name: '',
  email: '',
  phone: '',
  birthDate: '',
  address: '',
})

watch(() => user.value, (u) => {
  if (u) {
    profile.name = u.name || ''
    profile.email = u.email || ''
    profile.phone = u.phone || ''
    profile.birthDate = u.birthDate || ''
    birthDateDisplay.value = isoToDisplay(profile.birthDate)
    birthDateCalendar.value = isoToCalendar(profile.birthDate)
    profile.address = u.address || ''
  }
}, { immediate: true })

async function saveProfile() {
  saving.value = true
  try {
    await put('auth/profile', profile)
    updateProfile(profile)
    toast.add({ title: 'Profil güncellendi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Profil güncellenemedi', color: 'error' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <UForm :schema="profileSchema" :state="profile" @submit="saveProfile">
      <!-- Başlık -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h2 class="text-lg font-semibold">Profil</h2>
          <p class="text-sm text-muted">Kişisel bilgilerinizi güncelleyin.</p>
        </div>
        <UButton label="Kaydet" :loading="saving" type="submit" />
      </div>

      <UCard>
        <div class="space-y-0 divide-y divide-default">
          <!-- Ad Soyad -->
          <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4 first:pt-0">
            <div class="min-w-48">
              <p class="text-sm font-medium">Ad Soyad</p>
              <p class="text-xs text-muted">Sistemde görünecek isminiz.</p>
            </div>
            <UFormField name="name" class="w-full max-w-sm">
              <UInput v-model="profile.name" class="w-full" />
            </UFormField>
          </div>

          <!-- E-posta -->
          <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
            <div class="min-w-48">
              <p class="text-sm font-medium">E-posta</p>
              <p class="text-xs text-muted">Giriş ve bildirimler için.</p>
            </div>
            <UFormField name="email" class="w-full max-w-sm">
              <UInput v-model="profile.email" type="email" class="w-full" />
            </UFormField>
          </div>

          <!-- Telefon -->
          <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
            <div class="min-w-48">
              <p class="text-sm font-medium">Telefon</p>
              <p class="text-xs text-muted">İletişim için.</p>
            </div>
            <UFormField name="phone" class="w-full max-w-sm">
              <PhoneInput v-model="profile.phone" />
            </UFormField>
          </div>

          <!-- Doğum Tarihi -->
          <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
            <div class="min-w-48">
              <p class="text-sm font-medium">Doğum Tarihi</p>
              <p class="text-xs text-muted">Kimlik doğrulamasi için.</p>
            </div>
            <UFormField name="birthDate" class="w-full max-w-sm">
              <div class="flex gap-2 items-center w-full">
                <UInput
                  :model-value="birthDateDisplay"
                  placeholder="GG.AA.YYYY"
                  maxlength="10"
                  class="flex-1"
                  @update:model-value="onBirthDateInput($event)"
                />
                <UPopover v-model:open="birthDatePopoverOpen" :ui="{ content: 'p-0' }">
                  <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="outline" />
                  <template #content>
                    <UCalendar locale="tr-TR" v-model="birthDateCalendar" @update:model-value="onBirthDateCalendar" />
                  </template>
                </UPopover>
              </div>
            </UFormField>
          </div>

          <!-- Adres -->
          <div class="flex max-sm:flex-col justify-between items-start gap-4 py-4">
            <div class="min-w-48">
              <p class="text-sm font-medium">Adres</p>
              <p class="text-xs text-muted">Posta ve fatura adresi.</p>
            </div>
            <UFormField name="address" class="w-full max-w-sm">
              <UTextarea v-model="profile.address" :rows="2" placeholder="Açık adres" class="w-full" />
            </UFormField>
          </div>

          <!-- Rol -->
          <div class="flex max-sm:flex-col justify-between sm:items-center gap-4 py-4 last:pb-0">
            <div class="min-w-48">
              <p class="text-sm font-medium">Rol</p>
              <p class="text-xs text-muted">Sadece yöneticiler değiştirebilir.</p>
            </div>
            <UBadge
              :color="user?.role === 'admin' ? 'error' : user?.role === 'acente' ? 'warning' : 'info'"
              variant="solid"
              size="lg"
            >
              {{ roleLabels[user?.role || ''] }}
            </UBadge>
          </div>
        </div>
      </UCard>
    </UForm>
  </div>
</template>
