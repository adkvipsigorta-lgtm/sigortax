<script setup lang="ts">
import { z } from 'zod'

definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Referans Kaynakları' })

const toast = useToast()
const { post, put, del } = useApi()

interface ReferenceSource { id: number; name: string; commissionRate: number; businessType: 'NEW' | 'RENEWAL' | null; isActive: boolean; policyCount?: number; createdAt?: string }

const sources = usePaginatedData<ReferenceSource>({ endpoint: 'reference-sources', defaultLimit: 999, defaultSort: 'name', defaultOrder: 'asc' })
onMounted(() => sources.fetchData())

const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => { if (searchTimeout) clearTimeout(searchTimeout); searchTimeout = setTimeout(() => sources.setSearch(val), 400) })

const sortKeyMap: Record<string, string> = { name: 'name', commissionRate: 'commission_rate', createdAt: 'created_at' }
const sorting = ref<{ id: string, desc: boolean }[]>([])
watch(sorting, (val) => { if (val.length) { sources.setSort(sortKeyMap[val[0].id] || val[0].id, val[0].desc ? 'desc' : 'asc') } else { sources.setSort('name', 'asc') } }, { deep: true })

const columns = [
  { accessorKey: 'name', header: 'Kaynak Adı', enableSorting: true, size: 250, minSize: 200 },
  { accessorKey: 'businessType', header: 'İş Türü', enableSorting: false, minSize: 100, maxSize: 120 },
  { accessorKey: 'commissionRate', header: 'Komisyon (%)', enableSorting: true, minSize: 120, maxSize: 140 },
  { accessorKey: 'policyCount', header: 'Poliçe Sayısı', enableSorting: false, minSize: 120, maxSize: 140 },
  { accessorKey: 'isActive', header: 'Durum', enableSorting: false, minSize: 90, maxSize: 100 },
  { accessorKey: 'actions', header: '', enableSorting: false, minSize: 50, maxSize: 50 }
]

const referenceSchema = z.object({
  name: z.string().min(2, 'Kaynak adı en az 2 karakter olmalıdır'),
  commissionRate: z.number().min(0, 'Komisyon 0\'dan küçük olamaz').max(100, 'Komisyon 100\'den büyük olamaz'),
  isActive: z.boolean()
})

const isModalOpen = ref(false)
const isDeleteModalOpen = ref(false)
const editingItem = ref<ReferenceSource | null>(null)
const deletingItemId = ref<number | null>(null)

const defaultForm = { name: '', commissionRate: 0, businessType: 'ALL' as 'NEW' | 'RENEWAL' | 'ALL', isActive: true }
const form = ref({ ...defaultForm })

const businessTypeOptions = [
  { label: 'Tümü', value: 'ALL' },
  { label: 'Yeni İş', value: 'NEW' },
  { label: 'Yenileme', value: 'RENEWAL' },
]

function openAddModal() { editingItem.value = null; form.value = { ...defaultForm }; isModalOpen.value = true }
function openEditModal(item: ReferenceSource) {
  editingItem.value = item
  form.value = { name: item.name, commissionRate: item.commissionRate, businessType: (item.businessType || 'ALL') as any, isActive: item.isActive }
  isModalOpen.value = true
}

const savingSource = ref(false)
async function save() {
  if (savingSource.value) return; savingSource.value = true
  try {
    if (editingItem.value) { await put(`reference-sources/${editingItem.value.id}`, form.value); toast.add({ title: 'Referans kaynağı güncellendi', color: 'success' }) }
    else { await post('reference-sources', form.value); toast.add({ title: 'Yeni referans kaynağı eklendi', color: 'success' }) }
    isModalOpen.value = false; sources.refresh()
  } catch (error: any) { toast.add({ title: error.message || 'İşlem başarısız', color: 'error' }) }
  savingSource.value = false
}

function confirmDelete(id: number) { deletingItemId.value = id; isDeleteModalOpen.value = true }
async function doDelete() {
  if (!deletingItemId.value) return
  try { await del(`reference-sources/${deletingItemId.value}`); toast.add({ title: 'Referans kaynağı silindi', color: 'success' }); sources.refresh() }
  catch { toast.add({ title: 'Silinemedi', color: 'error' }) }
  isDeleteModalOpen.value = false; deletingItemId.value = null
}

async function toggleActive(item: ReferenceSource) {
  try { await put(`reference-sources/${item.id}`, { isActive: !item.isActive }); item.isActive = !item.isActive; toast.add({ title: item.isActive ? 'Kaynak aktif edildi' : 'Kaynak pasif edildi', color: 'success' }) }
  catch { toast.add({ title: 'Durum değiştirilemedi', color: 'error' }) }
}

function getRowActions(item: ReferenceSource) {
  return [
    [{ label: 'Düzenle', icon: 'i-lucide-pencil', onSelect: () => openEditModal(item) }],
    [{ label: 'Sil', icon: 'i-lucide-trash-2', color: 'error' as const, onSelect: () => confirmDelete(item.id) }]
  ]
}
</script>

<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-2xl font-semibold">Referans Kaynakları</h1>
      <p class="text-sm text-muted mt-1">İş kaynakları ve komisyon oranları.</p>
    </div>

    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <UInput v-model="searchInput" placeholder="Kaynak Ara..." icon="i-lucide-search" class="filter-w-search" />
          <UButton label="Yeni Kaynak" icon="i-lucide-plus"  @click="openAddModal" />
        </div>
      </template>

      <SkeletonTable v-if="sources.loading.value && !sources.data.value.length" :rows="6" :cols="5" />
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <UTable
          v-model:sorting="sorting"
          :data="sources.data.value"
          :columns="columns"
          :loading="sources.loading.value && !sources.data.value.length"
          :sorting-options="{ manualSorting: true }"
          :ui="{ base: 'table-fixed min-w-full', thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10', th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap', td: 'py-2 px-3 text-xs whitespace-nowrap overflow-hidden' }"
        >
          <template #name-header="{ column }"><SortableHeader label="Kaynak Adı" :column="column" /></template>
          <template #commissionRate-header="{ column }"><SortableHeader label="Komisyon" :column="column" /></template>

          <template #name-cell="{ row }"><span class="overflow-hidden whitespace-nowrap block" :class="row.original.isActive ? '' : 'text-muted'" :title="row.original.name">{{ row.original.name }}</span></template>
          <template #businessType-cell="{ row }">
            <UBadge :color="row.original.businessType === 'NEW' ? 'info' : row.original.businessType === 'RENEWAL' ? 'success' : 'neutral'" variant="subtle" size="sm">
              {{ row.original.businessType === 'NEW' ? 'Yeni İş' : row.original.businessType === 'RENEWAL' ? 'Yenileme' : 'Tümü' }}
            </UBadge>
          </template>
          <template #commissionRate-cell="{ row }"><span>%{{ row.original.commissionRate }}</span></template>
          <template #policyCount-cell="{ row }"><span>{{ row.original.policyCount }}</span></template>
          <template #isActive-cell="{ row }"><USwitch :model-value="row.original.isActive" @update:model-value="toggleActive(row.original)" size="xs" /></template>
          <template #actions-cell="{ row }">
            <UDropdownMenu :items="getRowActions(row.original)"><UButton icon="i-lucide-ellipsis-vertical" color="neutral" variant="ghost" size="xs" /></UDropdownMenu>
          </template>
        </UTable>
      </div>

      <div class="flex items-center pt-3 mt-3 border-t border-default">
        <span class="text-xs text-muted">Toplam {{ sources.total.value }} referans kaynağı</span>
      </div>
    </UCard>

    <!-- Ekle/Düzenle Modal -->
    <UModal :dismissible="false" v-model:open="isModalOpen" :title="editingItem ? 'Referans Kaynağı Düzenle' : 'Yeni Referans Kaynağı'" class="sm:max-w-md">
      <template #body>
        <UForm :schema="referenceSchema" :state="form" :validate-on='["submit"]' @submit="save" class="space-y-5">
          <div class="relative fl-form">
            <UInput v-model="form.name" placeholder=" " class="w-full peer/fl-rsname" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-rsname:top-0 peer-focus-within/fl-rsname:-translate-y-1/2 peer-focus-within/fl-rsname:text-xs peer-focus-within/fl-rsname:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-rsname:top-0 peer-has-[input:not(:placeholder-shown)]/fl-rsname:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-rsname:text-xs peer-has-[input:not(:placeholder-shown)]/fl-rsname:text-[var(--ui-text-highlighted)]">Kaynak Adı <span class="text-red-500">*</span></label>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div class="relative fl-select-form ">
              <USelect v-model="form.businessType" :items="businessTypeOptions" value-key="value" placeholder=" " class="w-full" />
              <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', form.businessType ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-1/2 -translate-y-1/2 text-[var(--ui-text-muted)]']">İş Türü</label>
            </div>
            <div class="relative fl-form">
              <UInput v-model.number="form.commissionRate" type="number" :min="0" :max="100" :step="0.01" placeholder=" " class="w-full peer/fl-rscomm" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-rscomm:top-0 peer-focus-within/fl-rscomm:-translate-y-1/2 peer-focus-within/fl-rscomm:text-xs peer-focus-within/fl-rscomm:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-rscomm:top-0 peer-has-[input:not(:placeholder-shown)]/fl-rscomm:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-rscomm:text-xs peer-has-[input:not(:placeholder-shown)]/fl-rscomm:text-[var(--ui-text-highlighted)]">Komisyon (%)</label>
            </div>
          </div>

          <UCheckbox v-model="form.isActive" label="Aktif" />

          <USeparator />

          <div class="flex justify-end gap-2">
            <UButton label="İptal" color="neutral" variant="outline"  :disabled="savingSource" @click="isModalOpen = false" />
            <UButton :label="editingItem ? 'Güncelle' : 'Kaydet'" icon="i-lucide-check"  type="submit" :loading="savingSource" :disabled="savingSource" />
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Silme Onayı -->
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Referans Kaynağı Sil">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-red-50 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-triangle-alert" class="size-5 text-red-500" />
          </div>
          <div>
            <p class="font-medium">Bu referans kaynağını silmek istediğinize emin misiniz?</p>
            <p class="text-sm text-muted mt-1">Bu işlem geri alınamaz.</p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline"  @click="isDeleteModalOpen = false" />
          <UButton label="Sil" color="error" icon="i-lucide-trash-2"  @click="doDelete" />
        </div>
      </template>
    </UModal>
  </div>
</template>

<style scoped>
</style>
