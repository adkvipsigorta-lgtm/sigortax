<script setup lang="ts">
import type { Branch } from '~/types'
import { z } from 'zod'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Tali Acenteler' })

const toast = useToast()
const { post, put, del } = useApi()

// Server-side paginated data
const branches = usePaginatedData<Branch>({
  endpoint: 'branches',
  defaultLimit: 999,
  defaultSort: 'name',
  defaultOrder: 'asc'
})

onMounted(() => branches.fetchData())

// Search
const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    branches.setSearch(val)
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
    branches.setSort(apiKey, val[0].desc ? 'desc' : 'asc')
  } else {
    branches.setSort('name', 'asc')
  }
}, { deep: true })

// Tablo
const columns = [
  { accessorKey: 'name', header: 'Acente Adı', enableSorting: true },
  { accessorKey: 'phone', header: 'Telefon', enableSorting: false },
  { accessorKey: 'commissionRate', header: 'Komisyon', enableSorting: true },
  { accessorKey: 'iban', header: 'IBAN', enableSorting: false },
  { accessorKey: 'isActive', header: 'Durum', enableSorting: false },
  { accessorKey: 'actions', header: '', enableSorting: false }
]

async function toggleActive(branch: any) {
  try {
    await put(`branches/${branch.id}`, { isActive: !branch.isActive })
    branch.isActive = !branch.isActive
    toast.add({ title: branch.isActive ? 'Acente aktif edildi' : 'Acente pasif edildi', color: 'success' })
  } catch {
    toast.add({ title: 'Durum değiştirilemedi', color: 'error' })
  }
}

// Modal
const isModalOpen = ref(false)
const isDeleteModalOpen = ref(false)
const editingBranch = ref<Branch | null>(null)
const deletingBranchId = ref<number | null>(null)

const branchSchema = z.object({
  name: z.string().min(2, 'Acente adı en az 2 karakter olmalıdir'),
  commissionRate: z.number().min(0, 'Komisyon 0\'dan kucuk olamaz').max(100, 'Komisyon 100\'den buyuk olamaz'),
  phone: z.string().optional().or(z.literal('')),
  iban: z.string().optional().or(z.literal(''))
})

const defaultForm = {
  name: '',
  commissionRate: 0,
  phone: '',
  iban: '',
  aliases: [] as string[]
}

const form = ref({ ...defaultForm })
const newAlias = ref('')

function addAlias() {
  const val = newAlias.value.trim().toUpperCase()
  if (!val) return
  if (form.value.aliases.includes(val)) {
    toast.add({ title: 'Bu isim zaten ekli', color: 'warning' })
    return
  }
  form.value.aliases.push(val)
  newAlias.value = ''
}

function removeAlias(index: number) {
  form.value.aliases.splice(index, 1)
}

// IBAN formatlama
function formatIban(value: string) {
  const clean = value.replace(/\s/g, '')
  return clean.match(/.{1,4}/g)?.join(' ') || clean
}

watch(() => form.value.iban, (val) => {
  if (val) {
    const formatted = formatIban(val)
    if (formatted !== val) form.value.iban = formatted
  }
})

function openAddModal() {
  editingBranch.value = null
  form.value = { ...defaultForm, aliases: [] }
  newAlias.value = ''
  isModalOpen.value = true
}

function openEditModal(branch: any) {
  editingBranch.value = branch
  form.value = {
    name: branch.name,
    commissionRate: branch.commissionRate,
    phone: branch.phone || '',
    iban: branch.iban || '',
    aliases: [...(branch.aliases || [])]
  }
  newAlias.value = ''
  isModalOpen.value = true
}

const savingBranch = ref(false)

async function saveBranch() {
  if (savingBranch.value) return
  savingBranch.value = true
  try {
    const payload: Record<string, any> = {
      name: form.value.name,
      commissionRate: form.value.commissionRate,
      phone: form.value.phone || null,
      iban: form.value.iban?.replace(/\s/g, '') || null,
      aliases: form.value.aliases.length > 0 ? form.value.aliases : []
    }

    if (editingBranch.value) {
      await put(`branches/${editingBranch.value.id}`, payload)
      toast.add({ title: 'Acente güncellendi', color: 'success' })
    } else {
      await post('branches', payload)
      toast.add({ title: 'Yeni acente eklendi', color: 'success' })
    }
    isModalOpen.value = false
    branches.refresh()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  }
  savingBranch.value = false
}

function confirmDelete(id: number) {
  deletingBranchId.value = id
  isDeleteModalOpen.value = true
}

async function doDelete() {
  if (!deletingBranchId.value) return
  try {
    await del(`branches/${deletingBranchId.value}`)
    toast.add({ title: 'Acente silindi', color: 'success' })
    branches.refresh()
  } catch {
    toast.add({ title: 'Acente silinemedi', color: 'error' })
  }
  isDeleteModalOpen.value = false
  deletingBranchId.value = null
}

function getRowActions(branch: Branch) {
  return [
    [{ label: 'Düzenle', icon: 'i-lucide-pencil', onSelect: () => openEditModal(branch) }],
    [{ label: 'Sil', icon: 'i-lucide-trash-2', color: 'error' as const, onSelect: () => confirmDelete(branch.id) }]
  ]
}
</script>

<template>
  <div class="space-y-4">
    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-col gap-3">
          <div>
            <h3 class="font-semibold">Tali Acenteler</h3>
            <p class="text-xs text-muted">Tali acente listesi ve komisyon oranları</p>
          </div>
          <div class="flex items-center justify-between gap-2">
            <UInput
              v-model="searchInput"
              icon="i-lucide-search"
              placeholder="Acente ara..."
              size="xs"
              :ui="{ base: 'h-[30px]' }"
              class="w-[180px]"
            />
            <UButton label="Yeni Acente" icon="i-lucide-plus" size="xs" @click="openAddModal" />
          </div>
        </div>
      </template>

      <SkeletonTable v-if="branches.loading.value && !branches.data.value.length" :rows="6" :cols="5" />
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <UTable
          v-model:sorting="sorting"
          :data="branches.data.value"
          :columns="columns"
          :loading="branches.loading.value && !branches.data.value.length"
          :sorting-options="{ manualSorting: true }"
          :ui="{
            base: 'table-fixed min-w-full',
            thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10',
            th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap',
            td: 'py-2 px-3 text-xs whitespace-nowrap overflow-hidden text-ellipsis'
          }"
        >
          <template #name-header="{ column }">
            <SortableHeader label="Acente Adı" :column="column" />
          </template>
          <template #commissionRate-header="{ column }">
            <SortableHeader label="Komisyon" :column="column" />
          </template>

          <template #name-cell="{ row }">
            <NuxtLink :to="`/settings/acenteler/${row.original.id}`" class="font-semibold hover:underline truncate block" :class="row.original.isActive ? 'text-primary' : 'text-muted'" :title="row.original.name">
              {{ row.original.name }}
            </NuxtLink>
          </template>

          <template #phone-cell="{ row }">
            <a v-if="row.original.phone" :href="`tel:${row.original.phone}`" class="text-primary hover:underline">
              {{ row.original.phone }}
            </a>
            <span v-else class="text-muted">—</span>
          </template>

          <template #commissionRate-cell="{ row }">
            <span class="tabular-nums font-semibold">%{{ row.original.commissionRate }}</span>
          </template>

          <template #iban-cell="{ row }">
            <span v-if="row.original.iban" class="font-mono text-muted truncate block" :title="formatIban(row.original.iban)">{{ formatIban(row.original.iban) }}</span>
            <span v-else class="text-muted">—</span>
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
        <span class="text-xs text-muted">Toplam {{ branches.total.value }} acente</span>
      </div>
    </UCard>

    <!-- Ekle/Düzenle Modal -->
    <UModal :dismissible="false" v-model:open="isModalOpen" :title="editingBranch ? 'Acente Düzenle' : 'Yeni Acente'" class="sm:max-w-lg">
      <template #body>
        <UForm :schema="branchSchema" :state="form" @submit="saveBranch" class="space-y-5">
          <!-- Acente Bilgileri -->
          <div class="space-y-4">
            <UFormField label="Acente Adi" name="name" required>
              <UInput v-model="form.name" placeholder="Acente adı" icon="i-lucide-building" class="w-full" />
            </UFormField>

            <div class="grid grid-cols-2 gap-4">
              <UFormField label="Komisyon (%)" name="commissionRate" required>
                <UInput v-model.number="form.commissionRate" type="number" :min="0" :max="100" placeholder="0" icon="i-lucide-percent" class="w-full" />
              </UFormField>

              <UFormField label="Telefon" name="phone">
                <UInput v-model="form.phone" placeholder="+90 5XX XXX XX XX" icon="i-lucide-phone" class="w-full" />
              </UFormField>
            </div>
          </div>

          <USeparator />

          <!-- Banka Bilgileri -->
          <div>
            <h4 class="font-medium text-sm mb-3">Banka Bilgileri</h4>
            <UFormField label="IBAN" name="iban">
              <UInput v-model="form.iban" placeholder="TR00 0000 0000 0000 0000 0000 00" icon="i-lucide-landmark" class="w-full font-mono" maxlength="32" />
            </UFormField>
          </div>

          <USeparator />

          <!-- Alt Şirketler / Resmi Unvanlar -->
          <div>
            <h4 class="font-medium text-sm mb-2">Alt Şirketler / Resmi Unvanlar</h4>
            <p class="text-xs text-muted mb-3">PDF'deki acente adı bu isimlerden biriyle eşleşirse otomatik olarak bu acente seçilir.</p>

            <!-- Eklenen isimler -->
            <div v-if="form.aliases.length > 0" class="space-y-1.5 mb-3">
              <div
                v-for="(alias, i) in form.aliases"
                :key="i"
                class="flex items-center gap-2 px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800/50 border border-default"
              >
                <UIcon name="i-lucide-building" class="size-3.5 text-muted shrink-0" />
                <span class="text-sm flex-1 truncate">{{ alias }}</span>
                <UButton icon="i-lucide-x" size="xs" color="error" variant="ghost" @click="removeAlias(i)" />
              </div>
            </div>

            <!-- Yeni isim ekleme -->
            <div class="flex gap-2">
              <UInput
                v-model="newAlias"
                placeholder="Resmi unvan / alt şirket adı"
                icon="i-lucide-plus-circle"
                class="flex-1"
                size="sm"
                @keydown.enter.prevent="addAlias"
              />
              <UButton label="Ekle" size="sm" color="neutral" variant="outline" :disabled="!newAlias.trim()" @click="addAlias" />
            </div>
          </div>

          <USeparator />

          <div class="flex justify-end gap-2">
            <UButton label="İptal" color="neutral" variant="outline" :disabled="savingBranch" @click="isModalOpen = false" />
            <UButton :label="editingBranch ? 'Güncelle' : 'Kaydet'" icon="i-lucide-check" type="submit" :loading="savingBranch" :disabled="savingBranch" />
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Silme Onay -->
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Acente Sil">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-error/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-triangle-alert" class="size-5 text-error" />
          </div>
          <div>
            <p class="font-medium">Bu acenteyi silmek istediginize emin misiniz?</p>
            <p class="text-sm text-muted mt-1">Bu islem geri alinamaz. Acenteye ait poliçeler etkilenmez.</p>
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
@media (max-width: 767px) {
  :deep(table th:nth-child(4)),
  :deep(table td:nth-child(4)) { display: none; }
}
</style>
