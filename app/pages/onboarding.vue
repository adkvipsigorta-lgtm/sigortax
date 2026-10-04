<script setup lang="ts">
definePageMeta({ layout: 'auth', middleware: 'auth' })
useSeoMeta({ title: 'Personel Onboarding' })

const { get, post } = useApi()
const toast = useToast()
const { user } = useAuth()

const loading = ref(true)
const currentStep = ref<'profile' | 'kvkk' | 'identity' | 'commitment' | 'completed'>('profile')
const steps = ref({ profile: false, kvkk: false, identity: false, commitment: false })
const userData = ref<any>({})

// ── Profil formu ──
const profileForm = ref({
  tcNo: '',
  birthDate: '',
  companyPhone: '',
  personalEmail: '',
  address: '',
})
const savingProfile = ref(false)

// ── Sözleşme ──
const agreementContent = ref('')
const agreementTitle = ref('')
const agreementAccepting = ref(false)
const smsStep = ref<'idle' | 'sent' | 'verifying'>('idle')
const smsCode = ref('')
const maskedPhone = ref('')
const verifying = ref(false)
const currentAgreementType = ref('')

// ── Kimlik ──
const uploadingFront = ref(false)
const uploadingBack = ref(false)
const identityFront = ref('')
const identityBack = ref('')

// ── Durum yükle ──
async function loadStatus() {
  loading.value = true
  try {
    const res = await get<any>('onboarding/status')
    const d = res.data
    currentStep.value = d.currentStep
    steps.value = d.steps
    userData.value = d.user

    // Profil formunu doldur
    profileForm.value.tcNo = d.user.tcNo || ''
    profileForm.value.birthDate = d.user.birthDate || ''
    profileForm.value.companyPhone = d.user.companyPhone || ''
    profileForm.value.personalEmail = d.user.personalEmail || ''
    profileForm.value.address = d.user.address || ''
    identityFront.value = d.user.identityFront || ''
    identityBack.value = d.user.identityBack || ''

    if (d.currentStep === 'completed') {
      navigateTo('/')
    }
  } catch (e: any) {
    toast.add({ title: e.message || 'Durum yüklenemedi', color: 'error' })
  }
  loading.value = false
}

// ── Profil kaydet ──
async function saveProfile() {
  if (savingProfile.value) return
  savingProfile.value = true
  try {
    await post('onboarding/profile', profileForm.value)
    toast.add({ title: 'Profil kaydedildi', color: 'success' })
    await loadStatus()
  } catch (e: any) {
    toast.add({ title: e.message || 'Profil kaydedilemedi', color: 'error' })
  }
  savingProfile.value = false
}

// ── Sözleşme yükle ──
async function loadAgreement(type: string) {
  currentAgreementType.value = type
  smsStep.value = 'idle'
  smsCode.value = ''
  try {
    const res = await get<any>(`onboarding/agreement/${type}`)
    agreementContent.value = res.data.content
    agreementTitle.value = res.data.title
  } catch (e: any) {
    toast.add({ title: 'Belge yüklenemedi', color: 'error' })
  }
}

// ── Sözleşme onayla + SMS gönder ──
async function acceptAgreement() {
  agreementAccepting.value = true
  try {
    const res = await post<any>(`onboarding/agreement/${currentAgreementType.value}/accept`, {})
    if (res.data?.alreadyAccepted) {
      toast.add({ title: 'Zaten onaylanmış', color: 'success' })
      await loadStatus()
    } else {
      maskedPhone.value = res.data?.maskedPhone || ''
      smsStep.value = 'sent'
      toast.add({ title: res.data?.message || 'SMS gönderildi', color: 'success' })
    }
  } catch (e: any) {
    toast.add({ title: e.message || 'Onay gönderilemedi', color: 'error' })
  }
  agreementAccepting.value = false
}

// ── SMS doğrula ──
async function verifySms() {
  if (smsCode.value.length !== 6) return
  verifying.value = true
  try {
    await post(`onboarding/agreement/${currentAgreementType.value}/verify`, { code: smsCode.value })
    toast.add({ title: 'Doğrulama başarılı', color: 'success' })
    smsStep.value = 'idle'
    smsCode.value = ''
    await loadStatus()
  } catch (e: any) {
    toast.add({ title: e.message || 'Doğrulama başarısız', color: 'error' })
  }
  verifying.value = false
}

// ── Kimlik yükle ──
async function uploadIdentity(side: 'front' | 'back', event: Event) {
  const file = (event.target as HTMLInputElement)?.files?.[0]
  if (!file) return

  const isUploading = side === 'front' ? uploadingFront : uploadingBack
  isUploading.value = true

  const formData = new FormData()
  formData.append('file', file)
  formData.append('side', side)

  try {
    const res = await post<any>('onboarding/identity', formData)
    toast.add({ title: res.data?.message || 'Yüklendi', color: 'success' })
    if (side === 'front') identityFront.value = res.data?.filename
    else identityBack.value = res.data?.filename
    await loadStatus()
  } catch (e: any) {
    toast.add({ title: e.message || 'Yükleme başarısız', color: 'error' })
  }
  isUploading.value = false
}

// ── Init ──
onMounted(() => {
  loadStatus()
})

// Adımlar yüklenince sözleşme içeriğini yükle
watch(currentStep, (step) => {
  if (step === 'kvkk') loadAgreement('KVKK')
  if (step === 'commitment') loadAgreement('COMMITMENT')
})

const stepLabels = [
  { key: 'profile', label: 'Profil', icon: 'i-lucide-user' },
  { key: 'kvkk', label: 'KVKK', icon: 'i-lucide-shield-check' },
  { key: 'identity', label: 'Kimlik', icon: 'i-lucide-id-card' },
  { key: 'commitment', label: 'Taahhütname', icon: 'i-lucide-file-signature' },
]
</script>

<template>
  <div class="w-full max-w-2xl mx-auto">
    <!-- Loading -->
    <div v-if="loading" class="flex flex-col items-center justify-center py-20 gap-4">
      <UIcon name="i-lucide-loader-circle" class="size-10 animate-spin text-primary" />
      <p class="text-sm text-muted">Yükleniyor...</p>
    </div>

    <template v-else>
      <!-- Stepper -->
      <div class="flex items-center justify-center gap-2 mb-8">
        <div
          v-for="(s, i) in stepLabels"
          :key="s.key"
          class="flex items-center gap-2"
        >
          <div
            class="flex items-center justify-center size-9 rounded-full text-sm font-semibold transition-colors"
            :class="currentStep === s.key ? 'bg-primary text-white' : steps[s.key as keyof typeof steps] ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-muted dark:bg-gray-800'"
          >
            <UIcon v-if="steps[s.key as keyof typeof steps]" name="i-lucide-check" class="size-4" />
            <span v-else>{{ i + 1 }}</span>
          </div>
          <span class="text-xs hidden sm:inline" :class="currentStep === s.key ? 'font-semibold text-primary' : 'text-muted'">{{ s.label }}</span>
          <UIcon v-if="i < stepLabels.length - 1" name="i-lucide-chevron-right" class="size-4 text-muted mx-1" />
        </div>
      </div>

      <!-- ADIM 1: Profil -->
      <div v-if="currentStep === 'profile'" class="space-y-6">
        <div class="text-center">
          <h2 class="text-2xl font-semibold">Hoş Geldiniz, {{ userData.name }}</h2>
          <p class="text-sm text-muted mt-2">Lütfen aşağıdaki bilgilerinizi tamamlayın.</p>
        </div>

        <UCard>
          <div class="modal-form grid grid-cols-1 sm:grid-cols-2">
            <div class="relative fl-form sm:col-span-2">
              <UInput v-model="profileForm.tcNo" placeholder=" " maxlength="11" class="w-full peer/fl-tc" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-tc:top-0 peer-focus-within/fl-tc:-translate-y-1/2 peer-focus-within/fl-tc:text-xs peer-focus-within/fl-tc:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-tc:top-0 peer-has-[input:not(:placeholder-shown)]/fl-tc:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-tc:text-xs peer-has-[input:not(:placeholder-shown)]/fl-tc:text-[var(--ui-text-highlighted)]">TC Kimlik No <span class="text-[var(--ui-error)]">*</span></label>
            </div>

            <div class="relative fl-form">
              <UInput v-model="profileForm.birthDate" type="date" placeholder=" " class="w-full peer/fl-bd" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-bd:top-0 peer-focus-within/fl-bd:-translate-y-1/2 peer-focus-within/fl-bd:text-xs peer-focus-within/fl-bd:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-bd:top-0 peer-has-[input:not(:placeholder-shown)]/fl-bd:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-bd:text-xs peer-has-[input:not(:placeholder-shown)]/fl-bd:text-[var(--ui-text-highlighted)]">Doğum Tarihi <span class="text-[var(--ui-error)]">*</span></label>
            </div>

            <div class="relative fl-form">
              <UInput v-model="profileForm.companyPhone" type="tel" placeholder=" " class="w-full peer/fl-cp" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-cp:top-0 peer-focus-within/fl-cp:-translate-y-1/2 peer-focus-within/fl-cp:text-xs peer-focus-within/fl-cp:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-cp:top-0 peer-has-[input:not(:placeholder-shown)]/fl-cp:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-cp:text-xs peer-has-[input:not(:placeholder-shown)]/fl-cp:text-[var(--ui-text-highlighted)]">Şirket Telefonu <span class="text-[var(--ui-error)]">*</span></label>
            </div>

            <div class="relative fl-form sm:col-span-2">
              <UInput v-model="profileForm.personalEmail" type="email" placeholder=" " class="w-full peer/fl-pe" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-pe:top-0 peer-focus-within/fl-pe:-translate-y-1/2 peer-focus-within/fl-pe:text-xs peer-focus-within/fl-pe:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-pe:top-0 peer-has-[input:not(:placeholder-shown)]/fl-pe:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-pe:text-xs peer-has-[input:not(:placeholder-shown)]/fl-pe:text-[var(--ui-text-highlighted)]">Kişisel E-posta <span class="text-[var(--ui-error)]">*</span></label>
            </div>

            <div class="relative fl-form sm:col-span-2">
              <UInput v-model="profileForm.address" placeholder=" " class="w-full peer/fl-addr" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-addr:top-0 peer-focus-within/fl-addr:-translate-y-1/2 peer-focus-within/fl-addr:text-xs peer-focus-within/fl-addr:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-addr:top-0 peer-has-[input:not(:placeholder-shown)]/fl-addr:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-addr:text-xs peer-has-[input:not(:placeholder-shown)]/fl-addr:text-[var(--ui-text-highlighted)]">Adres <span class="text-[var(--ui-error)]">*</span></label>
            </div>
          </div>

          <div class="flex justify-end mt-6">
            <UButton label="Devam Et" icon="i-lucide-arrow-right" :loading="savingProfile" @click="saveProfile" />
          </div>
        </UCard>
      </div>

      <!-- ADIM 2 & 4: Sözleşme (KVKK / Taahhütname) -->
      <div v-if="currentStep === 'kvkk' || currentStep === 'commitment'" class="space-y-6">
        <div class="text-center">
          <h2 class="text-2xl font-semibold">{{ agreementTitle }}</h2>
          <p class="text-sm text-muted mt-2">Lütfen belgeyi okuyun ve onaylayın.</p>
        </div>

        <UCard>
          <!-- Belge içeriği -->
          <div class="max-h-[400px] overflow-y-auto border border-default rounded-lg p-4 sm:p-6 text-sm leading-relaxed prose prose-sm max-w-none" v-html="agreementContent" />

          <!-- Onay + SMS -->
          <div class="mt-6 space-y-4">
            <div v-if="smsStep === 'idle'">
              <UButton
                label="Okudum, Anladım, Kabul Ediyorum"
                icon="i-lucide-check-circle"
                block
                :loading="agreementAccepting"
                @click="acceptAgreement"
              />
              <p class="text-xs text-muted text-center mt-2">
                Onayınız kişisel telefonunuza gönderilecek SMS kodu ile doğrulanacaktır.
              </p>
            </div>

            <div v-if="smsStep === 'sent'" class="space-y-3">
              <div class="p-3 bg-primary/5 border border-primary/20 rounded-lg text-center">
                <UIcon name="i-lucide-smartphone" class="size-6 text-primary mb-1" />
                <p class="text-sm">Doğrulama kodu <strong>{{ maskedPhone }}</strong> numarasına gönderildi.</p>
              </div>

              <div class="relative fl-form">
                <UInput v-model="smsCode" placeholder=" " maxlength="6" class="w-full peer/fl-sms text-center text-lg tracking-widest" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-sms:top-0 peer-focus-within/fl-sms:-translate-y-1/2 peer-focus-within/fl-sms:text-xs peer-focus-within/fl-sms:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-sms:top-0 peer-has-[input:not(:placeholder-shown)]/fl-sms:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-sms:text-xs peer-has-[input:not(:placeholder-shown)]/fl-sms:text-[var(--ui-text-highlighted)]">SMS Doğrulama Kodu</label>
              </div>

              <UButton
                label="Doğrula"
                icon="i-lucide-shield-check"
                block
                :loading="verifying"
                :disabled="smsCode.length !== 6"
                @click="verifySms"
              />
            </div>
          </div>
        </UCard>
      </div>

      <!-- ADIM 3: Kimlik Yükleme -->
      <div v-if="currentStep === 'identity'" class="space-y-6">
        <div class="text-center">
          <h2 class="text-2xl font-semibold">Kimlik Doğrulama</h2>
          <p class="text-sm text-muted mt-2">TC Kimlik kartınızın ön ve arka yüzünü yükleyin.</p>
        </div>

        <UCard>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <!-- Ön Yüz -->
            <div class="text-center space-y-3">
              <p class="text-sm font-semibold">Ön Yüz</p>
              <div
                class="border-2 border-dashed rounded-xl p-6 transition-colors cursor-pointer hover:border-primary"
                :class="identityFront ? 'border-green-400 bg-green-50/50 dark:bg-green-900/10' : 'border-default'"
              >
                <label class="cursor-pointer flex flex-col items-center gap-2">
                  <UIcon :name="identityFront ? 'i-lucide-check-circle' : 'i-lucide-upload'" :class="identityFront ? 'size-8 text-green-600' : 'size-8 text-muted'" />
                  <span class="text-xs text-muted">{{ identityFront ? 'Yüklendi — değiştirmek için tıklayın' : 'Fotoğraf seçin veya sürükleyin' }}</span>
                  <input type="file" accept="image/*" class="hidden" :disabled="uploadingFront" @change="uploadIdentity('front', $event)" />
                </label>
              </div>
              <UIcon v-if="uploadingFront" name="i-lucide-loader-circle" class="size-5 animate-spin text-primary" />
            </div>

            <!-- Arka Yüz -->
            <div class="text-center space-y-3">
              <p class="text-sm font-semibold">Arka Yüz</p>
              <div
                class="border-2 border-dashed rounded-xl p-6 transition-colors cursor-pointer hover:border-primary"
                :class="identityBack ? 'border-green-400 bg-green-50/50 dark:bg-green-900/10' : 'border-default'"
              >
                <label class="cursor-pointer flex flex-col items-center gap-2">
                  <UIcon :name="identityBack ? 'i-lucide-check-circle' : 'i-lucide-upload'" :class="identityBack ? 'size-8 text-green-600' : 'size-8 text-muted'" />
                  <span class="text-xs text-muted">{{ identityBack ? 'Yüklendi — değiştirmek için tıklayın' : 'Fotoğraf seçin veya sürükleyin' }}</span>
                  <input type="file" accept="image/*" class="hidden" :disabled="uploadingBack" @change="uploadIdentity('back', $event)" />
                </label>
              </div>
              <UIcon v-if="uploadingBack" name="i-lucide-loader-circle" class="size-5 animate-spin text-primary" />
            </div>
          </div>

          <p class="text-xs text-muted text-center mt-4">
            Kabul edilen formatlar: JPG, PNG, WebP — Maksimum 5MB
          </p>
        </UCard>
      </div>
    </template>
  </div>
</template>
