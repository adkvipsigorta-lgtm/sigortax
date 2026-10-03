<script setup lang="ts">
import { z } from 'zod'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Referans Kaynakları' })

const toast = useToast()
const { post, put, del } = useApi()

interface ReferenceSource {
  id: number
  name: string
  commissionRate: number
  businessType: 'NEW' | 'RENEWAL' | null
  isActive: boolean
  policyCount?: number
  createdAt?: string
}

// Server-side paginated data
const sources = usePaginatedData<ReferenceSource>({
  endpoint: 'reference-sources',
  defaultLimit: 999,
  defaultSort: 'name',
  defaultOrder: 'asc'
})

onMounted(() => sources.fetchData())

// Search
const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    sources.setSearch(val)
  }, 400)
})

// Sorting
const sortKeyMap: Record<string, string> = {
  name: 'name',
  commissionRate: 'commission_rate',
  createdAt: 'created_at'
}

const sorting = ref<{ id: string, desc: boolean }[]>([])
watch(sorting, (val) => {
  if (val.length) {
    const apiKey = sortKeyMap[val[0].id] || val[0].id
    sources.setSort(apiKey, val[0].desc ? 'desc' : 'asc')
  } else {
    sources.setSort('name', 'asc')
  }
}, { deep: true })

// Table
const columns = [
  { accessorKey: 'name', header: 'Kaynak Adi', enableSorting: true, size: 250, minSize: 200 },
  { accessorKey: 'businessType', header: 'İş Türü', enableSorting: false, minSize: 100, maxSize: 120 },
  { accessorKey: 'commissionRate', header: 'Komisyon (%)', enableSorting: true, minSize: 120, maxSize: 140 },
  { accessorKey: 'policyCount', header: 'Poliçe Sayısı', enableSorting: false, minSize: 120, maxSize: 140 },
  { accessorKey: 'isActive', header: 'Durum', enableSorting: false, minSize: 90, maxSize: 100 },
  { accessorKey: 'actions', header: '', enableSorting: false, minSize: 50, maxSize: 50 }
]

// Zod schema
const referenceSchema = z.object({
  name: z.string().min(2, 'Kaynak adı en az 2 karakter olmalıdir'),
  commissionRate: z.number().min(0, 'Komisyon 0\'dan kucuk olamaz').max(100, 'Komisyon 100\'den buyuk olamaz'),
  isActive: z.boolean()
})

// Modal
const isModalOpen = ref(false)
const isDeleteModalOpen = ref(false)
const editingItem = ref<ReferenceSource | null>(null)
const deletingItemId = ref<number | null>(null)

const defaultForm = {
  name: '',
  commissionRate: 0,
  businessType: 'ALL' as 'NEW' | 'RENEWAL' | 'ALL',
  isActive: true
}
const form = ref({ ...defaultForm })

const businessTypeOptions = [
  { label: 'Tümü', value: 'ALL' },
  { label: 'Yeni İş', value: 'NEW' },
  { label: 'Yenileme', value: 'RENEWAL' },
]

function openAddModal() {
  editingItem.value = null
  form.value = { ...defaultForm }
  isModalOpen.value = true
}

function openEditModal(item: ReferenceSource) {
  editingItem.value = item
  form.value = {
    name: item.name,
    commissionRate: item.commissionRate,
    businessType: (item.businessType || 'ALL') as 'NEW' | 'RENEWAL' | 'ALL',
    isActive: item.isActive
  }
  isModalOpen.value = true
}

const savingSource = ref(false)

async function save() {
  if (savingSource.value) return
  savingSource.value = true
  try {
    if (editingItem.value) {
      await put(`reference-sources/${editingItem.value.id}`, form.value)
      toast.add({ title: 'Referans kaynağı güncellendi', color: 'success' })
    } else {
      await post('reference-sources', form.value)
      toast.add({ title: 'Yeni referans kaynagi eklendi', color: 'success' })
    }
    isModalOpen.value = false
    sources.refresh()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  savingSource.value = false
}

function confirmDelete(id: number) {
  deletingItemId.value = id
  isDeleteModalOpen.value = true
}

async function doDelete() {
  if (!deletingItemId.value) return
  try {
    await del(`reference-sources/${deletingItemId.value}`)
    toast.add({ title: 'Referans kaynağı silindi', color: 'success' })
    sources.refresh()
  } catch {
    toast.add({ title: 'Silinemedi', color: 'error' })
  }
  isDeleteModalOpen.value = false
  deletingItemId.value = null
}

async function toggleActive(item: ReferenceSource) {
  try {
    await put(`reference-sources/${item.id}`, { isActive: !item.isActive })
    item.isActive = !item.isActive
    toast.add({ title: item.isActive ? 'Kaynak aktif edildi' : 'Kaynak pasif edildi', color: 'success' })
  } catch {
    toast.add({ title: 'Durum değiştirilemedi', color: 'error' })
  }
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
    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-col gap-3">
          <div>
            <h3 class="font-semibold">Referans Kaynakları</h3>
            <p class="text-xs text-muted">İş kaynakları ve komisyon oranları</p>
          </div>
          <div class="flex items-center justify-between gap-2">
            <UInput
              v-model="searchInput"
              icon="i-lucide-search"
              placeholder="Kaynak ara..."
              size="xs"
              :ui="{ base: 'h-[30px]' }"
              class="w-[180px]"
            />
            <UButton label="Yeni Kaynak" icon="i-lucide-plus" size="xs" @click="openAddModal" />
          </div>
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
          :ui="{
            base: 'table-fixed min-w-full',
            thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10',
            th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap',
            td: 'py-2 px-3 text-xs whitespace-nowrap overflow-hidden text-ellipsis'
          }"
        >
          <template #name-header="{ column }">
            <SortableHeader label="Kaynak Adı" :column="column" />
          </template>
          <template #commissionRate-header="{ column }">
            <SortableHeader label="Komisyon" :column="column" />
          </template>

          <template #name-cell="{ row }">
            <span class="font-semibold truncate block" :class="row.original.isActive ? '' : 'text-muted'" :title="row.original.name">{{ row.original.name }}</span>
          </template>

          <template #businessType-cell="{ row }">
            <span class="badge-cell" :class="row.original.businessType === 'NEW' ? 'badge-info' : row.original.businessType === 'RENEWAL' ? 'badge-success' : 'badge-neutral'">
              {{ row.original.businessType === 'NEW' ? 'Yeni İş' : row.original.businessType === 'RENEWAL' ? 'Yenileme' : 'Tümü' }}
            </span>
          </template>

          <template #commissionRate-cell="{ row }">
            <span class="tabular-nums font-semibold">%{{ row.original.commissionRate }}</span>
          </template>

          <template #policyCount-cell="{ row }">
            <span class="tabular-nums font-semibold">{{ row.original.policyCount }}</span>
          </template>

          <template #isActive-cell="{ row }">
            <USwitch :model-value="row.original.isActive" @update:model-value="toggleActive(row.original)" size="xs" />
          </template>

          <template #actions-cell="{ row }">
            <UDropdownMenu :items="getRowActions(row.original)">
              <UButton icon="i-lucide-ellipsis-vertical" color="neutral" variant="ghost" size="xs" />
            </UDropdownMenu>
          </template>
        </UTable>
      </div>

      <div class="flex items-center pt-3 mt-3 border-t border-default">
        <span class="text-xs text-muted">Toplam {{ sources.total.value }} referans kaynağı</span>
      </div>
    </UCard>

    <!-- Add/Edit Modal -->
    <UModal :dismissible="false" v-model:open="isModalOpen" :title="editingItem ? 'Referans Kaynagi Düzenle' : 'Yeni Referans Kaynagi'" class="sm:max-w-md">
      <template #body>
        <UForm :schema="referenceSchema" :state="form" @submit="save" class="space-y-5">
          <UFormField label="Kaynak Adi" name="name" required>
            <UInput v-model="form.name" placeholder="Ornegin: REFERANS, ADK GELEN..." icon="i-lucide-megaphone" class="w-full" />
          </UFormField>

          <div class="grid grid-cols-2 gap-4">
            <UFormField label="İş Türü" name="businessType">
              <USelect v-model="form.businessType" :items="businessTypeOptions" class="w-full" />
            </UFormField>

            <UFormField label="Komisyon Orani (%)" name="commissionRate">
              <UInput v-model.number="form.commissionRate" type="number" :min="0" :max="100" :step="0.01" placeholder="0" icon="i-lucide-percent" class="w-full" />
            </UFormField>
          </div>

          <UCheckbox v-model="form.isActive" label="Aktif" />

          <USeparator />

          <div class="flex justify-end gap-2">
            <UButton label="İptal" color="neutral" variant="outline" :disabled="savingSource" @click="isModalOpen = false" />
            <UButton :label="editingItem ? 'Güncelle' : 'Kaydet'" icon="i-lucide-check" type="submit" :loading="savingSource" :disabled="savingSource" />
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Delete Confirm -->
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Referans Kaynagi Sil">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-error/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-triangle-alert" class="size-5 text-error" />
          </div>
          <div>
            <p class="font-medium">Bu referans kaynagini silmek istediginize emin misiniz?</p>
            <p class="text-sm text-muted mt-1">Bu islem geri alinamaz.</p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" @click="isDeleteModalOpen = false" />
          <UButton label="Sil" color="error" icon="i-lucide-trash-2" @click="doDelete" />
        </div>
      </template>
    </UModal>
  </div>
</template>

<style scoped>
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
.badge-info    { background: rgb(59 130 246 / 0.1); color: #3b82f6; }
.badge-success { background: rgb(34 197 94 / 0.1);  color: #22c55e; }
.badge-neutral { background: rgb(107 114 128 / 0.1); color: #6b7280; }
</style>
