<script setup lang="ts">
import { z } from 'zod'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Güvenlik Ayarlari' })

const toast = useToast()
const { user, fetchMe } = useAuth()
const { post } = useApi()

// ---- Şifre Değiştirme ----
const passwordSchema = z.object({
  currentPassword: z.string().min(1, 'Mevcut şifre zorunludur'),
  newPassword: z.string().min(6, 'Yeni şifre en az 6 karakter olmalıdir'),
  confirmPassword: z.string().min(1, 'Şifre tekrari zorunludur')
}).refine((data) => data.newPassword === data.confirmPassword, {
  message: 'Yeni şifreler eşleşmiyor',
  path: ['confirmPassword']
})

const passwordForm = reactive({
  currentPassword: '',
  newPassword: '',
  confirmPassword: ''
})
const changingPassword = ref(false)

async function changePassword() {
  changingPassword.value = true
  try {
    await post('auth/change-password', {
      currentPassword: passwordForm.currentPassword,
      newPassword: passwordForm.newPassword
    })
    passwordForm.currentPassword = ''
    passwordForm.newPassword = ''
    passwordForm.confirmPassword = ''
    toast.add({ title: 'Şifreniz başarıyla değiştirildi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Şifre değiştirilemedi', color: 'error' })
  } finally {
    changingPassword.value = false
  }
}

// ---- 2FA ----
const twoFactorEnabled = ref(false)
const setupStep = ref<'idle' | 'qr' | 'recovery'>('idle')
const setupData = reactive({
  secret: '',
  otpauthUrl: ''
})
const verificationCode = ref('')
const recoveryCodes = ref<string[]>([])
const settingUp2FA = ref(false)
const enabling2FA = ref(false)

// 2FA Devre Disi Birakma
const disableModalOpen = ref(false)
const disablePassword = ref('')
const disabling2FA = ref(false)

// Kullanıcı verisinden 2FA durumunu yükle
watch(() => user.value, (u) => {
  if (u) {
    twoFactorEnabled.value = !!u.twoFactorEnabled
  }
}, { immediate: true })

async function startSetup() {
  settingUp2FA.value = true
  try {
    const response = await post('auth/2fa/setup')
    setupData.secret = response.data.secret
    setupData.otpauthUrl = response.data.otpauthUrl
    setupStep.value = 'qr'
  } catch (error: any) {
    toast.add({ title: error.message || '2FA kurulumu başlatilamadi', color: 'error' })
  } finally {
    settingUp2FA.value = false
  }
}

async function verifyAndEnable() {
  if (verificationCode.value.length !== 6) {
    toast.add({ title: 'Lütfen 6 haneli doğrulama kodunu girin', color: 'error' })
    return
  }

  enabling2FA.value = true
  try {
    const response = await post('auth/2fa/enable', { code: verificationCode.value })
    recoveryCodes.value = response.data.recoveryCodes || []
    twoFactorEnabled.value = true
    setupStep.value = 'recovery'
    verificationCode.value = ''
    await fetchMe()
    toast.add({ title: '2FA başarıyla etkinlestirildi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Doğrulama başarısız', color: 'error' })
  } finally {
    enabling2FA.value = false
  }
}

function copyRecoveryCodes() {
  const text = recoveryCodes.value.join('\n')
  navigator.clipboard.writeText(text)
  toast.add({ title: 'Kurtarma kodlari panoya kopyalandi', color: 'success' })
}

function closeRecovery() {
  setupStep.value = 'idle'
  recoveryCodes.value = []
}

function openDisableModal() {
  disablePassword.value = ''
  disableModalOpen.value = true
}

async function disable2FA() {
  if (!disablePassword.value) {
    toast.add({ title: 'Lütfen mevcut şifrenizi girin', color: 'error' })
    return
  }

  disabling2FA.value = true
  try {
    await post('auth/2fa/disable', { password: disablePassword.value })
    twoFactorEnabled.value = false
    disableModalOpen.value = false
    disablePassword.value = ''
    await fetchMe()
    toast.add({ title: '2FA devre disi birakildi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || '2FA devre disi birakilamadi', color: 'error' })
  } finally {
    disabling2FA.value = false
  }
}

function cancelSetup() {
  setupStep.value = 'idle'
  setupData.secret = ''
  setupData.otpauthUrl = ''
  verificationCode.value = ''
}

const qrCodeDataUrl = ref('')

watch(() => setupData.otpauthUrl, async (url) => {
  if (!url) { qrCodeDataUrl.value = ''; return }
  const QRCode = await import('qrcode')
  qrCodeDataUrl.value = await QRCode.toDataURL(url, { width: 200, margin: 2 })
})
</script>

<template>
  <div class="space-y-4">
    <!-- Başlık -->
    <div>
      <h3 class="font-semibold">Güvenlik</h3>
      <p class="text-xs text-muted">Şifre ve iki adımli doğrulama ayarlarinizi yonetin.</p>
    </div>

    <!-- Şifre Değiştirme -->
    <UCard>
      <template #header>
        <div>
          <h3 class="font-semibold">Şifre Değiştirme</h3>
          <p class="text-xs text-muted">Hesabinizin şifresini güncelleyin.</p>
        </div>
      </template>

      <UForm :schema="passwordSchema" :state="passwordForm" @submit="changePassword" class="space-y-4 max-w-sm">
        <UFormField label="Mevcut Şifre" name="currentPassword">
          <UInput v-model="passwordForm.currentPassword" type="password" class="w-full" />
        </UFormField>

        <UFormField label="Yeni Şifre" name="newPassword">
          <UInput v-model="passwordForm.newPassword" type="password" class="w-full" />
        </UFormField>

        <UFormField label="Yeni Şifre (Tekrar)" name="confirmPassword">
          <UInput v-model="passwordForm.confirmPassword" type="password" class="w-full" />
        </UFormField>

        <div class="flex justify-end">
          <UButton
            label="Şifreyi Değiştir"
            icon="i-lucide-lock"
            :loading="changingPassword"
            type="submit"
          />
        </div>
      </UForm>
    </UCard>

    <!-- İki Adimli Doğrulama -->
    <UCard>
      <template #header>
        <div class="flex items-center justify-between">
          <div>
            <h3 class="font-semibold">İki Adimli Doğrulama (2FA)</h3>
            <p class="text-xs text-muted">Hesabınıza ekstra bir güvenlik katmani ekleyin.</p>
          </div>
          <UBadge v-if="twoFactorEnabled" color="success" variant="solid">
            2FA Aktif
          </UBadge>
        </div>
      </template>

      <!-- 2FA Aktif Değil -->
      <div v-if="!twoFactorEnabled && setupStep === 'idle'">
        <p class="text-xs text-muted mb-4">
          İki adımli doğrulama, hesabınıza giriş yaparken şifrenize ek olarak
          telefonunuzdaki doğrulama uygulamasindan (Google Authenticator, Authy vb.)
          bir kod girmenizi gerektirir. Bu sayede şifreniz ele gecirilse bile
          hesabiniz güvenli kalir.
        </p>
        <UButton
          label="2FA Etkinlestir"
          icon="i-lucide-shield-check"
          :loading="settingUp2FA"
          @click="startSetup"
        />
      </div>

      <!-- QR Kod Kurulum -->
      <div v-else-if="setupStep === 'qr'" class="space-y-5">
        <p class="text-xs text-muted">
          Doğrulama uygulamanizla aşağıdaki QR kodu tarayin veya gizli anahtari manuel olarak girin.
        </p>

        <div class="flex flex-col items-center gap-4">
          <div class="border border-default rounded-lg p-3 bg-white">
            <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="2FA QR Code" width="200" height="200" />
          </div>

          <div class="text-center">
            <p class="text-xs text-muted mb-1">Manuel giriş için gizli anahtar:</p>
            <code class="text-sm font-mono bg-gray-100 dark:bg-gray-800 px-3 py-1.5 rounded select-all">
              {{ setupData.secret }}
            </code>
          </div>
        </div>

        <div class="max-w-xs mx-auto space-y-3">
          <div>
            <label class="text-sm font-medium mb-1.5 block">Doğrulama Kodu</label>
            <UInput
              v-model="verificationCode"
              placeholder="000000"
              class="w-full text-center"
              maxlength="6"
            />
          </div>
          <div class="flex gap-2">
            <UButton
              label="Vazgeç"
              color="neutral"
              variant="outline"
              class="flex-1"
              @click="cancelSetup"
            />
            <UButton
              label="Doğrula ve Etkinlestir"
              icon="i-lucide-check"
              class="flex-1"
              :loading="enabling2FA"
              @click="verifyAndEnable"
            />
          </div>
        </div>
      </div>

      <!-- Kurtarma Kodlari -->
      <div v-else-if="setupStep === 'recovery'" class="space-y-4">
        <div class="bg-warning/10 border border-warning/30 rounded-lg p-4">
          <div class="flex items-start gap-3">
            <UIcon name="i-lucide-triangle-alert" class="size-5 text-warning shrink-0 mt-0.5" />
            <div>
              <p class="font-medium text-sm">Bu kodlari güvenli bir yere kaydedin</p>
              <p class="text-xs text-muted mt-1">
                Doğrulama uygulamaniza erisemezseniz bu kurtarma kodlarini kullanarak giriş yapabilirsiniz.
                Her kod yalnizca bir kez kullanilabilir. Bu kodlari tekrar görüntüleyemezsiniz.
              </p>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2 max-w-sm">
          <code
            v-for="code in recoveryCodes"
            :key="code"
            class="text-sm font-mono bg-gray-100 dark:bg-gray-800 px-3 py-2 rounded text-center"
          >
            {{ code }}
          </code>
        </div>

        <div class="flex gap-2">
          <UButton
            label="Kodlari Kopyala"
            icon="i-lucide-copy"
            color="neutral"
            variant="outline"
            @click="copyRecoveryCodes"
          />
          <UButton
            label="Anladim, Kaydettim"
            icon="i-lucide-check"
            @click="closeRecovery"
          />
        </div>
      </div>

      <!-- 2FA Aktif -->
      <div v-else-if="twoFactorEnabled && setupStep === 'idle'">
        <p class="text-xs text-muted mb-4">
          İki adımli doğrulama hesabinizda aktif. Giriş yaparken doğrulama uygulamanizdan
          kod girmeniz gerekmektedir.
        </p>
        <UButton
          label="2FA Devre Disi Birak"
          icon="i-lucide-shield-off"
          color="error"
          variant="outline"
          @click="openDisableModal"
        />
      </div>
    </UCard>

    <!-- 2FA Devre Disi Birakma Modal -->
    <UModal :dismissible="false" v-model:open="disableModalOpen" title="2FA Devre Disi Birak">
      <template #body>
        <div class="space-y-4">
          <div class="flex items-start gap-3">
            <div class="size-10 rounded-full bg-error/10 flex items-center justify-center shrink-0">
              <UIcon name="i-lucide-shield-off" class="size-5 text-error" />
            </div>
            <div>
              <p class="font-medium">İki adımli doğrulamayi devre disi birakmak istediginize emin misiniz?</p>
              <p class="text-xs text-muted mt-1">Bu islem hesabinizin güvenligini azaltacaktir.</p>
            </div>
          </div>

          <div>
            <label class="text-sm font-medium mb-1.5 block">Mevcut Şifre</label>
            <UInput v-model="disablePassword" type="password" class="w-full" placeholder="Şifrenizi girin" />
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" @click="disableModalOpen = false" />
          <UButton
            label="Devre Disi Birak"
            color="error"
            icon="i-lucide-shield-off"
            :loading="disabling2FA"
            @click="disable2FA"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
