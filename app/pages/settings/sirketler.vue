<script setup lang="ts">
import type { Company } from '~/types'
import { z } from 'zod'

definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Sigorta Şirketleri' })

const toast = useToast()
const { post, put, del } = useApi()

const companies = usePaginatedData<Company>({ endpoint: 'companies', defaultLimit: 999, defaultSort: 'name', defaultOrder: 'asc' })
onMounted(() => companies.fetchData())

const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => { if (searchTimeout) clearTimeout(searchTimeout); searchTimeout = setTimeout(() => companies.setSearch(val), 400) })

const sortKeyMap: Record<string, string> = { name: 'name', activePolicyCount: 'active_policy_count', policyCount: 'policy_count', createdAt: 'created_at' }
const sorting = ref<{ id: string, desc: boolean }[]>([])
watch(sorting, (val) => { if (val.length) { companies.setSort(sortKeyMap[val[0].id] || val[0].id, val[0].desc ? 'desc' : 'asc') } else { companies.setSort('name', 'asc') } }, { deep: true })

const columns = [
  { accessorKey: 'logo', header: '', enableSorting: false, minSize: 50, maxSize: 50 },
  { accessorKey: 'name', header: 'Şirket Adı', enableSorting: true, size: 250, minSize: 200 },
  { accessorKey: 'color', header: 'Renk', enableSorting: false, minSize: 70, maxSize: 70 },
  { accessorKey: 'website', header: 'Web Sitesi', enableSorting: false, minSize: 200, maxSize: 250 },
  { accessorKey: 'activePolicyCount', header: 'Aktif Poliçe', enableSorting: true, minSize: 110, maxSize: 110 },
  { accessorKey: 'policyCount', header: 'Toplam Poliçe', enableSorting: true, minSize: 110, maxSize: 110 },
  { accessorKey: 'createdAt', header: 'Kayıt Tarihi', enableSorting: true, minSize: 120, maxSize: 120 },
  { accessorKey: 'actions', header: '', enableSorting: false, minSize: 50, maxSize: 50 }
]

const isModalOpen = ref(false)
const isDeleteModalOpen = ref(false)
const editingCompany = ref<Company | null>(null)
const deletingCompanyId = ref<number | null>(null)

const companySchema = z.object({
  name: z.string().min(2, 'Şirket adı en az 2 karakter olmalıdır'),
  website: z.string().url('Geçerli bir URL giriniz').or(z.literal('')).optional(),
  logo: z.string().optional(),
  color: z.string().regex(/^#[0-9a-fA-F]{6}$/, 'Geçerli bir renk kodu giriniz').optional()
})

type CompanyForm = z.infer<typeof companySchema>
const defaultForm: CompanyForm = { name: '', website: '', logo: '', color: '#3b82f6' }
const form = ref<CompanyForm>({ ...defaultForm })
const logoPreview = ref<string | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)

function openAddModal() { editingCompany.value = null; form.value = { ...defaultForm }; logoPreview.value = null; isModalOpen.value = true }
function openEditModal(company: Company) {
  editingCompany.value = company
  form.value = { name: company.name, website: company.website || '', logo: company.logo || '', color: company.color || '#3b82f6' }
  logoPreview.value = company.logo || null; isModalOpen.value = true
}

function onLogoFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  if (!input.files?.length) return
  const file = input.files[0]
  if (file.size > 2 * 1024 * 1024) { toast.add({ title: 'Logo 2MB\'den büyük olamaz', color: 'error' }); return }
  const reader = new FileReader()
  reader.onload = () => { logoPreview.value = reader.result as string; form.value.logo = reader.result as string }
  reader.readAsDataURL(file)
}

function removeLogo() { logoPreview.value = null; form.value.logo = ''; if (fileInputRef.value) fileInputRef.value.value = '' }

const savingCompany = ref(false)
async function saveCompany() {
  if (savingCompany.value) return
  const result = companySchema.safeParse(form.value); if (!result.success) return
  savingCompany.value = true
  try {
    const payload: Record<string, any> = { name: form.value.name, website: form.value.website || null, color: form.value.color || '#3b82f6' }
    if (form.value.logo?.startsWith('data:')) payload.logoBase64 = form.value.logo
    else if (form.value.logo) payload.logo = form.value.logo
    else payload.logo = null
    if (editingCompany.value) { await put(`companies/${editingCompany.value.id}`, payload); toast.add({ title: 'Şirket güncellendi', color: 'success' }) }
    else { await post('companies', payload); toast.add({ title: 'Yeni şirket eklendi', color: 'success' }) }
    isModalOpen.value = false; companies.refresh()
  } catch (error: any) { toast.add({ title: error.message || 'İşlem başarısız', color: 'error' }) }
  savingCompany.value = false
}

function confirmDelete(id: number) { deletingCompanyId.value = id; isDeleteModalOpen.value = true }
async function doDelete() {
  if (!deletingCompanyId.value) return
  try { await del(`companies/${deletingCompanyId.value}`); toast.add({ title: 'Şirket silindi', color: 'success' }); companies.refresh() }
  catch { toast.add({ title: 'Şirket silinemedi', color: 'error' }) }
  isDeleteModalOpen.value = false; deletingCompanyId.value = null
}

function getRowActions(company: Company) {
  return [[{ label: 'Düzenle', icon: 'i-lucide-pencil', onSelect: () => openEditModal(company) }], [{ label: 'Sil', icon: 'i-lucide-trash-2', color: 'error' as const, onSelect: () => confirmDelete(company.id) }]]
}

function formatDate(date?: string | null) { if (!date) return '-'; return new Date(date).toLocaleDateString('tr-TR') }
function getDomain(url?: string | null) { if (!url) return null; try { return new URL(url).hostname.replace('www.', '') } catch { return url } }
</script>

<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-2xl font-semibold">Sigorta Şirketleri</h1>
      <p class="text-sm text-muted mt-1">Şirket listesi ve yönetimi.</p>
    </div>

    <UCard :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <UInput v-model="searchInput" placeholder="Şirket Ara" icon="i-lucide-search" class="filter-w-search" />
          <UButton label="Yeni Şirket" icon="i-lucide-plus"  @click="openAddModal" />
        </div>
      </template>

      <SkeletonTable v-if="companies.loading.value && !companies.data.value.length" :rows="8" :cols="4" />
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <UTable v-model:sorting="sorting" :data="companies.data.value" :columns="columns" :loading="companies.loading.value && !companies.data.value.length" :sorting-options="{ manualSorting: true }" :ui="{ base: 'table-fixed min-w-full sirketler-table', thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10', th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap', td: 'py-2 px-3 text-xs whitespace-nowrap overflow-hidden' }">
          <template #name-header="{ column }"><SortableHeader label="Şirket Adı" :column="column" /></template>
          <template #activePolicyCount-header="{ column }"><SortableHeader label="Aktif Poliçe" :column="column" /></template>
          <template #policyCount-header="{ column }"><SortableHeader label="Toplam Poliçe" :column="column" /></template>
          <template #createdAt-header="{ column }"><SortableHeader label="Kayıt Tarihi" :column="column" /></template>

          <template #logo-cell="{ row }">
            <img v-if="row.original.logo" :src="row.original.logo" :alt="row.original.name" class="size-8 rounded object-contain bg-white p-0.5" @error="($event.target as HTMLImageElement).style.display = 'none'">
            <div v-else class="size-8 rounded bg-neutral-100 flex items-center justify-center text-xs font-bold text-muted">{{ row.original.name.charAt(0) }}</div>
          </template>
          <template #name-cell="{ row }"><span class="overflow-hidden whitespace-nowrap block" :title="row.original.name">{{ row.original.name }}</span></template>
          <template #color-cell="{ row }"><div class="size-5 rounded-full border border-default" :style="{ backgroundColor: row.original.color || '#3b82f6' }" /></template>
          <template #website-cell="{ row }">
            <a v-if="row.original.website" :href="row.original.website" target="_blank" rel="noopener" class="text-primary hover:underline flex items-center gap-1"><UIcon name="i-lucide-external-link" class="size-3.5" />{{ getDomain(row.original.website) }}</a>
            <span v-else class="text-muted">-</span>
          </template>
          <template #activePolicyCount-cell="{ row }"><span>{{ row.original.activePolicyCount ?? 0 }}</span></template>
          <template #policyCount-cell="{ row }"><span>{{ row.original.policyCount ?? 0 }}</span></template>
          <template #createdAt-cell="{ row }"><span class="text-muted">{{ formatDate(row.original.createdAt) }}</span></template>
          <template #actions-cell="{ row }">
            <UDropdownMenu :items="getRowActions(row.original)"><UButton icon="i-lucide-ellipsis-vertical" color="neutral" variant="ghost" size="xs" /></UDropdownMenu>
          </template>
        </UTable>
      </div>

      <div class="flex items-center pt-3 mt-3 border-t border-default">
        <span class="text-xs text-muted">Toplam {{ companies.total.value }} şirket</span>
      </div>
    </UCard>

    <!-- Ekle/Düzenle Modal -->
    <UModal :dismissible="false" v-model:open="isModalOpen" :title="editingCompany ? 'Şirket Düzenle' : 'Yeni Şirket'" class="sm:max-w-lg">
      <template #body>
        <UForm :schema="companySchema" :state="form" :validate-on='["submit"]' @submit="saveCompany" class="space-y-5">
          <!-- Logo -->
          <div class="flex flex-col items-center gap-3">
            <div class="size-20 rounded-xl border-2 border-dashed border-default flex items-center justify-center bg-neutral-50 overflow-hidden cursor-pointer transition hover:border-primary" @click="fileInputRef?.click()">
              <img v-if="logoPreview" :src="logoPreview" alt="Logo" class="size-full object-contain p-2">
              <div v-else class="flex flex-col items-center gap-1 text-muted"><UIcon name="i-lucide-image-plus" class="size-6" /><span class="text-[10px]">Logo</span></div>
            </div>
            <div v-if="logoPreview" class="flex items-center gap-1">
              <UButton label="Değiştir" icon="i-lucide-refresh-cw" color="neutral" variant="ghost" size="xs" @click="fileInputRef?.click()" />
              <UButton label="Kaldır" icon="i-lucide-trash-2" color="error" variant="ghost" size="xs" @click="removeLogo" />
            </div>
            <input ref="fileInputRef" type="file" class="hidden" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" @change="onLogoFileChange">
          </div>

          <USeparator />

          <!-- Şirket Adı -->
          <div class="relative fl-form">
            <UInput v-model="form.name" placeholder=" " class="w-full peer/fl-cname" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-cname:top-0 peer-focus-within/fl-cname:-translate-y-1/2 peer-focus-within/fl-cname:text-xs peer-focus-within/fl-cname:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-cname:top-0 peer-has-[input:not(:placeholder-shown)]/fl-cname:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-cname:text-xs peer-has-[input:not(:placeholder-shown)]/fl-cname:text-[var(--ui-text-highlighted)]">Şirket Adı <span class="text-red-500">*</span></label>
          </div>

          <!-- Web Sitesi -->
          <div class="relative fl-form">
            <UInput v-model="form.website" placeholder=" " class="w-full peer/fl-cweb" data-no-uppercase />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-cweb:top-0 peer-focus-within/fl-cweb:-translate-y-1/2 peer-focus-within/fl-cweb:text-xs peer-focus-within/fl-cweb:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-cweb:top-0 peer-has-[input:not(:placeholder-shown)]/fl-cweb:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-cweb:text-xs peer-has-[input:not(:placeholder-shown)]/fl-cweb:text-[var(--ui-text-highlighted)]">Web Sitesi</label>
          </div>

          <!-- Renk -->
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

          <USeparator />

          <!-- Logo URL -->
          <div class="relative fl-form">
            <UInput :model-value="form.logo" placeholder=" " class="w-full peer/fl-curl" data-no-uppercase @update:model-value="(v: string) => { form.logo = v; logoPreview = v || null }" />
            <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-curl:top-0 peer-focus-within/fl-curl:-translate-y-1/2 peer-focus-within/fl-curl:text-xs peer-focus-within/fl-curl:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-curl:top-0 peer-has-[input:not(:placeholder-shown)]/fl-curl:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-curl:text-xs peer-has-[input:not(:placeholder-shown)]/fl-curl:text-[var(--ui-text-highlighted)]">Logo URL (alternatif)</label>
          </div>

          <USeparator />

          <div class="flex justify-end gap-2">
            <UButton label="İptal" color="neutral" variant="outline"  :disabled="savingCompany" @click="isModalOpen = false" />
            <UButton :label="editingCompany ? 'Güncelle' : 'Kaydet'" icon="i-lucide-check"  type="submit" :loading="savingCompany" :disabled="savingCompany" />
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Silme Onayı -->
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Şirket Sil">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-red-50 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-triangle-alert" class="size-5 text-red-500" />
          </div>
          <div>
            <p class="font-medium">Bu şirketi silmek istediğinize emin misiniz?</p>
            <p class="text-sm text-muted mt-1">Bu işlem geri alınamaz. Şirkete ait poliçeler etkilenmez.</p>
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
:deep(.sirketler-table th:nth-child(1)) { width: 50px; min-width: 50px; max-width: 50px; }
:deep(.sirketler-table th:nth-child(2)) { width: 200px; min-width: 200px; max-width: 200px; }
:deep(.sirketler-table th:nth-child(3)) { width: 60px; min-width: 60px; max-width: 60px; }
:deep(.sirketler-table th:nth-child(4)) { width: 180px; min-width: 180px; max-width: 180px; }
:deep(.sirketler-table th:nth-child(5)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.sirketler-table th:nth-child(6)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.sirketler-table th:nth-child(7)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.sirketler-table th:nth-child(8)) { width: 60px; min-width: 60px; max-width: 60px; }
:deep(.sirketler-table > tbody > tr > td) { overflow: hidden; text-overflow: clip; }

@media (max-width: 767px) {
  :deep(.sirketler-table th:nth-child(3)), :deep(.sirketler-table td:nth-child(3)),
  :deep(.sirketler-table th:nth-child(4)), :deep(.sirketler-table td:nth-child(4)),
  :deep(.sirketler-table th:nth-child(6)), :deep(.sirketler-table td:nth-child(6)),
  :deep(.sirketler-table th:nth-child(7)), :deep(.sirketler-table td:nth-child(7)) { display: none; }
}
</style>
