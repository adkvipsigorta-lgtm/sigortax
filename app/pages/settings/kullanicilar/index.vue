<script setup lang="ts">
import { z } from 'zod'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Takım Yönetimi' })

const toast = useToast()
const { get, post, put, del } = useApi()
const { user: currentUser } = useAuth()

interface UserItem {
  id: number
  name: string
  email: string
  role: 'admin' | 'kullanıcı' | 'acente' | 'musteri'
  branchId: number | null
  isActive: boolean
  isSalesRep: boolean
  customerIds?: number[]
  createdAt: string
}

interface Branch {
  id: number
  name: string
}

// Server-side paginated data
const users = usePaginatedData<UserItem>({
  endpoint: 'users',
  defaultLimit: 999,
  defaultSort: 'created_at',
  defaultOrder: 'desc'
})

const branches = ref<Branch[]>([])

onMounted(async () => {
  users.fetchData()
  try {
    const res = await get<any>('branches?all=1')
    branches.value = res.data || []
  } catch {}
})

// Search
const searchInput = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    users.setSearch(val)
  }, 400)
})

// Sorting
const sortKeyMap: Record<string, string> = {
  name: 'name',
  role: 'role',
  isActive: 'is_active',
  createdAt: 'created_at'
}

const sorting = ref<{ id: string, desc: boolean }[]>([])
watch(sorting, (val) => {
  if (val.length) {
    const apiKey = sortKeyMap[val[0].id] || val[0].id
    users.setSort(apiKey, val[0].desc ? 'desc' : 'asc')
  } else {
    users.setSort('created_at', 'desc')
  }
}, { deep: true })

// Modal state
const modalOpen = ref(false)
const editing = ref<UserItem | null>(null)
const saving = ref(false)

const form = reactive({
  name: '',
  email: '',
  password: '',
  role: 'kullanıcı' as string,
  branchId: null as number | null,
  isActive: true,
  isSalesRep: true,
  customerIds: [] as number[]
})

// Musteri arama (portal kullanicisi icin)
const customerSearchQuery = ref('')
const customerSearchResults = ref<{ id: number, name: string }[]>([])
const customerSearching = ref(false)
let customerSearchTimeout: ReturnType<typeof setTimeout> | null = null

watch(customerSearchQuery, (val) => {
  if (customerSearchTimeout) clearTimeout(customerSearchTimeout)
  if (!val || val.length < 2) { customerSearchResults.value = []; return }
  customerSearchTimeout = setTimeout(async () => {
    customerSearching.value = true
    try {
      const res = await get<any>('customers/search', { q: val })
      customerSearchResults.value = (res.data || []).filter((c: any) => !form.customerIds.includes(c.id))
    } catch {}
    customerSearching.value = false
  }, 400)
})

function addCustomer(c: { id: number, name: string }) {
  if (!form.customerIds.includes(c.id)) {
    form.customerIds.push(c.id)
    selectedCustomerNames.value[c.id] = c.name
  }
  customerSearchQuery.value = ''
  customerSearchResults.value = []
}

function removeCustomer(id: number) {
  form.customerIds = form.customerIds.filter(cid => cid !== id)
  delete selectedCustomerNames.value[id]
}

const selectedCustomerNames = ref<Record<number, string>>({})

const formSchema = computed(() => {
  const base = z.object({
    name: z.string().min(2, 'Ad en az 2 karakter olmalıdir'),
    email: z.string().email('Geçerli bir e-posta girin'),
    password: editing.value
      ? z.string().optional().or(z.literal(''))
      : z.string().min(6, 'Şifre en az 6 karakter olmalıdir'),
    role: z.string().min(1, 'Rol seçin'),
    branchId: z.any().optional(),
    isActive: z.boolean()
  })
  return base
})

// Delete
const deleteModalOpen = ref(false)
const deletingUser = ref<UserItem | null>(null)
const deleting = ref(false)


const roleLabels: Record<string, string> = {
  admin: 'Yönetici',
  acente: 'Acente',
  kullanıcı: 'Kullanıcı',
  musteri: 'Müşteri'
}

const roleColors: Record<string, string> = {
  admin: 'error',
  acente: 'warning',
  kullanıcı: 'info',
  musteri: 'success'
}

const roleOptions = [
  { label: 'Kullanıcı', value: 'kullanıcı' },
  { label: 'Acente', value: 'acente' },
  { label: 'Yönetici', value: 'admin' },
  { label: 'Müşteri (Portal)', value: 'musteri' }
]

const isAdmin = computed(() => currentUser.value?.role === 'admin')

const branchMap = computed(() => {
  const map: Record<number, string> = {}
  branches.value.forEach(b => { map[b.id] = b.name })
  return map
})

// UTable
const columns = [
  { accessorKey: 'name', header: 'Kullanıcı', enableSorting: true, minSize: 200 },
  { accessorKey: 'role', header: 'Rol', enableSorting: true, minSize: 100, maxSize: 120 },
  { accessorKey: 'branchName', header: 'Sube', enableSorting: false, minSize: 120, maxSize: 150 },
  { accessorKey: 'isActive', header: 'Durum', enableSorting: true, minSize: 80, maxSize: 100 },
  { accessorKey: 'createdAt', header: 'Kayit Tarihi', enableSorting: true, minSize: 110, maxSize: 130 },
  { accessorKey: 'actions', header: '', enableSorting: false, minSize: 100, maxSize: 120 }
]

const tableData = computed(() =>
  users.data.value.map(u => ({
    ...u,
    branchName: u.branchId ? (branchMap.value[u.branchId] || '-') : '-'
  }))
)

function openCreate() {
  editing.value = null
  form.name = ''
  form.email = ''
  form.password = ''
  form.role = 'kullanıcı'
  form.branchId = null
  form.isActive = true
  form.isSalesRep = true
  form.customerIds = []
  selectedCustomerNames.value = {}
  modalOpen.value = true
}

async function openEdit(u: UserItem) {
  editing.value = u
  form.name = u.name
  form.email = u.email
  form.password = ''
  form.role = u.role
  form.branchId = u.branchId
  form.isActive = u.isActive
  form.isSalesRep = u.isSalesRep
  form.customerIds = (u as any).customerIds || []
  selectedCustomerNames.value = {}
  // Musteri isimlerini cek
  if (form.customerIds.length > 0) {
    try {
      const res = await get<any>('customers/list-all')
      const allCustomers = res.data || []
      form.customerIds.forEach((cid: number) => {
        const found = allCustomers.find((c: any) => c.id === cid)
        if (found) selectedCustomerNames.value[cid] = found.name
        else selectedCustomerNames.value[cid] = `#${cid}`
      })
    } catch {}
  }
  modalOpen.value = true
}

async function saveUser() {
  saving.value = true
  try {
    const payload: any = {
      name: form.name,
      email: form.email,
      role: form.role,
      branchId: form.role === 'acente' ? form.branchId : null,
      isActive: form.isActive,
      isSalesRep: form.role !== 'musteri' ? form.isSalesRep : false,
      customerIds: form.role === 'musteri' ? form.customerIds : undefined
    }
    if (form.password) {
      payload.password = form.password
    }

    if (editing.value) {
      await put(`users/${editing.value.id}`, payload)
      toast.add({ title: 'Kullanıcı güncellendi', color: 'success' })
    } else {
      await post('users', payload)
      toast.add({ title: 'Kullanıcı oluşturuldu', color: 'success' })
    }
    modalOpen.value = false
    users.refresh()
  } catch (error: any) {
    toast.add({ title: error.message || 'İşlem başarısız', color: 'error' })
  } finally {
    saving.value = false
  }
}

function confirmDelete(u: UserItem) {
  deletingUser.value = u
  deleteModalOpen.value = true
}

async function deleteUser() {
  if (!deletingUser.value) return
  deleting.value = true
  try {
    await del(`users/${deletingUser.value.id}`)
    toast.add({ title: 'Kullanıcı silindi', color: 'success' })
    deleteModalOpen.value = false
    users.refresh()
  } catch (error: any) {
    toast.add({ title: error.message || 'Kullanıcı silinemedi', color: 'error' })
  } finally {
    deleting.value = false
  }
}

async function toggleStatus(u: UserItem) {
  try {
    await put(`users/${u.id}`, { isActive: !u.isActive })
    toast.add({ title: u.isActive ? 'Kullanıcı devre disi birakildi' : 'Kullanıcı etkinlestirildi', color: 'success' })
    users.refresh()
  } catch (error: any) {
    toast.add({ title: error.message || 'Durum değiştirilemedi', color: 'error' })
  }
}

function formatDate(date: string) {
  return new Date(date).toLocaleDateString('tr-TR')
}

function getRowActions(u: UserItem) {
  if (!isAdmin.value) return []
  const items: any[][] = [
    [
      { label: 'Personel Kartı', icon: 'i-lucide-id-card', onSelect: () => navigateTo(`/personel/${u.id}`) },
      { label: 'İzinler', icon: 'i-lucide-shield', onSelect: () => navigateTo(`/settings/kullanicilar/${u.id}/izinler`) },
      { label: 'Düzenle', icon: 'i-lucide-pencil', onSelect: () => openEdit(u) },
      {
        label: u.isActive ? 'Pasife Al' : 'Aktif Et',
        icon: u.isActive ? 'i-lucide-user-x' : 'i-lucide-user-check',
        disabled: u.id === currentUser.value?.id,
        onSelect: () => toggleStatus(u)
      }
    ],
    [
      {
        label: 'Sil',
        icon: 'i-lucide-trash-2',
        color: 'error',
        disabled: u.id === currentUser.value?.id,
        onSelect: () => confirmDelete(u)
      }
    ]
  ]
  return items
}
</script>

<template>
  <div class="space-y-4">
    <UCard :ui="{ body: 'p-4' }">
      <!-- Header -->
      <template #header>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <UInput
            v-model="searchInput"
            icon="i-lucide-search"
            placeholder="Kullanıcı ara..."
            size="xs"
            class="w-[180px] h-[30px]"
          />
          <div class="flex items-center gap-3">
            <UButton v-if="isAdmin" label="Yeni Kullanıcı" icon="i-lucide-user-plus" size="xs" @click="openCreate" />
          </div>
        </div>
      </template>

      <SkeletonTable v-if="users.loading.value && !users.data.value.length" :rows="8" :cols="5" />
      <div v-else class="border border-default rounded-lg overflow-hidden">
        <UTable
          v-model:sorting="sorting"
          :data="tableData"
          :columns="columns"
          :loading="users.loading.value && !users.data.value.length"
          :sorting-options="{ manualSorting: true }"
          :ui="{
            base: 'table-fixed min-w-full kullanicilar-table',
            thead: 'bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10',
            th: 'py-2 px-3 text-xs font-semibold tracking-wide text-muted whitespace-nowrap',
            td: 'py-2 px-3 text-xs whitespace-nowrap'
          }"
        >
          <!-- Sortable Headers -->
          <template #name-header="{ column }">
            <SortableHeader label="Kullanıcı" :column="column" />
          </template>
          <template #role-header="{ column }">
            <SortableHeader label="Rol" :column="column" />
          </template>
          <template #isActive-header="{ column }">
            <SortableHeader label="Durum" :column="column" />
          </template>
          <template #createdAt-header="{ column }">
            <SortableHeader label="Kayit Tarihi" :column="column" />
          </template>

          <!-- Cells -->
          <template #name-cell="{ row }">
            <div class="flex items-center gap-3">
              <div class="size-8 rounded-full bg-primary/10 text-primary flex items-center justify-center text-xs font-semibold shrink-0">
                {{ row.original.name.split(' ').map((n: string) => n[0]).join('').toUpperCase().slice(0, 2) }}
              </div>
              <div class="min-w-0">
                <span class="font-semibold text-primary truncate block" :title="row.original.name">{{ row.original.name }}</span>
                <p class="text-xs text-muted">{{ row.original.email }}</p>
              </div>
            </div>
          </template>

          <template #role-cell="{ row }">
            <UBadge :color="(roleColors[row.original.role] as any)" variant="solid" size="xs">
              {{ roleLabels[row.original.role] }}
            </UBadge>
          </template>

          <template #branchName-cell="{ row }">
            <span>{{ row.original.branchName }}</span>
          </template>

          <template #isActive-cell="{ row }">
            <UBadge :color="row.original.isActive ? 'success' : 'neutral'" variant="solid" size="xs">
              {{ row.original.isActive ? 'Aktif' : 'Pasif' }}
            </UBadge>
          </template>

          <template #createdAt-cell="{ row }">
            <span class="tabular-nums text-muted">{{ formatDate(row.original.createdAt) }}</span>
          </template>

          <template #actions-cell="{ row }">
            <UDropdownMenu :items="getRowActions(row.original)">
              <UButton icon="i-lucide-ellipsis-vertical" color="neutral" variant="ghost" size="xs" />
            </UDropdownMenu>
          </template>
        </UTable>
      </div>

      <div class="h-[2px] w-full overflow-hidden">
        <div v-if="users.loading.value" class="h-full bg-primary nav-loading-bar" />
      </div>

      <div class="pt-2 text-xs text-muted">
        Toplam {{ users.total.value }} kayıt
      </div>
    </UCard>

    <!-- Kullanıcı Ekle/Düzenle Modal -->
    <UModal :dismissible="false" v-model:open="modalOpen" :title="editing ? 'Kullanıcı Düzenle' : 'Yeni Kullanıcı'">
      <template #body>
        <UForm :schema="formSchema" :state="form" @submit="saveUser" class="space-y-4">
          <UFormField label="Ad Soyad" name="name">
            <UInput v-model="form.name" placeholder="Örnek Isim" class="w-full" />
          </UFormField>

          <UFormField label="E-posta" name="email">
            <UInput v-model="form.email" type="email" placeholder="örnek@mail.com" class="w-full" />
          </UFormField>

          <UFormField :label="editing ? 'Yeni Şifre (bos birakilirsa değişmez)' : 'Şifre'" name="password">
            <UInput v-model="form.password" type="password" placeholder="En az 6 karakter" class="w-full" />
          </UFormField>

          <UFormField label="Rol" name="role">
            <USelect
              v-model="form.role"
              :items="roleOptions"
              value-key="value"
              class="w-full"
            />
          </UFormField>

          <UFormField v-if="form.role === 'acente'" label="Sube" name="branchId">
            <USelect
              v-model="form.branchId"
              :items="branches.map(b => ({ label: b.name, value: b.id }))"
              value-key="value"
              placeholder="Sube seçin"
              class="w-full"
            />
          </UFormField>

          <!-- Musteri baglama (portal kullanicisi icin) -->
          <UFormField v-if="form.role === 'musteri'" label="Bağlı Müşteriler" name="customerIds">
            <div class="space-y-2">
              <!-- Secili musteriler -->
              <div v-if="form.customerIds.length" class="flex flex-wrap gap-1.5">
                <UBadge v-for="cid in form.customerIds" :key="cid" color="primary" variant="subtle" size="sm" class="gap-1">
                  {{ selectedCustomerNames[cid] || `#${cid}` }}
                  <UButton icon="i-lucide-x" color="neutral" variant="ghost" size="2xs" :padded="false" @click="removeCustomer(cid)" />
                </UBadge>
              </div>
              <!-- Arama -->
              <div class="relative">
                <UInput
                  v-model="customerSearchQuery"
                  icon="i-lucide-search"
                  placeholder="Müşteri ara..."
                  class="w-full"
                  :loading="customerSearching"
                />
                <div v-if="customerSearchResults.length" class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-900 rounded-lg border border-default shadow-lg max-h-48 overflow-y-auto">
                  <button
                    v-for="c in customerSearchResults"
                    :key="c.id"
                    type="button"
                    class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                    @click="addCustomer(c)"
                  >
                    {{ c.name }}
                  </button>
                </div>
              </div>
              <p class="text-xs text-muted">Bu kullanıcı portalde seçili müşterilerin poliçelerini görebilecek.</p>
            </div>
          </UFormField>

          <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
              <USwitch v-model="form.isActive" size="xs" />
              <span class="text-xs">{{ form.isActive ? 'Aktif' : 'Pasif' }}</span>
            </div>
            <div class="flex items-center gap-2">
              <USwitch v-model="form.isSalesRep" size="xs" />
              <span class="text-xs">Satış Temsilcisi</span>
            </div>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <UButton label="Vazgeç" color="neutral" variant="outline" @click="modalOpen = false" />
            <UButton
              :label="editing ? 'Güncelle' : 'Oluştur'"
              :loading="saving"
              type="submit"
            />
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Silme Onay Modal -->
    <UModal :dismissible="false" v-model:open="deleteModalOpen" title="Kullanıcı Sil">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-error/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-trash-2" class="size-5 text-error" />
          </div>
          <div>
            <p class="font-medium">{{ deletingUser?.name }} adli kullanıcıyi silmek istediginize emin misiniz?</p>
            <p class="text-xs text-muted mt-1">Bu islem geri alinamaz.</p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="Vazgeç" color="neutral" variant="outline" @click="deleteModalOpen = false" />
          <UButton
            label="Sil"
            color="error"
            icon="i-lucide-trash-2"
            :loading="deleting"
            @click="deleteUser"
          />
        </div>
      </template>
    </UModal>

  </div>
</template>

<style scoped>
:deep(.kullanicilar-table th:nth-child(1)) { width: 200px; min-width: 200px; max-width: 200px; }
:deep(.kullanicilar-table th:nth-child(2)) { width: 100px; min-width: 100px; max-width: 100px; }
:deep(.kullanicilar-table th:nth-child(3)) { width: 140px; min-width: 140px; max-width: 140px; }
:deep(.kullanicilar-table th:nth-child(4)) { width: 90px;  min-width: 90px;  max-width: 90px;  }
:deep(.kullanicilar-table th:nth-child(5)) { width: 110px; min-width: 110px; max-width: 110px; }
:deep(.kullanicilar-table th:nth-child(6)) { width: 120px; min-width: 120px; max-width: 120px; }
:deep(.kullanicilar-table > tbody > tr > td) { overflow: hidden; text-overflow: ellipsis; }

@media (max-width: 767px) {
  :deep(.kullanicilar-table th:nth-child(3)),
  :deep(.kullanicilar-table td:nth-child(3)),
  :deep(.kullanicilar-table th:nth-child(5)),
  :deep(.kullanicilar-table td:nth-child(5)) { display: none; }
}
</style>
