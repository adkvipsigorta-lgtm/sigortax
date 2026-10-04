<script setup lang="ts">
interface Props {
  /** Poliçe veya müşteri id'si - biri zorunlu */
  policyId?: number
  customerId?: number
}
const props = defineProps<Props>()

type Doc = {
  id: number
  name: string
  type: string | null
  size: number
  encrypted: boolean
  uploadedAt: string
  uploadedBy: string | null
}

const { get, del: apiDel } = useApi()
const { user, token } = useAuth()
const toast = useToast()
const fileInput = ref<HTMLInputElement>()

const docs = ref<Doc[]>([])
const loading = ref(false)
const uploading = ref(false)

const queryKey = computed(() => {
  if (props.policyId) return `policyId=${props.policyId}`
  if (props.customerId) return `customerId=${props.customerId}`
  return ''
})

async function fetchDocs() {
  if (!queryKey.value) return
  loading.value = true
  try {
    const res = await get<any>(`documents?${queryKey.value}`)
    docs.value = res.data || []
  } catch {
    docs.value = []
  } finally {
    loading.value = false
  }
}

async function uploadFiles(files: FileList | File[]) {
  const list = Array.from(files)
  if (!list.length) return

  for (const file of list) {
    if (file.size > 20 * 1024 * 1024) {
      toast.add({ title: `${file.name}: 20MB'den büyük`, color: 'error' })
      continue
    }
    uploading.value = true
    const fd = new FormData()
    fd.append('file', file)
    if (props.policyId) fd.append('policyId', String(props.policyId))
    if (props.customerId) fd.append('customerId', String(props.customerId))

    try {
      const res = await fetch('/api/documents', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token.value}` },
        body: fd
      })
      const data = await res.json()
      if (!res.ok || !data.success) {
        toast.add({ title: data.message || `${file.name}: yükleme başarısız`, color: 'error' })
      } else {
        toast.add({ title: `${file.name} yüklendi`, color: 'success' })
      }
    } catch {
      toast.add({ title: `${file.name}: ağ hatası`, color: 'error' })
    } finally {
      uploading.value = false
    }
  }

  if (fileInput.value) fileInput.value.value = ''
  fetchDocs()
}

function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files?.length) uploadFiles(input.files)
}

async function shareDoc(d: Doc) {
  try {
    const res = await fetch(`/api/documents/${d.id}/download`, {
      headers: { Authorization: `Bearer ${token.value}` }
    })
    if (!res.ok) {
      toast.add({ title: 'Dosya yüklenemedi', color: 'error' })
      return
    }
    const blob = await res.blob()
    const file = new File([blob], d.name, { type: d.type || 'application/pdf' })

    await navigator.share({ files: [file] })
  } catch (err: any) {
    if (err?.name !== 'AbortError') {
      toast.add({ title: 'Paylaşım başarısız', color: 'error' })
    }
  }
}

async function viewDoc(d: Doc) {
  try {
    const res = await fetch(`/api/documents/${d.id}/download`, {
      headers: { Authorization: `Bearer ${token.value}` }
    })
    if (!res.ok) {
      toast.add({ title: 'Dosya açılamadı', color: 'error' })
      return
    }
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    window.open(url, '_blank')
  } catch {
    toast.add({ title: 'Dosya açılamadı', color: 'error' })
  }
}

async function downloadDoc(d: Doc) {
  try {
    const res = await fetch(`/api/documents/${d.id}/download`, {
      headers: { Authorization: `Bearer ${token.value}` }
    })
    if (!res.ok) {
      const txt = await res.text()
      toast.add({ title: 'İndirme başarısız', color: 'error', description: txt.slice(0, 80) })
      return
    }
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = d.name
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch {
    toast.add({ title: 'İndirme hatası', color: 'error' })
  }
}

async function deleteDoc(d: Doc) {
  if (!confirm(`"${d.name}" silinsin mi?`)) return
  try {
    await apiDel(`documents/${d.id}`)
    toast.add({ title: 'Belge silindi', color: 'success' })
    fetchDocs()
  } catch {
    toast.add({ title: 'Silme başarısız', color: 'error' })
  }
}

// Drag-drop
const isDragging = ref(false)
function onDrop(e: DragEvent) {
  e.preventDefault()
  isDragging.value = false
  if (e.dataTransfer?.files?.length) uploadFiles(e.dataTransfer.files)
}
function onDragOver(e: DragEvent) {
  e.preventDefault()
  isDragging.value = true
}
function onDragLeave() {
  isDragging.value = false
}

const canShare = ref(false)
onMounted(() => {
  canShare.value = !!navigator.share && window.isSecureContext
})

function fileIcon(mime: string | null): string {
  if (!mime) return 'i-lucide-file'
  if (mime.startsWith('image/')) return 'i-lucide-image'
  if (mime === 'application/pdf') return 'i-lucide-file-text'
  if (mime.includes('spreadsheet') || mime.includes('excel')) return 'i-lucide-file-spreadsheet'
  if (mime.includes('word') || mime.includes('document')) return 'i-lucide-file-text'
  return 'i-lucide-file'
}

function formatSize(b: number): string {
  if (b < 1024) return b + ' B'
  if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB'
  return (b / 1024 / 1024).toFixed(2) + ' MB'
}

function formatDate(iso: string): string {
  try { return new Date(iso).toLocaleString('tr-TR') } catch { return iso }
}

watch(() => queryKey.value, () => fetchDocs(), { immediate: true })
</script>

<template>
  <div class="space-y-3">
    <!-- List -->
    <div v-if="loading" class="text-center text-sm text-muted py-4">Yükleniyor...</div>
    <div v-else-if="!docs.length" class="text-center text-sm text-muted py-6">
      Henüz dosya yok
    </div>
    <div v-else class="space-y-2">
      <div
        v-for="d in docs"
        :key="d.id"
        class="flex items-center gap-3 p-3 border border-default rounded-lg hover:bg-elevated group"
      >
        <UIcon :name="fileIcon(d.type)" class="size-6 text-muted shrink-0" />
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium overflow-hidden whitespace-nowrap" :title="d.name">{{ d.name }}</p>
        </div>
        <div class="flex gap-1">
          <UButton
            icon="i-lucide-eye"
            size="xs"
            variant="ghost"
            color="primary"
            title="Görüntüle"
            @click="viewDoc(d)"
          />
          <UButton
            v-if="canShare"
            icon="i-lucide-share-2"
            size="xs"
            variant="ghost"
            color="success"
            title="Paylaş"
            class="sm:hidden"
            @click="shareDoc(d)"
          />
          <UButton
            icon="i-lucide-download"
            size="xs"
            variant="ghost"
            color="neutral"
            title="İndir"
            class="hidden sm:inline-flex"
            @click="downloadDoc(d)"
          />
          <UButton
            v-if="user?.role === 'admin' || d.uploadedBy"
            icon="i-lucide-trash-2"
            size="xs"
            variant="ghost"
            color="error"
            title="Sil"
            class="hidden sm:inline-flex"
            @click="deleteDoc(d)"
          />
        </div>
      </div>
    </div>

    <!-- Upload Area -->
    <div
      class="border-2 border-dashed rounded-lg p-4 text-center cursor-pointer transition-colors"
      :class="isDragging ? 'border-primary bg-primary/5' : 'border-default hover:bg-elevated'"
      @click="fileInput?.click()"
      @drop="onDrop"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
    >
      <UIcon name="i-lucide-upload-cloud" class="size-8 mx-auto mb-2 text-muted" />
      <p class="text-sm text-muted">
        <span class="font-medium text-primary">Dosya seç</span> veya sürükle
      </p>
      <p class="text-xs text-muted mt-1">
        PDF, JPG, PNG, WebP, DOCX, XLSX — maks. 20 MB
      </p>
      <input
        ref="fileInput"
        type="file"
        multiple
        accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,.doc,.xls"
        class="hidden"
        @change="onFileChange"
      >
    </div>

    <!-- Uploading indicator -->
    <div v-if="uploading" class="text-center text-xs text-muted flex items-center justify-center gap-2">
      <UIcon name="i-lucide-loader" class="size-3 animate-spin" />
      Yükleniyor (şifreleniyor)...
    </div>
  </div>
</template>
