<script setup lang="ts">
import type { Message, MessageTemplate } from '~/types'

definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Mesajlar' })

const toast = useToast()
const { get, post, del } = useApi()
const { user } = useAuth()
const { can } = usePermissions()
const isAdmin = computed(() => user.value?.role === 'admin')

// Tabs
const activeTab = ref<'messages' | 'compose' | 'templates'>('messages')

// Messages list
const messages = usePaginatedData<Message>({
  endpoint: 'messages',
  defaultLimit: 15,
  defaultSort: 'created_at',
  defaultOrder: 'desc'
})

onMounted(() => {
  messages.fetchData()
  fetchTemplates()
  fetchCustomers()
})

// Filters
const filterChannel = ref('all')
const filterStatus = ref('all')

watch([filterChannel, filterStatus], () => {
  const f: Record<string, any> = {}
  if (filterChannel.value && filterChannel.value !== 'all') f.channel = filterChannel.value
  if (filterStatus.value && filterStatus.value !== 'all') f.status = filterStatus.value
  messages.setFilters(f)
})

const channelOptions = [
  { label: 'Tümü', value: 'all' },
  { label: 'SMS', value: 'sms' },
  { label: 'WhatsApp', value: 'whatsapp' },
  { label: 'E-posta', value: 'email' },
]

const statusOptions = [
  { label: 'Tümü', value: 'all' },
  { label: 'Gonderildi', value: 'sent' },
  { label: 'Bekliyor', value: 'pending' },
  { label: 'Başarısız', value: 'failed' },
]

// Templates
const templates = ref<MessageTemplate[]>([])

async function fetchTemplates() {
  try {
    const res = await get<any>('messages/templates')
    templates.value = res.data || []
  } catch {}
}

// Customers for compose
const customers = ref<{ id: number, name: string, phone: string, email?: string }[]>([])

async function fetchCustomers() {
  try {
    const res = await get<any>('customers/list-all?hasPhone=1')
    customers.value = res.data || []
  } catch {}
}

// Compose form
const composeForm = ref({
  channel: 'sms' as 'sms' | 'whatsapp' | 'email',
  selectedCustomers: [] as number[],
  manualRecipient: '',
  subject: '',
  content: '',
  templateId: 0 as number,
})

const selectedTemplate = computed(() => {
  if (!composeForm.value.templateId) return null
  return templates.value.find(t => t.id === composeForm.value.templateId) || null
})

watch(() => composeForm.value.templateId, (val) => {
  if (val && val > 0) {
    const t = templates.value.find(t => t.id === val)
    if (t) {
      composeForm.value.content = t.content
      composeForm.value.subject = t.subject || ''
      composeForm.value.channel = t.channel
    }
  }
})

const sending = ref(false)

async function sendMessage() {
  if (!composeForm.value.content.trim()) {
    toast.add({ title: 'Mesaj icerigi zorunludur', color: 'error' })
    return
  }

  sending.value = true
  try {
    if (composeForm.value.selectedCustomers.length > 0) {
      // Bulk send
      const recipients = composeForm.value.selectedCustomers.map(cId => {
        const c = customers.value.find(cu => cu.id === cId)
        if (!c) return null
        return {
          name: c.name,
          phone: c.phone,
          email: c.email || '',
          customerId: c.id,
        }
      }).filter(Boolean)

      const res = await post('messages', {
        channel: composeForm.value.channel,
        content: composeForm.value.content,
        subject: composeForm.value.subject || null,
        templateId: composeForm.value.templateId || null,
        recipients,
      })
      toast.add({ title: `${res.data?.sent ?? 0} mesaj gönderildi`, color: 'success' })
    } else if (composeForm.value.manualRecipient.trim()) {
      // Single send
      await post('messages', {
        channel: composeForm.value.channel,
        recipient: composeForm.value.manualRecipient.trim(),
        content: composeForm.value.content,
        subject: composeForm.value.subject || null,
        templateId: composeForm.value.templateId || null,
      })
      toast.add({ title: 'Mesaj gönderildi', color: 'success' })
    } else {
      toast.add({ title: 'Alici seçin veya numara/e-posta girin', color: 'error' })
      sending.value = false
      return
    }

    // Reset form
    composeForm.value.selectedCustomers = []
    composeForm.value.manualRecipient = ''
    composeForm.value.content = ''
    composeForm.value.subject = ''
    composeForm.value.templateId = 0
    activeTab.value = 'messages'
    messages.refresh()
  } catch (err: any) {
    toast.add({ title: err.message || 'Gonderilemedi', color: 'error' })
  } finally {
    sending.value = false
  }
}

// Delete message
const isDeleteModalOpen = ref(false)
const deletingId = ref<number | null>(null)

function confirmDelete(id: number) {
  deletingId.value = id
  isDeleteModalOpen.value = true
}

async function doDelete() {
  if (!deletingId.value) return
  try {
    await del(`messages/${deletingId.value}`)
    toast.add({ title: 'Mesaj silindi', color: 'success' })
    messages.refresh()
  } catch {
    toast.add({ title: 'Silinemedi', color: 'error' })
  }
  isDeleteModalOpen.value = false
  deletingId.value = null
}

// Detail modal
const detailMessage = ref<Message | null>(null)
const isDetailOpen = ref(false)

function showDetail(msg: Message) {
  detailMessage.value = msg
  isDetailOpen.value = true
}

// Template CRUD
const isTemplateModalOpen = ref(false)
const editingTemplate = ref<MessageTemplate | null>(null)
const templateForm = ref({ name: '', channel: 'sms' as 'sms' | 'whatsapp' | 'email', subject: '', content: '' })

function openTemplateAdd() {
  editingTemplate.value = null
  templateForm.value = { name: '', channel: 'sms', subject: '', content: '' }
  isTemplateModalOpen.value = true
}

function openTemplateEdit(t: MessageTemplate) {
  editingTemplate.value = t
  templateForm.value = { name: t.name, channel: t.channel, subject: t.subject || '', content: t.content }
  isTemplateModalOpen.value = true
}

async function saveTemplate() {
  if (!templateForm.value.name || !templateForm.value.content) {
    toast.add({ title: 'Ad ve icerik zorunludur', color: 'error' })
    return
  }

  try {
    const payload = { ...templateForm.value, subject: templateForm.value.subject || null }
    if (editingTemplate.value) {
      const { put } = useApi()
      await put(`messages/templates/${editingTemplate.value.id}`, payload)
      toast.add({ title: 'Sablon güncellendi', color: 'success' })
    } else {
      await post('messages/templates', payload)
      toast.add({ title: 'Sablon oluşturuldu', color: 'success' })
    }
    isTemplateModalOpen.value = false
    fetchTemplates()
  } catch (err: any) {
    toast.add({ title: err.message || 'İşlem başarısız', color: 'error' })
  }
}

async function deleteTemplate(id: number) {
  try {
    await del(`messages/templates/${id}`)
    toast.add({ title: 'Sablon silindi', color: 'success' })
    fetchTemplates()
  } catch {
    toast.add({ title: 'Silinemedi', color: 'error' })
  }
}

// Customer search for compose
const customerSearch = ref('')
const filteredCustomers = computed(() => {
  let list = [...customers.value]
  if (customerSearch.value) {
    const q = customerSearch.value.toLowerCase()
    list = list.filter(c => c.name.toLowerCase().includes(q) || c.phone?.includes(q))
  }
  list.sort((a, b) => a.name.localeCompare(b.name, 'tr'))
  return list
})

function toggleCustomer(id: number) {
  const idx = composeForm.value.selectedCustomers.indexOf(id)
  if (idx >= 0) composeForm.value.selectedCustomers.splice(idx, 1)
  else composeForm.value.selectedCustomers.push(id)
}

function formatDate(d?: string | null) {
  if (!d) return '-'
  return new Date(d).toLocaleString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function getStatusLabel(s: string) {
  if (s === 'sent') return 'Gonderildi'
  if (s === 'pending') return 'Bekliyor'
  if (s === 'failed') return 'Başarısız'
  return s
}

function getStatusColor(s: string) {
  if (s === 'sent') return 'success' as const
  if (s === 'pending') return 'warning' as const
  if (s === 'failed') return 'error' as const
  return 'neutral' as const
}
</script>

<template>
  <div class="space-y-4">
    <!-- Mesajlar Tab -->
    <UCard v-if="activeTab === 'messages'" :ui="{ body: 'p-4' }">
      <template #header>
        <div class="flex flex-col gap-3">
          <div class="flex items-center justify-between">
            <div>
              <h3 >Mesajlar</h3>
              <p class="text-xs text-muted">SMS, WhatsApp ve e-posta mesajları</p>
            </div>
          </div>
          <div class="flex items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5">
              <div class="flex items-center gap-1 bg-gray-100 dark:bg-gray-800 rounded-lg p-0.5 shrink-0">
                <button
                  :class="['px-3.5 py-1.5 text-xs font-medium rounded-md transition-all', activeTab === 'messages' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm' : 'text-muted hover:text-default']"
                  @click="activeTab = 'messages'"
                >Mesajlar</button>
                <button
                  v-if="can('messages.send')"
                  :class="['px-3.5 py-1.5 text-xs font-medium rounded-md transition-all', activeTab === 'compose' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm' : 'text-muted hover:text-default']"
                  @click="activeTab = 'compose'"
                >Yeni Mesaj</button>
                <button
                  v-if="isAdmin"
                  :class="['px-3.5 py-1.5 text-xs font-medium rounded-md transition-all', activeTab === 'templates' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm' : 'text-muted hover:text-default']"
                  @click="activeTab = 'templates'"
                >Şablonlar</button>
              </div>
              <USelect v-model="filterChannel" :items="channelOptions" size="xs" :ui="{ base: 'h-[30px]' }" class="w-[180px]" />
              <USelect v-model="filterStatus" :items="statusOptions" size="xs" :ui="{ base: 'h-[30px]' }" class="w-[180px]" />
            </div>
          </div>
        </div>
      </template>

      <div v-if="messages.loading.value" class="py-4">
        <SkeletonTable :rows="5" :cols="7" />
      </div>

      <div v-else-if="messages.data.value.length === 0" class="text-center py-12 text-muted">
        <UIcon name="i-lucide-inbox" class="size-8 mx-auto mb-2" />
        <p class="text-sm">Mesaj bulunamadı</p>
      </div>

      <div v-else class="border border-default rounded-lg overflow-hidden">
        <table class="text-xs w-full table-fixed">
          <thead class="sticky top-0 z-10">
            <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
              <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:10%">Kanal</th>
              <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:18%">Alıcı</th>
              <th class="hidden md:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:25%">İçerik</th>
              <th class="py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:10%">Durum</th>
              <th class="hidden sm:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:12%">Tarih</th>
              <th class="hidden md:table-cell py-2 px-3 text-left text-xs font-semibold tracking-wide text-muted" style="width:12%">Gönderen</th>
              <th class="py-2 px-3" style="width:5%" />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="msg in messages.data.value"
              :key="msg.id"
              class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors cursor-pointer"
              @click="showDetail(msg)"
            >
              <td class="py-2 px-3">
                <span class="badge-cell" :class="msg.channel === 'sms' ? 'badge-info' : msg.channel === 'whatsapp' ? 'badge-success' : 'badge-primary'">
                  {{ msg.channel === 'sms' ? 'SMS' : msg.channel === 'whatsapp' ? 'WhatsApp' : 'E-posta' }}
                </span>
              </td>
              <td class="py-2 px-3 overflow-hidden" style="max-width:0">
                <p v-if="msg.customerName" class="truncate">{{ msg.customerName }}</p>
                <span class="text-muted truncate block">{{ msg.recipient }}</span>
              </td>
              <td class="hidden md:table-cell py-2 px-3 overflow-hidden" style="max-width:0">
                <p class="truncate">{{ msg.subject || msg.content }}</p>
              </td>
              <td class="py-2 px-3">
                <span class="badge-cell" :class="'badge-' + getStatusColor(msg.status)">
                  {{ getStatusLabel(msg.status) }}
                </span>
              </td>
              <td class="hidden sm:table-cell py-2 px-3 tabular-nums text-muted">
                {{ formatDate(msg.sentAt || msg.createdAt) }}
              </td>
              <td class="hidden md:table-cell py-2 px-3 text-muted truncate">
                {{ msg.sentByName || '-' }}
              </td>
              <td class="py-2 px-3">
                <UButton icon="i-lucide-trash-2" color="error" variant="ghost" size="xs" @click.stop="confirmDelete(msg.id)" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="messages.total.value > 0" class="flex items-center justify-between pt-4 mt-4 border-t border-default">
        <span class="text-xs text-muted">Toplam {{ messages.total.value }} mesaj</span>
        <div class="flex items-center gap-1">
          <UButton icon="i-lucide-chevron-left" size="xs" color="neutral" variant="outline" :disabled="messages.page.value <= 1" @click="messages.setPage(messages.page.value - 1)" />
          <span class="text-xs text-muted px-2">{{ messages.page.value }} / {{ Math.ceil(messages.total.value / messages.limit.value) }}</span>
          <UButton icon="i-lucide-chevron-right" size="xs" color="neutral" variant="outline" :disabled="messages.page.value >= Math.ceil(messages.total.value / messages.limit.value)" @click="messages.setPage(messages.page.value + 1)" />
        </div>
      </div>
    </UCard>

    <!-- Compose Tab -->
    <div v-if="activeTab === 'compose'" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Customer Selection -->
      <UCard class="lg:col-span-1">
        <template #header>
          <div class="flex items-center justify-between">
            <h3 >Müşteriler</h3>
            <UBadge v-if="composeForm.selectedCustomers.length" color="primary" variant="solid" size="sm">
              {{ composeForm.selectedCustomers.length }} seçili
            </UBadge>
          </div>
        </template>
        <div class="space-y-3">
          <UInput
            v-model="customerSearch"
            icon="i-lucide-search"
            placeholder="Müşteri ara..."
            size="sm"
            class="w-full"
          />
          <div class="flex items-center justify-between">
            <button
              class="text-xs text-primary hover:underline"
              @click="composeForm.selectedCustomers = composeForm.selectedCustomers.length === filteredCustomers.length ? [] : filteredCustomers.map(c => c.id)"
            >
              {{ composeForm.selectedCustomers.length === filteredCustomers.length ? 'Tümünü Kaldır' : 'Tümünü Seç' }}
            </button>
            <span v-if="composeForm.selectedCustomers.length" class="text-xs text-muted">{{ composeForm.selectedCustomers.length }} / {{ filteredCustomers.length }}</span>
          </div>
          <div class="max-h-64 overflow-y-auto space-y-1">
            <div
              v-for="c in filteredCustomers"
              :key="c.id"
              class="flex items-center gap-2 px-2 py-1.5 rounded cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
              :class="{ 'bg-primary/5 border border-primary/20': composeForm.selectedCustomers.includes(c.id) }"
              @click="toggleCustomer(c.id)"
            >
              <UIcon
                :name="composeForm.selectedCustomers.includes(c.id) ? 'i-lucide-check-square' : 'i-lucide-square'"
                class="size-4 shrink-0"
                :class="composeForm.selectedCustomers.includes(c.id) ? 'text-primary' : 'text-muted'"
              />
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium">{{ c.name }}</p>
                <p class="text-xs text-muted">{{ c.phone }}</p>
              </div>
            </div>
            <div v-if="filteredCustomers.length === 0" class="text-center text-sm text-muted py-4">
              Müşteri bulunamadı
            </div>
          </div>
        </div>
      </UCard>

      <!-- Message Compose -->
      <UCard class="lg:col-span-2">
        <template #header>
          <h3 >Mesaj Oluştur</h3>
        </template>
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <UFormField label="Kanal">
              <div class="flex gap-2">
                <UButton
                  label="SMS"
                  icon="i-lucide-smartphone"
                  :variant="composeForm.channel === 'sms' ? 'solid' : 'outline'"
                  :color="composeForm.channel === 'sms' ? 'primary' : 'neutral'"
                  size="sm"
                  @click="composeForm.channel = 'sms'"
                />
                <UButton
                  label="WhatsApp"
                  icon="i-lucide-message-circle"
                  :variant="composeForm.channel === 'whatsapp' ? 'solid' : 'outline'"
                  :color="composeForm.channel === 'whatsapp' ? 'success' : 'neutral'"
                  size="sm"
                  @click="composeForm.channel = 'whatsapp'"
                />
                <UButton
                  label="E-posta"
                  icon="i-lucide-mail"
                  :variant="composeForm.channel === 'email' ? 'solid' : 'outline'"
                  :color="composeForm.channel === 'email' ? 'primary' : 'neutral'"
                  size="sm"
                  @click="composeForm.channel = 'email'"
                />
              </div>
            </UFormField>
            <UFormField label="Sablon">
              <USelect
                v-model="composeForm.templateId"
                :items="[{ label: 'Sablon seçin...', value: 0 }, ...templates.filter(t => t.channel === composeForm.channel).map(t => ({ label: t.name, value: t.id }))]"
                value-key="value"
                label-key="label"
                size="sm"
              />
            </UFormField>
          </div>

          <UFormField v-if="composeForm.selectedCustomers.length === 0" label="Alici (müşteri secilmediyse)">
            <UInput
              v-model="composeForm.manualRecipient"
              :placeholder="composeForm.channel === 'email' ? 'örnek@email.com' : '05xx xxx xx xx'"
              :icon="composeForm.channel === 'email' ? 'i-lucide-mail' : 'i-lucide-phone'"
              size="sm"
            />
          </UFormField>

          <UFormField v-if="composeForm.channel === 'email'" label="Konu">
            <UInput v-model="composeForm.subject" placeholder="E-posta konusu" icon="i-lucide-type" size="sm" />
          </UFormField>

          <UFormField label="Mesaj İçerigi" required>
            <UTextarea
              v-model="composeForm.content"
              :rows="6"
              placeholder="Mesaj icerigini yazin..."
              class="w-full"
            />
            <p class="text-xs text-muted mt-1">
              Kullanilabilir değişkenler: <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded">{müşteri}</code>
              <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded ml-1">{tarih}</code>
              <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded ml-1">{telefon}</code>
            </p>
          </UFormField>
        </div>

        <template #footer>
          <div class="flex items-center justify-between">
            <span v-if="composeForm.selectedCustomers.length > 0" class="text-sm text-muted">
              {{ composeForm.selectedCustomers.length }} müşteriye gonderilecek
            </span>
            <span v-else />
            <UButton
              label="Gonder"
              icon="i-lucide-send"
              :loading="sending"
              @click="sendMessage"
            />
          </div>
        </template>
      </UCard>
    </div>

    <!-- Templates Tab (admin only) -->
    <UCard v-if="activeTab === 'templates' && isAdmin">
      <template #header>
        <div class="flex items-center justify-between">
          <h3 >Mesaj Şablonları</h3>
          <UButton label="Yeni Sablon" icon="i-lucide-plus" size="sm" @click="openTemplateAdd" />
        </div>
      </template>

      <div v-if="templates.length === 0" class="text-center py-8 text-muted">
        Sablon bulunamadı
      </div>
      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="t in templates"
          :key="t.id"
          class="border border-default rounded-lg p-4 hover:border-primary/50 transition-colors"
        >
          <div class="flex items-center justify-between mb-2">
            <h5 class="font-medium text-sm">{{ t.name }}</h5>
            <UBadge :color="t.channel === 'sms' ? 'info' : t.channel === 'whatsapp' ? 'success' : 'primary'" variant="solid" size="sm">
              {{ t.channel === 'sms' ? 'SMS' : t.channel === 'whatsapp' ? 'WhatsApp' : 'E-posta' }}
            </UBadge>
          </div>
          <p v-if="t.subject" class="text-xs text-muted mb-1">Konu: {{ t.subject }}</p>
          <p class="text-sm text-muted line-clamp-3">{{ t.content }}</p>
          <div class="flex items-center gap-1 mt-3">
            <UButton label="Düzenle" variant="ghost" size="sm" icon="i-lucide-pencil" @click="openTemplateEdit(t)" />
            <UButton label="Sil" variant="ghost" size="sm" icon="i-lucide-trash-2" color="error" @click="deleteTemplate(t.id)" />
          </div>
        </div>
      </div>
    </UCard>

    <!-- Detail Modal -->
    <UModal :dismissible="false" v-model:open="isDetailOpen" title="Mesaj Detayı" class="sm:max-w-lg">
      <template #body>
        <div v-if="detailMessage" class="space-y-4">
          <div class="flex items-center gap-2">
            <UBadge :color="detailMessage.channel === 'sms' ? 'info' : detailMessage.channel === 'whatsapp' ? 'success' : 'primary'" variant="solid" size="sm">
              {{ detailMessage.channel === 'sms' ? 'SMS' : detailMessage.channel === 'whatsapp' ? 'WhatsApp' : 'E-posta' }}
            </UBadge>
            <UBadge :color="getStatusColor(detailMessage.status)" variant="solid" size="sm">
              {{ getStatusLabel(detailMessage.status) }}
            </UBadge>
          </div>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-muted">Alici:</span>
              <span class="font-medium">{{ detailMessage.customerName || detailMessage.recipient }}</span>
            </div>
            <div v-if="detailMessage.customerName" class="flex justify-between">
              <span class="text-muted">Numara/E-posta:</span>
              <span>{{ detailMessage.recipient }}</span>
            </div>
            <div v-if="detailMessage.subject" class="flex justify-between">
              <span class="text-muted">Konu:</span>
              <span>{{ detailMessage.subject }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted">Gonderen:</span>
              <span>{{ detailMessage.sentByName || '-' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted">Tarih:</span>
              <span>{{ formatDate(detailMessage.sentAt || detailMessage.createdAt) }}</span>
            </div>
          </div>
          <USeparator />
          <div>
            <p class="text-xs text-muted mb-1">İçerik:</p>
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-sm whitespace-pre-wrap">{{ detailMessage.content }}</div>
          </div>
        </div>
      </template>
      <template #footer>
        <UButton label="Kapat" color="neutral" variant="outline" @click="isDetailOpen = false" />
      </template>
    </UModal>

    <!-- Template Modal -->
    <UModal :dismissible="false" v-model:open="isTemplateModalOpen" :title="editingTemplate ? 'Sablon Düzenle' : 'Yeni Sablon'" class="sm:max-w-lg">
      <template #body>
        <div class="space-y-4">
          <UFormField label="Sablon Adi" required>
            <UInput v-model="templateForm.name" placeholder="Örnek: Yenileme Hatirlatma" />
          </UFormField>
          <UFormField label="Kanal">
            <div class="flex gap-2">
              <UButton
                label="SMS"
                :variant="templateForm.channel === 'sms' ? 'solid' : 'outline'"
                :color="templateForm.channel === 'sms' ? 'primary' : 'neutral'"
                size="sm"
                @click="templateForm.channel = 'sms'"
              />
              <UButton
                label="WhatsApp"
                :variant="templateForm.channel === 'whatsapp' ? 'solid' : 'outline'"
                :color="templateForm.channel === 'whatsapp' ? 'success' : 'neutral'"
                size="sm"
                @click="templateForm.channel = 'whatsapp'"
              />
              <UButton
                label="E-posta"
                :variant="templateForm.channel === 'email' ? 'solid' : 'outline'"
                :color="templateForm.channel === 'email' ? 'primary' : 'neutral'"
                size="sm"
                @click="templateForm.channel = 'email'"
              />
            </div>
          </UFormField>
          <UFormField v-if="templateForm.channel === 'email'" label="Konu">
            <UInput v-model="templateForm.subject" placeholder="E-posta konusu" />
          </UFormField>
          <UFormField label="İçerik" required>
            <UTextarea v-model="templateForm.content" :rows="5" placeholder="Mesaj icerigi..." class="w-full" />
            <p class="text-xs text-muted mt-1">
              Değişkenler: <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded">{müşteri}</code>
              <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded ml-1">{tarih}</code>
              <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded ml-1">{telefon}</code>
            </p>
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton label="İptal" color="neutral" variant="outline" @click="isTemplateModalOpen = false" />
          <UButton :label="editingTemplate ? 'Güncelle' : 'Kaydet'" icon="i-lucide-check" @click="saveTemplate" />
        </div>
      </template>
    </UModal>

    <!-- Delete Confirm -->
    <UModal :dismissible="false" v-model:open="isDeleteModalOpen" title="Mesaj Sil">
      <template #body>
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-error/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-triangle-alert" class="size-5 text-error" />
          </div>
          <div>
            <p class="font-medium">Bu mesaji silmek istediginize emin misiniz?</p>
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
table td { overflow: hidden; text-overflow: clip; white-space: nowrap; }

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
.badge-error   { background: rgb(239 68 68 / 0.1);  color: #ef4444; }
.badge-warning { background: rgb(245 158 11 / 0.1); color: #f59e0b; }
.badge-info    { background: rgb(59 130 246 / 0.1); color: #3b82f6; }
.badge-success { background: rgb(34 197 94 / 0.1);  color: #22c55e; }
.badge-neutral { background: rgb(107 114 128 / 0.1); color: #6b7280; }
.badge-primary { background: rgb(99 102 241 / 0.1); color: #6366f1; }
</style>
