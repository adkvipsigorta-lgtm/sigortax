<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Çapraz Satış' })

const { get, post } = useApi()
const { user } = useAuth()
const toast = useToast()
const { insurances, fetchInsurances } = useInsuranceTypes()

const isAdmin = computed(() => user.value?.role === 'admin')

const legacyColorMap: Record<string, string> = {
  primary: '#3b82f6',
  error: '#ef4444',
  success: '#22c55e',
  warning: '#f59e0b',
  info: '#8b5cf6',
  neutral: '#6b7280'
}

function toHex(color?: string): string {
  if (!color) return '#3b82f6'
  if (color.startsWith('#')) return color
  return legacyColorMap[color] || '#3b82f6'
}

const hasType = ref<number | null>(null)
const notType = ref<number | null>(null)
const searched = ref(false)

const crossSell = usePaginatedData({
  endpoint: 'dashboard/cross-sell',
  defaultLimit: 15,
  defaultSort: 'customer_name',
  defaultOrder: 'asc',
  immediate: false
})

const insuranceOptions = computed(() => {
  const seen = new Set<string>()
  return insurances.value
    .filter(i => i.level === 'subcategory')
    .filter(i => {
      if (seen.has(i.name)) return false
      seen.add(i.name)
      return true
    })
    .sort((a, b) => a.name.localeCompare(b.name, 'tr'))
    .map(i => ({ label: i.name, value: i.id }))
})

// Seçili notType'in adı
const notTypeName = computed(() => {
  if (!notType.value) return ''
  const ins = insurances.value.find(i => i.id === notType.value)
  return ins?.name || ''
})

function doSearch() {
  if (!hasType.value || !notType.value || hasType.value === notType.value) return
  searched.value = true
  crossSell.setFilters({
    hasType: hasType.value,
    notType: notType.value
  })
}

// Arama debounce
const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (!searched.value) return
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    crossSell.setSearch(val)
  }, 400)
})

// Siralama
const sortKeyMap: Record<string, string> = {
  customerName: 'customer_name',
  policyNo: 'policy_no',
  plateNo: 'plate_no',
  insuranceName: 'insurance_name',
  companyName: 'company_name',
  expiresAt: 'expires_at',
}

const sorting = ref<{ id: string, desc: boolean }[]>([])
watch(sorting, (val) => {
  if (!searched.value) return
  if (val.length) {
    const apiKey = sortKeyMap[val[0].id] || val[0].id
    crossSell.setSort(apiKey, val[0].desc ? 'desc' : 'asc')
  } else {
    crossSell.setSort('customer_name', 'asc')
  }
}, { deep: true })

const columns = [
  { accessorKey: 'customerName', header: 'Müşteri', enableSorting: true, size: 220, minSize: 180 },
  { accessorKey: 'policyNo', header: 'Poliçe No', enableSorting: true, minSize: 140, maxSize: 160 },
  { accessorKey: 'plateNo', header: 'Plaka No', enableSorting: true, minSize: 110, maxSize: 130 },
  { accessorKey: 'registrationNo', header: 'Belge Seri No', enableSorting: false, minSize: 120, maxSize: 140 },
  { accessorKey: 'insuranceName', header: 'Poliçe Türü', enableSorting: true, minSize: 130, maxSize: 150 },
  { accessorKey: 'companyName', header: 'Şirket', enableSorting: true, minSize: 130, maxSize: 160 },
  { accessorKey: 'expiresAt', header: 'Bitiş T.', enableSorting: true, minSize: 110, maxSize: 120 },
  { accessorKey: 'actions', header: 'İşlem', enableSorting: false, minSize: 120, maxSize: 140 }
]

function formatDate(d: string | null) {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('tr-TR')
}

// Görev oluşturma
const showAssignModal = ref(false)
const assignRow = ref<any>(null)
const assignForm = ref({
  assignedTo: undefined as number | undefined,
  priority: 'MEDIUM' as string,
})
const savingTask = ref(false)

// Kullanıcı listesi (admin için atama)
const users = ref<{ id: number; name: string }[]>([])
async function fetchUsers() {
  try {
    const res = await get<any>('users')
    users.value = (res.data || res || []).map((u: any) => ({ id: u.id, name: u.name }))
  } catch {}
}

const userOptions = computed(() =>
  users.value.map(u => ({ label: u.name, value: u.id }))
)

const priorityOptions = [
  { label: 'Düşük', value: 'LOW' },
  { label: 'Normal', value: 'MEDIUM' },
  { label: 'Yüksek', value: 'HIGH' },
  { label: 'Acil', value: 'URGENT' },
]

async function handleCreateTask(row: any) {
  if (isAdmin.value) {
    // Admin: modal ac, kullanıcı sec
    assignRow.value = row
    assignForm.value = { assignedTo: undefined, priority: 'MEDIUM' }
    showAssignModal.value = true
  } else {
    // Kullanıcı: kendine otomatik ata
    savingTask.value = true
    try {
      await post('tasks', {
        type: 'CROSS_SELL',
        customerId: row.customerId,
        insuranceId: notType.value,
        policyNo: row.policyNo,
        assignedTo: user.value?.id,
        priority: 'MEDIUM',
      })
      toast.add({ title: 'Görev oluşturuldu', color: 'success' })
      crossSell.refresh()
    } catch (e: any) {
      toast.add({ title: e?.data?.message || 'Hata olustu', color: 'error' })
    } finally {
      savingTask.value = false
    }
  }
}

async function createTask() {
  if (!assignRow.value || !notType.value) return
  savingTask.value = true
  try {
    await post('tasks', {
      type: 'CROSS_SELL',
      customerId: assignRow.value.customerId,
      insuranceId: notType.value,
      policyNo: assignRow.value.policyNo,
      assignedTo: assignForm.value.assignedTo,
      priority: assignForm.value.priority,
    })
    toast.add({ title: 'Görev oluşturuldu', color: 'success' })
    showAssignModal.value = false
    crossSell.refresh()
  } catch (e: any) {
    toast.add({ title: e?.data?.message || 'Hata olustu', color: 'error' })
  } finally {
    savingTask.value = false
  }
}

onMounted(() => {
  fetchInsurances()
  fetchUsers()
})
</script>

<template>
  <div class="space-y-4">
    <!-- Filtreler -->
    <UCard>
      <div class="space-y-4">
        <h3 class="font-semibold">Çapraz Satış Analizi</h3>
        <p class="text-xs text-muted">
          Bir sigorta türüne sahip olan ancak baska bir sigorta türüne sahip olmayan müşterileri bulun.
        </p>

        <div class="flex flex-wrap items-end gap-4">
          <div class="w-64">
            <label class="block text-sm font-medium mb-1">Sahip Oldugu Tür</label>
            <USelectMenu
              v-model="hasType"
              :items="insuranceOptions"
              value-key="value"
              label-key="label"
              placeholder="Sigorta türü sec..."
              class="w-full"
            />
          </div>

          <div class="flex items-center pt-5">
            <UIcon name="i-lucide-arrow-right" class="size-5 text-muted" />
          </div>

          <div class="w-64">
            <label class="block text-sm font-medium mb-1">Sahip Olmadigi Tür</label>
            <USelectMenu
              v-model="notType"
              :items="insuranceOptions"
              value-key="value"
              label-key="label"
              placeholder="Sigorta türü sec..."
              class="w-full"
            />
          </div>

          <UButton
            label="Ara"
            icon="i-lucide-search"
            color="primary"
            :loading="crossSell.loading.value"
            :disabled="!hasType || !notType || hasType === notType"
            @click="doSearch"
          />
        </div>

        <p v-if="hasType && notType && hasType === notType" class="text-sm text-red-500">
          Ayni sigorta türünü secemezsiniz.
        </p>
      </div>
    </UCard>

    <!-- Sonuçlar -->
    <UCard v-if="searched" :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <h3 class="font-semibold">
            Sonuçlar
            <span class="text-xs font-normal text-muted ml-2">({{ crossSell.total.value }} müşteri)</span>
          </h3>

          <UInput
            v-model="searchInput"
            icon="i-lucide-search"
            placeholder="Müşteri, poliçe no, plaka ara..."
            class="w-full sm:w-64"
          />
        </div>
      </template>

      <SkeletonTable v-if="crossSell.loading.value && !crossSell.data.value.length" :rows="8" :cols="6" />
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <UTable
          v-model:sorting="sorting"
          :data="crossSell.data.value"
          :columns="columns"
          :loading="crossSell.loading.value && !crossSell.data.value.length"
          :sorting-options="{ manualSorting: true }"
          :ui="{
            base: 'table-fixed min-w-full capraz-satis-table',
            thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10',
            th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap',
            td: 'py-2 px-3 text-xs whitespace-nowrap'
          }"
        >
          <!-- Sortable headers -->
          <template #customerName-header="{ column }">
            <SortableHeader label="Müşteri" :column="column" />
          </template>
          <template #policyNo-header="{ column }">
            <SortableHeader label="Poliçe No" :column="column" />
          </template>
          <template #plateNo-header="{ column }">
            <SortableHeader label="Plaka No" :column="column" />
          </template>
          <template #insuranceName-header="{ column }">
            <SortableHeader label="Poliçe Türü" :column="column" />
          </template>
          <template #companyName-header="{ column }">
            <SortableHeader label="Şirket" :column="column" />
          </template>
          <template #expiresAt-header="{ column }">
            <SortableHeader label="Bitiş T." :column="column" />
          </template>

          <!-- Data cells -->
          <template #customerName-cell="{ row }">
            <div class="flex flex-col">
              <NuxtLink
                :to="`/musteriler/${row.original.customerId}`"
                class="font-semibold text-primary hover:underline uppercase"
              >
                {{ row.original.customerName }}
              </NuxtLink>
              <span v-if="row.original.identityNo" class="text-xs text-muted">{{ row.original.identityNo }}</span>
            </div>
          </template>

          <template #policyNo-cell="{ row }">
            <span>{{ row.original.policyNo || '-' }}</span>
          </template>

          <template #plateNo-cell="{ row }">
            <span>{{ row.original.plateNo || '-' }}</span>
          </template>

          <template #registrationNo-cell="{ row }">
            <span>{{ row.original.registrationNo || '-' }}</span>
          </template>

          <template #insuranceName-cell="{ row }">
            <span
              class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold"
              :style="{
                backgroundColor: toHex(row.original.insuranceColor) + '1a',
                color: toHex(row.original.insuranceColor)
              }"
            >
              {{ row.original.insuranceName }}
            </span>
          </template>

          <template #companyName-cell="{ row }">
            <span>{{ row.original.companyName || '-' }}</span>
          </template>

          <template #expiresAt-cell="{ row }">
            <span class="tabular-nums">{{ formatDate(row.original.expiresAt) }}</span>
          </template>

          <template #actions-cell="{ row }">
            <div class="flex items-center gap-1">
              <UTooltip text="Görev Oluştur">
                <UButton
                  icon="i-lucide-clipboard-plus"
                  variant="ghost"
                  color="primary"
                  size="xs"
                  :loading="!isAdmin && savingTask"
                  @click="handleCreateTask(row.original)"
                />
              </UTooltip>
              <UTooltip text="Müşteri Detay">
                <UButton
                  icon="i-lucide-user"
                  variant="ghost"
                  color="neutral"
                  size="xs"
                  :to="`/musteriler/${row.original.customerId}`"
                />
              </UTooltip>
              <UTooltip v-if="row.original.phone" text="Ara">
                <UButton
                  icon="i-lucide-phone"
                  variant="ghost"
                  color="neutral"
                  size="xs"
                  :href="`tel:${row.original.phone}`"
                  tag="a"
                />
              </UTooltip>
            </div>
          </template>

          <!-- Empty state -->
          <template #empty>
            <div class="py-12 text-center text-muted">
              <UIcon name="i-lucide-search-x" class="size-8 mx-auto mb-2" />
              <p class="text-xs">Eşleşen müşteri bulunamadı.</p>
            </div>
          </template>
        </UTable>
      </div>

      <div class="h-[2px] w-full overflow-hidden">
        <div v-if="crossSell.loading.value" class="h-full bg-primary nav-loading-bar" />
      </div>

      <!-- Sayfalama -->
      <div class="flex flex-col sm:flex-row items-center gap-2 py-3 sm:justify-between">
        <div class="flex items-center gap-2 text-muted">
          <span class="hidden sm:inline text-xs">Sayfa başına satır</span>
          <USelect
            :model-value="crossSell.limit.value"
            :items="[{ label: '10', value: 10 }, { label: '15', value: 15 }, { label: '25', value: 25 }, { label: '50', value: 50 }]"
            value-key="value"
            size="xs"
            class="w-16"
            @update:model-value="(v: any) => { crossSell.limit.value = v; crossSell.setPage(1) }"
          />
          <span class="text-xs">{{ (crossSell.page.value - 1) * crossSell.limit.value + 1 }} - {{ Math.min(crossSell.page.value * crossSell.limit.value, crossSell.total.value) }} / {{ crossSell.total.value }}</span>
        </div>

        <div class="flex items-center gap-2 sm:hidden">
          <UButton icon="i-lucide-chevron-left" size="xs" color="neutral" variant="ghost" :disabled="crossSell.page.value <= 1" @click="crossSell.setPage(crossSell.page.value - 1)" />
          <span class="text-xs text-muted whitespace-nowrap">{{ crossSell.page.value }} / {{ Math.ceil(crossSell.total.value / crossSell.limit.value) }}</span>
          <UButton icon="i-lucide-chevron-right" size="xs" color="neutral" variant="ghost" :disabled="crossSell.page.value >= Math.ceil(crossSell.total.value / crossSell.limit.value)" @click="crossSell.setPage(crossSell.page.value + 1)" />
        </div>
        <UPagination
          v-if="crossSell.total.value > crossSell.limit.value"
          class="hidden sm:flex"
          :default-page="crossSell.page.value"
          :items-per-page="crossSell.limit.value"
          :total="crossSell.total.value"
          @update:page="crossSell.setPage"
        />
      </div>
    </UCard>

    <!-- Görev Oluştur Modal -->
    <UModal :dismissible="false" v-model:open="showAssignModal" title="Çapraz Satış Görevi Oluştur">
      <template #body>
        <div class="space-y-4">
          <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3 space-y-1 text-sm">
            <p><span class="text-muted">Müşteri:</span> <strong>{{ assignRow?.customerName }}</strong></p>
            <p><span class="text-muted">Mevcut Poliçe:</span> {{ assignRow?.insuranceName }} - {{ assignRow?.policyNo || '-' }}</p>
            <p><span class="text-muted">Hedef Tür:</span> <UBadge variant="solid" color="success" size="sm">{{ notTypeName }}</UBadge></p>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Atanacak Kişi</label>
            <USelectMenu
              v-model="assignForm.assignedTo"
              :items="userOptions"
              value-key="value"
              label-key="label"
              placeholder="Kullanıcı sec..."
              class="w-full"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Öncelik</label>
            <USelectMenu
              v-model="assignForm.priority"
              :items="priorityOptions"
              value-key="value"
              label-key="label"
              class="w-full"
            />
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="İptal" color="neutral" variant="outline" @click="showAssignModal = false" />
          <UButton
            label="Oluştur"
            icon="i-lucide-plus"
            color="primary"
            :loading="savingTask"
            @click="createTask"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>

<style scoped>
:deep(.capraz-satis-table th:nth-child(1)) { width: 180px; min-width: 180px; max-width: 180px; }
:deep(.capraz-satis-table th:nth-child(2)) { width: 140px; min-width: 140px; max-width: 140px; }
:deep(.capraz-satis-table th:nth-child(3)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.capraz-satis-table th:nth-child(4)) { width: 130px; min-width: 130px; max-width: 130px; }
:deep(.capraz-satis-table th:nth-child(5)) { width: 140px; min-width: 140px; max-width: 140px; }
:deep(.capraz-satis-table th:nth-child(6)) { width: 140px; min-width: 140px; max-width: 140px; }
:deep(.capraz-satis-table th:nth-child(7)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.capraz-satis-table th:nth-child(8)) { width: 120px; min-width: 120px; max-width: 120px; }
:deep(.capraz-satis-table > tbody > tr > td) { overflow: hidden; text-overflow: ellipsis; }
</style>
