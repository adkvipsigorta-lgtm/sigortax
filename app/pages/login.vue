<script setup lang="ts">
import { z } from 'zod'

definePageMeta({
  layout: 'auth',
  middleware: 'auth'
})

const { login, verifyTwoFactor } = useAuth()
const { agency } = useAgency()
const toast = useToast()

const schema = z.object({
  email: z.string({ required_error: 'E-posta zorunludur' })
    .email('Geçersiz e-posta adresi'),
  password: z.string({ required_error: 'Şifre zorunludur' })
    .min(6, 'Şifre en az 6 karakter olmalıdır')
})

type LoginForm = z.infer<typeof schema>

const state = reactive<LoginForm>({
  email: '',
  password: ''
})

const REMEMBER_EMAIL_KEY = 'remember_email'

const rememberMe = ref(false)
const showPassword = ref(false)
const loading = ref(false)

onMounted(() => {
  const savedEmail = localStorage.getItem(REMEMBER_EMAIL_KEY)
  if (savedEmail) {
    state.email = savedEmail
    rememberMe.value = true
  }
})

// 2FA state
const twoFactorStep = ref(false)
const twoFactorUserId = ref<number | null>(null)
const twoFactorCode = ref('')
const verifying2FA = ref(false)

const isFormValid = computed(() => {
  return state.email.length > 0 && state.password.length >= 6 && state.email.includes('@')
})

async function handleLogin() {
  loading.value = true
  const result = await login(state.email, state.password, rememberMe.value)
  loading.value = false

  if (rememberMe.value) {
    localStorage.setItem(REMEMBER_EMAIL_KEY, state.email)
  } else {
    localStorage.removeItem(REMEMBER_EMAIL_KEY)
  }

  if (result.success) {
    navigateTo('/')
  } else if ((result as any).requiresTwoFactor) {
    twoFactorStep.value = true
    twoFactorUserId.value = (result as any).userId
  } else {
    toast.add({ title: result.error || 'Giriş başarısız', color: 'error' })
  }
}

async function handleVerify2FA() {
  if (!twoFactorUserId.value || !twoFactorCode.value) return
  verifying2FA.value = true
  const result = await verifyTwoFactor(twoFactorUserId.value, twoFactorCode.value)
  verifying2FA.value = false

  if (result.success) {
    navigateTo('/')
  } else {
    toast.add({ title: result.error || 'Doğrulama başarısız', color: 'error' })
  }
}

function backToLogin() {
  twoFactorStep.value = false
  twoFactorUserId.value = null
  twoFactorCode.value = ''
}
</script>

<template>
  <div>
    <!-- Normal Login -->
    <template v-if="!twoFactorStep">
      <!-- Başlık -->
      <div class="mb-8">
        <h2 class="text-2xl font-bold text-slate-800">Hoş Geldiniz</h2>
        <p class="text-muted text-sm mt-1">{{ agency.description || 'Hesabınıza giriş yapın' }}</p>
      </div>

      <UForm :schema="schema" :state="state" class="space-y-5" @submit="handleLogin">
        <UFormField label="E-posta" name="email">
          <UInput
            v-model="state.email"
            type="email"
            placeholder="örnek@acenteniz.com"
            size="xl"
            class="w-full"
            icon="i-lucide-mail"
          />
        </UFormField>

        <UFormField label="Şifre" name="password">
          <UInput
            v-model="state.password"
            :type="showPassword ? 'text' : 'password'"
            placeholder="••••••••"
            size="xl"
            class="w-full"
            icon="i-lucide-lock"
          >
            <template #trailing>
              <UButton
                :icon="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                color="neutral"
                variant="ghost"
                size="xs"
                :padded="false"
                @click="showPassword = !showPassword"
              />
            </template>
          </UInput>
        </UFormField>

        <div class="flex items-center justify-between">
          <UCheckbox v-model="rememberMe" label="Beni hatırla" />
        </div>

        <UButton
          type="submit"
          block
          size="xl"
          :loading="loading"
          :disabled="!isFormValid"
          class="mt-2 font-semibold"
        >
          <template v-if="loading">
            <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin mr-2" />
            Giriş yapılıyor...
          </template>
          <template v-else>
            Giriş Yap
          </template>
        </UButton>
      </UForm>
    </template>

    <!-- 2FA Verification -->
    <template v-else>
      <!-- Başlık -->
      <div class="mb-8">
        <div class="size-12 rounded-xl bg-blue-50 flex items-center justify-center mb-4">
          <UIcon name="i-lucide-shield-check" class="size-6 text-blue-600" />
        </div>
        <h2 class="text-2xl font-bold text-slate-800">İki Adımlı Doğrulama</h2>
        <p class="text-muted text-sm mt-1">Doğrulama uygulamanızdan veya kurtarma kodlarınızdan bir kod girin.</p>
      </div>

      <div class="space-y-5">
        <div>
          <label class="text-sm font-medium text-slate-700 mb-1.5 block">Doğrulama Kodu</label>
          <UInput
            v-model="twoFactorCode"
            placeholder="000000"
            size="xl"
            class="w-full tracking-widest text-center"
            maxlength="8"
            icon="i-lucide-key-round"
            @keyup.enter="handleVerify2FA"
          />
          <p class="text-xs text-muted mt-1.5">6 haneli TOTP kodu veya kurtarma kodu girin.</p>
        </div>

        <UButton
          block
          size="xl"
          :loading="verifying2FA"
          :disabled="!twoFactorCode"
          class="font-semibold"
          @click="handleVerify2FA"
        >
          <template v-if="verifying2FA">
            <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin mr-2" />
            Doğrulanıyor...
          </template>
          <template v-else>
            Doğrula
          </template>
        </UButton>

        <UButton
          label="← Geri Dön"
          block
          size="xl"
          color="neutral"
          variant="outline"
          @click="backToLogin"
        />
      </div>
    </template>
  </div>
</template>
