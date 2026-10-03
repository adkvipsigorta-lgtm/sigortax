<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-xl">Günlük Aktivite</h1>
      <p class="text-sm text-muted mt-1">Günlük poliçe takibi ve eksik alan kontrolü.</p>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <!-- Poliçe -->
      <UCard :ui="{ body: 'p-3' }" class="hidden sm:block">
        <p class="text-xs font-semibold tracking-wide uppercase text-muted mb-3">Toplam Poliçe</p>
        <p class="text-2xl font-bold tabular-nums">{{ policies.length }}</p>
      </UCard>

      <!-- Prim Özeti -->
      <UCard :ui="{ body: 'p-3' }">
        <p class="text-xs font-semibold tracking-wide uppercase text-muted mb-3">Prim Özeti</p>
        <div class="flex flex-col gap-1.5 sm:grid sm:grid-cols-3 sm:gap-2">
          <div class="flex items-center justify-between sm:block">
            <p class="text-[10px] text-muted sm:mb-1">Brüt Prim</p>
            <p class="text-sm font-bold text-green-600 dark:text-green-400 tabular-nums leading-tight">{{ formatCurrency(totalGross) }}</p>
          </div>
          <div class="flex items-center justify-between sm:block">
            <p class="text-[10px] text-muted sm:mb-1">Net Prim</p>
            <p class="text-sm font-semibold tabular-nums leading-tight">{{ formatCurrency(totalNet) }}</p>
          </div>
          <div class="flex items-center justify-between sm:block">
            <p class="text-[10px] text-muted sm:mb-1">K</p>
            <p class="text-sm font-semibold text-blue-600 dark:text-blue-400 tabular-nums leading-tight">{{ totalCommission ? formatCurrency(totalCommission) : '—' }}</p>
          </div>
        </div>
      </UCard>

      <!-- Eksik Alan -->
      <UCard :ui="{ body: 'p-3' }" class="hidden sm:block">
        <p class="text-xs font-semibold tracking-wide uppercase text-muted mb-3">Eksik Alan</p>
        <p class="text-2xl font-bold tabular-nums" :class="missing > 0 ? 'text-orange-500' : 'text-green-600 dark:text-green-400'">{{ missing }}</p>
      </UCard>
    </div>

    <!-- Table -->
    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-wrap items-center gap-2">
              <div class="flex items-center gap-1 border border-[var(--ui-border)] rounded-md px-2 h-[38px]">
                <UButton icon="i-lucide-chevron-left" size="xs" color="neutral" variant="ghost" @click="prevDay" />
                <input
                  v-model="selectedDate"
                  type="date"
                  class="flex-1 text-sm font-semibold bg-transparent border-0 outline-none cursor-pointer text-center w-[130px]"
                  @change="fetch"
                />
                <UButton icon="i-lucide-chevron-right" size="xs" color="neutral" variant="ghost" @click="nextDay" />
              </div>
              <UButton size="xl" color="neutral" variant="outline"  @click="goToday">Bugün</UButton>
              <UButton
                size="xl"
                :color="viewMode === 'month' ? 'primary' : 'neutral'"
                :variant="viewMode === 'month' ? 'solid' : 'outline'"
                
                @click="toggleMonthView"
              >
                Bu Ay
              </UButton>
              <div v-if="isAdmin" class="relative fl-select  w-[200px]">
                <USelect v-model="filterSoldBy" :items="[{ label: 'Tüm Temsilciler', value: 'all' }, ...userOptions]" placeholder=" " class="w-full" @change="fetch" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">Temsilci</label>
              </div>
        </div>
      </template>

      <div v-if="loading" class="py-4">
        <SkeletonTable :columns="9" />
      </div>

      <div v-else-if="policies.length === 0" class="text-center py-12 text-muted">
        <UIcon name="i-lucide-inbox" class="size-8 mx-auto mb-2" />
        <p class="text-sm">Bu tarihte poliçe bulunamadı.</p>
      </div>

      <div v-else class="border border-default rounded-lg overflow-hidden">
        <table class="text-xs w-full table-fixed">
          <thead class="sticky top-0 z-10">
            <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
              <th class="py-2 px-3 text-xs font-semibold tracking-wide text-muted text-left" style="width:13%">Ad/Soyad</th>
              <th class="py-2 px-3 text-xs font-semibold tracking-wide text-muted text-left hidden lg:table-cell" style="width:10%">Şirket</th>
              <th class="py-2 px-3 text-xs font-semibold tracking-wide text-muted text-left" style="width:10%">Poliçe Türü</th>
              <th class="py-2 px-3 text-xs font-semibold tracking-wide text-muted text-left hidden md:table-cell" style="width:9%">Üretim Yeri</th>
              <th class="py-2 px-3 text-xs font-semibold tracking-wide text-muted text-left hidden md:table-cell" style="width:9%">Tali Acente</th>
              <th class="py-2 px-3 text-xs font-semibold tracking-wide text-muted text-left hidden md:table-cell" style="width:9%">Satış Yapan</th>
              <th class="py-2 px-3 text-xs font-semibold tracking-wide text-muted text-right" style="width:7%">Prim</th>
              <th class="py-2 px-3 text-xs font-semibold tracking-wide text-muted text-left hidden md:table-cell" style="width:8%">İş Türü</th>
              <th class="py-2 px-3 text-xs font-semibold tracking-wide text-muted text-left hidden md:table-cell" style="width:9%">Kaynak</th>
              <th class="py-2 px-3 text-xs font-semibold tracking-wide text-muted text-center hidden md:table-cell" style="width:4%">PDF</th>
              <th class="py-2 px-3 text-xs font-semibold tracking-wide text-muted text-center" style="width:6%">Durum</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="p in policies"
              :key="p.id"
              class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors"
              :class="isMissing(p) ? 'bg-orange-50/50 dark:bg-orange-900/10' : ''"
            >
              <!-- Ad/Soyad -->
              <td class="py-2 px-3 overflow-hidden" style="max-width:0">
                <NuxtLink v-if="p.customerId" :to="`/musteriler/${p.customerId}`" class="text-primary hover:underline truncate block" :title="p.customerName || p.insuredName">
                  {{ p.customerName || p.insuredName || '-' }}
                </NuxtLink>
                <span v-else class="truncate block" :title="p.insuredName">{{ p.insuredName || '-' }}</span>
                <span class="text-muted truncate block">{{ p.customerIdentity }}</span>
              </td>

              <!-- Şirket -->
              <td class="py-2 px-3 hidden lg:table-cell">
                <span class="block">{{ p.companyName }}</span>
                <span class="text-muted block text-[10px]">{{ p.policyNo }}</span>
              </td>

              <!-- Poliçe Türü -->
              <td class="py-2 px-3 overflow-visible whitespace-nowrap">
                <span
                  v-if="p.insuranceName"
                  class="badge-cell"
                  :style="{ backgroundColor: toHex(p.insuranceColor) + '1a', color: toHex(p.insuranceColor) }"
                >
                  {{ p.insuranceName?.split(' ')[0] }}
                </span>
                <span v-else class="text-muted">-</span>
              </td>

              <!-- Üretim Yeri -->
              <td class="py-2 px-3 hidden md:table-cell">
                <template v-if="!p.isEditable">
                  <USelect
                    :model-value="p.productionType ?? undefined"
                    :items="prodOptions"
                    size="xs"
                    class="w-full"
                    disabled
                  />
                </template>
                <template v-else>
                  <USelect
                    :model-value="p.productionType ?? undefined"
                    :items="prodOptions"
                    size="xs"
                    class="w-full"
                    @update:model-value="val => updateField(p, 'productionType', val)"
                  />
                </template>
              </td>
              <!-- Tali Acente -->
              <td class="py-2 px-3 hidden md:table-cell">
                <template v-if="!p.isEditable">
                  <USelectMenu
                    v-if="p.productionType !== 'SELF' && p.branchId"
                    :model-value="branchOptions.find(b => b.value === p.branchId)"
                    :items="branchOptions"
                    size="xs"
                    class="w-full"
                    disabled
                  />
                  <span v-else class="text-muted">-</span>
                </template>
                <template v-else>
                  <template v-if="p.productionType !== 'SELF'">
                    <USelectMenu
                      :model-value="branchOptions.find(b => b.value === p.branchId)"
                      :items="branchOptions"
                      size="xs"
                      placeholder="Acente..."
                      searchable
                      :search-input="{ placeholder: 'Ara...' }"
                      class="w-full"
                      :ui="{ content: 'w-auto min-w-[200px]' }"
                      @update:model-value="val => updateField(p, 'branchId', val?.value)"
                    />
                  </template>
                  <span v-else class="text-muted">-</span>
                </template>
              </td>

              <!-- Satış Yapan -->
              <td class="py-2 px-3 hidden md:table-cell">
                <USelectMenu
                  v-if="!p.isEditable || (p.soldByLocked && p.soldBy)"
                  :model-value="userOptions.find(u => u.value === p.soldBy)"
                  :items="userOptions"
                  size="xs"
                  class="w-full"
                  disabled
                />
                <USelectMenu
                  v-else
                  :model-value="userOptions.find(u => u.value === p.soldBy)"
                  :items="userOptions"
                  size="xs"
                  class="w-full"
                  :class="!p.soldBy ? 'ring-1 ring-orange-400 rounded-lg' : ''"
                  placeholder="Seç..."
                  searchable
                  :search-input="{ placeholder: 'Ara...' }"
                  :ui="{ content: 'w-auto min-w-[200px]' }"
                  @update:model-value="val => updateField(p, 'soldBy', val?.value)"
                />
              </td>

              <!-- Prim -->
              <td class="py-2 px-3 text-right whitespace-nowrap">
                <div class="font-bold">{{ formatCurrency(p.grossPremium) }}</div>
                <div class="text-muted">{{ formatCurrency(p.netPremium) }}</div>
              </td>

              <!-- İş Türü -->
              <td class="py-2 px-3 hidden md:table-cell">
                <USelect
                  v-if="!p.isEditable || p.isCancelled"
                  :model-value="p.businessType ?? undefined"
                  :items="businessTypeOptions"
                  size="xs"
                  class="w-full"
                  disabled
                />
                <USelect
                  v-else
                  :model-value="p.businessType ?? undefined"
                  :items="businessTypeOptions"
                  size="xs"
                  class="w-full"
                  :class="!p.businessType ? 'ring-1 ring-orange-400 rounded-lg' : ''"
                  placeholder="Seç..."
                  @update:model-value="val => { updateField(p, 'businessType', val); p.referenceSource = null; p.referenceSourceName = null }"
                />
              </td>

              <!-- Kaynak -->
              <td class="py-2 px-3 hidden md:table-cell">
                <USelectMenu
                  v-if="!p.isEditable || p.isCancelled"
                  :model-value="getRefOptionsForPolicy(p).find(r => r.value === p.referenceSource)"
                  :items="getRefOptionsForPolicy(p)"
                  size="xs"
                  class="w-full"
                  disabled
                />
                <span v-else-if="!p.businessType" class="text-xs text-muted italic">Önce iş türü seçin</span>
                <USelectMenu
                  v-else
                  :model-value="getRefOptionsForPolicy(p).find(r => r.value === p.referenceSource)"
                  :items="getRefOptionsForPolicy(p)"
                  size="xs"
                  class="w-full"
                  :class="!p.referenceSource ? 'ring-1 ring-orange-400 rounded-lg' : ''"
                  placeholder="Seç..."
                  searchable
                  :search-input="{ placeholder: 'Ara...' }"
                  :ui="{ content: 'w-auto min-w-[220px]' }"
                  @update:model-value="val => updateField(p, 'referenceSource', val?.value)"
                />
              </td>

              <!-- PDF -->
              <td class="py-2 px-3 text-center hidden md:table-cell">
                <button
                  v-if="p.docCount > 0"
                  class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 text-[10px] font-semibold hover:bg-green-200 dark:hover:bg-green-900/50 transition-colors"
                  :title="`${p.docCount} belge yüklü — müşteri sayfasına git`"
                  @click="handleDocClick(p)"
                >
                  <UIcon name="i-lucide-file-check" class="size-3" />
                  {{ p.docCount }}
                </button>
                <UButton
                  v-else
                  icon="i-lucide-upload"
                  color="neutral"
                  variant="ghost"
                  size="2xs"
                  title="PDF yükle"
                  :loading="uploadingPolicyId === p.id"
                  @click="handleDocClick(p)"
                />
                <input
                  :ref="el => { if (el) fileInputRefs[p.id] = el as HTMLInputElement }"
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png"
                  class="hidden"
                  @change="onFileSelected(p, $event)"
                />
              </td>
              <!-- Durum -->
              <td class="py-2 px-1 text-center overflow-hidden">
                <span
                  class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md"
                  :class="p.status === 'ACTIVE' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : p.status === 'CANCELLED' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'"
                >
                  {{ p.status === 'ACTIVE' ? 'Aktif' : p.status === 'CANCELLED' ? 'İptal' : 'S. Dolmuş' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </UCard>

    <!-- Sol alt bildirim -->
    <Teleport to="body">
      <Transition name="slide-up">
        <div v-if="localToast" class="fixed bottom-4 left-4 z-[100] max-w-sm">
          <div class="flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg border border-default text-sm"
            :class="localToast.color === 'error' ? 'bg-red-50 dark:bg-red-950 text-red-700 dark:text-red-300' : localToast.color === 'neutral' ? 'bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300' : 'bg-green-50 dark:bg-green-950 text-green-700 dark:text-green-300'">
            <UIcon :name="localToast.color === 'error' ? 'i-lucide-circle-x' : localToast.color === 'neutral' ? 'i-lucide-undo-2' : 'i-lucide-circle-check'" class="size-4 shrink-0" />
            <span class="font-medium">{{ localToast.title }}</span>
            <UButton v-if="localToast.action" :label="localToast.action.label" size="xs" color="neutral" variant="outline" class="ml-auto shrink-0" @click="localToast.action.onClick(); localToast = null" />
            <button v-else class="ml-auto text-current opacity-50 hover:opacity-100 shrink-0" @click="localToast = null"><UIcon name="i-lucide-x" class="size-3.5" /></button>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
const { get, put } = useApi()
const { user } = useAuth()
const isAdmin = computed(() => user.value?.role === 'admin')

// Sol alt bildirim
const localToast = ref<{ title: string; color: string; action?: { label: string; onClick: () => void } } | null>(null)
let localToastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(opts: { title: string; color?: string; duration?: number; action?: { label: string; onClick: () => void } }) {
  if (localToastTimer) clearTimeout(localToastTimer)
  localToast.value = { title: opts.title, color: opts.color || 'success', action: opts.action }
  localToastTimer = setTimeout(() => { localToast.value = null }, opts.duration || 4000)
}

// --- PDF Yükleme ---
const fileInputRefs: Record<number, HTMLInputElement> = {}
const uploadingPolicyId = ref<number | null>(null)

function handleDocClick(policy: any) {
  if (policy.docCount > 0) {
    navigateTo(`/musteriler/${policy.customerId}`)
  } else {
    const input = fileInputRefs[policy.id]
    if (input) {
      input.value = ''
      input.click()
    }
  }
}

async function onFileSelected(policy: any, event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  uploadingPolicyId.value = policy.id
  try {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('policyId', String(policy.id))
    if (policy.customerId) fd.append('customerId', String(policy.customerId))
    const ext = file.name.split('.').pop() || 'pdf'
    if (policy.policyNo) fd.append('customName', policy.policyNo + '.' + ext)

    const tk = localStorage.getItem('auth_token') || ''
    const json = await $fetch('/api/documents', {
      method: 'POST',
      headers: { Authorization: `Bearer ${tk}` },
      body: fd
    }) as any

    if (json?.success) {
      policy.docCount = (policy.docCount || 0) + 1
      showToast({ title: 'Belge yüklendi', color: 'success' })
    } else {
      showToast({ title: json?.message || 'Yükleme başarısız', color: 'error' })
    }
  } catch (err: any) {
    console.error('[PDF Upload Error]', err)
    const msg = err?.data?.message || err?.message || 'Bilinmeyen hata'
    showToast({ title: 'Yükleme hatası: ' + msg, color: 'error' })
  }
  uploadingPolicyId.value = null
}

// --- State ---
const policies = ref<any[]>([])
const totalGross = ref(0)
const totalNet = ref(0)
const totalCommission = ref(0)
const missing = ref(0)
const loading = ref(false)

const today = new Date().toISOString().slice(0, 10)
const selectedDate = ref(today)
const filterSoldBy = ref<string | number>('all')
const viewMode = ref<'day' | 'month'>('day')

const users = ref<any[]>([])
const branches = ref<any[]>([])
const refSources = ref<any[]>([])

// --- Options ---
const userOptions = computed(() =>
  users.value.map(u => ({ label: u.name, value: u.id }))
)
const branchOptions = computed(() =>
  branches.value.map(b => ({ label: b.name, value: b.id }))
)
const businessTypeOptions = [
  { label: 'YENİ İŞ', value: 'NEW' },
  { label: 'YENİLEME', value: 'RENEWAL' },
]

function getRefOptionsForPolicy(policy: any) {
  const bt = policy.businessType
  if (!bt) return refSources.value.filter((r: any) => r.isActive).map((r: any) => ({ label: r.name, value: r.id }))
  if (bt === 'RENEWAL') {
    return refSources.value.filter((r: any) => r.isActive && (r.businessType === 'RENEWAL' || !r.businessType)).map((r: any) => ({ label: r.name, value: r.id }))
  }
  return refSources.value.filter((r: any) => r.isActive && (r.businessType === 'NEW' || !r.businessType)).map((r: any) => ({ label: r.name, value: r.id }))
}

const refOptions = computed(() =>
  refSources.value.filter((r: any) => r.isActive).map((r: any) => ({ label: r.name, value: r.id }))
)
const prodOptions = [
  { label: 'Acentem', value: 'SELF' },
  { label: 'Tali Gelen', value: 'INCOMING' },
  { label: 'Tali Giden', value: 'OUTGOING' },
]

// --- Fetch ---
async function fetch() {
  loading.value = true
  try {
    const q: Record<string, any> = {}
    if (viewMode.value === 'month') {
      q.month = selectedDate.value.slice(0, 7)
    } else {
      q.date = selectedDate.value
    }
    if (filterSoldBy.value !== 'all') q.soldBy = filterSoldBy.value
    const res = await get<any>('policies/daily', q)
    policies.value = res.data?.policies ?? []
    totalGross.value = res.data?.totalGross ?? 0
    totalNet.value = res.data?.totalNet ?? 0
    totalCommission.value = res.data?.totalCommission ?? 0
    missing.value = res.data?.missing ?? 0
  } catch (e: any) {
    showToast({ title: 'Yüklenemedi', color: 'error' })
  } finally {
    loading.value = false
  }
}

async function fetchDropdowns() {
  const [u, b, r] = await Promise.all([
    get<any>('users?dropdown=1'),
    get<any>('branches?all=1'),
    get<any>('reference-sources'),
  ])
  users.value = u.data || u || []
  branches.value = b.data || b || []
  refSources.value = r.data || r || []
}

// --- Inline edit ---
async function updateField(policy: any, field: string, value: any) {
  // Değişiklik öncesi tüm etkilenebilecek alanları kaydet
  const snapshot = {
    businessType:        policy.businessType,
    productionType:      policy.productionType,
    branchId:            policy.branchId,
    branchName:          policy.branchName,
    soldBy:              policy.soldBy,
    soldByName:          policy.soldByName,
    referenceSource:     policy.referenceSource,
    referenceSourceName: policy.referenceSourceName,
  }

  // Reaktif güncelle
  policy[field] = value
  if (field === 'soldBy') policy.soldByName = users.value.find(u => u.id === value)?.name ?? null
  if (field === 'branchId') policy.branchName = branches.value.find(b => b.id === value)?.name ?? null
  if (field === 'referenceSource') policy.referenceSourceName = refSources.value.find((r: any) => r.id === value)?.name ?? null
  if (field === 'productionType' && value === 'SELF') { policy.branchId = null; policy.branchName = null }
  if (field === 'businessType') { policy.referenceSource = null; policy.referenceSourceName = null }
  missing.value = policies.value.filter(p => !p.isCancelled && (!p.soldBy || !p.referenceSource || !p.businessType)).length

  // DB'ye gönderilecek veri (productionType SELF'e dönerse branchId de temizlenir)
  const payload: Record<string, any> = { [field]: value }
  if (field === 'productionType' && value === 'SELF') payload.branchId = null
  if (field === 'businessType') payload.referenceSource = null

  try {
    await put(`policies/${policy.id}`, payload)

    showToast({
      title: 'Kaydedildi',
      color: 'success',
      duration: 8000,
      action: {
        label: 'Geri Al',
        onClick: async () => {
          Object.assign(policy, snapshot)
          missing.value = policies.value.filter(p => !p.isCancelled && (!p.soldBy || !p.referenceSource || !p.businessType)).length

          const revertPayload: Record<string, any> = { [field]: snapshot[field as keyof typeof snapshot] }
          if (field === 'productionType') revertPayload.branchId = snapshot.branchId
          if (field === 'businessType') revertPayload.referenceSource = snapshot.referenceSource

          try {
            await put(`policies/${policy.id}`, revertPayload)
            showToast({ title: 'Geri alındı', color: 'neutral', duration: 3000 })
          } catch {
            policy[field] = value
            if (field === 'soldBy') policy.soldByName = users.value.find(u => u.id === value)?.name ?? null
            if (field === 'branchId') policy.branchName = branches.value.find(b => b.id === value)?.name ?? null
            if (field === 'referenceSource') policy.referenceSourceName = refSources.value.find(r => r.id === value)?.name ?? null
            if (field === 'productionType' && value === 'SELF') { policy.branchId = null; policy.branchName = null }
            missing.value = policies.value.filter(p => !p.isCancelled && (!p.soldBy || !p.referenceSource || !p.businessType)).length
            showToast({ title: 'Geri alma başarısız', color: 'error', duration: 4000 })
          }
        },
      },
    })
  } catch (e: any) {
    // Hata durumunda snapshot'tan geri yükle
    Object.assign(policy, snapshot)
    missing.value = policies.value.filter(p => !p.isCancelled && (!p.soldBy || !p.referenceSource || !p.businessType)).length
    showToast({ title: 'Güncelleme başarısız', color: 'error' })
  }
}

// --- Navigation ---
function prevDay() {
  const d = new Date(selectedDate.value)
  if (viewMode.value === 'month') {
    d.setMonth(d.getMonth() - 1)
  } else {
    d.setDate(d.getDate() - 1)
  }
  selectedDate.value = d.toISOString().slice(0, 10)
  fetch()
}
function nextDay() {
  const d = new Date(selectedDate.value)
  if (viewMode.value === 'month') {
    d.setMonth(d.getMonth() + 1)
  } else {
    d.setDate(d.getDate() + 1)
  }
  selectedDate.value = d.toISOString().slice(0, 10)
  fetch()
}
function goToday() {
  selectedDate.value = today
  viewMode.value = 'day'
  fetch()
}
function toggleMonthView() {
  viewMode.value = viewMode.value === 'month' ? 'day' : 'month'
  fetch()
}

// --- Helpers ---
function isMissing(p: any) {
  if (p.isCancelled) return false
  return !p.soldBy || !p.referenceSource
}
function formatDate(d?: string) {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
function formatDateShort(d?: string) {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: '2-digit' })
}
function formatCurrency(v: number) {
  return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)
}

const legacyColorMap: Record<string, string> = {
  primary: '#3b82f6', error: '#ef4444', success: '#22c55e',
  warning: '#f59e0b', info: '#8b5cf6', neutral: '#6b7280'
}
function toHex(color?: string): string {
  if (!color) return '#3b82f6'
  if (color.startsWith('#')) return color
  return legacyColorMap[color] || '#3b82f6'
}

function shortName(fullName?: string): string {
  if (!fullName) return ''
  const parts = fullName.trim().split(/\s+/)
  if (parts.length === 1) return parts[0].charAt(0).toLocaleUpperCase('tr') + parts[0].slice(1).toLocaleLowerCase('tr')
  const first = parts[0].charAt(0).toLocaleUpperCase('tr') + parts[0].slice(1).toLocaleLowerCase('tr')
  const lastInitial = parts[parts.length - 1].charAt(0).toLocaleUpperCase('tr')
  return first + ' ' + lastInitial + '.'
}
function getCustomerId(p: any) {
  return p.customerId ?? ''
}

// --- Init ---
onMounted(async () => {
  await fetchDropdowns()
  await fetch()
})
</script>

<style scoped>
.slide-up-enter-active, .slide-up-leave-active {
  transition: all 0.25s ease;
}
.slide-up-enter-from, .slide-up-leave-to {
  opacity: 0;
  transform: translateY(12px);
}

table td { overflow: hidden; text-overflow: clip; white-space: nowrap; }

.badge-cell {
  display: inline-block;
  width: 90px;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.4;
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: clip;
  vertical-align: middle;
}
.badge-error   { background: rgb(239 68 68 / 0.1);  color: #ef4444; }
.badge-warning { background: rgb(245 158 11 / 0.1); color: #f59e0b; }
.badge-info    { background: rgb(59 130 246 / 0.1); color: #3b82f6; }
.badge-success { background: rgb(34 197 94 / 0.1);  color: #22c55e; }
.badge-neutral { background: rgb(107 114 128 / 0.1); color: #6b7280; }
</style>
