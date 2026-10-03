<script setup lang="ts">
import type { InsuranceType, InsuranceCode } from '~/types'
import { z } from 'zod'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Poliçe Türleri' })

const toast = useToast()
const { put } = useApi()

// Server-side paginated data
const insuranceTypes = usePaginatedData<InsuranceType>({
  endpoint: 'insurance-types',
  defaultLimit: 999,
  defaultSort: 'name',
  defaultOrder: 'asc'
})

onMounted(() => insuranceTypes.fetchData())

// Search
const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    insuranceTypes.setSearch(val)
  }, 400)
})

// Sorting
const sortKeyMap: Record<string, string> = {
  name: 'name',
  branchGroup: 'branch_group',
  defaultCommRate: 'default_comm_rate',
  renewalDays: 'renewal_days',
  isActive: 'is_active'
}

const sorting = ref<{ id: string, desc: boolean }[]>([])
watch(sorting, (val) => {
  if (val.length) {
    const apiKey = sortKeyMap[val[0].id] || val[0].id
    insuranceTypes.setSort(apiKey, val[0].desc ? 'desc' : 'asc')
  } else {
    insuranceTypes.setSort('name', 'asc')
  }
}, { deep: true })

// Tablo
const columns = [
  { accessorKey: 'name', header: 'Poliçe Türü', enableSorting: true, size: 250, minSize: 200 },
  { accessorKey: 'branchGroup', header: 'Grup', enableSorting: true, minSize: 120, maxSize: 150 },
  { accessorKey: 'defaultCommRate', header: 'Komisyon (%)', enableSorting: true, minSize: 110, maxSize: 120 },
  { accessorKey: 'renewalDays', header: 'Hatırlatma', enableSorting: true, minSize: 100, maxSize: 120 },
  { accessorKey: 'isRenewable', header: 'Yenileme Takibi', enableSorting: true, minSize: 120, maxSize: 140 },
  { accessorKey: 'isActive', header: 'Durum', enableSorting: true, minSize: 90, maxSize: 100 },
  { accessorKey: 'actions', header: '', enableSorting: false, minSize: 50, maxSize: 50 }
]

// Düzenleme modali
const isModalOpen = ref(false)
const editingInsurance = ref<InsuranceType | null>(null)

const codeOptions = [
  { label: 'Sağlık', value: 'HEALTH' },
  { label: 'Trafik/Kasko', value: 'TRAFFIC' },
  { label: 'Konut', value: 'HOUSING' },
  { label: 'DASK', value: 'DASK' },
  { label: 'Diğer', value: 'OTHER' }
]

const groupOptions = [
  { label: 'SAGLIK', value: 'SAĞLIK' },
  { label: 'KONUT', value: 'KONUT' },
  { label: 'TRAFIK', value: 'TRAFİK' },
  { label: 'KASKO', value: 'KASKO' },
  { label: 'DIGER', value: 'DİĞER' }
]

// Legacy theme name -> hex converter (for old data)
const themeColorMap: Record<string, string> = {
  primary: '#3b82f6',
  error: '#ef4444',
  success: '#22c55e',
  warning: '#f59e0b',
  info: '#8b5cf6',
  neutral: '#6b7280'
}

function normalizeColor(color: string): string {
  if (color.startsWith('#')) return color
  return themeColorMap[color] || '#3b82f6'
}

const insuranceSchema = z.object({
  name: z.string().min(2, 'Poliçe türü adı en az 2 karakter olmalıdır'),
  code: z.string().min(1, 'Kategori seçimi zorunludur'),
  color: z.string().regex(/^#[0-9a-fA-F]{6}$/, 'Geçerli bir renk kodu giriniz').optional(),
  defaultCommRate: z.number().min(0, 'Komisyon 0\'dan kucuk olamaz').max(100, 'Komisyon 100\'den buyuk olamaz'),
  renewalDays: z.number().min(0, 'Hatırlatma süresi 0\'dan kucuk olamaz').max(90, 'Hatırlatma süresi 90\'dan buyuk olamaz'),
  isActive: z.boolean(),
  showInCharts: z.boolean(),
  isRenewable: z.boolean(),
  branchGroup: z.string().optional()
})

const defaultForm = {
  name: '',
  code: 'OTHER' as InsuranceCode,
  color: '#3b82f6',
  defaultCommRate: 0,
  renewalDays: 10,
  isActive: true,
  showInCharts: false,
  isRenewable: true,
  branchGroup: ''
}

const form = ref({ ...defaultForm })

function openCreateModal() {
  editingInsurance.value = null
  form.value = { ...defaultForm }
  isModalOpen.value = true
}

function openEditModal(insurance: InsuranceType) {
  editingInsurance.value = insurance
  form.value = {
    name: insurance.name,
    code: insurance.code,
    color: normalizeColor(insurance.color),
    defaultCommRate: insurance.defaultCommRate ?? 0,
    renewalDays: insurance.renewalDays ?? 10,
    isActive: insurance.isActive,
    showInCharts: insurance.showInCharts || false,
    isRenewable: insurance.isRenewable ?? true,
    branchGroup: insurance.branchGroup || ''
  }
  isModalOpen.value = true
}

const { post } = useApi()

const savingInsurance = ref(false)

async function saveInsurance() {
  if (savingInsurance.value) return
  savingInsurance.value = true
  const payload = {
    name: form.value.name,
    code: form.value.code,
    color: form.value.color,
    defaultCommRate: form.value.defaultCommRate,
    renewalDays: form.value.renewalDays,
    isActive: form.value.isActive,
    showInCharts: form.value.showInCharts,
    isRenewable: form.value.isRenewable,
    branchGroup: form.value.branchGroup,
    level: 'subcategory'
  }

  try {
    if (editingInsurance.value) {
      await put(`insurance-types/${editingInsurance.value.id}`, payload)
      toast.add({ title: 'Poliçe türü güncellendi', color: 'success' })
    } else {
      await post('insurance-types', payload)
      toast.add({ title: 'Poliçe türü oluşturuldu', color: 'success' })
    }
    isModalOpen.value = false
    insuranceTypes.refresh()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  savingInsurance.value = false
}

async function toggleRenewable(insurance: InsuranceType) {
  try {
    await put(`insurance-types/${insurance.id}`, { isRenewable: !insurance.isRenewable })
    insurance.isRenewable = !insurance.isRenewable
  } catch (error: any) {
    toast.add({ title: error.message || 'Güncelleme başarısız', color: 'error' })
  }
}

function getRowActions(insurance: InsuranceType) {
  return [
    [{ label: 'Düzenle', icon: 'i-lucide-pencil', onSelect: () => openEditModal(insurance) }]
  ]
}

</script>

<template>
  <div class="space-y-4">
    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-col gap-3">
          <div>
            <h3 class="font-semibold">Poliçe Türleri</h3>
            <p class="text-xs text-muted">Sigorta türleri ve komisyon ayarları</p>
          </div>
          <div class="flex items-center justify-between gap-2">
            <UInput
              v-model="searchInput"
              icon="i-lucide-search"
              placeholder="Poliçe türü ara..."
              size="xs"
              :ui="{ base: 'h-[30px]' }"
              class="w-[180px]"
            />
            <UButton label="Yeni Poliçe Türü" icon="i-lucide-plus" size="xs" @click="openCreateModal()" />
          </div>
        </div>
      </template>

      <SkeletonTable v-if="insuranceTypes.loading.value && !insuranceTypes.data.value.length" :rows="8" :cols="5" />
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <UTable
          v-model:sorting="sorting"
          :data="insuranceTypes.data.value"
          :columns="columns"
          :loading="insuranceTypes.loading.value && !insuranceTypes.data.value.length"
          :sorting-options="{ manualSorting: true }"
          :ui="{
            base: 'table-fixed min-w-full sigorta-turleri-table',
            thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10',
            th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap',
            td: 'py-2 px-3 text-xs whitespace-nowrap overflow-hidden text-ellipsis'
          }"
        >
          <template #name-header="{ column }">
            <SortableHeader label="Sigorta Türü" :column="column" />
          </template>
          <template #branchGroup-header="{ column }">
            <SortableHeader label="Grup" :column="column" />
          </template>
          <template #defaultCommRate-header="{ column }">
            <SortableHeader label="Komisyon (%)" :column="column" />
          </template>
          <template #renewalDays-header="{ column }">
            <SortableHeader label="Hatırlatma" :column="column" />
          </template>
          <template #isRenewable-header="{ column }">
            <SortableHeader label="Yenileme Takibi" :column="column" />
          </template>
          <template #isActive-header="{ column }">
            <SortableHeader label="Durum" :column="column" />
          </template>

          <template #name-cell="{ row }">
            <div class="flex items-center gap-2 min-w-0">
              <div class="size-2 rounded-full shrink-0" :style="{ backgroundColor: normalizeColor(row.original.color) }" />
              <span class="font-semibold truncate" :title="row.original.name">{{ row.original.name }}</span>
            </div>
          </template>

          <template #branchGroup-cell="{ row }">
            <span v-if="row.original.branchGroup" class="badge-cell badge-neutral">{{ row.original.branchGroup }}</span>
            <span v-else class="text-muted">-</span>
          </template>

          <template #defaultCommRate-cell="{ row }">
            <span v-if="row.original.defaultCommRate" class="tabular-nums font-semibold">%{{ row.original.defaultCommRate }}</span>
            <span v-else class="text-muted">-</span>
          </template>

          <template #renewalDays-cell="{ row }">
            <span class="tabular-nums">{{ row.original.renewalDays }} gün</span>
          </template>

          <template #isRenewable-cell="{ row }">
            <USwitch
              :model-value="row.original.isRenewable"
              @update:model-value="toggleRenewable(row.original)"
              size="xs"
            />
          </template>

          <template #isActive-cell="{ row }">
            <span class="badge-cell" :class="row.original.isActive ? 'badge-success' : 'badge-neutral'">
              {{ row.original.isActive ? 'Aktif' : 'Pasif' }}
            </span>
          </template>

          <template #actions-cell="{ row }">
            <UDropdownMenu :items="getRowActions(row.original)">
              <UButton icon="i-lucide-ellipsis-vertical" color="neutral" variant="ghost" size="xs" />
            </UDropdownMenu>
          </template>
        </UTable>
      </div>

      <div class="flex items-center pt-3 mt-3 border-t border-default">
        <span class="text-xs text-muted">Toplam {{ insuranceTypes.total.value }} poliçe türü</span>
      </div>
    </UCard>

    <!-- Düzenleme Modali -->
    <UModal :dismissible="false" v-model:open="isModalOpen" :title="editingInsurance ? 'Poliçe Türü Düzenle' : 'Yeni Poliçe Türü'" class="sm:max-w-lg">
      <template #body>
        <UForm :schema="insuranceSchema" :state="form" @submit="saveInsurance" class="space-y-5">
          <!-- Tür Bilgileri -->
          <div class="space-y-4">
            <UFormField label="Sigorta Türü Adi" name="name" required>
              <UInput v-model="form.name" placeholder="TSS, Kasko, Trafik..." icon="i-lucide-shield" class="w-full" />
            </UFormField>

            <div class="grid grid-cols-2 gap-4">
              <UFormField label="Kategori" name="code">
                <USelect v-model="form.code" :items="codeOptions" value-key="value" class="w-full" />
              </UFormField>

              <UFormField label="Grup" name="branchGroup">
                <USelect v-model="form.branchGroup" :items="groupOptions" value-key="value" placeholder="Grup sec..." class="w-full" />
              </UFormField>
            </div>
          </div>

          <USeparator />

          <!-- Komisyon & Hatırlatma -->
          <div>
            <h4 class="font-medium text-sm mb-3">Komisyon & Hatırlatma</h4>
            <div class="grid grid-cols-2 gap-4">
              <UFormField label="Komisyon (%)" name="defaultCommRate">
                <UInput v-model.number="form.defaultCommRate" type="number" :min="0" :max="100" placeholder="0" icon="i-lucide-percent" class="w-full" />
              </UFormField>

              <UFormField label="Hatırlatma (gün)" name="renewalDays">
                <UInput v-model.number="form.renewalDays" type="number" :min="0" :max="90" placeholder="10" icon="i-lucide-bell" class="w-full" />
              </UFormField>
            </div>
          </div>

          <USeparator />

          <!-- Gorunum & Durum -->
          <div>
            <h4 class="font-medium text-sm mb-3">Gorunum</h4>
            <div class="grid grid-cols-2 gap-4">
              <UFormField label="Renk" name="color">
                <div class="flex items-center gap-3">
                  <label class="relative size-10 rounded-lg border border-default overflow-hidden cursor-pointer">
                    <input
                      type="color"
                      v-model="form.color"
                      class="absolute inset-0 size-full cursor-pointer opacity-0"
                    >
                    <div class="size-full rounded-lg" :style="{ backgroundColor: form.color }" />
                  </label>
                  <UInput v-model="form.color" maxlength="7" class="w-28 font-mono" size="sm" />
                </div>
              </UFormField>

              <div class="flex flex-col justify-end gap-3 pb-1">
                <UCheckbox v-model="form.isActive" label="Aktif" />
                <UCheckbox v-model="form.showInCharts" label="Grafikte Göster" />
              </div>
            </div>
          </div>

          <USeparator />

          <div class="flex justify-end gap-2">
            <UButton label="İptal" color="neutral" variant="outline" :disabled="savingInsurance" @click="isModalOpen = false" />
            <UButton label="Güncelle" icon="i-lucide-check" type="submit" :loading="savingInsurance" :disabled="savingInsurance" />
          </div>
        </UForm>
      </template>
    </UModal>
  </div>
</template>

<style scoped>
:deep(.sigorta-turleri-table th:nth-child(1)) { width: 220px; min-width: 220px; max-width: 220px; }
:deep(.sigorta-turleri-table th:nth-child(2)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.sigorta-turleri-table th:nth-child(3)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.sigorta-turleri-table th:nth-child(4)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.sigorta-turleri-table th:nth-child(5)) { width: 120px; min-width: 120px; max-width: 120px; }
:deep(.sigorta-turleri-table th:nth-child(6)) { width: 90px;  min-width: 90px;  max-width: 90px;  }
:deep(.sigorta-turleri-table th:nth-child(7)) { width: 60px;  min-width: 60px;  max-width: 60px;  }
:deep(.sigorta-turleri-table > tbody > tr > td) { overflow: hidden; text-overflow: clip; max-width: 0; }

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
.badge-success { background: rgb(34 197 94 / 0.1);  color: #22c55e; }
.badge-neutral { background: rgb(107 114 128 / 0.1); color: #6b7280; }
</style>
