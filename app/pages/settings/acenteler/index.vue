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

const branches = usePaginatedData<Branch>({
  endpoint: 'branches',
  defaultLimit: 999,
  defaultSort: 'name',
  defaultOrder: 'asc'
})

onMounted(() => branches.fetchData())

const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => { branches.setSearch(val) }, 400)
})

const sortKeyMap: Record<string, string> = { name: 'name', commissionRate: 'commission_rate', createdAt: 'created_at' }
const sorting = ref<{ id: string, desc: boolean }[]>([])
watch(sorting, (val) => {
  if (val.length) { branches.setSort(sortKeyMap[val[0].id] || val[0].id, val[0].desc ? 'desc' : 'asc') }
  else { branches.setSort('name', 'asc') }
}, { deep: true })

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
  } catch { toast.add({ title: 'Durum değiştirilemedi', color: 'error' }) }
}

// Modal
const isModalOpen = ref(false)
const isDeleteModalOpen = ref(false)
const editingBranch = ref<Branch | null>(null)
const deletingBranchId = ref<number | null>(null)

const branchSchema = z.object({
  name: z.string().min(2, 'Acente adı en az 2 karakter olmalıdır'),
  commissionRate: z.number().min(0, 'Komisyon 0\'dan küçük olamaz').max(100, 'Komisyon 100\'den büyük olamaz'),
  phone: z.string().optional().or(z.literal('')),
  iban: z.string().optional().or(z.literal(''))
})

const defaultForm = { name: '', commissionRate: 0, phone: '', iban: '', aliases: [] as string[] }
const form = ref({ ...defaultForm })
const newAlias = ref('')

function addAlias() {
  const val = newAlias.value.trim().toUpperCase()
  if (!val) return
  if (form.value.aliases.includes(val)) { toast.add({ title: 'Bu isim zaten ekli', color: 'warning' }); return }
  form.value.aliases.push(val)
  newAlias.value = ''
}

function removeAlias(index: number) { form.value.aliases.splice(index, 1) }

function formatIban(value: string) {
  const clean = value.replace(/\s/g, '')
  return clean.match(/.{1,4}/g)?.join(' ') || clean
}

watch(() => form.value.iban, (val) => {
  if (val) { const formatted = formatIban(val); if (formatted !== val) form.value.iban = formatted }
})

function openAddModal() {
  editingBranch.value = null
  form.value = { ...defaultForm, aliases: [] }
  newAlias.value = ''
  isModalOpen.value = true
}

function openEditModal(branch: any) {
  editingBranch.value = branch
  form.value = { name: branch.name, commissionRate: branch.commissionRate, phone: branch.phone || '', iban: branch.iban || '', aliases: [...(branch.aliases || [])] }
  newAlias.value = ''
  isModalOpen.value = true
}

const savingBranch = ref(false)

async function saveBranch() {
  if (savingBranch.value) return
  savingBranch.value = true
  try {
    const payload: Record<string, any> = { name: form.value.name, commissionRate: form.value.commissionRate, phone: form.value.phone || null, iban: form.value.iban?.replace(/\s/g, '') || null, aliases: form.value.aliases.length > 0 ? form.value.aliases : [] }
    if (editingBranch.value) { await put(`branches/${editingBranch.value.id}`, payload); toast.add({ title: 'Acente güncellendi', color: 'success' }) }
    else { await post('branches', payload); toast.add({ title: 'Yeni acente eklendi', color: 'success' }) }
    isModalOpen.value = false
    branches.refresh()
  } catch (error: any) { toast.add({ title: error.message || 'İşlem başarısız', color: 'error' }) }
  savingBranch.value = false
}

function confirmDelete(id: number) { deletingBranchId.value = id; isDeleteModalOpen.value = true }

async function doDelete() {
  if (!deletingBranchId.value) return
  try { await del(`branches/${deletingBranchId.value}`); toast.add({ title: 'Acente silindi', color: 'success' }); branches.refresh() }
  catch { toast.add({ title: 'Acente silinemedi', color: 'error' }) }
  isDeleteModalOpen.value = false; deletingBranchId.value = null
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
    <!-- Sayfa Başlığı -->
    <div class="mb-6 pb-4 border-b border-default">
      <h1 class="text-xl">Tali Acenteler</h1>
      <p class="text-sm text-muted mt-1">Tali acente listesi ve komisyon oranları.</p>
    </div>

    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="relative w-full sm:w-[250px] [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput v-model="searchInput" placeholder=" " class="w-full peer/fl-bsearch" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-bsearch:top-0 peer-focus-within/fl-bsearch:-translate-y-1/2 peer-focus-within/fl-bsearch:text-xs peer-focus-within/fl-bsearch:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-bsearch:top-0 peer-has-[input:not(:placeholder-shown)]/fl-bsearch:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-bsearch:text-xs peer-has-[input:not(:placeholder-shown)]/fl-bsearch:text-[var(--ui-text-highlighted)]">Acente Ara</label>
          </div>
          <UButton label="Yeni Acente" icon="i-lucide-plus" size="xl"  @click="openAddModal" />
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
          <template #name-header="{ column }"><SortableHeader label="Acente Adı" :column="column" /></template>
          <template #commissionRate-header="{ column }"><SortableHeader label="Komisyon" :column="column" /></template>

          <template #name-cell="{ row }">
            <NuxtLink :to="`/settings/acenteler/${row.original.id}`" class="hover:underline truncate block" :class="row.original.isActive ? 'text-primary' : 'text-muted'" :title="row.original.name">{{ row.original.name }}</NuxtLink>
          </template>
          <template #phone-cell="{ row }">
            <a v-if="row.original.phone" :href="`tel:${row.original.phone}`" class="text-primary hover:underline">{{ row.original.phone }}</a>
            <span v-else class="text-muted">—</span>
          </template>
          <template #commissionRate-cell="{ row }"><span class="tabular-nums">%{{ row.original.commissionRate }}</span></template>
          <template #iban-cell="{ row }">
            <span v-if="row.original.iban" class="font-mono text-muted truncate block" :title="formatIban(row.original.iban)">{{ formatIban(row.original.iban) }}</span>
            <span v-else class="text-muted">—</span>
          </template>
          <template #isActive-cell="{ row }"><USwitch :model-value="row.original.isActive" @update:model-value="toggleActive(row.original)" size="xs" /></template>
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
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5 sm:col-span-2">
              <UInput v-model="form.name" placeholder=" " class="w-full peer/fl-bname" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-bname:top-0 peer-focus-within/fl-bname:-translate-y-1/2 peer-focus-within/fl-bname:text-xs peer-focus-within/fl-bname:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-bname:top-0 peer-has-[input:not(:placeholder-shown)]/fl-bname:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-bname:text-xs peer-has-[input:not(:placeholder-shown)]/fl-bname:text-[var(--ui-text-highlighted)]">Acente Adı <span class="text-red-500">*</span></label>
            </div>
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model.number="form.commissionRate" type="number" :min="0" :max="100" placeholder=" " class="w-full peer/fl-bcomm" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-bcomm:top-0 peer-focus-within/fl-bcomm:-translate-y-1/2 peer-focus-within/fl-bcomm:text-xs peer-focus-within/fl-bcomm:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-bcomm:top-0 peer-has-[input:not(:placeholder-shown)]/fl-bcomm:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-bcomm:text-xs peer-has-[input:not(:placeholder-shown)]/fl-bcomm:text-[var(--ui-text-highlighted)]">Komisyon (%) <span class="text-red-500">*</span></label>
            </div>
            <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
              <UInput v-model="form.phone" placeholder=" " class="w-full peer/fl-bphone" />
              <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-bphone:top-0 peer-focus-within/fl-bphone:-translate-y-1/2 peer-focus-within/fl-bphone:text-xs peer-focus-within/fl-bphone:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-bphone:top-0 peer-has-[input:not(:placeholder-shown)]/fl-bphone:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-bphone:text-xs peer-has-[input:not(:placeholder-shown)]/fl-bphone:text-[var(--ui-text-highlighted)]">Telefon</label>
            </div>
          </div>

          <USeparator />

          <!-- IBAN -->
          <div class="relative [&_input]:!pt-5 [&_input]:!pb-2.5">
            <UInput v-model="form.iban" placeholder=" " class="w-full font-mono peer/fl-biban" maxlength="32" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-biban:top-0 peer-focus-within/fl-biban:-translate-y-1/2 peer-focus-within/fl-biban:text-xs peer-focus-within/fl-biban:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-biban:top-0 peer-has-[input:not(:placeholder-shown)]/fl-biban:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-biban:text-xs peer-has-[input:not(:placeholder-shown)]/fl-biban:text-[var(--ui-text-highlighted)]">IBAN</label>
          </div>

          <USeparator />

          <!-- Alt Şirketler -->
          <div>
            <h4 class="font-medium text-sm mb-2">Alt Şirketler / Resmî Unvanlar</h4>
            <p class="text-xs text-muted mb-3">PDF'deki acente adı bu isimlerden biriyle eşleşirse otomatik olarak bu acente seçilir.</p>

            <div v-if="form.aliases.length > 0" class="space-y-1.5 mb-3">
              <div v-for="(alias, i) in form.aliases" :key="i" class="flex items-center gap-2 px-3 py-2 rounded-lg bg-neutral-50 border border-default">
                <UIcon name="i-lucide-building" class="size-3.5 text-muted shrink-0" />
                <span class="text-sm flex-1 truncate">{{ alias }}</span>
                <UButton icon="i-lucide-x" size="xs" color="error" variant="ghost" @click="removeAlias(i)" />
              </div>
            </div>

            <div class="flex gap-2">
              <div class="relative flex-1 [&_input]:!pt-5 [&_input]:!pb-2.5">
                <UInput v-model="newAlias" placeholder=" " class="w-full peer/fl-balias" @keydown.enter.prevent="addAlias" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-balias:top-0 peer-focus-within/fl-balias:-translate-y-1/2 peer-focus-within/fl-balias:text-xs peer-focus-within/fl-balias:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-balias:top-0 peer-has-[input:not(:placeholder-shown)]/fl-balias:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-balias:text-xs peer-has-[input:not(:placeholder-shown)]/fl-balias:text-[var(--ui-text-highlighted)]">Resmî unvan / alt şirket adı</label>
              </div>
              <UButton label="Ekle" size="xl"  color="neutral" variant="outline" :disabled="!newAlias.trim()" @click="addAlias" />
            </div>
          </div>

          <USeparator />

          <div class="flex justify-end gap-2">
            <UButton label="İptal" color="neutral" variant="outline" size="xl"  :disabled="savingBranch" @click="isModalOpen = false" />
            <UButton :label="editingBranch ? 'Güncelle' : 'Kaydet'" icon="i-lucide-check" size="xl"  type="submit" :loading="savingBranch" :disabled="savingBranch" />
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Silme Onay -->
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Acente Sil">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-red-50 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-triangle-alert" class="size-5 text-red-500" />
          </div>
          <div>
            <p class="font-medium">Bu acenteyi silmek istediğinize emin misiniz?</p>
            <p class="text-sm text-muted mt-1">Bu işlem geri alınamaz. Acenteye ait poliçeler etkilenmez.</p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" size="xl"  @click="isDeleteModalOpen = false" />
          <UButton label="Sil" color="error" icon="i-lucide-trash-2" size="xl"  @click="doDelete" />
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
