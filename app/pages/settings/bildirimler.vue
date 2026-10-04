<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Bildirim Ayarları' })

const toast = useToast()
const { get, post } = useApi()
const { user } = useAuth()

const isAdmin = computed(() => user.value?.role === 'admin')
const allNotificationsDisabled = useState('all-notifs-disabled', () => false)
const loading = ref(true)
const savingUser = ref(false)
const savingAdmin = ref(false)

interface NotifType {
  key: string
  label: string
  description: string
  icon: string
  category: string
}

const notifTypes: NotifType[] = [
  { key: 'policy_renewal', label: 'Poliçe Yenileme', description: 'Poliçe bitiş tarihi yaklaştığında bildirim', icon: 'i-lucide-refresh-cw', category: 'poliçe' },
  { key: 'policy_created', label: 'Yeni Poliçe', description: 'Yeni poliçe oluşturulduğunda bildirim', icon: 'i-lucide-shield-check', category: 'poliçe' },
  { key: 'policy_cancelled', label: 'Poliçe İptali', description: 'Poliçe iptal edildiğinde bildirim', icon: 'i-lucide-shield-off', category: 'poliçe' },
  { key: 'policy_expiry', label: 'Poliçe Süresi Dolumu', description: 'Poliçe süresi sona erdiğinde bildirim', icon: 'i-lucide-clock', category: 'poliçe' },
  { key: 'task_assigned', label: 'Görev Ataması', description: 'Size yeni bir görev atandığında bildirim', icon: 'i-lucide-calendar-check', category: 'görev' },
  { key: 'task_deadline', label: 'Görev Tarihi Yaklaşma', description: 'Görev son tarihi yaklaştığında bildirim', icon: 'i-lucide-alarm-clock', category: 'görev' },
  { key: 'task_completed', label: 'Görev Tamamlandı', description: 'Atanan görev tamamlandığında bildirim', icon: 'i-lucide-check-circle', category: 'görev' },
  { key: 'customer_birthday', label: 'Müşteri Doğum Günü', description: 'Müşterinin doğum günü yaklaştığında bildirim', icon: 'i-lucide-cake', category: 'müşteri' },
  { key: 'customer_created', label: 'Yeni Müşteri', description: 'Yeni müşteri kaydedildiğinde bildirim', icon: 'i-lucide-user-plus', category: 'müşteri' },
  { key: 'offer_created', label: 'Yeni Teklif', description: 'Yeni teklif oluşturulduğunda bildirim', icon: 'i-lucide-file-text', category: 'teklif' },
  { key: 'offer_expiry', label: 'Teklif Süresi Dolumu', description: 'Teklif süresi yaklaştığında bildirim', icon: 'i-lucide-file-clock', category: 'teklif' },
  { key: 'system_update', label: 'Sistem Güncelleme', description: 'Sistem güncellemeleri ve duyurular', icon: 'i-lucide-megaphone', category: 'sistem' },
  { key: 'login_new_device', label: 'Yeni Cihaz Girişi', description: 'Hesabınıza yeni bir cihazdan giriş yapıldığında', icon: 'i-lucide-monitor-smartphone', category: 'sistem' },
]

const categories = [
  { key: 'poliçe', label: 'Poliçe Bildirimleri', icon: 'i-lucide-shield-check' },
  { key: 'görev', label: 'Görev Bildirimleri', icon: 'i-lucide-calendar' },
  { key: 'müşteri', label: 'Müşteri Bildirimleri', icon: 'i-lucide-users' },
  { key: 'teklif', label: 'Teklif Bildirimleri', icon: 'i-lucide-file-text' },
  { key: 'sistem', label: 'Sistem Bildirimleri', icon: 'i-lucide-settings' },
]

const userPrefs = reactive<Record<string, boolean>>({})
const adminSettings = reactive<Record<string, boolean>>({})

function initDefaults(target: Record<string, boolean>) {
  notifTypes.forEach(n => { if (target[n.key] === undefined) target[n.key] = true })
}

async function fetchAll() {
  loading.value = true
  try {
    const [prefsRes, optionsRes] = await Promise.all([get('auth/notification-preferences'), get('settings')])
    const prefsData = prefsRes.data || {}
    notifTypes.forEach(n => { userPrefs[n.key] = prefsData[n.key] !== undefined ? !!prefsData[n.key] : true })
    const globalData = optionsRes.data?.notification_settings || {}
    notifTypes.forEach(n => { adminSettings[n.key] = globalData[n.key] !== undefined ? !!globalData[n.key] : true })
  } catch (error: any) {
    toast.add({ title: error.message || 'Ayarlar yüklenemedi', color: 'error' })
    initDefaults(userPrefs)
    initDefaults(adminSettings)
  } finally { loading.value = false }
}

async function saveUserPrefs() {
  savingUser.value = true
  try {
    await post('auth/notification-preferences', { ...userPrefs })
    toast.add({ title: 'Bildirim tercihleriniz kaydedildi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Kaydedilemedi', color: 'error' })
  } finally { savingUser.value = false }
}

async function saveAdminSettings() {
  savingAdmin.value = true
  try {
    await post('settings', { notification_settings: { ...adminSettings } })
    const values = Object.values(adminSettings)
    allNotificationsDisabled.value = values.length > 0 && values.every(v => !v)
    toast.add({ title: 'Genel bildirim ayarları kaydedildi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Kaydedilemedi', color: 'error' })
  } finally { savingAdmin.value = false }
}

const isAcente = computed(() => user.value?.role === 'acente')
const visibleCategories = computed(() => {
  if (isAcente.value) return categories.filter(c => c.key === 'poliçe')
  return categories
})

function getTypesForCategory(catKey: string, onlyEnabled = false) {
  return notifTypes.filter(n => {
    if (n.category !== catKey) return false
    if (onlyEnabled && adminSettings[n.key] === false) return false
    return true
  })
}
function hasEnabledTypes(catKey: string) { return getTypesForCategory(catKey, true).length > 0 }

// Ses tercihi
const soundPref = ref('loop')
const soundOptions = [
  { label: 'Kapalı', value: 'off' },
  { label: '1 Defa Çal', value: 'önce' },
  { label: '2 Defa Çal', value: 'twice' },
  { label: 'Sürekli Çal', value: 'loop' },
]

const { setPref: setSoundPref } = useNotificationSound()

function testSound() {
  const a = new Audio('/notification.mp3')
  a.volume = 0.7
  a.play().then(() => { toast.add({ title: 'Ses çalıyor', color: 'success' }) })
    .catch((e: any) => { toast.add({ title: 'Ses çalınamadı: ' + e.message, color: 'error' }) })
}

async function saveSoundPref() {
  try {
    setSoundPref(soundPref.value)
    await post('auth/notification-preferences', { ...userPrefs, sound_pref: soundPref.value })
    toast.add({ title: 'Ses tercihi kaydedildi', color: 'success' })
  } catch {}
}

// Tab
const activeTab = ref('user')

onMounted(async () => {
  await fetchAll()
  try {
    const res = await get('auth/notification-preferences')
    soundPref.value = res.data?.sound_pref || 'loop'
  } catch {}
  if (!isAdmin.value && allNotificationsDisabled.value) navigateTo('/settings')
})
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <!-- Sayfa Başlığı -->
    <div class="mb-6 pb-4 border-b border-default">
      <h1 class="text-2xl font-semibold">Bildirim Ayarları</h1>
      <p class="text-sm text-muted mt-1">Hangi bildirimleri almak istediğinizi seçin.</p>
    </div>

    <!-- Ses Tercihi -->
    <UCard class="mb-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <div class="size-10 rounded-lg bg-orange-50 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-volume-2" class="size-5 text-orange-500" />
          </div>
          <div>
            <p class="font-medium text-sm">Bildirim Sesi</p>
            <p class="text-xs text-muted">Yeni bildirim geldiğinde ses çalma tercihi</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <UButton icon="i-lucide-play" color="neutral" variant="outline" label="Test"  @click="testSound" />
          <USelect v-model="soundPref" :items="soundOptions" value-key="value" label-key="label" class="w-44" @update:model-value="saveSoundPref()" />
        </div>
      </div>
    </UCard>

    <!-- Loading -->
    <div v-if="loading" class="space-y-4">
      <SkeletonCard v-for="i in 4" :key="i">
        <div class="flex items-center justify-between">
          <div class="space-y-2">
            <div class="h-4 bg-neutral-200 rounded w-40" />
            <div class="h-3 bg-neutral-200 rounded w-56" />
          </div>
          <div class="h-6 w-10 bg-neutral-200 rounded-full" />
        </div>
      </SkeletonCard>
    </div>

    <template v-else>
      <!-- Tab seçimi (admin ise) -->
      <div v-if="isAdmin" class="flex gap-2 mb-6">
        <UButton
          label="Kişisel Tercihlerim"
          :color="activeTab === 'user' ? 'primary' : 'neutral'"
          :variant="activeTab === 'user' ? 'solid' : 'outline'"
          icon="i-lucide-user"
          
          @click="activeTab = 'user'"
        />
        <UButton
          label="Genel Bildirim Ayarları"
          :color="activeTab === 'admin' ? 'primary' : 'neutral'"
          :variant="activeTab === 'admin' ? 'solid' : 'outline'"
          icon="i-lucide-settings"
          
          @click="activeTab = 'admin'"
        />
      </div>

      <!-- ═══ KİŞİSEL TERCİHLER ═══ -->
      <template v-if="activeTab === 'user'">
        <div class="flex items-center justify-between mb-4">
          <p class="text-sm text-muted">Almak istediğiniz bildirimleri açıp kapatabilirsiniz.</p>
          <UButton label="Kaydet"  icon="i-lucide-check" :loading="savingUser" @click="saveUserPrefs" />
        </div>

        <div class="space-y-4">
          <UCard v-for="cat in visibleCategories" v-show="hasEnabledTypes(cat.key)" :key="cat.key">
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon :name="cat.icon" class="size-4 text-primary" />
                <h3 class="text-sm">{{ cat.label }}</h3>
              </div>
            </template>

            <div class="space-y-0 divide-y divide-default">
              <div v-for="nt in getTypesForCategory(cat.key, true)" :key="nt.key" class="flex items-center justify-between py-3 first:pt-0 last:pb-0">
                <div class="flex items-start gap-3 min-w-0">
                  <UIcon :name="nt.icon" class="size-4 text-muted mt-0.5 shrink-0" />
                  <div class="min-w-0">
                    <p class="text-sm font-medium">{{ nt.label }}</p>
                    <p class="text-xs text-muted">{{ nt.description }}</p>
                  </div>
                </div>
                <USwitch v-model="userPrefs[nt.key]" size="xs" class="shrink-0 ml-3" />
              </div>
            </div>
          </UCard>
        </div>
      </template>

      <!-- ═══ ADMİN GENEL AYARLAR ═══ -->
      <template v-if="activeTab === 'admin' && isAdmin">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
          <div>
            <p class="text-sm text-muted">Tüm kullanıcılar için bildirimleri açıp kapatabilirsiniz.</p>
            <p class="text-xs text-warning-600 mt-0.5">Kapatılan bildirimler hiçbir kullanıcıya gönderilmez.</p>
          </div>
          <UButton label="Kaydet" class="shrink-0" icon="i-lucide-check" :loading="savingAdmin" @click="saveAdminSettings" />
        </div>

        <div class="space-y-4">
          <UCard v-for="cat in categories" :key="cat.key">
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon :name="cat.icon" class="size-4 text-primary" />
                <h3 class="text-sm">{{ cat.label }}</h3>
              </div>
            </template>

            <div class="space-y-0 divide-y divide-default">
              <div v-for="nt in getTypesForCategory(cat.key)" :key="nt.key" class="flex items-center justify-between py-3 first:pt-0 last:pb-0">
                <div class="flex items-start gap-3 min-w-0">
                  <UIcon :name="nt.icon" class="size-4 text-muted mt-0.5 shrink-0" />
                  <div class="min-w-0">
                    <p class="text-sm font-medium">{{ nt.label }}</p>
                    <p class="text-xs text-muted">{{ nt.description }}</p>
                  </div>
                </div>
                <USwitch v-model="adminSettings[nt.key]" size="xs" class="shrink-0 ml-3" />
              </div>
            </div>
          </UCard>
        </div>
      </template>
    </template>
  </div>
</template>
