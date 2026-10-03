<script setup lang="ts">
import { z } from 'zod'

definePageMeta({
  layout: 'auth',
  middleware: 'auth'
})

const { login, verifyTwoFactor, setupTwoFactor, enableTwoFactor, confirmSetup } = useAuth()
const { agency } = useAgency()
const toast = useToast()

// ── Login step machine ──
type LoginStep = 'credentials' | 'totp' | 'setup-qr' | 'setup-verify' | 'setup-recovery' | 'setup-confirm'
const step = ref<LoginStep>('credentials')

// ── Login form ──
const loginSchema = z.object({
  email: z.string({ message: 'E-posta zorunludur' })
    .email('Geçersiz e-posta adresi'),
  password: z.string({ message: 'Şifre zorunludur' })
    .min(6, 'Şifre en az 6 karakter olmalıdır')
})

type LoginForm = z.infer<typeof loginSchema>

const loginState = reactive<LoginForm>({
  email: '',
  password: ''
})

const REMEMBER_EMAIL_KEY = 'remember_email'
const rememberEmail = ref(false)
const showPassword = ref(false)
const loginLoading = ref(false)

// Form ref — error'lara erişim için
const formRef = ref()

// Field validation state
const emailError = ref('')
const passwordError = ref('')
const totpError = ref('')

// Touched tracking — input'a hiç dokunulmadıysa hata gösterme
const emailTouched = ref(false)
const passwordTouched = ref(false)
const totpTouched = ref(false)

// Authentication error (field validation'dan ayrı)
const authError = ref('')

// Form errors'ı izle (submit tetiklendiğinde)
function onFormError(event: any) {
  const errors = event?.errors || []
  // Submit'te touched olarak işaretle
  if (errors.find((e: any) => e.name === 'email')) emailTouched.value = true
  if (errors.find((e: any) => e.name === 'password')) passwordTouched.value = true
  if (errors.find((e: any) => e.name === 'code')) totpTouched.value = true
  validateEmail()
  validatePassword()
  validateTotp()
}

// Kullanıcı IP adresi
const clientIp = ref('')

onMounted(async () => {
  const savedEmail = localStorage.getItem(REMEMBER_EMAIL_KEY)
  if (savedEmail) {
    loginState.email = savedEmail.trim().toLowerCase()
    rememberEmail.value = true
  }

  // IP adresini al
  try {
    const res = await fetch('https://api.ipify.org?format=json')
    const data = await res.json()
    clientIp.value = data.ip || ''
  } catch {
    clientIp.value = ''
  }
})

// Email normalize: trim + lowercase
function onEmailInput(val: string | number) {
  loginState.email = String(val).trim().toLowerCase()
  emailTouched.value = true
  if (emailError.value) emailError.value = ''
  if (authError.value) authError.value = ''
}

function onPasswordInput(val: string | number) {
  loginState.password = String(val)
  passwordTouched.value = true
  if (passwordError.value) passwordError.value = ''
  if (authError.value) authError.value = ''
}

function onTotpInput(val: string | number) {
  totpState.code = String(val)
  totpTouched.value = true
  if (totpError.value) totpError.value = ''
}

// Blur'da validation — sadece touched ise
function validateEmail() {
  emailTouched.value = true
  const val = loginState.email
  if (!val) {
    emailError.value = 'Mail adresinizi giriniz.'
  } else if (!val.includes('@') || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
    emailError.value = 'Geçerli bir mail adresi giriniz.'
  } else {
    emailError.value = ''
  }
}

function validateTotp() {
  totpTouched.value = true
  const val = totpState.code
  if (!val) {
    totpError.value = 'Doğrulama kodunu giriniz.'
  } else if (val.length < 6) {
    totpError.value = 'En az 6 karakter giriniz.'
  } else {
    totpError.value = ''
  }
}

function validatePassword() {
  passwordTouched.value = true
  const val = loginState.password
  if (!val) {
    passwordError.value = 'Şifrenizi giriniz.'
  } else if (val.length < 6) {
    passwordError.value = 'Şifre en az 6 karakter olmalıdır.'
  } else {
    passwordError.value = ''
  }
}

// ── Challenge state ──
const challengeToken = ref('')

// ── TOTP form ──
const totpSchema = z.object({
  code: z.string({ message: 'Doğrulama kodu zorunludur' })
    .min(6, 'En az 6 karakter giriniz')
})

const totpState = reactive({ code: '' })
const totpLoading = ref(false)

// ── Setup state ──
const setupSecret = ref('')
const setupOtpauthUrl = ref('')
const setupLoading = ref(false)
const setupVerifyCode = ref('')
const setupEnableLoading = ref(false)
const recoveryCodes = ref<string[]>([])
const recoveryConfirmed = ref(false)
const confirmLoading = ref(false)

// ── Başarılı giriş geçiş ekranı ──
const loginSuccess = ref(false)

// ── QR Code ──
const qrCodeDataUrl = ref('')

watch(setupOtpauthUrl, async (url) => {
  if (!url) { qrCodeDataUrl.value = ''; return }
  const QRCode = await import('qrcode')
  qrCodeDataUrl.value = await QRCode.toDataURL(url, { width: 200, margin: 2 })
})

// ── Login form valid ──
const isLoginFormValid = computed(() => {
  return loginState.email.length > 0 && loginState.password.length >= 6 && loginState.email.includes('@')
})

// ── Handlers ──

async function handleLogin() {
  loginLoading.value = true
  const result = await login(loginState.email, loginState.password)
  loginLoading.value = false

  if (rememberEmail.value) {
    localStorage.setItem(REMEMBER_EMAIL_KEY, loginState.email)
  } else {
    localStorage.removeItem(REMEMBER_EMAIL_KEY)
  }

  if (result.success) {
    navigateTo('/')
    return
  }

  if ((result as any).requiresTwoFactor) {
    challengeToken.value = (result as any).challengeToken
    if ((result as any).requiresSetup) {
      await startSetup()
    } else {
      step.value = 'totp'
    }
    return
  }

  authError.value = 'Mail adresi veya şifre hatalı. Bilgilerinizi kontrol ederek tekrar deneyiniz.'
}

async function handleTotpVerify() {
  if (!totpState.code || totpState.code.length < 6) return
  totpLoading.value = true
  const result = await verifyTwoFactor(challengeToken.value, totpState.code)
  totpLoading.value = false

  if (result.success) {
    loginSuccess.value = true
    await new Promise(r => setTimeout(r, 1500))
    navigateTo('/')
  } else {
    toast.add({ title: result.error || 'Doğrulama başarısız', color: 'error' })
  }
}

async function startSetup() {
  setupLoading.value = true
  const result = await setupTwoFactor(challengeToken.value)
  setupLoading.value = false

  if (result.success) {
    setupSecret.value = result.secret!
    setupOtpauthUrl.value = result.otpauthUrl!
    step.value = 'setup-qr'
  } else {
    toast.add({ title: result.error || 'Kurulum başlatılamadı', color: 'error' })
    resetToCredentials()
  }
}

async function handleSetupVerify() {
  if (!setupVerifyCode.value || setupVerifyCode.value.length !== 6) {
    toast.add({ title: 'Lütfen 6 haneli doğrulama kodunu girin', color: 'error' })
    return
  }

  setupEnableLoading.value = true
  const result = await enableTwoFactor(challengeToken.value, setupVerifyCode.value)
  setupEnableLoading.value = false

  if (result.success) {
    recoveryCodes.value = result.recoveryCodes!
    step.value = 'setup-recovery'
  } else {
    toast.add({ title: result.error || 'Doğrulama başarısız', color: 'error' })
  }
}

function copyRecoveryCodes() {
  const text = recoveryCodes.value.join('\n')
  navigator.clipboard.writeText(text)
  toast.add({ title: 'Kurtarma kodları panoya kopyalandı', color: 'success' })
}

async function handleConfirmSetup() {
  confirmLoading.value = true
  const result = await confirmSetup(challengeToken.value)
  confirmLoading.value = false

  if (result.success) {
    loginSuccess.value = true
    await new Promise(r => setTimeout(r, 1500))
    navigateTo('/')
  } else {
    toast.add({ title: result.error || 'Giriş yapılamadı. Tekrar deneyin.', color: 'error' })
    resetToCredentials()
  }
}

function resetToCredentials() {
  step.value = 'credentials'
  challengeToken.value = ''
  totpState.code = ''
  setupSecret.value = ''
  setupOtpauthUrl.value = ''
  setupVerifyCode.value = ''
  recoveryCodes.value = []
  recoveryConfirmed.value = false
  emailError.value = ''
  passwordError.value = ''
  totpError.value = ''
  authError.value = ''
  emailTouched.value = false
  passwordTouched.value = false
  totpTouched.value = false
}
</script>

<template>
  <div>
    <!-- ═══ Başarılı giriş geçiş ekranı ═══ -->
    <template v-if="loginSuccess">
      <div class="flex flex-col items-center justify-center py-12 login-success-enter">
        <div class="size-16 rounded-full bg-green-50 flex items-center justify-center mb-4">
          <UIcon name="i-lucide-check" class="size-8 text-green-500" />
        </div>
        <h2 class="text-xl font-semibold text-neutral-800">Giriş Başarılı</h2>
        <p class="text-sm text-muted mt-1">Yönlendiriliyorsunuz...</p>
      </div>
    </template>

    <!-- ═══ STEP 1: Credentials ═══ -->
    <template v-else-if="step === 'credentials'">
      <div class="mb-8">
        <h2 class="text-2xl font-bold text-neutral-800">Hoş Geldiniz</h2>
      </div>

      <UForm ref="formRef" :schema="loginSchema" :state="loginState" class="login-form space-y-5" @error="onFormError" @submit="handleLogin">
        <!-- Authentication error -->
        <div v-if="authError" class="flex items-start gap-2.5 rounded-lg bg-red-50 border border-red-200 px-3.5 py-3">
          <UIcon name="i-lucide-circle-alert" class="size-4 text-red-500 shrink-0 mt-0.5" />
          <p class="text-sm text-red-700">{{ authError }}</p>
        </div>

        <!-- Email -->
        <UFormField name="email" :error="false">
          <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput
              :model-value="loginState.email"
              type="email"
              placeholder=" "
              class="w-full peer/fl-email"
              :class="emailTouched && emailError ? 'login-input-error' : ''"
              autocomplete="email"
              data-no-uppercase
              @update:model-value="onEmailInput"
              @blur="validateEmail"
            >
              <template v-if="emailTouched && emailError" #trailing>
                <UTooltip :text="emailError">
                  <UIcon name="i-lucide-circle-alert" class="size-4 text-red-500 cursor-help shrink-0" />
                </UTooltip>
              </template>
            </UInput>
            <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', 'top-1/2 -translate-y-1/2', 'peer-focus-within/fl-email:top-0 peer-focus-within/fl-email:-translate-y-1/2 peer-focus-within/fl-email:text-xs', 'peer-has-[input:not(:placeholder-shown)]/fl-email:top-0 peer-has-[input:not(:placeholder-shown)]/fl-email:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-email:text-xs', emailTouched && emailError ? 'text-red-500 peer-focus-within/fl-email:text-red-500 peer-has-[input:not(:placeholder-shown)]/fl-email:text-red-500' : 'text-[var(--ui-text-muted)] peer-focus-within/fl-email:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-email:text-[var(--ui-text-highlighted)]']">Mail Adresiniz</label>
          </div>
        </UFormField>

        <!-- Password -->
        <UFormField name="password" :error="false">
          <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput
              :model-value="loginState.password"
              :type="showPassword ? 'text' : 'password'"
              placeholder=" "
              class="w-full peer/fl-pass"
              :class="passwordTouched && passwordError ? 'login-input-error' : ''"
              autocomplete="current-password"
              @update:model-value="onPasswordInput"
              @blur="validatePassword"
            >
              <template #trailing>
                <div class="flex items-center gap-1">
                  <UTooltip v-if="passwordTouched && passwordError" :text="passwordError">
                    <UIcon name="i-lucide-circle-alert" class="size-4 text-red-500 cursor-help shrink-0" />
                  </UTooltip>
                  <UButton
                    :icon="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                    color="neutral"
                    variant="ghost"
                    size="xs"
                    :padded="false"
                    :aria-label="showPassword ? 'Şifreyi gizle' : 'Şifreyi göster'"
                    @click="showPassword = !showPassword"
                  />
                </div>
              </template>
            </UInput>
            <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', 'top-1/2 -translate-y-1/2', 'peer-focus-within/fl-pass:top-0 peer-focus-within/fl-pass:-translate-y-1/2 peer-focus-within/fl-pass:text-xs', 'peer-has-[input:not(:placeholder-shown)]/fl-pass:top-0 peer-has-[input:not(:placeholder-shown)]/fl-pass:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-pass:text-xs', passwordTouched && passwordError ? 'text-red-500 peer-focus-within/fl-pass:text-red-500 peer-has-[input:not(:placeholder-shown)]/fl-pass:text-red-500' : 'text-[var(--ui-text-muted)] peer-focus-within/fl-pass:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-pass:text-[var(--ui-text-highlighted)]']">Şifre</label>
          </div>
        </UFormField>

        <div class="flex items-center justify-between">
          <UCheckbox v-model="rememberEmail" label="Beni Hatırla" />
          <span v-if="clientIp" class="text-xs text-neutral-400">{{ clientIp }}</span>
        </div>

        <UButton
          type="submit"
          block
          size="xl"
          :loading="loginLoading"
          :disabled="!isLoginFormValid"
          class="font-semibold"
        >
          Giriş Yap
        </UButton>
      </UForm>
    </template>

    <!-- ═══ STEP 2: TOTP Challenge ═══ -->
    <template v-else-if="step === 'totp'">
      <div class="mb-8">
        <h2 class="text-2xl font-bold text-neutral-800">Güvenlik Doğrulaması</h2>
      </div>

      <UForm :schema="totpSchema" :state="totpState" class="login-form space-y-5" @error="onFormError" @submit="handleTotpVerify">
        <UFormField name="code" :error="false">
          <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput
              :model-value="totpState.code"
              placeholder=" "
              class="w-full tracking-widest text-center peer/fl-totp"
              :class="totpTouched && totpError ? 'login-input-error' : ''"
              maxlength="8"
              inputmode="numeric"
              @update:model-value="onTotpInput"
              @blur="validateTotp"
            >
              <template v-if="totpTouched && totpError" #trailing>
                <UTooltip :text="totpError">
                  <UIcon name="i-lucide-circle-alert" class="size-4 text-red-500 cursor-help shrink-0" />
                </UTooltip>
              </template>
            </UInput>
            <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', 'top-1/2 -translate-y-1/2', 'peer-focus-within/fl-totp:top-0 peer-focus-within/fl-totp:-translate-y-1/2 peer-focus-within/fl-totp:text-xs', 'peer-has-[input:not(:placeholder-shown)]/fl-totp:top-0 peer-has-[input:not(:placeholder-shown)]/fl-totp:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-totp:text-xs', totpTouched && totpError ? 'text-red-500 peer-focus-within/fl-totp:text-red-500 peer-has-[input:not(:placeholder-shown)]/fl-totp:text-red-500' : 'text-[var(--ui-text-muted)] peer-focus-within/fl-totp:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-totp:text-[var(--ui-text-highlighted)]']">Doğrulama Kodu</label>
          </div>
        </UFormField>

        <UButton
          type="submit"
          block
          size="xl"
          :loading="totpLoading"
          :disabled="!totpState.code || totpState.code.length < 6"
          class="font-semibold"
        >
          Doğrula
        </UButton>

      </UForm>
    </template>

    <!-- ═══ STEP 3a: Setup QR ═══ -->
    <template v-else-if="step === 'setup-qr'">
      <div class="mb-6">
        <div class="size-12 rounded-xl bg-primary-50 flex items-center justify-center mb-4">
          <UIcon name="i-lucide-smartphone" class="size-6 text-primary-600" />
        </div>
        <h2 class="text-2xl font-bold text-neutral-800">2FA Kurulumu</h2>
        <p class="text-muted text-sm mt-1">Hesabınızın güvenliğini artırmak için doğrulama uygulaması kurun.</p>
      </div>

      <div class="space-y-5">
        <div class="flex flex-col items-center gap-3">
          <div class="border border-neutral-200 rounded-lg p-3 bg-white">
            <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="2FA QR Code" width="180" height="180" />
          </div>
          <div class="text-center">
            <p class="text-xs text-muted mb-1">Manuel giriş için gizli anahtar:</p>
            <code class="text-xs font-mono bg-neutral-100 px-3 py-1.5 rounded select-all break-all">
              {{ setupSecret }}
            </code>
          </div>
        </div>

        <p class="text-xs text-muted text-center">
          Google Authenticator, Microsoft Authenticator veya benzeri bir uygulama ile QR kodu tarayın.
        </p>

        <UButton
          label="Kodu Taradım, Devam Et"
          block
          size="xl"
          class="font-semibold"
          @click="step = 'setup-verify'"
        />

        <UButton
          label="Vazgeç"
          block
          size="xl"
          color="neutral"
          variant="outline"
          class="font-semibold"
          @click="resetToCredentials"
        />
      </div>
    </template>

    <!-- ═══ STEP 3b: Setup Verify ═══ -->
    <template v-else-if="step === 'setup-verify'">
      <div class="mb-6">
        <div class="size-12 rounded-xl bg-primary-50 flex items-center justify-center mb-4">
          <UIcon name="i-lucide-check-circle" class="size-6 text-primary-600" />
        </div>
        <h2 class="text-2xl font-bold text-neutral-800">Kodu Doğrulayın</h2>
        <p class="text-muted text-sm mt-1">Doğrulama uygulamanızda görünen 6 haneli kodu girin.</p>
      </div>

      <div class="space-y-5">
        <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
          <UInput
            v-model="setupVerifyCode"
            placeholder=" "
            class="w-full tracking-widest text-center peer/fl-setup"
            maxlength="6"
            inputmode="numeric"
            @keyup.enter="handleSetupVerify"
          />
          <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-setup:top-0 peer-focus-within/fl-setup:-translate-y-1/2 peer-focus-within/fl-setup:text-xs peer-focus-within/fl-setup:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-setup:top-0 peer-has-[input:not(:placeholder-shown)]/fl-setup:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-setup:text-xs peer-has-[input:not(:placeholder-shown)]/fl-setup:text-[var(--ui-text-highlighted)]">Doğrulama Kodu</label>
        </div>

        <UButton
          label="Doğrula ve Etkinleştir"
          block
          size="xl"
          :loading="setupEnableLoading"
          :disabled="!setupVerifyCode || setupVerifyCode.length !== 6"
          class="font-semibold"
          @click="handleSetupVerify"
        />

        <UButton
          label="QR Koda Dön"
          block
          size="xl"
          color="neutral"
          variant="outline"
          class="font-semibold"
          @click="step = 'setup-qr'"
        />
      </div>
    </template>

    <!-- ═══ STEP 3c: Recovery Codes ═══ -->
    <template v-else-if="step === 'setup-recovery'">
      <div class="mb-6">
        <div class="size-12 rounded-xl bg-warning-50 flex items-center justify-center mb-4">
          <UIcon name="i-lucide-triangle-alert" class="size-6 text-warning-600" />
        </div>
        <h2 class="text-2xl font-bold text-neutral-800">Kurtarma Kodları</h2>
        <p class="text-muted text-sm mt-1">Bu kodları güvenli bir yere kaydedin. Doğrulama uygulamanıza erişilemezse bu kodlar ile giriş yapabilirsiniz.</p>
      </div>

      <div class="space-y-5">
        <div class="bg-warning-50 border border-warning-200 rounded-lg p-3">
          <p class="text-xs font-medium text-warning-800">Her kod yalnızca bir kez kullanılabilir. Bu kodları tekrar görüntüleme imkânınız olmayacak.</p>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <code
            v-for="code in recoveryCodes"
            :key="code"
            class="text-sm font-mono bg-neutral-100 px-3 py-2 rounded text-center"
          >
            {{ code }}
          </code>
        </div>

        <UButton
          label="Kodları Kopyala"
          block
          size="xl"
          color="neutral"
          variant="outline"
          icon="i-lucide-copy"
          class="font-semibold"
          @click="copyRecoveryCodes"
        />

        <UCheckbox
          v-model="recoveryConfirmed"
          label="Kurtarma kodlarımı güvenli bir yere kaydettim"
        />

        <UButton
          label="Tamam, Giriş Yap"
          block
          size="xl"
          :loading="confirmLoading"
          :disabled="!recoveryConfirmed"
          class="font-semibold"
          @click="handleConfirmSetup"
        />
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Autofill durumunda floating-label'i yukarı taşı */
.peer\/fl-email:has(input:-webkit-autofill) ~ label,
.peer\/fl-pass:has(input:-webkit-autofill) ~ label {
  top: 0;
  transform: translateY(-50%);
  font-size: 0.75rem;
  color: var(--ui-text-highlighted);
  background-color: var(--ui-bg);
  padding-inline: 0.25rem;
}

/* Login'de Nuxt UI error alt mesajını ve otomatik kırmızı ring'i devre dışı bırak.
   Error gösterimi tooltip ile input içinde yapılır. */
.login-form :deep([data-slot="error"]) {
  display: none !important;
}
.login-form :deep([data-slot="root"]) {
  --ui-error: var(--ui-border);
}

/* Error durumunda ince kırmızı çerçeve — Nuxt UI ring sistemini kullanarak.
   ring-1 (1px) ile ince border efekti, ring-2 kalın kare efektini önler. */
.login-input-error :deep(input) {
  --tw-ring-color: var(--color-red-500) !important;
  --tw-ring-offset-width: 0px !important;
  --tw-ring-shadow: var(--tw-ring-inset) 0 0 0 1px var(--tw-ring-color) !important;
}

/* Başarılı giriş geçiş animasyonu */
.login-success-enter {
  animation: successFadeScale 0.4s ease-out both;
}
@keyframes successFadeScale {
  0% {
    opacity: 0;
    transform: scale(0.9);
  }
  100% {
    opacity: 1;
    transform: scale(1);
  }
}
</style>
