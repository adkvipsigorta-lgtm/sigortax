<script setup lang="ts">
definePageMeta({ layout: 'default', middleware: 'auth' })

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { get, put, post, del } = useApi()

const id = computed(() => Number(route.params.id))
const loading = ref(true)
const saving = ref(false)
const activeTab = ref('ozluk')

// ==================== OZLUK ====================
const person = ref<any>(null)
const editMode = ref(false)
const form = reactive({
  phone: '',
  tcNo: '',
  birthDate: '',
  hireDate: '',
  terminationDate: '',
  position: '',
  address: '',
  emergencyContactName: '',
  emergencyContactPhone: '',
  emergencyContactRelation: '',
})

async function loadPerson() {
  loading.value = true
  try {
    const res = await get<any>(`personnel/${id.value}`)
    person.value = res.data
    useSeoMeta({ title: `${res.data.name} - Personel Kartı` })
    syncForm()
  } catch {
    toast.add({ title: 'Personel bulunamadı', color: 'error' })
    router.push('/personel')
  } finally {
    loading.value = false
  }
}

function syncForm() {
  if (!person.value) return
  form.phone = person.value.phone || ''
  form.tcNo = person.value.tcNo || ''
  form.birthDate = person.value.birthDate || ''
  form.hireDate = person.value.hireDate || ''
  form.terminationDate = person.value.terminationDate || ''
  form.position = person.value.position || ''
  form.address = person.value.address || ''
  form.emergencyContactName = person.value.emergencyContactName || ''
  form.emergencyContactPhone = person.value.emergencyContactPhone || ''
  form.emergencyContactRelation = person.value.emergencyContactRelation || ''
}

async function savePerson() {
  saving.value = true
  try {
    await put(`personnel/${id.value}`, { ...form })
    toast.add({ title: 'Bilgiler güncellendi', color: 'success' })
    editMode.value = false
    await loadPerson()
  } catch (e: any) {
    toast.add({ title: e.message || 'Güncelleme başarısız', color: 'error' })
  } finally {
    saving.value = false
  }
}

// ==================== IZIN ====================
const leaveBalances = ref<any[]>([])
const leaveRequests = ref<any[]>([])
const leaveTypes = ref<any[]>([])
const leaveLoading = ref(false)
const leaveModalOpen = ref(false)
const leaveForm = reactive({
  leaveTypeId: null as number | null,
  startDate: '',
  endDate: '',
  note: '',
})
const leaveSaving = ref(false)

async function loadLeaveData() {
  leaveLoading.value = true
  try {
    const [balRes, reqRes, typeRes] = await Promise.all([
      get<any>(`personnel/${id.value}/leaves`),
      get<any>('personnel/leave-requests', { userId: id.value, limit: 50 }),
      get<any>('personnel/leave-types'),
    ])
    leaveBalances.value = balRes.data
    leaveRequests.value = reqRes.data?.data || reqRes.data || []
    leaveTypes.value = typeRes.data
  } catch {} finally {
    leaveLoading.value = false
  }
}

async function submitLeave() {
  leaveSaving.value = true
  try {
    await post('personnel/leave-requests', {
      userId: id.value,
      leaveTypeId: leaveForm.leaveTypeId,
      startDate: leaveForm.startDate,
      endDate: leaveForm.endDate,
      note: leaveForm.note,
    })
    toast.add({ title: 'İzin talebi oluşturuldu', color: 'success' })
    leaveModalOpen.value = false
    leaveForm.leaveTypeId = null
    leaveForm.startDate = ''
    leaveForm.endDate = ''
    leaveForm.note = ''
    await loadLeaveData()
  } catch (e: any) {
    toast.add({ title: e.message || 'İzin talebi oluşturulamadı', color: 'error' })
  } finally {
    leaveSaving.value = false
  }
}

async function approveLeave(reqId: number) {
  try {
    await put(`personnel/leave-requests/${reqId}`, { status: 'APPROVED' })
    toast.add({ title: 'İzin onaylandı', color: 'success' })
    await loadLeaveData()
  } catch (e: any) {
    toast.add({ title: e.message || 'Onay başarısız', color: 'error' })
  }
}

async function rejectLeave(reqId: number) {
  try {
    await put(`personnel/leave-requests/${reqId}`, { status: 'REJECTED' })
    toast.add({ title: 'İzin reddedildi', color: 'warning' })
    await loadLeaveData()
  } catch (e: any) {
    toast.add({ title: e.message || 'Ret başarısız', color: 'error' })
  }
}

async function cancelLeave(reqId: number) {
  try {
    await put(`personnel/leave-requests/${reqId}`, { status: 'CANCELLED' })
    toast.add({ title: 'İzin iptal edildi', color: 'warning' })
    await loadLeaveData()
  } catch (e: any) {
    toast.add({ title: e.message || 'İptal başarısız', color: 'error' })
  }
}

const balanceEditId = ref<number | null>(null)
const balanceEditValue = ref<number>(0)
async function saveBalance(b: any) {
  try {
    await put(`personnel/${id.value}/leaves`, { leaveTypeId: b.leaveTypeId, totalDays: balanceEditValue.value })
    toast.add({ title: 'Bakiye güncellendi', color: 'success' })
    balanceEditId.value = null
    await loadLeaveData()
  } catch (e: any) {
    toast.add({ title: e.message || 'Güncelleme başarısız', color: 'error' })
  }
}

// ==================== PERFORMANS ====================
const performance = ref<any>(null)
const perfLoading = ref(false)
const perfYear = ref(new Date().getFullYear())

async function loadPerformance() {
  perfLoading.value = true
  try {
    const res = await get<any>(`personnel/${id.value}/performance`, { year: perfYear.value })
    performance.value = res.data
  } catch {} finally {
    perfLoading.value = false
  }
}

watch(perfYear, () => loadPerformance())

watch(activeTab, (tab) => {
  if (tab === 'izin' && !leaveBalances.value.length) loadLeaveData()
  if (tab === 'performans' && !performance.value) loadPerformance()
})

onMounted(() => { loadPerson() })

const roleLabels: Record<string, string> = { admin: 'Yönetici', acente: 'Acente', kullanici: 'Kullanıcı' }
const roleColors: Record<string, string> = { admin: 'error', acente: 'warning', kullanici: 'info' }

function formatDate(d: string | null) {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('tr-TR')
}

function formatCurrency(v: number) {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', minimumFractionDigits: 0 }).format(v)
}

const leaveStatusLabels: Record<string, string> = { PENDING: 'Bekliyor', APPROVED: 'Onaylandı', REJECTED: 'Reddedildi', CANCELLED: 'İptal' }
const leaveStatusColors: Record<string, string> = { PENDING: 'warning', APPROVED: 'success', REJECTED: 'error', CANCELLED: 'neutral' }

const monthNames = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık']

const leaveTypeOptions = computed(() =>
  leaveTypes.value.map(t => ({ label: t.name, value: t.id }))
)

const tabs = [
  { label: 'Özlük Bilgileri', value: 'ozluk', icon: 'i-lucide-user' },
  { label: 'İzin Yönetimi', value: 'izin', icon: 'i-lucide-calendar-days' },
  { label: 'Performans', value: 'performans', icon: 'i-lucide-bar-chart-3' },
]
</script>

<template>
  <div class="space-y-4">
    <!-- Header -->
    <div class="flex items-center gap-3">
      <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" size="sm" @click="router.push('/personel')" />
      <div v-if="person" class="flex items-center gap-3">
        <div class="size-12 rounded-full bg-primary/10 text-primary flex items-center justify-center text-lg font-bold">
          {{ person.name.split(' ').map((n: string) => n[0]).join('').toUpperCase().slice(0, 2) }}
        </div>
        <div>
          <h3 >{{ person.name }}</h3>
          <div class="flex items-center gap-2 text-xs text-muted">
            <span>{{ person.position || 'Pozisyon belirtilmemiş' }}</span>
            <span>·</span>
            <UBadge :color="(roleColors[person.role] as any)" variant="subtle" size="xs">
              {{ roleLabels[person.role] }}
            </UBadge>
            <template v-if="person.seniorityYears !== null">
              <span>·</span>
              <span>{{ person.seniorityYears }} yıl kıdem</span>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <UTabs
      :items="tabs"
      :model-value="activeTab"
      @update:model-value="activeTab = $event as string"
    />

    <!-- OZLUK TAB -->
    <div v-if="activeTab === 'ozluk'" class="space-y-4">
      <div v-if="loading" class="flex items-center justify-center py-20">
        <UIcon name="i-lucide-loader-2" class="size-8 animate-spin text-muted" />
      </div>
      <template v-else-if="person">
        <div class="flex justify-end">
          <UButton
            v-if="!editMode"
            label="Düzenle" icon="i-lucide-pencil" size="sm" variant="outline"
            @click="editMode = true; syncForm()"
          />
          <div v-else class="flex gap-2">
            <UButton label="Vazgeç" color="neutral" variant="outline" size="sm" @click="editMode = false" />
            <UButton label="Kaydet" icon="i-lucide-check" size="sm" :loading="saving" @click="savePerson" />
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <UCard>
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-user" class="size-4 text-primary" />
                <h3 >Kişisel Bilgiler</h3>
              </div>
            </template>
            <div class="space-y-3">
              <div class="flex justify-between">
                <span class="text-xs text-muted">TC Kimlik No</span>
                <UInput v-if="editMode" v-model="form.tcNo" size="sm" class="w-48" placeholder="TC Kimlik No" />
                <span v-else class="text-xs font-medium">{{ person.tcNo || '-' }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-xs text-muted">Telefon</span>
                <UInput v-if="editMode" v-model="form.phone" size="sm" class="w-48" placeholder="Telefon" />
                <span v-else class="text-xs font-medium">{{ person.phone || '-' }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-xs text-muted">E-posta</span>
                <span class="text-xs font-medium">{{ person.email }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-xs text-muted">Doğum Tarihi</span>
                <UInput v-if="editMode" v-model="form.birthDate" type="date" size="sm" class="w-48" />
                <span v-else class="text-xs font-medium">{{ formatDate(person.birthDate) }}</span>
              </div>
              <div class="flex justify-between items-start">
                <span class="text-xs text-muted">Adres</span>
                <UTextarea v-if="editMode" v-model="form.address" size="sm" class="w-48" :rows="2" />
                <span v-else class="text-xs font-medium text-right max-w-[200px]">{{ person.address || '-' }}</span>
              </div>
            </div>
          </UCard>

          <UCard>
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-briefcase" class="size-4 text-primary" />
                <h3 >İş Bilgileri</h3>
              </div>
            </template>
            <div class="space-y-3">
              <div class="flex justify-between">
                <span class="text-xs text-muted">Pozisyon</span>
                <UInput v-if="editMode" v-model="form.position" size="sm" class="w-48" placeholder="Pozisyon" />
                <span v-else class="text-xs font-medium">{{ person.position || '-' }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-xs text-muted">İşe Giriş Tarihi</span>
                <UInput v-if="editMode" v-model="form.hireDate" type="date" size="sm" class="w-48" />
                <span v-else class="text-xs font-medium">{{ formatDate(person.hireDate) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-xs text-muted">İşten Çıkış Tarihi</span>
                <UInput v-if="editMode" v-model="form.terminationDate" type="date" size="sm" class="w-48" />
                <span v-else class="text-xs font-medium">{{ formatDate(person.terminationDate) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-xs text-muted">Şube</span>
                <span class="text-xs font-medium">{{ person.branchName || '-' }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-xs text-muted">Durum</span>
                <UBadge :color="person.isActive ? 'success' : 'neutral'" variant="subtle" size="xs">
                  {{ person.isActive ? 'Aktif' : 'Pasif' }}
                </UBadge>
              </div>
            </div>
          </UCard>

          <UCard class="lg:col-span-2">
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-heart-pulse" class="size-4 text-error" />
                <h3 >Acil Durum İletişim</h3>
              </div>
            </template>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <p class="text-xs text-muted mb-1">Ad Soyad</p>
                <UInput v-if="editMode" v-model="form.emergencyContactName" size="sm" placeholder="Ad Soyad" />
                <p v-else class="text-xs font-medium">{{ person.emergencyContactName || '-' }}</p>
              </div>
              <div>
                <p class="text-xs text-muted mb-1">Telefon</p>
                <UInput v-if="editMode" v-model="form.emergencyContactPhone" size="sm" placeholder="Telefon" />
                <p v-else class="text-xs font-medium">{{ person.emergencyContactPhone || '-' }}</p>
              </div>
              <div>
                <p class="text-xs text-muted mb-1">Yakınlık</p>
                <UInput v-if="editMode" v-model="form.emergencyContactRelation" size="sm" placeholder="Ör: Eş, Anne, Baba" />
                <p v-else class="text-xs font-medium">{{ person.emergencyContactRelation || '-' }}</p>
              </div>
            </div>
          </UCard>
        </div>
      </template>
    </div>

    <!-- IZIN TAB -->
    <div v-if="activeTab === 'izin'" class="space-y-4">
      <div v-if="leaveLoading" class="flex items-center justify-center py-20">
        <UIcon name="i-lucide-loader-2" class="size-8 animate-spin text-muted" />
      </div>
      <template v-else>
        <div class="flex items-center justify-between">
          <h3 >İzin Bakiyeleri ({{ new Date().getFullYear() }})</h3>
          <UButton label="İzin Talebi Oluştur" icon="i-lucide-plus" size="xs" @click="leaveModalOpen = true" />
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
          <UCard v-for="b in leaveBalances" :key="b.id" :ui="{ body: 'p-3' }">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs text-muted">{{ b.typeName }}</span>
              <UButton
                v-if="balanceEditId !== b.id"
                icon="i-lucide-pencil" size="2xs" color="neutral" variant="ghost"
                @click="balanceEditId = b.id; balanceEditValue = b.totalDays"
              />
              <div v-else class="flex gap-1">
                <UButton icon="i-lucide-check" size="2xs" color="success" variant="ghost" @click="saveBalance(b)" />
                <UButton icon="i-lucide-x" size="2xs" color="neutral" variant="ghost" @click="balanceEditId = null" />
              </div>
            </div>
            <div class="flex items-baseline gap-2">
              <span class="text-2xl font-bold" :class="b.remainingDays <= 0 ? 'text-error' : 'text-primary'">
                {{ b.remainingDays }}
              </span>
              <span class="text-xs text-muted">
                /
                <template v-if="balanceEditId === b.id">
                  <input v-model.number="balanceEditValue" type="number" class="w-12 text-xs border rounded px-1 py-0.5 inline" min="0" />
                </template>
                <template v-else>{{ b.totalDays }}</template>
                gün
              </span>
            </div>
            <div class="mt-1.5 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
              <div
                class="h-1.5 rounded-full transition-all"
                :class="b.remainingDays <= 0 ? 'bg-error' : 'bg-primary'"
                :style="{ width: `${Math.min(100, b.totalDays > 0 ? (b.usedDays / b.totalDays) * 100 : 0)}%` }"
              />
            </div>
            <p class="text-xs text-muted mt-1">{{ b.usedDays }} gün kullanıldı</p>
          </UCard>
        </div>

        <h3 class="mt-6">İzin Geçmişi</h3>
        <div v-if="leaveRequests.length === 0" class="text-center text-muted py-8 text-xs">
          Henüz izin talebi yok
        </div>
        <div v-else class="border border-default rounded-lg overflow-hidden">
          <table class="text-xs w-full table-fixed">
            <thead class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
              <tr>
                <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Tür</th>
                <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Tarih</th>
                <th class="hidden sm:table-cell text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Gün</th>
                <th class="hidden md:table-cell text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Not</th>
                <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Durum</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">İşlem</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-default">
              <tr v-for="lr in leaveRequests" :key="lr.id" class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                <td class="py-2 px-3 whitespace-nowrap overflow-hidden text-ellipsis">{{ lr.typeName }}</td>
                <td class="py-2 px-3 tabular-nums whitespace-nowrap overflow-hidden text-ellipsis">{{ formatDate(lr.startDate) }} — {{ formatDate(lr.endDate) }}</td>
                <td class="hidden sm:table-cell py-2 px-3 text-center tabular-nums whitespace-nowrap">{{ lr.days }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-muted max-w-[200px] overflow-hidden text-ellipsis">{{ lr.note || '-' }}</td>
                <td class="py-2 px-3 text-center whitespace-nowrap">
                  <UBadge :color="(leaveStatusColors[lr.status] as any)" variant="subtle" size="xs">
                    {{ leaveStatusLabels[lr.status] }}
                  </UBadge>
                </td>
                <td class="py-2 px-3 text-right whitespace-nowrap">
                  <div v-if="lr.status === 'PENDING'" class="flex items-center justify-end gap-1">
                    <UButton icon="i-lucide-check" size="2xs" color="success" variant="ghost" @click="approveLeave(lr.id)" />
                    <UButton icon="i-lucide-x" size="2xs" color="error" variant="ghost" @click="rejectLeave(lr.id)" />
                  </div>
                  <div v-else-if="lr.status === 'APPROVED'" class="flex items-center justify-end">
                    <UButton label="İptal" size="2xs" color="warning" variant="ghost" @click="cancelLeave(lr.id)" />
                  </div>
                  <span v-else class="text-xs text-muted">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <!-- Izin Talebi Modal -->
      <UModal :dismissible="false" v-model:open="leaveModalOpen" title="Yeni İzin Talebi">
        <template #body>
          <div class="space-y-4">
            <div>
              <label class="text-sm font-medium mb-1 block">İzin Türü</label>
              <USelect
                v-model="leaveForm.leaveTypeId"
                :items="leaveTypeOptions"
                value-key="value"
                placeholder="Seçin"
                class="w-full"
              />
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="text-sm font-medium mb-1 block">Başlangıç</label>
                <UInput v-model="leaveForm.startDate" type="date" class="w-full" />
              </div>
              <div>
                <label class="text-sm font-medium mb-1 block">Bitiş</label>
                <UInput v-model="leaveForm.endDate" type="date" class="w-full" />
              </div>
            </div>
            <div>
              <label class="text-sm font-medium mb-1 block">Not</label>
              <UTextarea v-model="leaveForm.note" :rows="2" placeholder="Açıklama (isteğe bağlı)" class="w-full" />
            </div>
            <div class="flex justify-end gap-2 pt-2">
              <UButton label="Vazgeç" color="neutral" variant="outline" @click="leaveModalOpen = false" />
              <UButton label="Talep Oluştur" :loading="leaveSaving" @click="submitLeave" />
            </div>
          </div>
        </template>
      </UModal>
    </div>

    <!-- PERFORMANS TAB -->
    <div v-if="activeTab === 'performans'" class="space-y-4">
      <div class="flex items-center justify-between">
        <h3 >Performans Özeti</h3>
        <USelect
          v-model="perfYear"
          :items="[
            { label: '2024', value: 2024 },
            { label: '2025', value: 2025 },
            { label: '2026', value: 2026 },
          ]"
          value-key="value"
          size="sm"
          class="w-24"
        />
      </div>

      <div v-if="perfLoading" class="flex items-center justify-center py-20">
        <UIcon name="i-lucide-loader-2" class="size-8 animate-spin text-muted" />
      </div>
      <template v-else-if="performance">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <UCard :ui="{ body: 'p-4' }">
            <p class="text-xs text-muted">Poliçe Sayısı</p>
            <p class="text-2xl font-bold mt-1">{{ performance.totalPolicies }}</p>
          </UCard>
          <UCard :ui="{ body: 'p-4' }">
            <p class="text-xs text-muted">Toplam Prim</p>
            <p class="text-2xl font-bold mt-1">{{ formatCurrency(performance.totalPremium) }}</p>
          </UCard>
          <UCard :ui="{ body: 'p-4' }">
            <p class="text-xs text-muted">Tamamlanan Görev</p>
            <p class="text-2xl font-bold mt-1">{{ performance.completedTasks }} <span class="text-sm text-muted font-normal">/ {{ performance.totalTasks }}</span></p>
          </UCard>
          <UCard :ui="{ body: 'p-4' }">
            <p class="text-xs text-muted">Tamamlama Oranı</p>
            <p class="text-2xl font-bold mt-1" :class="performance.completionRate >= 70 ? 'text-success' : performance.completionRate >= 40 ? 'text-warning' : 'text-error'">
              %{{ performance.completionRate }}
            </p>
          </UCard>
        </div>

        <UCard>
          <template #header>
            <h3 >Aylık Kırılım</h3>
          </template>
          <div v-if="performance.monthlyData.length === 0" class="text-center text-muted py-6 text-xs">
            Bu yıl için veri yok
          </div>
          <table v-else class="text-xs w-full table-fixed">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Ay</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Poliçe</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap">Prim</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-default">
              <tr v-for="m in performance.monthlyData" :key="m.month" class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                <td class="py-2 px-3 font-medium">{{ monthNames[m.month] }}</td>
                <td class="py-2 px-3 text-right tabular-nums">{{ m.policies }}</td>
                <td class="py-2 px-3 text-right tabular-nums">{{ formatCurrency(m.premium) }}</td>
              </tr>
            </tbody>
          </table>
        </UCard>
      </template>
    </div>
  </div>
</template>
