<script setup lang="ts">
import { z } from 'zod'

definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Lead Ürünleri' })

const toast = useToast()
const { post, put, del } = useApi()

interface LeadProduct {
  id: number
  name: string
  color: string | null
  isActive: boolean
  requiresFile: boolean
  sortOrder: number
  createdAt: string
}

const products = usePaginatedData<LeadProduct>({
  endpoint: 'lead-products',
  defaultLimit: 999,
  defaultSort: 'name',
  defaultOrder: 'asc'
})

onMounted(() => products.fetchData())

// Arama
const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => products.setSearch(val), 400)
})

// Tablo
const columns = [
  { accessorKey: 'name', header: 'Ürün Adı', size: 250, minSize: 200 },
  { accessorKey: 'color', header: 'Renk', minSize: 80, maxSize: 80 },
  { accessorKey: 'requiresFile', header: 'Dosya Yükleme', minSize: 110, maxSize: 130 },
  { accessorKey: 'sortOrder', header: 'Sıra', minSize: 80, maxSize: 80 },
  { accessorKey: 'isActive', header: 'Durum', minSize: 90, maxSize: 100 },
  { accessorKey: 'actions', header: '', minSize: 50, maxSize: 50 }
]

// Modal
const isModalOpen = ref(false)
const isDeleteModalOpen = ref(false)
const editingItem = ref<LeadProduct | null>(null)
const deletingItemId = ref<number | null>(null)

const defaultForm = { name: '', color: '#8b5cf6', isActive: true, requiresFile: false, sortOrder: 0 }
const form = ref({ ...defaultForm })

const schema = z.object({
  name: z.string().min(2, 'Ürün adı en az 2 karakter olmalıdır'),
})

function openAddModal() {
  editingItem.value = null
  form.value = { ...defaultForm }
  isModalOpen.value = true
}

function openEditModal(item: LeadProduct) {
  editingItem.value = item
  form.value = {
    name: item.name,
    color: item.color || '#8b5cf6',
    isActive: item.isActive,
    requiresFile: item.requiresFile,
    sortOrder: item.sortOrder
  }
  isModalOpen.value = true
}

const saving = ref(false)
async function save() {
  if (saving.value) return
  saving.value = true
  try {
    if (editingItem.value) {
      await put(`lead-products/${editingItem.value.id}`, form.value)
      toast.add({ title: 'Ürün güncellendi', color: 'success' })
    } else {
      await post('lead-products', form.value)
      toast.add({ title: 'Yeni ürün eklendi', color: 'success' })
    }
    isModalOpen.value = false
    products.refresh()
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
    await del(`lead-products/${deletingItemId.value}`)
    toast.add({ title: 'Ürün silindi', color: 'success' })
    products.refresh()
  } catch {
    toast.add({ title: 'Silinemedi', color: 'error' })
  }
  isDeleteModalOpen.value = false
  deletingItemId.value = null
}

async function toggleActive(item: LeadProduct) {
  try {
    await put(`lead-products/${item.id}`, { isActive: !item.isActive })
    item.isActive = !item.isActive
    toast.add({ title: item.isActive ? 'Ürün aktif edildi' : 'Ürün pasif edildi', color: 'success' })
  } catch {
    toast.add({ title: 'Durum değiştirilemedi', color: 'error' })
  }
}

function getRowActions(item: LeadProduct) {
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
            <h3 class="font-semibold">Lead Ürünleri</h3>
            <p class="text-xs text-muted">Lead'lerde seçilecek ürün listesini yönetin</p>
          </div>
          <div class="flex items-center justify-between gap-2">
            <UInput v-model="searchInput" icon="i-lucide-search" placeholder="Ürün ara..." size="xs" :ui="{ base: 'h-[30px]' }" class="w-[180px]" />
            <UButton label="Yeni Ürün" icon="i-lucide-plus" size="xs" @click="openAddModal" />
          </div>
        </div>
      </template>

      <SkeletonTable v-if="products.loading.value && !products.data.value.length" :rows="5" :cols="4" />
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <UTable
          :data="products.data.value"
          :columns="columns"
          :loading="products.loading.value && !products.data.value.length"
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

          <template #requiresFile-cell="{ row }">
            <span v-if="row.original.requiresFile" class="inline-flex items-center gap-1 text-xs font-semibold text-green-600">
              <UIcon name="i-lucide-check-circle" class="size-3.5" /> Açık
            </span>
            <span v-else class="text-xs text-muted">Kapalı</span>
          </template>

          <template #sortOrder-cell="{ row }">
            <span class="tabular-nums font-semibold">{{ row.original.sortOrder }}</span>
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
        <span class="text-xs text-muted">Toplam {{ products.total.value }} ürün</span>
      </div>
    </UCard>

    <!-- Ekle/Düzenle Modal -->
    <UModal :dismissible="false" v-model:open="isModalOpen" :title="editingItem ? 'Ürün Düzenle' : 'Yeni Ürün'" class="sm:max-w-md">
      <template #body>
        <UForm :schema="schema" :state="form" @submit="save" class="space-y-5">
          <UFormField label="Ürün Adı" name="name" required>
            <UInput v-model="form.name" placeholder="Örneğin: Kasko, Trafik..." icon="i-lucide-package" class="w-full" />
          </UFormField>

          <div class="grid grid-cols-2 gap-4">
            <UFormField label="Badge Rengi" name="color">
              <div class="flex items-center gap-2">
                <input type="color" v-model="form.color" class="w-8 h-8 rounded cursor-pointer border border-default" />
                <span class="text-xs text-muted">{{ form.color }}</span>
              </div>
            </UFormField>

            <UFormField label="Sıralama" name="sortOrder">
              <UInput v-model.number="form.sortOrder" type="number" :min="0" placeholder="0" icon="i-lucide-arrow-up-down" class="w-full" />
            </UFormField>
          </div>

          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium">Dosya Yükleme</p>
              <p class="text-xs text-muted">Bu ürün seçildiğinde dosya yükleme alanı gösterilsin</p>
            </div>
            <USwitch v-model="form.requiresFile" size="sm" />
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
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Ürünü Sil">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-error/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-triangle-alert" class="size-5 text-error" />
          </div>
          <div>
            <p class="font-medium">Bu ürünü silmek istediğinize emin misiniz?</p>
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
