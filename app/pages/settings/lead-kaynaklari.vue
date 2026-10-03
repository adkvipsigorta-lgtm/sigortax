<script setup lang="ts">
import { z } from 'zod'

definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Lead Kaynakları' })

const toast = useToast()
const { get, post, put, del } = useApi()

interface LeadSource {
  id: number
  name: string
  color: string | null
  isActive: boolean
  autoAssignEnabled: boolean
  createdAt: string
}

// Veri
const sources = usePaginatedData<LeadSource>({
  endpoint: 'lead-sources',
  defaultLimit: 999,
  defaultSort: 'name',
  defaultOrder: 'asc'
})

onMounted(() => sources.fetchData())

// Arama
const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => sources.setSearch(val), 400)
})

// Tablo
const columns = [
  { accessorKey: 'name', header: 'Kaynak Adı', size: 200, minSize: 150 },
  { accessorKey: 'color', header: 'Renk', minSize: 80, maxSize: 80 },
  { accessorKey: 'autoAssignEnabled', header: 'Otomatik Atama', minSize: 140, maxSize: 160 },
  { accessorKey: 'isActive', header: 'Durum', minSize: 90, maxSize: 100 },
  { accessorKey: 'actions', header: '', minSize: 50, maxSize: 50 }
]

// Modal
const isModalOpen = ref(false)
const isDeleteModalOpen = ref(false)
const editingItem = ref<LeadSource | null>(null)
const deletingItemId = ref<number | null>(null)

const defaultForm = { name: '', color: '#3b82f6', isActive: true, autoAssignEnabled: false, autoAssignTo: null as number | null }
const form = ref({ ...defaultForm })

// Toggle kapanınca atamayı sıfırla
watch(() => form.value.autoAssignEnabled, (val) => {
  if (!val) form.value.autoAssignTo = null
})

const schema = z.object({
  name: z.string().min(2, 'Kaynak adı en az 2 karakter olmalıdır'),
})

function openAddModal() {
  editingItem.value = null
  form.value = { ...defaultForm }
  isModalOpen.value = true
}

function openEditModal(item: LeadSource) {
  editingItem.value = item
  form.value = {
    name: item.name,
    color: item.color || '#3b82f6',
    isActive: item.isActive,
    autoAssignEnabled: item.autoAssignEnabled,
    autoAssignTo: null
  }
  isModalOpen.value = true
}

const saving = ref(false)
async function save() {
  if (saving.value) return
  saving.value = true
  try {
    if (editingItem.value) {
      await put(`lead-sources/${editingItem.value.id}`, form.value)
      toast.add({ title: 'Kaynak güncellendi', color: 'success' })
    } else {
      await post('lead-sources', form.value)
      toast.add({ title: 'Yeni kaynak eklendi', color: 'success' })
    }
    isModalOpen.value = false
    sources.refresh()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  saving.value = false
}

function confirmDelete(id: number) {
  deletingItemId.value = id
  isDeleteModalOpen.value = true
}

async function doDelete() {
  if (!deletingItemId.value) return
  try {
    await del(`lead-sources/${deletingItemId.value}`)
    toast.add({ title: 'Kaynak silindi', color: 'success' })
    sources.refresh()
  } catch {
    toast.add({ title: 'Silinemedi', color: 'error' })
  }
  isDeleteModalOpen.value = false
  deletingItemId.value = null
}

async function toggleActive(item: LeadSource) {
  try {
    await put(`lead-sources/${item.id}`, { isActive: !item.isActive })
    item.isActive = !item.isActive
    toast.add({ title: item.isActive ? 'Kaynak aktif edildi' : 'Kaynak pasif edildi', color: 'success' })
  } catch {
    toast.add({ title: 'Durum değiştirilemedi', color: 'error' })
  }
}

function getRowActions(item: LeadSource) {
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
            <h3 class="font-semibold">Lead Kaynakları</h3>
            <p class="text-xs text-muted">Lead'lerin geldiği kaynakları yönetin</p>
          </div>
          <div class="flex items-center justify-between gap-2">
            <UInput v-model="searchInput" icon="i-lucide-search" placeholder="Kaynak ara..." size="xs" :ui="{ base: 'h-[30px]' }" class="w-[180px]" />
            <UButton label="Yeni Kaynak" icon="i-lucide-plus" size="xs" @click="openAddModal" />
          </div>
        </div>
      </template>

      <SkeletonTable v-if="sources.loading.value && !sources.data.value.length" :rows="5" :cols="4" />
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <UTable
          :data="sources.data.value"
          :columns="columns"
          :loading="sources.loading.value && !sources.data.value.length"
          :ui="{
            base: 'table-fixed min-w-full',
            thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10',
            th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap',
            td: 'py-2 px-3 text-xs whitespace-nowrap overflow-hidden text-ellipsis'
          }"
        >
          <template #name-cell="{ row }">
            <span class="font-semibold" :class="row.original.isActive ? '' : 'text-muted'">{{ row.original.name }}</span>
          </template>

          <template #color-cell="{ row }">
            <span v-if="row.original.color" class="inline-block w-5 h-5 rounded" :style="{ backgroundColor: row.original.color }" />
            <span v-else class="text-muted">-</span>
          </template>

          <template #autoAssignEnabled-cell="{ row }">
            <span v-if="row.original.autoAssignEnabled" class="inline-flex items-center gap-1 text-xs font-semibold text-green-600">
              <UIcon name="i-lucide-check-circle" class="size-3.5" /> Açık
            </span>
            <span v-else class="text-xs text-muted">Kapalı</span>
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
        <span class="text-xs text-muted">Toplam {{ sources.total.value }} kaynak</span>
      </div>
    </UCard>

    <!-- Ekle/Düzenle Modal -->
    <UModal :dismissible="false" v-model:open="isModalOpen" :title="editingItem ? 'Kaynak Düzenle' : 'Yeni Kaynak'" class="sm:max-w-md">
      <template #body>
        <UForm :schema="schema" :state="form" @submit="save" class="space-y-5">
          <UFormField label="Kaynak Adı" name="name" required>
            <UInput v-model="form.name" placeholder="Örneğin: sigortax.net" icon="i-lucide-globe" class="w-full" />
          </UFormField>

          <UFormField label="Badge Rengi" name="color">
            <div class="flex items-center gap-2">
              <input type="color" v-model="form.color" class="w-8 h-8 rounded cursor-pointer border border-default" />
              <span class="text-xs text-muted">{{ form.color }}</span>
            </div>
          </UFormField>

          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium">Otomatik Atama</p>
              <p class="text-xs text-muted">Bu kaynaktan gelen lead'ler satış personellerine otomatik dağıtılsın</p>
            </div>
            <USwitch v-model="form.autoAssignEnabled" size="sm" />
          </div>

          <UCheckbox v-model="form.isActive" label="Aktif" />

          <USeparator />

          <div class="flex justify-end gap-2">
            <UButton label="İptal" color="neutral" variant="outline" :disabled="saving" @click="isModalOpen = false" />
            <UButton :label="editingItem ? 'Güncelle' : 'Kaydet'" icon="i-lucide-check" type="submit" :loading="saving" :disabled="saving" />
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Silme Onayı -->
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Kaynağı Sil">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-error/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-triangle-alert" class="size-5 text-error" />
          </div>
          <div>
            <p class="font-medium">Bu kaynağı silmek istediğinize emin misiniz?</p>
            <p class="text-sm text-muted mt-1">Bu işlem geri alınamaz.</p>
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
