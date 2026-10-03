<script setup lang="ts">
import type { CustomerCategory } from '~/composables/useCustomerCategories'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Müşteri Grupları' })

const toast = useToast()
const { post, put, del } = useApi()

type GroupRow = CustomerCategory & { customerCount?: number, createdAt?: string }

const groups = usePaginatedData<GroupRow>({
  endpoint: 'customer-categories',
  defaultLimit: 15,
  defaultSort: 'name',
  defaultOrder: 'asc'
})

onMounted(() => groups.fetchData())

// Search
const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    groups.setSearch(val)
  }, 400)
})

// Sorting - frontend key -> API column mapping
const sortKeyMap: Record<string, string> = {
  name: 'name',
  customerCount: 'customer_count',
  createdAt: 'created_at'
}

const sorting = ref<{ id: string, desc: boolean }[]>([])
watch(sorting, (val) => {
  if (val.length) {
    const apiKey = sortKeyMap[val[0].id] || val[0].id
    groups.setSort(apiKey, val[0].desc ? 'desc' : 'asc')
  } else {
    groups.setSort('name', 'asc')
  }
}, { deep: true })

const columns = [
  { accessorKey: 'name', header: 'Grup Adı', enableSorting: true },
  { accessorKey: 'range', header: 'Prim Aralığı', enableSorting: false },
  { accessorKey: 'description', header: 'Açıklama', enableSorting: false },
  { accessorKey: 'color', header: 'Renk', enableSorting: false },
  { accessorKey: 'customerCount', header: 'Müşteri Sayısı', enableSorting: true },
  { accessorKey: 'actions', header: '', enableSorting: false }
]

// Yeni / Düzenle
const isModalOpen = ref(false)
const isDeleteModalOpen = ref(false)
const editingGroup = ref<CustomerCategory | null>(null)
const deletingGroupId = ref<number | null>(null)

const form = reactive({
  name: '',
  color: '#3B82F6',
  description: '',
  minAmount: undefined as number | undefined,
  maxAmount: undefined as number | undefined
})

function openAddModal() {
  editingGroup.value = null
  form.name = ''
  form.color = '#3B82F6'
  form.description = ''
  form.minAmount = undefined
  form.maxAmount = undefined
  isModalOpen.value = true
}

function openEditModal(group: CustomerCategory) {
  editingGroup.value = group
  form.name = group.name
  form.color = group.color || '#3B82F6'
  form.description = group.description || ''
  form.minAmount = group.minAmount ?? undefined
  form.maxAmount = group.maxAmount ?? undefined
  isModalOpen.value = true
}

const savingGroup = ref(false)

async function saveGroup() {
  if (savingGroup.value) return
  if (!form.name) {
    toast.add({ title: 'Grup adı zorunludur', color: 'error' })
    return
  }
  try {
    if (editingGroup.value) {
      await put(`customer-categories/${editingGroup.value.id}`, form)
      toast.add({ title: 'Grup güncellendi', color: 'success' })
    } else {
      await post('customer-categories', form)
      toast.add({ title: 'Yeni grup eklendi', color: 'success' })
    }
    isModalOpen.value = false
    groups.refresh()
  } catch (error: any) {
    toast.add({ title: error.message || 'Kaydedilemedi', color: 'error' })
  }
  savingGroup.value = false
}

function confirmDelete(id: number) {
  deletingGroupId.value = id
  isDeleteModalOpen.value = true
}

async function doDelete() {
  if (!deletingGroupId.value) return
  try {
    await del(`customer-categories/${deletingGroupId.value}`)
    toast.add({ title: 'Grup silindi', color: 'success' })
    groups.refresh()
  } catch {
    toast.add({ title: 'Silinemedi', color: 'error' })
  }
  isDeleteModalOpen.value = false
  deletingGroupId.value = null
}

function getRowActions(group: CustomerCategory) {
  if (group.isDefault) {
    return [[{ label: 'Varsayılan grup', icon: 'i-lucide-lock', disabled: true }]]
  }
  return [
    [{ label: 'Düzenle', icon: 'i-lucide-pencil', onSelect: () => openEditModal(group) }],
    [{ label: 'Sil', icon: 'i-lucide-trash-2', color: 'error' as const, onSelect: () => confirmDelete(group.id) }]
  ]
}
</script>

<template>
  <div class="space-y-4">
    <UCard :ui="{ body: 'p-4' }">
      <!-- Ust bar (card içinde) -->
      <template #header>
        <div class="flex items-center justify-between gap-3">
          <UInput
            v-model="searchInput"
            icon="i-lucide-search"
            placeholder="Müşteri grubu ara..."
            class="w-full sm:w-64"
          />
          <UButton label="Yeni" icon="i-lucide-plus" @click="openAddModal" />
        </div>
      </template>

      <SkeletonTable v-if="groups.loading.value && !groups.data.value.length" :rows="8" :cols="4" />
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <UTable
          v-model:sorting="sorting"
          :data="groups.data.value"
          :columns="columns"
          :loading="groups.loading.value && !groups.data.value.length"
          :sorting-options="{ manualSorting: true }"
          :ui="{
            base: 'table-fixed min-w-full gruplar-table',
            thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10',
            th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap',
            td: 'py-2 px-3 text-xs whitespace-nowrap'
          }"
        >
          <template #name-header="{ column }">
            <SortableHeader label="Grup Adı" :column="column" />
          </template>
          <template #customerCount-header="{ column }">
            <SortableHeader label="Müşteri Sayısı" :column="column" />
          </template>

          <template #range-cell="{ row }">
            <span class="tabular-nums">
              {{ row.original.minAmount != null ? Number(row.original.minAmount).toLocaleString('tr-TR') : '0' }}
              -
              {{ row.original.maxAmount != null ? Number(row.original.maxAmount).toLocaleString('tr-TR') : 'Sınırsız' }}
            </span>
          </template>

          <template #name-cell="{ row }">
            <div class="flex items-center gap-2">
              <span class="font-medium">{{ row.original.name }}</span>
              <UBadge v-if="row.original.isDefault" color="neutral" variant="solid" size="sm">Varsayılan</UBadge>
            </div>
          </template>

          <template #description-cell="{ row }">
            <span class="text-muted">{{ row.original.description || '-' }}</span>
          </template>

          <template #color-cell="{ row }">
            <div class="flex items-center gap-2">
              <div
                class="size-5 rounded"
                :style="{ backgroundColor: row.original.color }"
              />
              <span class="text-muted">{{ row.original.color || '-' }}</span>
            </div>
          </template>

          <template #customerCount-cell="{ row }">
            <span class="tabular-nums">{{ row.original.customerCount ?? 0 }}</span>
          </template>

          <template #actions-cell="{ row }">
            <UDropdownMenu :items="getRowActions(row.original)">
              <UButton icon="i-lucide-ellipsis-vertical" color="neutral" variant="ghost" size="xs" />
            </UDropdownMenu>
          </template>
        </UTable>
      </div>

      <div class="h-[2px] w-full overflow-hidden">
        <div v-if="groups.loading.value" class="h-full bg-primary nav-loading-bar" />
      </div>

      <!-- Sayfalama -->
      <div class="flex flex-col sm:flex-row items-center gap-2 py-3 sm:justify-between">
        <div class="flex items-center gap-2 text-muted">
          <span class="hidden sm:inline text-xs">Sayfa başına satır</span>
          <USelect
            :model-value="groups.limit.value"
            :items="[{ label: '10', value: 10 }, { label: '15', value: 15 }, { label: '25', value: 25 }, { label: '50', value: 50 }]"
            value-key="value"
            size="xs"
            class="w-16"
            @update:model-value="(v: any) => { groups.limit.value = v; groups.setPage(1) }"
          />
          <span class="text-xs">{{ (groups.page.value - 1) * groups.limit.value + 1 }} - {{ Math.min(groups.page.value * groups.limit.value, groups.total.value) }} / {{ groups.total.value }}</span>
        </div>

        <div class="flex items-center gap-2 sm:hidden">
          <UButton icon="i-lucide-chevron-left" size="xs" color="neutral" variant="ghost" :disabled="groups.page.value <= 1" @click="groups.setPage(groups.page.value - 1)" />
          <span class="text-xs text-muted whitespace-nowrap">{{ groups.page.value }} / {{ Math.ceil(groups.total.value / groups.limit.value) }}</span>
          <UButton icon="i-lucide-chevron-right" size="xs" color="neutral" variant="ghost" :disabled="groups.page.value >= Math.ceil(groups.total.value / groups.limit.value)" @click="groups.setPage(groups.page.value + 1)" />
        </div>
        <UPagination
          v-if="groups.total.value > groups.limit.value"
          class="hidden sm:flex"
          :default-page="groups.page.value"
          :items-per-page="groups.limit.value"
          :total="groups.total.value"
          @update:page="groups.setPage"
        />
      </div>
    </UCard>

    <!-- Yeni / Düzenle Modal -->
    <UModal :dismissible="false" v-model:open="isModalOpen" :title="editingGroup ? 'Grubu Düzenle' : 'Yeni Müşteri Grubu'">
      <template #body>
        <div class="space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <UFormField label="Grup Adı" required class="sm:col-span-2">
              <UInput v-model="form.name" placeholder="Grup adı" />
            </UFormField>
            <UFormField label="Renk">
              <div class="flex items-center gap-2">
                <label
                  class="size-9 rounded-md border border-default shrink-0 overflow-hidden relative"
                  :style="{ backgroundColor: form.color }"
                >
                  <input
                    v-model="form.color"
                    type="color"
                    class="absolute inset-0 opacity-0 cursor-pointer"
                  >
                </label>
                <UInput v-model="form.color" placeholder="#3B82F6" class="flex-1" />
              </div>
            </UFormField>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <UFormField label="Min Prim">
              <UInput v-model.number="form.minAmount" type="number" :min="0" placeholder="0" class="w-full" />
            </UFormField>
            <UFormField label="Max Prim">
              <UInput v-model.number="form.maxAmount" type="number" :min="0" placeholder="Sınırsız" class="w-full" />
            </UFormField>
          </div>
          <UFormField label="Açıklama">
            <UTextarea v-model="form.description" :rows="2" placeholder="Açıklama" class="w-full" />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="İptal" color="neutral" variant="outline" :disabled="savingGroup" @click="isModalOpen = false" />
          <UButton label="Kaydet" :loading="savingGroup" :disabled="savingGroup" @click="saveGroup" />
        </div>
      </template>
    </UModal>

    <!-- Silme Onay -->
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Grubu Sil">
      <template #body>
        <p>Bu müşteri grubunu silmek istediğinize emin misiniz?</p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" @click="isDeleteModalOpen = false" />
          <UButton label="Sil" color="error" @click="doDelete" />
        </div>
      </template>
    </UModal>
  </div>
</template>

<style scoped>
:deep(.gruplar-table th:nth-child(1)) { width: 160px; min-width: 160px; max-width: 160px; }
:deep(.gruplar-table th:nth-child(2)) { width: 180px; min-width: 180px; max-width: 180px; }
:deep(.gruplar-table th:nth-child(3)) { width: 200px; min-width: 200px; max-width: 200px; }
:deep(.gruplar-table th:nth-child(4)) { width: 90px;  min-width: 90px;  max-width: 90px;  }
:deep(.gruplar-table th:nth-child(5)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.gruplar-table th:nth-child(6)) { width: 60px;  min-width: 60px;  max-width: 60px;  }
:deep(.gruplar-table > tbody > tr > td) { overflow: hidden; text-overflow: ellipsis; }
</style>
