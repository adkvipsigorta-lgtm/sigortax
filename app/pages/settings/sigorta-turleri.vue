<script setup lang="ts">
import type { InsuranceType, InsuranceCode } from '~/types'
import { z } from 'zod'

definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Poliçe Türleri' })

const toast = useToast()
const { put, post } = useApi()

const insuranceTypes = usePaginatedData<InsuranceType>({ endpoint: 'insurance-types', defaultLimit: 999, defaultSort: 'name', defaultOrder: 'asc' })
onMounted(() => insuranceTypes.fetchData())

const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => { if (searchTimeout) clearTimeout(searchTimeout); searchTimeout = setTimeout(() => insuranceTypes.setSearch(val), 400) })

const sortKeyMap: Record<string, string> = { name: 'name', branchGroup: 'branch_group', defaultCommRate: 'default_comm_rate', renewalDays: 'renewal_days', isActive: 'is_active' }
const sorting = ref<{ id: string, desc: boolean }[]>([])
watch(sorting, (val) => { if (val.length) { insuranceTypes.setSort(sortKeyMap[val[0].id] || val[0].id, val[0].desc ? 'desc' : 'asc') } else { insuranceTypes.setSort('name', 'asc') } }, { deep: true })

const columns = [
  { accessorKey: 'name', header: 'Poliçe Türü', enableSorting: true, size: 250, minSize: 200 },
  { accessorKey: 'branchGroup', header: 'Grup', enableSorting: true, minSize: 120, maxSize: 150 },
  { accessorKey: 'defaultCommRate', header: 'Komisyon (%)', enableSorting: true, minSize: 110, maxSize: 120 },
  { accessorKey: 'renewalDays', header: 'Hatırlatma', enableSorting: true, minSize: 100, maxSize: 120 },
  { accessorKey: 'isRenewable', header: 'Yenileme Takibi', enableSorting: true, minSize: 120, maxSize: 140 },
  { accessorKey: 'isActive', header: 'Durum', enableSorting: true, minSize: 90, maxSize: 100 },
  { accessorKey: 'actions', header: '', enableSorting: false, minSize: 50, maxSize: 50 }
]

const isModalOpen = ref(false)
const editingInsurance = ref<InsuranceType | null>(null)

const codeOptions = [
  { label: 'Sağlık', value: 'HEALTH' }, { label: 'Trafik/Kasko', value: 'TRAFFIC' },
  { label: 'Konut', value: 'HOUSING' }, { label: 'DASK', value: 'DASK' }, { label: 'Diğer', value: 'OTHER' }
]
const groupOptions = [
  { label: 'SAĞLIK', value: 'SAĞLIK' }, { label: 'KONUT', value: 'KONUT' }, { label: 'TRAFİK', value: 'TRAFİK' },
  { label: 'KASKO', value: 'KASKO' }, { label: 'DİĞER', value: 'DİĞER' }
]

const themeColorMap: Record<string, string> = { primary: '#3b82f6', error: '#ef4444', success: '#22c55e', warning: '#f59e0b', info: '#8b5cf6', neutral: '#6b7280' }
function normalizeColor(color: string): string { if (color.startsWith('#')) return color; return themeColorMap[color] || '#3b82f6' }

const insuranceSchema = z.object({
  name: z.string().min(2, 'Poliçe türü adı en az 2 karakter olmalıdır'),
  code: z.string().min(1, 'Kategori seçimi zorunludur'),
  color: z.string().regex(/^#[0-9a-fA-F]{6}$/, 'Geçerli bir renk kodu giriniz').optional(),
  defaultCommRate: z.number().min(0).max(100),
  renewalDays: z.number().min(0).max(90),
  isActive: z.boolean(), showInCharts: z.boolean(), isRenewable: z.boolean(),
  branchGroup: z.string().optional()
})

const defaultForm = { name: '', code: 'OTHER' as InsuranceCode, color: '#3b82f6', defaultCommRate: 0, renewalDays: 10, isActive: true, showInCharts: false, isRenewable: true, branchGroup: '' }
const form = ref({ ...defaultForm })

function openCreateModal() { editingInsurance.value = null; form.value = { ...defaultForm }; isModalOpen.value = true }
function openEditModal(insurance: InsuranceType) {
  editingInsurance.value = insurance
  form.value = { name: insurance.name, code: insurance.code, color: normalizeColor(insurance.color), defaultCommRate: insurance.defaultCommRate ?? 0, renewalDays: insurance.renewalDays ?? 10, isActive: insurance.isActive, showInCharts: insurance.showInCharts || false, isRenewable: insurance.isRenewable ?? true, branchGroup: insurance.branchGroup || '' }
  isModalOpen.value = true
}

const savingInsurance = ref(false)
async function saveInsurance() {
  if (savingInsurance.value) return; savingInsurance.value = true
  const payload = { ...form.value, level: 'subcategory' }
  try {
    if (editingInsurance.value) { await put(`insurance-types/${editingInsurance.value.id}`, payload); toast.add({ title: 'Poliçe türü güncellendi', color: 'success' }) }
    else { await post('insurance-types', payload); toast.add({ title: 'Poliçe türü oluşturuldu', color: 'success' }) }
    isModalOpen.value = false; insuranceTypes.refresh()
  } catch (error: any) { toast.add({ title: error.message || 'İşlem başarısız', color: 'error' }) }
  savingInsurance.value = false
}

async function toggleRenewable(insurance: InsuranceType) {
  try { await put(`insurance-types/${insurance.id}`, { isRenewable: !insurance.isRenewable }); insurance.isRenewable = !insurance.isRenewable }
  catch (error: any) { toast.add({ title: error.message || 'Güncelleme başarısız', color: 'error' }) }
}

function getRowActions(insurance: InsuranceType) {
  return [[{ label: 'Düzenle', icon: 'i-lucide-pencil', onSelect: () => openEditModal(insurance) }]]
}
</script>

<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-xl font-semibold">Poliçe Türleri</h1>
      <p class="text-sm text-muted mt-1">Sigorta türleri ve komisyon ayarları.</p>
    </div>

    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="relative w-full sm:w-[250px] [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput v-model="searchInput" placeholder=" " class="w-full peer/fl-itsearch" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-itsearch:top-0 peer-focus-within/fl-itsearch:-translate-y-1/2 peer-focus-within/fl-itsearch:text-xs peer-focus-within/fl-itsearch:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-itsearch:top-0 peer-has-[input:not(:placeholder-shown)]/fl-itsearch:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-itsearch:text-xs peer-has-[input:not(:placeholder-shown)]/fl-itsearch:text-[var(--ui-text-highlighted)]">Poliçe Türü Ara</label>
          </div>
          <UButton label="Yeni Poliçe Türü" icon="i-lucide-plus" size="xl" class="font-semibold" @click="openCreateModal()" />
        </div>
      </template>

      <SkeletonTable v-if="insuranceTypes.loading.value && !insuranceTypes.data.value.length" :rows="8" :cols="5" />
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <UTable v-model:sorting="sorting" :data="insuranceTypes.data.value" :columns="columns" :loading="insuranceTypes.loading.value && !insuranceTypes.data.value.length" :sorting-options="{ manualSorting: true }" :ui="{ base: 'table-fixed min-w-full sigorta-turleri-table', thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10', th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap', td: 'py-2 px-3 text-xs whitespace-nowrap overflow-hidden text-ellipsis' }">
          <template #name-header="{ column }"><SortableHeader label="Sigorta Türü" :column="column" /></template>
          <template #branchGroup-header="{ column }"><SortableHeader label="Grup" :column="column" /></template>
          <template #defaultCommRate-header="{ column }"><SortableHeader label="Komisyon (%)" :column="column" /></template>
          <template #renewalDays-header="{ column }"><SortableHeader label="Hatırlatma" :column="column" /></template>
          <template #isRenewable-header="{ column }"><SortableHeader label="Yenileme Takibi" :column="column" /></template>
          <template #isActive-header="{ column }"><SortableHeader label="Durum" :column="column" /></template>

          <template #name-cell="{ row }">
            <div class="flex items-center gap-2 min-w-0">
              <div class="size-2 rounded-full shrink-0" :style="{ backgroundColor: normalizeColor(row.original.color) }" />
              <span class="font-semibold truncate" :title="row.original.name">{{ row.original.name }}</span>
            </div>
          </template>
          <template #branchGroup-cell="{ row }">
            <UBadge v-if="row.original.branchGroup" color="neutral" variant="subtle" size="sm">{{ row.original.branchGroup }}</UBadge>
            <span v-else class="text-muted">-</span>
          </template>
          <template #defaultCommRate-cell="{ row }">
            <span v-if="row.original.defaultCommRate" class="tabular-nums font-semibold">%{{ row.original.defaultCommRate }}</span>
            <span v-else class="text-muted">-</span>
          </template>
          <template #renewalDays-cell="{ row }"><span class="tabular-nums">{{ row.original.renewalDays }} gün</span></template>
          <template #isRenewable-cell="{ row }"><USwitch :model-value="row.original.isRenewable" @update:model-value="toggleRenewable(row.original)" size="xs" /></template>
          <template #isActive-cell="{ row }">
            <UBadge :color="row.original.isActive ? 'success' : 'neutral'" variant="subtle" size="sm">{{ row.original.isActive ? 'Aktif' : 'Pasif' }}</UBadge>
          </template>
          <template #actions-cell="{ row }">
            <UDropdownMenu :items="getRowActions(row.original)"><UButton icon="i-lucide-ellipsis-vertical" color="neutral" variant="ghost" size="xs" /></UDropdownMenu>
          </template>
        </UTable>
      </div>

      <div class="flex items-center pt-3 mt-3 border-t border-default">
        <span class="text-xs text-muted">Toplam {{ insuranceTypes.total.value }} poliçe türü</span>
      </div>
    </UCard>

    <!-- Düzenleme Modalı -->
    <UModal :dismissible="false" v-model:open="isModalOpen" :title="editingInsurance ? 'Poliçe Türü Düzenle' : 'Yeni Poliçe Türü'" class="sm:max-w-lg">
      <template #body>
        <UForm :schema="insuranceSchema" :state="form" @submit="saveInsurance" class="space-y-5">
          <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput v-model="form.name" placeholder=" " class="w-full peer/fl-itname" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-itname:top-0 peer-focus-within/fl-itname:-translate-y-1/2 peer-focus-within/fl-itname:text-xs peer-focus-within/fl-itname:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-itname:top-0 peer-has-[input:not(:placeholder-shown)]/fl-itname:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-itname:text-xs peer-has-[input:not(:placeholder-shown)]/fl-itname:text-[var(--ui-text-highlighted)]">Sigorta Türü Adı <span class="text-red-500">*</span></label>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
              <USelect v-model="form.code" :items="codeOptions" value-key="value" placeholder=" " class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', form.code ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">Kategori <span class="text-red-500">*</span></label>
            </div>
            <div class="relative select-fl [&_button]:!pt-5 [&_button]:!pb-2.5">
              <USelect v-model="form.branchGroup" :items="groupOptions" value-key="value" placeholder=" " class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', form.branchGroup ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">Grup</label>
            </div>
          </div>

          <USeparator />

          <div class="grid grid-cols-2 gap-4">
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model.number="form.defaultCommRate" type="number" :min="0" :max="100" placeholder=" " class="w-full peer/fl-itcomm" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-itcomm:top-0 peer-focus-within/fl-itcomm:-translate-y-1/2 peer-focus-within/fl-itcomm:text-xs peer-focus-within/fl-itcomm:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-itcomm:top-0 peer-has-[input:not(:placeholder-shown)]/fl-itcomm:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-itcomm:text-xs peer-has-[input:not(:placeholder-shown)]/fl-itcomm:text-[var(--ui-text-highlighted)]">Komisyon (%)</label>
            </div>
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model.number="form.renewalDays" type="number" :min="0" :max="90" placeholder=" " class="w-full peer/fl-itdays" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-itdays:top-0 peer-focus-within/fl-itdays:-translate-y-1/2 peer-focus-within/fl-itdays:text-xs peer-focus-within/fl-itdays:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-itdays:top-0 peer-has-[input:not(:placeholder-shown)]/fl-itdays:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-itdays:text-xs peer-has-[input:not(:placeholder-shown)]/fl-itdays:text-[var(--ui-text-highlighted)]">Hatırlatma (gün)</label>
            </div>
          </div>

          <USeparator />

          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-sm font-medium mb-2">Renk</p>
              <div class="flex items-center gap-3">
                <label class="relative size-10 rounded-lg border border-default overflow-hidden cursor-pointer">
                  <input type="color" v-model="form.color" class="absolute inset-0 size-full cursor-pointer opacity-0" />
                  <div class="size-full rounded-lg" :style="{ backgroundColor: form.color }" />
                </label>
                <span class="text-xs text-muted font-mono">{{ form.color }}</span>
              </div>
            </div>
            <div class="flex flex-col justify-end gap-3 pb-1">
              <UCheckbox v-model="form.isActive" label="Aktif" />
              <UCheckbox v-model="form.showInCharts" label="Grafikte Göster" />
            </div>
          </div>

          <USeparator />

          <div class="flex justify-end gap-2">
            <UButton label="İptal" color="neutral" variant="outline" size="xl" class="font-semibold" :disabled="savingInsurance" @click="isModalOpen = false" />
            <UButton :label="editingInsurance ? 'Güncelle' : 'Kaydet'" icon="i-lucide-check" size="xl" class="font-semibold" type="submit" :loading="savingInsurance" :disabled="savingInsurance" />
          </div>
        </UForm>
      </template>
    </UModal>
  </div>
</template>

<style scoped>
.select-fl :deep(button) {
  min-height: 50px !important;
  height: auto !important;
}
:deep(.sigorta-turleri-table th:nth-child(1)) { width: 220px; min-width: 220px; max-width: 220px; }
:deep(.sigorta-turleri-table th:nth-child(2)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.sigorta-turleri-table th:nth-child(3)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.sigorta-turleri-table th:nth-child(4)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.sigorta-turleri-table th:nth-child(5)) { width: 120px; min-width: 120px; max-width: 120px; }
:deep(.sigorta-turleri-table th:nth-child(6)) { width: 90px; min-width: 90px; max-width: 90px; }
:deep(.sigorta-turleri-table th:nth-child(7)) { width: 60px; min-width: 60px; max-width: 60px; }
:deep(.sigorta-turleri-table > tbody > tr > td) { overflow: hidden; text-overflow: clip; max-width: 0; }
</style>
