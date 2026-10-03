<script setup lang="ts">
import { CalendarDate } from '@internationalized/date'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Profil ve Güvenlik' })

const toast = useToast()
const { user, updateProfile, fetchMe } = useAuth()
const { put, post } = useApi()

const roleLabels: Record<string, string> = {
  admin: 'Yönetici',
  acente: 'Acente',
  kullanici: 'Kullanıcı'
}
const roleColors: Record<string, 'error' | 'warning' | 'info'> = {
  admin: 'error',
  acente: 'warning',
  kullanici: 'info'
}

// ════════════════════════════════════════
// PROFİL
// ════════════════════════════════════════

const saving = ref(false)
const nameTouched = ref(false)
const emailTouched = ref(false)
const nameError = ref('')
const emailError = ref('')
const birthDateError = ref('')

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
  if (date > new Date()) return null
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}
function preventNonDigitKey(e: KeyboardEvent) {
  if (e.key.length === 1 && !/\d/.test(e.key) && !e.ctrlKey && !e.metaKey) e.preventDefault()
}
function onBirthDateInput(val: string | number) {
  const formatted = autoFormatDateInput(String(val))
  birthDateDisplay.value = formatted
  birthDateError.value = ''
  const iso = parseDisplayToIso(formatted)
  if (iso) {
    birthDateSyncing = true
    profile.birthDate = iso
    birthDateCalendar.value = isoToCalendar(iso)
    nextTick(() => { birthDateSyncing = false })
  } else if (formatted.replace(/\D/g, '').length === 8) {
    profile.birthDate = ''
    birthDateError.value = 'Geçersiz tarih.'
  }
}
function onBirthDateCalendar(val: any) {
  if (!val) return
  birthDateSyncing = true
  birthDateCalendar.value = val
  profile.birthDate = `${val.year}-${String(val.month).padStart(2, '0')}-${String(val.day).padStart(2, '0')}`
  birthDateDisplay.value = `${String(val.day).padStart(2, '0')}.${String(val.month).padStart(2, '0')}.${val.year}`
  birthDatePopoverOpen.value = false
  birthDateError.value = ''
  nextTick(() => { birthDateSyncing = false })
}

const profile = reactive({ name: '', email: '', phone: '', birthDate: '' })
const originalProfile = reactive({ name: '', email: '', phone: '', birthDate: '' })

watch(() => user.value, (u) => {
  if (u) {
    profile.name = u.name || ''
    profile.email = (u.email || '').toLowerCase()
    profile.phone = u.phone || ''
    profile.birthDate = u.birthDate || ''
    birthDateDisplay.value = isoToDisplay(profile.birthDate)
    birthDateCalendar.value = isoToCalendar(profile.birthDate)
    Object.assign(originalProfile, { ...profile })
  }
}, { immediate: true })

const isProfileDirty = computed(() =>
  profile.name !== originalProfile.name
  || profile.email !== originalProfile.email
  || profile.phone !== originalProfile.phone
  || profile.birthDate !== originalProfile.birthDate
)

function onEmailInput(val: string | number) {
  profile.email = String(val).trim().toLowerCase()
  emailTouched.value = true
  emailError.value = ''
}
function onNameInput(val: string | number) {
  profile.name = String(val)
  nameTouched.value = true
  nameError.value = ''
}

function validateName() {
  nameTouched.value = true
  if (!profile.name) { nameError.value = 'Ad Soyad giriniz.'; return }
  if (profile.name.length < 2) { nameError.value = 'En az 2 karakter olmalıdır.'; return }
  nameError.value = ''
}
function validateEmail() {
  emailTouched.value = true
  if (!profile.email) { emailError.value = 'E-posta adresinizi giriniz.'; return }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(profile.email)) { emailError.value = 'Geçerli bir e-posta giriniz.'; return }
  emailError.value = ''
}
function validateBirthDate() {
  if (!birthDateDisplay.value) { birthDateError.value = ''; return }
  const digits = birthDateDisplay.value.replace(/\D/g, '')
  if (digits.length > 0 && digits.length < 8) { birthDateError.value = 'Tarihi tamamlayınız.'; return }
  if (digits.length === 8 && !parseDisplayToIso(birthDateDisplay.value)) { birthDateError.value = 'Geçersiz tarih.'; return }
  birthDateError.value = ''
}

async function saveProfile() {
  validateName()
  validateEmail()
  validateBirthDate()
  if (nameError.value || emailError.value || birthDateError.value) return
  saving.value = true
  try {
    await put('auth/profile', profile)
    updateProfile(profile)
    Object.assign(originalProfile, { ...profile })
    toast.add({ title: 'Profil başarıyla güncellendi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Profil güncellenemedi', color: 'error' })
  } finally {
    saving.value = false
  }
}

// Unsaved changes guard
onBeforeRouteLeave((_to, _from, next) => {
  if (isProfileDirty.value) {
    next(window.confirm('Kaydedilmemiş değişiklikleriniz var. Sayfadan ayrılmak istediğinize emin misiniz?'))
  } else {
    next()
  }
})
if (import.meta.client) {
  window.addEventListener('beforeunload', (e) => {
    if (isProfileDirty.value) e.preventDefault()
  })
}

// ════════════════════════════════════════
// ŞİFRE DEĞİŞTİRME
// ════════════════════════════════════════

const passwordForm = reactive({ currentPassword: '', newPassword: '', confirmPassword: '' })
const changingPassword = ref(false)
const currentPwTouched = ref(false)
const newPwTouched = ref(false)
const confirmPwTouched = ref(false)
const currentPwError = ref('')
const newPwError = ref('')
const confirmPwError = ref('')
const showCurrentPw = ref(false)
const showNewPw = ref(false)
const showConfirmPw = ref(false)

function validateCurrentPw() { currentPwTouched.value = true; currentPwError.value = !passwordForm.currentPassword ? 'Mevcut şifrenizi giriniz.' : '' }
function validateNewPw() {
  newPwTouched.value = true
  if (!passwordForm.newPassword) { newPwError.value = 'Yeni şifrenizi giriniz.'; return }
  if (passwordForm.newPassword.length < 6) { newPwError.value = 'En az 6 karakter olmalıdır.'; return }
  newPwError.value = ''
  if (confirmPwTouched.value) validateConfirmPw()
}
function validateConfirmPw() {
  confirmPwTouched.value = true
  if (!passwordForm.confirmPassword) { confirmPwError.value = 'Şifre tekrarını giriniz.'; return }
  if (passwordForm.confirmPassword !== passwordForm.newPassword) { confirmPwError.value = 'Şifreler eşleşmiyor.'; return }
  confirmPwError.value = ''
}

async function changePassword() {
  validateCurrentPw(); validateNewPw(); validateConfirmPw()
  if (currentPwError.value || newPwError.value || confirmPwError.value) return
  changingPassword.value = true
  try {
    await post('auth/change-password', { currentPassword: passwordForm.currentPassword, newPassword: passwordForm.newPassword })
    passwordForm.currentPassword = ''; passwordForm.newPassword = ''; passwordForm.confirmPassword = ''
    currentPwTouched.value = false; newPwTouched.value = false; confirmPwTouched.value = false
    toast.add({ title: 'Şifreniz başarıyla değiştirildi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Şifre değiştirilemedi', color: 'error' })
  } finally {
    changingPassword.value = false
  }
}

// ════════════════════════════════════════
// 2FA
// ════════════════════════════════════════

const twoFactorEnabled = ref(false)
const setupStep = ref<'idle' | 'qr' | 'recovery'>('idle')
const setupData = reactive({ secret: '', otpauthUrl: '' })
const verificationCode = ref('')
const recoveryCodes = ref<string[]>([])
const settingUp2FA = ref(false)
const enabling2FA = ref(false)
const qrCodeDataUrl = ref('')

watch(() => user.value, (u) => { if (u) twoFactorEnabled.value = !!u.twoFactorEnabled }, { immediate: true })
watch(() => setupData.otpauthUrl, async (url) => {
  if (!url) { qrCodeDataUrl.value = ''; return }
  const QRCode = await import('qrcode')
  qrCodeDataUrl.value = await QRCode.toDataURL(url, { width: 200, margin: 2 })
})

async function startSetup() {
  settingUp2FA.value = true
  try {
    const response = await post('auth/2fa/setup')
    setupData.secret = response.data.secret
    setupData.otpauthUrl = response.data.otpauthUrl
    setupStep.value = 'qr'
  } catch (error: any) {
    toast.add({ title: error.message || '2FA kurulumu başlatılamadı', color: 'error' })
  } finally { settingUp2FA.value = false }
}

async function verifyAndEnable() {
  if (verificationCode.value.length !== 6) { toast.add({ title: 'Lütfen 6 haneli doğrulama kodunu girin', color: 'error' }); return }
  enabling2FA.value = true
  try {
    const response = await post('auth/2fa/enable', { code: verificationCode.value })
    recoveryCodes.value = response.data.recoveryCodes || []
    twoFactorEnabled.value = true
    setupStep.value = 'recovery'
    verificationCode.value = ''
    await fetchMe()
    toast.add({ title: '2FA başarıyla etkinleştirildi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Doğrulama başarısız', color: 'error' })
  } finally { enabling2FA.value = false }
}

function copyRecoveryCodes() {
  navigator.clipboard.writeText(recoveryCodes.value.join('\n'))
  toast.add({ title: 'Kurtarma kodları panoya kopyalandı', color: 'success' })
}
function closeRecovery() { setupStep.value = 'idle'; recoveryCodes.value = [] }
function cancelSetup() { setupStep.value = 'idle'; setupData.secret = ''; setupData.otpauthUrl = ''; verificationCode.value = '' }

// Güvenlik bölümüne scroll
const route = useRoute()
const securitySection = ref<HTMLElement | null>(null)
onMounted(() => {
  if (route.hash === '#guvenlik' && securitySection.value) {
    nextTick(() => securitySection.value?.scrollIntoView({ behavior: 'smooth' }))
  }
})
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <!-- Sayfa Başlığı -->
    <div class="mb-6 pb-4 border-b border-default">
      <h1 class="text-xl">Profil ve Güvenlik</h1>
      <p class="text-sm text-muted mt-1">Kişisel bilgilerinizi ve hesap güvenliği ayarlarınızı yönetin.</p>
    </div>

    <!-- ═══ PROFİL ═══ -->
    <UCard>
      <template #header>
        <div class="flex items-center justify-between">
          <h2 >Profil Bilgileri</h2>
          <UButton
            label="Kaydet"
            icon="i-lucide-check"
            size="xl"
            :loading="saving"
            :disabled="!isProfileDirty"
            
            @click="saveProfile"
          />
        </div>
      </template>

      <!-- Avatar + Rol -->
      <div class="flex items-center gap-3 sm:gap-4 pb-5 mb-5 border-b border-default">
        <div class="size-12 sm:size-14 rounded-full bg-primary-100 flex items-center justify-center text-primary-700 text-base sm:text-lg font-bold shrink-0">
          {{ user?.name?.charAt(0)?.toUpperCase() || '?' }}
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold truncate">{{ user?.name }}</p>
          <p class="text-xs text-muted truncate">{{ (user?.email || '').toLowerCase() }}</p>
        </div>
        <UBadge :color="roleColors[user?.role || ''] || 'info'" variant="subtle" size="sm" class="shrink-0">
          {{ roleLabels[user?.role || ''] || user?.role }}
        </UBadge>
      </div>

      <!-- Profil Alanları -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-5">
        <!-- Ad Soyad -->
        <div class="relative fl-form">
          <UInput :model-value="profile.name" placeholder=" " class="w-full peer/fl-name" :class="nameTouched && nameError ? 'fl-error' : ''" @update:model-value="onNameInput" @blur="validateName">
            <template v-if="nameTouched && nameError" #trailing>
              <UTooltip :text="nameError"><UIcon name="i-lucide-circle-alert" class="size-4 text-red-500 cursor-help shrink-0" /></UTooltip>
            </template>
          </UInput>
          <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm top-1/2 -translate-y-1/2', 'peer-focus-within/fl-name:top-0 peer-focus-within/fl-name:-translate-y-1/2 peer-focus-within/fl-name:text-xs', 'peer-has-[input:not(:placeholder-shown)]/fl-name:top-0 peer-has-[input:not(:placeholder-shown)]/fl-name:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-name:text-xs', nameTouched && nameError ? 'text-red-500 peer-focus-within/fl-name:text-red-500 peer-has-[input:not(:placeholder-shown)]/fl-name:text-red-500' : 'text-[var(--ui-text-muted)] peer-focus-within/fl-name:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-name:text-[var(--ui-text-highlighted)]']">Ad Soyad</label>
        </div>

        <!-- E-posta -->
        <div class="relative fl-form">
          <UInput :model-value="profile.email" type="email" placeholder=" " class="w-full peer/fl-pemail" :class="emailTouched && emailError ? 'fl-error' : ''" data-no-uppercase @update:model-value="onEmailInput" @blur="validateEmail">
            <template v-if="emailTouched && emailError" #trailing>
              <UTooltip :text="emailError"><UIcon name="i-lucide-circle-alert" class="size-4 text-red-500 cursor-help shrink-0" /></UTooltip>
            </template>
          </UInput>
          <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm top-1/2 -translate-y-1/2', 'peer-focus-within/fl-pemail:top-0 peer-focus-within/fl-pemail:-translate-y-1/2 peer-focus-within/fl-pemail:text-xs', 'peer-has-[input:not(:placeholder-shown)]/fl-pemail:top-0 peer-has-[input:not(:placeholder-shown)]/fl-pemail:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-pemail:text-xs', emailTouched && emailError ? 'text-red-500 peer-focus-within/fl-pemail:text-red-500 peer-has-[input:not(:placeholder-shown)]/fl-pemail:text-red-500' : 'text-[var(--ui-text-muted)] peer-focus-within/fl-pemail:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-pemail:text-[var(--ui-text-highlighted)]']">E-posta</label>
        </div>

        <!-- Telefon -->
        <PhoneInput v-model="profile.phone" />

        <!-- Doğum Tarihi -->
        <div class="relative fl-form">
          <UInput :model-value="birthDateDisplay" placeholder=" " maxlength="10" class="w-full peer/fl-bdate" :class="birthDateError ? 'fl-error' : ''" @keydown="preventNonDigitKey" @update:model-value="onBirthDateInput" @blur="validateBirthDate">
            <template #trailing>
              <div class="flex items-center gap-1">
                <UTooltip v-if="birthDateError" :text="birthDateError"><UIcon name="i-lucide-circle-alert" class="size-4 text-red-500 cursor-help shrink-0" /></UTooltip>
                <UPopover v-model:open="birthDatePopoverOpen">
                  <UButton type="button" icon="i-lucide-calendar" color="neutral" variant="ghost" size="xs" />
                  <template #content>
                    <UCalendar locale="tr-TR" v-model="birthDateCalendar" class="p-2" @update:model-value="onBirthDateCalendar" />
                  </template>
                </UPopover>
              </div>
            </template>
          </UInput>
          <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm top-1/2 -translate-y-1/2', 'peer-focus-within/fl-bdate:top-0 peer-focus-within/fl-bdate:-translate-y-1/2 peer-focus-within/fl-bdate:text-xs', 'peer-has-[input:not(:placeholder-shown)]/fl-bdate:top-0 peer-has-[input:not(:placeholder-shown)]/fl-bdate:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-bdate:text-xs', birthDateError ? 'text-red-500 peer-focus-within/fl-bdate:text-red-500 peer-has-[input:not(:placeholder-shown)]/fl-bdate:text-red-500' : 'text-[var(--ui-text-muted)] peer-focus-within/fl-bdate:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-bdate:text-[var(--ui-text-highlighted)]']">Doğum Tarihi</label>
        </div>
      </div>
    </UCard>

    <!-- ═══ GÜVENLİK ═══ -->
    <section ref="securitySection" id="guvenlik" class="mt-6">
      <UCard>
        <template #header>
          <h2 >Güvenlik</h2>
          <p class="text-xs text-muted mt-0.5">Şifre ve iki adımlı doğrulama ayarlarınızı yönetin.</p>
        </template>

        <!-- Şifre Değiştirme -->
        <div class="pb-5 mb-5 border-b border-default">
          <h3 class="text-sm font-medium mb-4">Şifre Değiştirme</h3>
          <div class="space-y-4 sm:max-w-md">
            <!-- Mevcut Şifre -->
            <div class="relative fl-form">
              <UInput v-model="passwordForm.currentPassword" :type="showCurrentPw ? 'text' : 'password'" placeholder=" " class="w-full peer/fl-curpw" :class="currentPwTouched && currentPwError ? 'fl-error' : ''" autocomplete="current-password" @blur="validateCurrentPw" @input="currentPwTouched = true; currentPwError = ''">
                <template #trailing>
                  <div class="flex items-center gap-1">
                    <UTooltip v-if="currentPwTouched && currentPwError" :text="currentPwError"><UIcon name="i-lucide-circle-alert" class="size-4 text-red-500 cursor-help shrink-0" /></UTooltip>
                    <UButton :icon="showCurrentPw ? 'i-lucide-eye-off' : 'i-lucide-eye'" color="neutral" variant="ghost" size="xs" :padded="false" :aria-label="showCurrentPw ? 'Şifreyi gizle' : 'Şifreyi göster'" @click="showCurrentPw = !showCurrentPw" />
                  </div>
                </template>
              </UInput>
              <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm top-1/2 -translate-y-1/2', 'peer-focus-within/fl-curpw:top-0 peer-focus-within/fl-curpw:-translate-y-1/2 peer-focus-within/fl-curpw:text-xs', 'peer-has-[input:not(:placeholder-shown)]/fl-curpw:top-0 peer-has-[input:not(:placeholder-shown)]/fl-curpw:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-curpw:text-xs', currentPwTouched && currentPwError ? 'text-red-500' : 'text-[var(--ui-text-muted)] peer-focus-within/fl-curpw:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-curpw:text-[var(--ui-text-highlighted)]']">Mevcut Şifre</label>
            </div>

            <!-- Yeni Şifre -->
            <div class="relative fl-form">
              <UInput v-model="passwordForm.newPassword" :type="showNewPw ? 'text' : 'password'" placeholder=" " class="w-full peer/fl-newpw" :class="newPwTouched && newPwError ? 'fl-error' : ''" autocomplete="new-password" @blur="validateNewPw" @input="newPwTouched = true; newPwError = ''">
                <template #trailing>
                  <div class="flex items-center gap-1">
                    <UTooltip v-if="newPwTouched && newPwError" :text="newPwError"><UIcon name="i-lucide-circle-alert" class="size-4 text-red-500 cursor-help shrink-0" /></UTooltip>
                    <UButton :icon="showNewPw ? 'i-lucide-eye-off' : 'i-lucide-eye'" color="neutral" variant="ghost" size="xs" :padded="false" :aria-label="showNewPw ? 'Şifreyi gizle' : 'Şifreyi göster'" @click="showNewPw = !showNewPw" />
                  </div>
                </template>
              </UInput>
              <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm top-1/2 -translate-y-1/2', 'peer-focus-within/fl-newpw:top-0 peer-focus-within/fl-newpw:-translate-y-1/2 peer-focus-within/fl-newpw:text-xs', 'peer-has-[input:not(:placeholder-shown)]/fl-newpw:top-0 peer-has-[input:not(:placeholder-shown)]/fl-newpw:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-newpw:text-xs', newPwTouched && newPwError ? 'text-red-500' : 'text-[var(--ui-text-muted)] peer-focus-within/fl-newpw:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-newpw:text-[var(--ui-text-highlighted)]']">Yeni Şifre</label>
            </div>

            <!-- Yeni Şifre (Tekrar) -->
            <div class="relative fl-form">
              <UInput v-model="passwordForm.confirmPassword" :type="showConfirmPw ? 'text' : 'password'" placeholder=" " class="w-full peer/fl-confpw" :class="confirmPwTouched && confirmPwError ? 'fl-error' : ''" autocomplete="new-password" @blur="validateConfirmPw" @input="confirmPwTouched = true; confirmPwError = ''">
                <template #trailing>
                  <div class="flex items-center gap-1">
                    <UTooltip v-if="confirmPwTouched && confirmPwError" :text="confirmPwError"><UIcon name="i-lucide-circle-alert" class="size-4 text-red-500 cursor-help shrink-0" /></UTooltip>
                    <UButton :icon="showConfirmPw ? 'i-lucide-eye-off' : 'i-lucide-eye'" color="neutral" variant="ghost" size="xs" :padded="false" :aria-label="showConfirmPw ? 'Şifreyi gizle' : 'Şifreyi göster'" @click="showConfirmPw = !showConfirmPw" />
                  </div>
                </template>
              </UInput>
              <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm top-1/2 -translate-y-1/2', 'peer-focus-within/fl-confpw:top-0 peer-focus-within/fl-confpw:-translate-y-1/2 peer-focus-within/fl-confpw:text-xs', 'peer-has-[input:not(:placeholder-shown)]/fl-confpw:top-0 peer-has-[input:not(:placeholder-shown)]/fl-confpw:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-confpw:text-xs', confirmPwTouched && confirmPwError ? 'text-red-500' : 'text-[var(--ui-text-muted)] peer-focus-within/fl-confpw:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-confpw:text-[var(--ui-text-highlighted)]']">Yeni Şifre (Tekrar)</label>
            </div>

            <UButton label="Şifreyi Değiştir" icon="i-lucide-lock" block size="xl"  :loading="changingPassword" @click="changePassword" />
          </div>
        </div>

        <!-- İki Adımlı Doğrulama -->
        <div>
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-medium">İki Adımlı Doğrulama (2FA)</h3>
            <UBadge v-if="twoFactorEnabled" color="success" variant="subtle" size="sm">Aktif</UBadge>
          </div>

          <!-- 2FA Aktif -->
          <div v-if="twoFactorEnabled && setupStep === 'idle'">
            <p class="text-xs text-muted">
              İki adımlı doğrulama hesabınızda zorunlu olarak aktiftir. Giriş yaparken doğrulama uygulamanızdan kod girmeniz gerekmektedir.
            </p>
          </div>

          <!-- 2FA Aktif Değil -->
          <div v-else-if="!twoFactorEnabled && setupStep === 'idle'">
            <p class="text-xs text-muted mb-4">
              İki adımlı doğrulama, hesabınıza giriş yaparken şifrenize ek olarak doğrulama uygulamasından (Google Authenticator, Authy vb.) bir kod girmenizi gerektirir.
            </p>
            <UButton label="2FA Etkinleştir" icon="i-lucide-shield-check" size="xl"  :loading="settingUp2FA" @click="startSetup" />
          </div>

          <!-- QR Kurulum -->
          <div v-else-if="setupStep === 'qr'" class="space-y-5">
            <p class="text-xs text-muted">Doğrulama uygulamanızla aşağıdaki QR kodu tarayın veya gizli anahtarı manuel olarak girin.</p>
            <div class="flex flex-col items-center gap-3">
              <div class="border border-neutral-200 rounded-lg p-3 bg-white">
                <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="2FA QR Code" width="180" height="180" />
              </div>
              <div class="text-center">
                <p class="text-xs text-muted mb-1">Manuel giriş için gizli anahtar:</p>
                <code class="text-xs font-mono bg-neutral-100 px-3 py-1.5 rounded select-all break-all">{{ setupData.secret }}</code>
              </div>
            </div>
            <div class="max-w-xs mx-auto space-y-3">
              <div class="relative fl-form">
                <UInput v-model="verificationCode" placeholder=" " class="w-full tracking-widest text-center peer/fl-2facode" maxlength="6" inputmode="numeric" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-2facode:top-0 peer-focus-within/fl-2facode:-translate-y-1/2 peer-focus-within/fl-2facode:text-xs peer-focus-within/fl-2facode:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-2facode:top-0 peer-has-[input:not(:placeholder-shown)]/fl-2facode:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-2facode:text-xs peer-has-[input:not(:placeholder-shown)]/fl-2facode:text-[var(--ui-text-highlighted)]">Doğrulama Kodu</label>
              </div>
              <div class="flex gap-2">
                <UButton label="Vazgeç" color="neutral" variant="outline" size="xl" class="flex-1" @click="cancelSetup" />
                <UButton label="Doğrula ve Etkinleştir" icon="i-lucide-check" size="xl" class="flex-1" :loading="enabling2FA" @click="verifyAndEnable" />
              </div>
            </div>
          </div>

          <!-- Kurtarma Kodları -->
          <div v-else-if="setupStep === 'recovery'" class="space-y-4">
            <div class="bg-warning-50 border border-warning-200 rounded-lg p-3">
              <div class="flex items-start gap-2.5">
                <UIcon name="i-lucide-triangle-alert" class="size-4 text-warning-600 shrink-0 mt-0.5" />
                <div>
                  <p class="font-medium text-sm">Bu kodları güvenli bir yere kaydedin</p>
                  <p class="text-xs text-muted mt-1">Doğrulama uygulamanıza erişemezseniz bu kurtarma kodlarını kullanarak giriş yapabilirsiniz. Her kod yalnızca bir kez kullanılabilir.</p>
                </div>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-2 max-w-sm">
              <code v-for="code in recoveryCodes" :key="code" class="text-sm font-mono bg-neutral-100 px-3 py-2 rounded text-center">{{ code }}</code>
            </div>
            <div class="flex gap-2">
              <UButton label="Kodları Kopyala" icon="i-lucide-copy" color="neutral" variant="outline" size="xl"  @click="copyRecoveryCodes" />
              <UButton label="Anladım, Kaydettim" icon="i-lucide-check" size="xl"  @click="closeRecovery" />
            </div>
          </div>
        </div>
      </UCard>
    </section>
  </div>
</template>

<style scoped>
.fl-error :deep(input) {
  --tw-ring-color: var(--color-red-500) !important;
  --tw-ring-offset-width: 0px !important;
  --tw-ring-shadow: var(--tw-ring-inset) 0 0 0 1px var(--tw-ring-color) !important;
}
</style>
