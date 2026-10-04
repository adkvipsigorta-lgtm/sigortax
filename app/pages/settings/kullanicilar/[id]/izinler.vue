<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

const route = useRoute()
const toast = useToast()
const { get, post, put } = useApi()

const userId = Number(route.params.id)

interface PermItem {
  key: string
  group: string
  groupLabel: string
  label: string
  allowed: boolean
  isOverride: boolean
}

interface UserInfo {
  id: number
  name: string
  email: string
  role: string
  branchId: number | null
  isActive: boolean
  isSalesRep: boolean
}

const userInfo = ref<UserInfo | null>(null)
const permData = ref<PermItem[]>([])
const loading = ref(true)
const saving = ref(false)
const savingProfile = ref(false)

// Profil düzenleme
const editingProfile = ref(false)
const profileForm = reactive({
  name: '', email: '', role: '', branchId: null as number | null,
  isActive: true, isSalesRep: true,
  personalPhone: '', companyPhone: '', personalEmail: '',
})
const branches = ref<{ id: number, name: string }[]>([])
const resettingPassword = ref(false)

const roleOptions = [
  { label: 'Acente Sahibi', value: 'admin' },
  { label: 'Personel', value: 'kullanici' },
  { label: 'Stajyer', value: 'stajer' },
]

function startEditProfile() {
  if (!userInfo.value) return
  profileForm.name = userInfo.value.name
  profileForm.email = userInfo.value.email
  profileForm.role = userInfo.value.role
  profileForm.branchId = userInfo.value.branchId
  profileForm.isActive = userInfo.value.isActive
  profileForm.isSalesRep = userInfo.value.isSalesRep
  profileForm.personalPhone = onboardingData.value?.personalPhone || ''
  profileForm.companyPhone = onboardingData.value?.companyPhone || ''
  profileForm.personalEmail = onboardingData.value?.personalEmail || ''
  editingProfile.value = true
}

function cancelEditProfile() {
  editingProfile.value = false
}

async function saveProfile() {
  savingProfile.value = true
  try {
    await put(`users/${userId}`, {
      name: profileForm.name,
      email: profileForm.email,
      role: profileForm.role,
      branchId: null,
      isActive: profileForm.isActive,
      isSalesRep: profileForm.role !== 'musteri' ? profileForm.isSalesRep : false,
      personalPhone: profileForm.personalPhone,
      companyPhone: profileForm.companyPhone,
      personalEmail: profileForm.personalEmail,
    })
    const wasPasified = userInfo.value!.isActive && !profileForm.isActive
    userInfo.value = { ...userInfo.value!, name: profileForm.name, email: profileForm.email, role: profileForm.role, branchId: profileForm.branchId, isActive: profileForm.isActive, isSalesRep: profileForm.isSalesRep }
    editingProfile.value = false

    // Pasife alındıysa tüm izinleri kapat ve kaydet
    if (wasPasified && permData.value.length) {
      permData.value.forEach(p => { p.allowed = false })
      try {
        const perms: Record<string, boolean> = {}
        for (const p of permData.value) { perms[p.key] = false }
        await put(`users/${userId}/permissions`, { permissions: perms })
        toast.add({ title: 'Kullanıcı pasife alındı ve tüm izinleri kapatıldı', color: 'success' })
      } catch {
        toast.add({ title: 'Kullanıcı güncellendi ancak izinler kapatılamadı', color: 'warning' })
      }
    } else {
      toast.add({ title: 'Kullanıcı bilgileri güncellendi', color: 'success' })
    }
  } catch (e: any) {
    toast.add({ title: e.message || 'Güncellenemedi', color: 'error' })
  } finally {
    savingProfile.value = false
  }
}

const groupIcons: Record<string, string> = {
  'Müşteriler': 'i-lucide-users', 'Poliçeler': 'i-lucide-file-text', 'Görev Takibi': 'i-lucide-check-square',
  'Kaçırılan Poliçeler': 'i-lucide-x-circle', 'Teklifler': 'i-lucide-tag', 'Mesajlar': 'i-lucide-message-square',
  'Raporlar': 'i-lucide-bar-chart-2', 'Satış Performansı': 'i-lucide-trending-up', 'Portföy': 'i-lucide-briefcase',
  'Lead Yönetimi': 'i-lucide-target', 'Personel / İK': 'i-lucide-id-card', 'Araçlar': 'i-lucide-wrench', 'Ayarlar': 'i-lucide-settings',
}
const groupDescriptions: Record<string, string> = {
  'Müşteriler': 'Müşteri kayıtlarını görüntüleme, ekleme, düzenleme ve silme yetkileri',
  'Poliçeler': 'Poliçe kayıtlarını görüntüleme, ekleme, düzenleme, silme ve geçmiş ay düzenleme yetkileri',
  'Görev Takibi': 'Yenileme, teklif ve takip görevlerini görüntüleme ve yönetme yetkileri',
  'Kaçırılan Poliçeler': 'Yenilenmeyen poliçeleri görüntüleme ve geri kazanım sürecini yönetme',
  'Teklifler': 'Teklif oluşturma ve takip etme yetkileri',
  'Mesajlar': 'SMS ve bildirim mesajlarını görüntüleme ve gönderme yetkileri',
  'Raporlar': 'Satış ve performans raporlarını görüntüleme yetkisi',
  'Satış Performansı': 'Aylık satış performansı ekranını görüntüleme yetkisi',
  'Portföy': 'Kişisel portföy bilgilerini görüntüleme yetkisi',
  'Lead Yönetimi': 'Lead kayıtlarını görüntüleme, ekleme, atama ve ayar yönetimi yetkileri',
  'Personel / İK': 'Personel bilgileri ve izin yönetimi yetkileri',
  'Araçlar': 'Excel/Allianz import, mutabakat ve çapraz satış araçlarına erişim',
  'Ayarlar': 'Sistem ayarları, kullanıcı yönetimi, şirketler ve sigorta türleri yetkileri',
}
const roleLabels: Record<string, string> = { admin: 'Acente Sahibi', kullanici: 'Personel', stajer: 'Stajyer' }
const roleColors: Record<string, string> = { admin: 'error', kullanici: 'primary', stajer: 'warning' }

const permGroups = computed(() => {
  const groups: Record<string, { key: string, label: string, icon: string, description: string, items: PermItem[] }> = {}
  for (const p of permData.value) {
    if (!groups[p.group]) { groups[p.group] = { key: p.group, label: p.groupLabel, icon: groupIcons[p.groupLabel] || 'i-lucide-shield', description: groupDescriptions[p.groupLabel] || '', items: [] } }
    groups[p.group].items.push(p)
  }
  return Object.values(groups)
})

// Onboarding bilgileri
const onboardingData = ref<any>(null)
const onboardingAgreements = ref<any[]>([])
const identityFrontUrl = ref('')
const identityBackUrl = ref('')

function loadOnboardingFromUser(userData: any) {
  if (!userData) return
  onboardingData.value = userData
  onboardingAgreements.value = userData.agreements || []
  if (userData.identityFront) {
    identityFrontUrl.value = `/api/onboarding/identity/${userId}/front`
  }
  if (userData.identityBack) {
    identityBackUrl.value = `/api/onboarding/identity/${userId}/back`
  }
}

async function resetPasswordAndSendSms() {
  resettingPassword.value = true
  try {
    const res = await post(`users/${userId}/reset-password`, {})
    toast.add({ title: res.data?.message || 'Şifre sıfırlandı ve SMS gönderildi', color: 'success' })
    // Kullanıcı bilgilerini yeniden yükle
    const uRes = await get<any>(`users/${userId}`)
    userInfo.value = uRes.data
    loadOnboardingFromUser(uRes.data)
  } catch (e: any) {
    toast.add({ title: e.message || 'Şifre sıfırlanamadı', color: 'error' })
  }
  resettingPassword.value = false
}

const initials = computed(() => { if (!userInfo.value) return ''; return userInfo.value.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) })
const activeCount = computed(() => permData.value.filter(p => p.allowed).length)
const totalCount = computed(() => permData.value.length)

function togglePerm(key: string) { const item = permData.value.find(p => p.key === key); if (item) item.allowed = !item.allowed }
function toggleGroup(groupKey: string, value: boolean) { permData.value.forEach(p => { if (p.group === groupKey) p.allowed = value }) }

async function save() {
  saving.value = true
  try {
    const perms: Record<string, boolean> = {}
    for (const p of permData.value) { perms[p.key] = p.allowed }
    await put(`users/${userId}/permissions`, { permissions: perms })
    toast.add({ title: 'İzinler kaydedildi', color: 'success' })
    const res = await get<any>(`users/${userId}/permissions`)
    permData.value = res.data || []
  } catch (e: any) {
    toast.add({ title: e.message || 'Kaydedilemedi', color: 'error' })
  } finally { saving.value = false }
}

onMounted(async () => {
  try {
    const [uRes, pRes, bRes] = await Promise.all([
      get<any>(`users/${userId}`),
      get<any>(`users/${userId}/permissions`),
      get<any>('branches?all=1')
    ])
    userInfo.value = uRes.data
    permData.value = pRes.data || []
    branches.value = bRes.data || []
    useSeoMeta({ title: `${uRes.data?.name} — Kullanıcı Detay` })
    loadOnboardingFromUser(uRes.data)
  } catch { toast.add({ title: 'Yüklenemedi', color: 'error' }) }
  finally { loading.value = false }
})
</script>

<template>
  <div class="space-y-4">
    <!-- Üst Bar -->
    <div class="flex items-center justify-between gap-3 pb-4 border-b border-default">
      <div class="flex items-center gap-3">
        <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost"  @click="navigateTo('/settings/kullanicilar')" />
        <div>
          <h1 class="text-2xl font-semibold">Kullanıcı Detay</h1>
          <p class="text-sm text-muted">{{ userInfo?.name }}</p>
        </div>
      </div>
      <UButton label="İzinleri Kaydet" icon="i-lucide-check"  :loading="saving" :disabled="loading" @click="save" />
    </div>

    <div v-if="loading" class="flex items-center justify-center py-24">
      <UIcon name="i-lucide-loader-2" class="size-8 animate-spin text-muted" />
    </div>

    <div v-else class="space-y-6">

    <!-- ÜST: 3'lü grid — Profil + Sözleşme + Kimlik -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Profil Kartı -->
      <UCard :ui="{ body: 'p-5' }">
        <div class="flex flex-col items-center text-center gap-3">
          <div class="size-16 rounded-full bg-primary/10 text-primary flex items-center justify-center text-xl font-bold">{{ initials }}</div>

          <!-- Görüntüleme modu -->
          <template v-if="!editingProfile">
            <div>
              <p class="text-sm font-medium">{{ userInfo?.name }}</p>
              <p class="text-xs text-muted mt-0.5">{{ userInfo?.email }}</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap justify-center">
              <UBadge :color="(roleColors[userInfo?.role || ''] as any)" variant="solid" size="sm">{{ roleLabels[userInfo?.role || ''] || userInfo?.role }}</UBadge>
              <UBadge :color="userInfo?.isActive ? 'success' : 'neutral'" variant="subtle" size="sm">{{ userInfo?.isActive ? 'Aktif' : 'Pasif' }}</UBadge>
            </div>
            <div v-if="onboardingData" class="w-full text-left space-y-1 text-xs">
              <div v-if="onboardingData.personalPhone" class="flex justify-between"><span class="text-muted">Kişisel Tel</span><span class="tabular-nums">{{ onboardingData.personalPhone }}</span></div>
              <div v-if="onboardingData.companyPhone" class="flex justify-between"><span class="text-muted">Şirket Tel</span><span class="tabular-nums">{{ onboardingData.companyPhone }}</span></div>
              <div v-if="onboardingData.personalEmail" class="flex justify-between"><span class="text-muted">Kişisel Mail</span><span class="text-xs">{{ onboardingData.personalEmail }}</span></div>
            </div>
            <UButton label="Düzenle" icon="i-lucide-pencil" class="w-full" variant="outline" color="neutral" @click="startEditProfile" />
            <UButton label="Şifre Sıfırla + SMS Gönder" icon="i-lucide-key-round" class="w-full" variant="outline" color="warning" :loading="resettingPassword" @click="resetPasswordAndSendSms" />
          </template>

          <!-- Düzenleme modu -->
          <template v-else>
            <div class="w-full space-y-3 text-left">
              <div class="relative fl-form"><UInput v-model="profileForm.name" placeholder=" " class="w-full peer/fl-pname" /><label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-pname:top-0 peer-focus-within/fl-pname:-translate-y-1/2 peer-focus-within/fl-pname:text-xs peer-focus-within/fl-pname:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-pname:top-0 peer-has-[input:not(:placeholder-shown)]/fl-pname:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-pname:text-xs peer-has-[input:not(:placeholder-shown)]/fl-pname:text-[var(--ui-text-highlighted)]">Ad Soyad</label></div>
              <div class="relative fl-form"><UInput v-model="profileForm.email" type="email" placeholder=" " class="w-full peer/fl-pmail" data-no-uppercase /><label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-pmail:top-0 peer-focus-within/fl-pmail:-translate-y-1/2 peer-focus-within/fl-pmail:text-xs peer-focus-within/fl-pmail:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-pmail:top-0 peer-has-[input:not(:placeholder-shown)]/fl-pmail:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-pmail:text-xs peer-has-[input:not(:placeholder-shown)]/fl-pmail:text-[var(--ui-text-highlighted)]">E-posta</label></div>
              <div class="relative fl-select-form"><USelect v-model="profileForm.role" :items="roleOptions" value-key="value" placeholder=" " class="w-full" /><label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', profileForm.role ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">Rol</label></div>
              <div class="relative fl-form"><UInput v-model="profileForm.personalPhone" type="tel" placeholder=" " class="w-full peer/fl-pph" /><label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-pph:top-0 peer-focus-within/fl-pph:-translate-y-1/2 peer-focus-within/fl-pph:text-xs peer-focus-within/fl-pph:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-pph:top-0 peer-has-[input:not(:placeholder-shown)]/fl-pph:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-pph:text-xs peer-has-[input:not(:placeholder-shown)]/fl-pph:text-[var(--ui-text-highlighted)]">Kişisel Telefon</label></div>
              <div class="relative fl-form"><UInput v-model="profileForm.companyPhone" type="tel" placeholder=" " class="w-full peer/fl-cph" /><label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-cph:top-0 peer-focus-within/fl-cph:-translate-y-1/2 peer-focus-within/fl-cph:text-xs peer-focus-within/fl-cph:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-cph:top-0 peer-has-[input:not(:placeholder-shown)]/fl-cph:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-cph:text-xs peer-has-[input:not(:placeholder-shown)]/fl-cph:text-[var(--ui-text-highlighted)]">Şirket Telefonu</label></div>
              <div class="relative fl-form"><UInput v-model="profileForm.personalEmail" type="email" placeholder=" " class="w-full peer/fl-pem" /><label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-pem:top-0 peer-focus-within/fl-pem:-translate-y-1/2 peer-focus-within/fl-pem:text-xs peer-focus-within/fl-pem:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-pem:top-0 peer-has-[input:not(:placeholder-shown)]/fl-pem:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-pem:text-xs peer-has-[input:not(:placeholder-shown)]/fl-pem:text-[var(--ui-text-highlighted)]">Kişisel E-posta</label></div>
              <div class="flex items-center gap-4">
                <div class="flex items-center gap-2"><USwitch v-model="profileForm.isActive" size="xs" /><span class="text-xs">{{ profileForm.isActive ? 'Aktif' : 'Pasif' }}</span></div>
                <div class="flex items-center gap-2"><USwitch v-model="profileForm.isSalesRep" size="xs" /><span class="text-xs">Satış T.</span></div>
              </div>
              <div class="flex gap-2">
                <UButton label="İptal" color="neutral" variant="outline" class="flex-1" @click="cancelEditProfile" />
                <UButton label="Kaydet" icon="i-lucide-check" class="flex-1" :loading="savingProfile" @click="saveProfile" />
              </div>
            </div>
          </template>
        </div>
      </UCard>

      <!-- Sözleşme Durumu -->
      <UCard :ui="{ body: 'p-4' }">
        <template #header>
          <div class="flex items-center gap-2">
            <UIcon name="i-lucide-file-signature" class="size-4 text-primary" />
            <span class="text-sm font-semibold">Sözleşme ve Onay Durumu</span>
          </div>
        </template>
        <div v-if="onboardingData" class="space-y-2.5 text-sm">
          <!-- Onboarding adımları -->
          <div class="flex items-center justify-between">
            <span>Profil</span>
            <UIcon :name="onboardingData.tcNo && onboardingData.birthDate && onboardingData.address ? 'i-lucide-check-circle' : 'i-lucide-circle-x'" :class="onboardingData.tcNo && onboardingData.birthDate && onboardingData.address ? 'text-green-600' : 'text-red-500'" class="size-4" />
          </div>
          <div class="flex items-center justify-between">
            <span>KVKK</span>
            <UIcon :name="onboardingAgreements.some((a: any) => a.type === 'KVKK' && a.smsVerified) ? 'i-lucide-check-circle' : 'i-lucide-circle-x'" :class="onboardingAgreements.some((a: any) => a.type === 'KVKK' && a.smsVerified) ? 'text-green-600' : 'text-red-500'" class="size-4" />
          </div>
          <div class="flex items-center justify-between">
            <span>Kimlik</span>
            <UIcon :name="onboardingData.identityFront && onboardingData.identityBack ? 'i-lucide-check-circle' : 'i-lucide-circle-x'" :class="onboardingData.identityFront && onboardingData.identityBack ? 'text-green-600' : 'text-red-500'" class="size-4" />
          </div>
          <div class="flex items-center justify-between">
            <span>Taahhütname</span>
            <UIcon :name="onboardingAgreements.some((a: any) => a.type === 'COMMITMENT' && a.smsVerified) ? 'i-lucide-check-circle' : 'i-lucide-circle-x'" :class="onboardingAgreements.some((a: any) => a.type === 'COMMITMENT' && a.smsVerified) ? 'text-green-600' : 'text-red-500'" class="size-4" />
          </div>
          <!-- Onay detayları -->
          <template v-for="agr in onboardingAgreements" :key="agr.type + agr.version">
            <div class="border-t border-default pt-2">
              <div class="flex items-center justify-between">
                <span class="font-medium text-xs">{{ agr.type === 'KVKK' ? 'KVKK Metni' : 'Taahhütname' }}</span>
                <UBadge :color="agr.smsVerified ? 'success' : 'warning'" variant="subtle" size="xs">{{ agr.smsVerified ? 'Onaylandı' : 'Bekleniyor' }}</UBadge>
              </div>
              <div class="mt-1 text-xs text-muted space-y-0.5">
                <p v-if="agr.acceptedAt">Onay: {{ agr.acceptedAt }}</p>
                <p v-if="agr.ipAddress">IP: {{ agr.ipAddress }}</p>
                <p v-if="agr.smsPhone">SMS: {{ agr.smsPhone }}</p>
              </div>
            </div>
          </template>
          <!-- Aktif İzinler -->
          <div class="border-t border-default pt-2.5">
            <div class="flex items-center justify-between text-xs mb-1.5">
              <span>Aktif İzinler</span>
              <span class="font-medium">{{ activeCount }} / {{ totalCount }}</span>
            </div>
            <div class="w-full bg-neutral-100 rounded-full h-1.5">
              <div class="bg-primary rounded-full h-1.5 transition-all duration-300" :style="{ width: totalCount ? `${(activeCount / totalCount) * 100}%` : '0%' }" />
            </div>
          </div>
        </div>
      </UCard>

      <!-- Kimlik Görselleri -->
      <UCard :ui="{ body: 'p-4' }">
        <template #header>
          <div class="flex items-center gap-2">
            <UIcon name="i-lucide-id-card" class="size-4 text-primary" />
            <span class="text-sm font-semibold">Kimlik Belgeleri</span>
          </div>
        </template>
        <div class="grid grid-cols-2 gap-3">
          <div class="text-center">
            <p class="text-xs text-muted mb-1">Ön Yüz</p>
            <div v-if="identityFrontUrl" class="border border-default rounded-lg overflow-hidden"><img :src="identityFrontUrl" alt="Ön Yüz" class="w-full h-auto" /></div>
            <p v-else class="text-xs text-muted py-6 bg-gray-50 dark:bg-gray-800/50 rounded-lg">Yüklenmedi</p>
          </div>
          <div class="text-center">
            <p class="text-xs text-muted mb-1">Arka Yüz</p>
            <div v-if="identityBackUrl" class="border border-default rounded-lg overflow-hidden"><img :src="identityBackUrl" alt="Arka Yüz" class="w-full h-auto" /></div>
            <p v-else class="text-xs text-muted py-6 bg-gray-50 dark:bg-gray-800/50 rounded-lg">Yüklenmedi</p>
          </div>
        </div>
      </UCard>
    </div>

    <!-- ALT: İzin Grupları — 3'lü grid -->
    <div>
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold">İzin Yönetimi</h2>
        <UButton label="İzinleri Kaydet" icon="i-lucide-check" :loading="saving" :disabled="loading" @click="save" />
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
        <UCard v-for="group in permGroups" :key="group.key" :ui="{ body: 'p-4' }">
          <div class="mb-3">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <div class="size-7 rounded-md bg-primary/10 text-primary flex items-center justify-center"><UIcon :name="group.icon" class="size-3.5" /></div>
                <span class="text-sm">{{ group.label }}</span>
              </div>
              <UButton size="xs" color="neutral" variant="ghost" class="text-xs text-muted" :label="group.items.every(p => p.allowed) ? 'Tümünü Kapat' : 'Tümünü Aç'" @click="toggleGroup(group.key, !group.items.every(p => p.allowed))" />
            </div>
            <p v-if="group.description" class="text-[11px] text-muted mt-1 ml-9">{{ group.description }}</p>
          </div>
          <div class="space-y-1">
            <div v-for="p in group.items" :key="p.key" class="flex items-center justify-between py-1.5 px-2 rounded-md hover:bg-neutral-50 cursor-pointer" @click="togglePerm(p.key)">
              <div class="flex items-center gap-2">
                <span class="text-sm select-none">{{ p.label }}</span>
                <UBadge v-if="p.isOverride" color="warning" variant="subtle" size="xs">Özel</UBadge>
              </div>
              <USwitch :model-value="p.allowed" size="sm" @click.stop @update:model-value="togglePerm(p.key)" />
            </div>
          </div>
        </UCard>
      </div>
    </div>

    </div>
  </div>
</template>

<style scoped>
</style>
