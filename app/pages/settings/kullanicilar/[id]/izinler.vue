<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

const route = useRoute()
const toast = useToast()
const { get, put } = useApi()

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
  isActive: boolean
}

const userInfo = ref<UserInfo | null>(null)
const permData = ref<PermItem[]>([])
const loading = ref(true)
const saving = ref(false)

const groupIcons: Record<string, string> = {
  'Müşteriler':            'i-lucide-users',
  'Poliçeler':             'i-lucide-file-text',
  'Görev Takibi':          'i-lucide-check-square',
  'Kaçırılan Poliçeler':   'i-lucide-x-circle',
  'Teklifler':             'i-lucide-tag',
  'Mesajlar':              'i-lucide-message-square',
  'Raporlar':              'i-lucide-bar-chart-2',
  'Satış Performansı':     'i-lucide-trending-up',
  'Portföy':               'i-lucide-briefcase',
  'Lead Yönetimi':         'i-lucide-target',
  'Personel / İK':         'i-lucide-id-card',
  'Araçlar':               'i-lucide-wrench',
  'Ayarlar':               'i-lucide-settings',
}

const groupDescriptions: Record<string, string> = {
  'Müşteriler':            'Müşteri kayıtlarını görüntüleme, ekleme, düzenleme ve silme yetkileri',
  'Poliçeler':             'Poliçe kayıtlarını görüntüleme, ekleme, düzenleme, silme ve geçmiş ay düzenleme yetkileri',
  'Görev Takibi':          'Yenileme, teklif ve takip görevlerini görüntüleme ve yönetme yetkileri',
  'Kaçırılan Poliçeler':   'Yenilenmeyen poliçeleri görüntüleme ve geri kazanım sürecini yönetme',
  'Teklifler':             'Teklif oluşturma ve takip etme yetkileri',
  'Mesajlar':              'SMS ve bildirim mesajlarını görüntüleme ve gönderme yetkileri',
  'Raporlar':              'Satış ve performans raporlarını görüntüleme yetkisi',
  'Satış Performansı':     'Aylık satış performansı ekranını görüntüleme yetkisi',
  'Portföy':               'Kişisel portföy bilgilerini görüntüleme yetkisi',
  'Lead Yönetimi':         'Lead kayıtlarını görüntüleme, ekleme, atama ve ayar yönetimi yetkileri',
  'Personel / İK':         'Personel bilgileri ve izin yönetimi yetkileri',
  'Araçlar':               'Excel/Allianz import, mutabakat ve çapraz satış araçlarına erişim',
  'Ayarlar':               'Sistem ayarları, kullanıcı yönetimi, şirketler ve sigorta türleri yetkileri',
}

const roleLabels: Record<string, string> = {
  admin:     'Yönetici',
  acente:    'Acente',
  kullanıcı: 'Kullanıcı'
}

const roleColors: Record<string, string> = {
  admin:     'error',
  acente:    'warning',
  kullanıcı: 'info'
}

const permGroups = computed(() => {
  const groups: Record<string, { key: string, label: string, icon: string, description: string, items: PermItem[] }> = {}
  for (const p of permData.value) {
    if (!groups[p.group]) {
      groups[p.group] = {
        key: p.group,
        label: p.groupLabel,
        icon: groupIcons[p.groupLabel] || 'i-lucide-shield',
        description: groupDescriptions[p.groupLabel] || '',
        items: []
      }
    }
    groups[p.group].items.push(p)
  }
  return Object.values(groups)
})

const initials = computed(() => {
  if (!userInfo.value) return ''
  return userInfo.value.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
})

const activeCount = computed(() => permData.value.filter(p => p.allowed).length)
const totalCount = computed(() => permData.value.length)

function togglePerm(key: string) {
  const item = permData.value.find(p => p.key === key)
  if (item) item.allowed = !item.allowed
}

function toggleGroup(groupKey: string, value: boolean) {
  permData.value.forEach(p => {
    if (p.group === groupKey) p.allowed = value
  })
}

async function save() {
  saving.value = true
  try {
    const perms: Record<string, boolean> = {}
    for (const p of permData.value) {
      perms[p.key] = p.allowed
    }
    await put(`users/${userId}/permissions`, { permissions: perms })
    toast.add({ title: 'İzinler kaydedildi', color: 'success' })
    // isOverride bilgisini yenile
    const res = await get<any>(`users/${userId}/permissions`)
    permData.value = res.data || []
  } catch (e: any) {
    toast.add({ title: e.message || 'Kaydedilemedi', color: 'error' })
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    const [uRes, pRes] = await Promise.all([
      get<any>(`users/${userId}`),
      get<any>(`users/${userId}/permissions`)
    ])
    userInfo.value = uRes.data
    permData.value = pRes.data || []
    useSeoMeta({ title: `${uRes.data?.name} — İzinler` })
  } catch (e: any) {
    toast.add({ title: 'Yüklenemedi', color: 'error' })
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-4">

    <!-- Üst Bar -->
    <div class="flex items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <UButton
          icon="i-lucide-arrow-left"
          color="neutral"
          variant="ghost"
          size="sm"
          @click="navigateTo('/settings/kullanicilar')"
        />
        <div>
          <h1 class="text-base font-semibold">İzin Yönetimi</h1>
          <p class="text-xs text-muted">{{ userInfo?.name }}</p>
        </div>
      </div>
      <UButton
        label="Kaydet"
        icon="i-lucide-check"
        size="sm"
        :loading="saving"
        :disabled="loading"
        @click="save"
      />
    </div>

    <div v-if="loading" class="flex items-center justify-center py-24">
      <UIcon name="i-lucide-loader-2" class="size-8 animate-spin text-muted" />
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-4 gap-4 items-start">

      <!-- Sol: Kullanıcı Kartı -->
      <UCard :ui="{ body: 'p-5' }" class="lg:col-span-1">
        <div class="flex flex-col items-center text-center gap-3">
          <div class="size-16 rounded-full bg-primary/10 text-primary flex items-center justify-center text-xl font-bold">
            {{ initials }}
          </div>
          <div>
            <p class="font-semibold text-sm">{{ userInfo?.name }}</p>
            <p class="text-xs text-muted mt-0.5">{{ userInfo?.email }}</p>
          </div>
          <div class="flex items-center gap-2 flex-wrap justify-center">
            <UBadge :color="(roleColors[userInfo?.role || ''] as any)" variant="solid" size="sm">
              {{ roleLabels[userInfo?.role || ''] || userInfo?.role }}
            </UBadge>
            <UBadge :color="userInfo?.isActive ? 'success' : 'neutral'" variant="subtle" size="sm">
              {{ userInfo?.isActive ? 'Aktif' : 'Pasif' }}
            </UBadge>
          </div>
          <div class="w-full pt-3 border-t border-default">
            <div class="flex items-center justify-between text-xs mb-2">
              <span class="text-muted">Aktif İzinler</span>
              <span class="font-semibold">{{ activeCount }} / {{ totalCount }}</span>
            </div>
            <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-1.5">
              <div
                class="bg-primary rounded-full h-1.5 transition-all duration-300"
                :style="{ width: totalCount ? `${(activeCount / totalCount) * 100}%` : '0%' }"
              />
            </div>
          </div>
        </div>
      </UCard>

      <!-- Sağ: İzin Grupları -->
      <div class="lg:col-span-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
        <UCard
          v-for="group in permGroups"
          :key="group.key"
          :ui="{ body: 'p-4' }"
        >
          <!-- Grup Başlığı -->
          <div class="mb-3">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <div class="size-7 rounded-md bg-primary/10 text-primary flex items-center justify-center">
                  <UIcon :name="group.icon" class="size-3.5" />
                </div>
                <span class="text-sm font-semibold">{{ group.label }}</span>
              </div>
              <UButton
                size="xs"
                color="neutral"
                variant="ghost"
                class="text-xs text-muted"
                :label="group.items.every(p => p.allowed) ? 'Tümünü Kapat' : 'Tümünü Aç'"
                @click="toggleGroup(group.key, !group.items.every(p => p.allowed))"
              />
            </div>
            <p v-if="group.description" class="text-[11px] text-muted mt-1 ml-9">{{ group.description }}</p>
          </div>

          <!-- İzin Satırları -->
          <div class="space-y-1">
            <div
              v-for="p in group.items"
              :key="p.key"
              class="flex items-center justify-between py-1.5 px-2 rounded-md hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer"
              @click="togglePerm(p.key)"
            >
              <div class="flex items-center gap-2">
                <span class="text-sm select-none">{{ p.label }}</span>
                <UBadge v-if="p.isOverride" color="warning" variant="subtle" size="xs">Özel</UBadge>
              </div>
              <USwitch
                :model-value="p.allowed"
                size="sm"
                @click.stop
                @update:model-value="togglePerm(p.key)"
              />
            </div>
          </div>
        </UCard>
      </div>
    </div>

  </div>
</template>
