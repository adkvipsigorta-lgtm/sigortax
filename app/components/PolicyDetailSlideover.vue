<script setup lang="ts">
const props = defineProps<{
  open: boolean
  policyId?: number | null
  showBackButton?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'edit': [policy: any]
  'back': []
}>()

const { get } = useApi()
const toast = useToast()
const { formatCurrency, getStatusColor, getStatusLabel } = usePolicyHelpers()

const loading = ref(false)
const policy = ref<any>(null)

watch(() => props.policyId, async (id) => {
  if (!id) { policy.value = null; return }
  loading.value = true
  policy.value = null
  try {
    const res = await get(`policies/${id}`)
    policy.value = res.data || res
  } catch {
    toast.add({ title: 'Poliçe detayı yüklenemedi', color: 'error' })
  } finally {
    loading.value = false
  }
}, { immediate: true })

// additional_insureds: JSON [{"name":"...","tc":"..."}] veya eski virgüllü format
function parseAdditionalInsureds(raw: string | null | undefined): Array<{ name: string; tc: string }> {
  if (!raw) return []
  if (raw.trim().startsWith('[')) {
    try { return JSON.parse(raw) } catch {}
  }
  // Geriye dönük uyumluluk: "ALİ KORKMAZ, NURAY KORKMAZ"
  return raw.split(',').map(n => ({ name: n.trim(), tc: '' })).filter(item => item.name !== '')
}

function formatDate(dateStr?: string): string {
  if (!dateStr) return '-'
  try { return new Date(dateStr).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' }) }
  catch { return dateStr }
}

function getStatusInternal(status: string): string {
  const map: Record<string, string> = { ACTIVE: 'aktif', EXPIRED: 'vadesi_gecmis', CANCELLED: 'iptal' }
  return map[status] || 'pasif'
}

const sections = computed(() => {
  const p = policy.value
  if (!p) return []

  type Field = { label: string; value: string; icon?: string; wide?: boolean; truncate?: boolean; dim?: boolean; multiline?: boolean }
  const f = (label: string, value: string | null | undefined, icon?: string, wide = false, truncate = false, dim = false, multiline = false): Field =>
    ({ label, value: (value != null && String(value).trim() !== '') ? String(value) : '-', icon, wide, truncate, dim, multiline })

  const prodLabels: Record<string, string> = { SELF: 'Acentem', INCOMING: 'Tali Gelen', OUTGOING: 'Tali Giden' }
  const commAmount = p.netPremium && p.companyCommRate
    ? (parseFloat(p.netPremium) * parseFloat(p.companyCommRate)) / 100
    : null
  const isSelf = p.productionType === 'SELF'
  const acenteRate = isSelf ? 100 : (p.branchCommRate ?? null)
  const acenteAmount = isSelf
    ? commAmount
    : (commAmount && p.branchCommRate ? commAmount * parseFloat(p.branchCommRate) / 100 : null)

  const result: { title: string; fields: Field[]; cols?: number }[] = []

  // 1. Poliçe Bilgileri
  result.push({ title: 'Poliçe Bilgileri', cols: 3, fields: [
    f('Sigortalı', p.customerName, undefined, false, true),
    f('Sigorta Ettiren', p.insuredName, undefined, false, true),
    f('Sigorta Türü', p.insuranceName),
    f('Üretim Tipi', p.productionType ? (prodLabels[p.productionType] || p.productionType) : null),
    f('Tali Acente', p.branchName),
    f('Şirket', p.companyName),
    f('Oluşturan', p.createdByName),
    f('Satış Temsilcisi', p.soldByName),
    f('Referans Kaynağı', p.referenceSourceName),
    f('Kayıt Tarihi', p.createdAt ? formatDate(p.createdAt) : null),
  ]})

  // 2. Tarihler
  result.push({ title: 'Tarihler', cols: 3, fields: [
    f('Tanzim Tarihi', p.issuedAt ? formatDate(p.issuedAt) : null, 'i-lucide-calendar-check'),
    f('Başlangıç', formatDate(p.startsAt), 'i-lucide-calendar'),
    f('Bitiş', formatDate(p.effectiveExpiresAt || p.expiresAt), 'i-lucide-calendar-x'),
  ]})

  // 3. Araç Bilgileri (sadece oto branşları)
  if (['KASKO', 'TRAFİK'].includes(p.branchGroup)) {
    result.push({ title: 'Araç Bilgileri', cols: 3, fields: [
      f('Plaka', p.plateNo),
      f('Tescil No', p.registrationNo),
      f('Şasi No', p.chassisNo, undefined, false, false, true),
    ]})
  }

  // 4. Prim & Komisyon
  result.push({ title: 'Prim & Komisyon', cols: 3, fields: [
    f('Brüt Prim', formatCurrency(p.grossPremium) + ' TL'),
    f('Net Prim', formatCurrency(p.netPremium) + ' TL'),
    f('Şirket Kom. Tutarı', commAmount ? formatCurrency(commAmount) + ' TL' : null),
    f('Acente Payı Oranı', acenteRate != null ? `%${acenteRate}` : null),
    f('Şirket Kom. Oranı', p.companyCommRate != null ? `%${p.companyCommRate}` : null),
    f('Acente Payı Tutarı', acenteAmount ? formatCurrency(acenteAmount) + ' TL' : null),
  ]})

  // 5. Risk Adres Bilgileri (sadece Konut / DASK branşı)
  if (p.branchGroup === 'KONUT' && (p.riskAddress || p.daskNo)) {
    result.push({ title: 'Risk Adres Bilgileri', fields: [
      f('DASK Poliçe No', p.daskNo),
      f('Riziko Adresi', p.riskAddress, undefined, true, false, false, true),
    ]})
  }

  return result
})
</script>

<template>
  <USlideover
    :open="open"
    :title="policy?.policyNo ? `Poliçe: ${policy.policyNo}` : 'Poliçe Detay'"
    class="sm:max-w-lg"
    @update:open="emit('update:open', $event)"
  >
    <template #body>
      <div v-if="loading" class="animate-pulse space-y-4 py-4">
        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4" />
        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/2" />
        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-2/3" />
        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-5/6" />
        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/3" />
      </div>

      <div v-else-if="policy" class="space-y-4">
        <!-- Geri butonu (zeyil listesinden geldiyse) -->
        <button
          v-if="showBackButton"
          class="flex items-center gap-1 text-sm text-primary hover:underline cursor-pointer"
          @click="emit('back')"
        >
          <UIcon name="i-lucide-arrow-left" class="size-4" />
          Zeyil Listesine Dön
        </button>

        <!-- Durum & Prim özet -->
        <div class="flex items-center justify-between p-3 rounded-lg bg-gray-50 dark:bg-gray-800/50">
          <div class="flex items-center gap-2">
            <UBadge :color="getStatusColor(getStatusInternal(policy.status))" variant="solid">
              {{ getStatusLabel(getStatusInternal(policy.status)) }}
            </UBadge>
            <span v-if="policy.endorsementNo > 0" class="text-xs text-muted">
              Zeyil #{{ policy.endorsementNo }}
            </span>
            <button
              class="inline-flex items-center gap-1 text-xs text-primary hover:underline cursor-pointer ml-1"
              @click="emit('edit', policy)"
            >
              <UIcon name="i-lucide-pencil" class="size-3.5" />
              Düzenle
            </button>
          </div>
          <div class="text-right">
            <p class="font-semibold">{{ formatCurrency(policy.grossPremium) }} TL</p>
            <p class="text-xs text-muted">Brüt Prim</p>
          </div>
        </div>

        <!-- Detay alanları — seksiyon grupları -->
        <div class="space-y-4">
          <div
            v-for="(section, si) in sections"
            :key="section.title"
            :class="si > 0 ? 'pt-2' : ''"
          >
            <p class="text-xs font-semibold text-muted uppercase tracking-wider px-3 py-1.5 mb-3 bg-gray-100 dark:bg-gray-800 rounded-md">{{ section.title }}</p>
            <div class="grid gap-y-3 gap-x-4" :class="section.cols === 3 ? 'grid-cols-3' : 'grid-cols-2'">
              <div
                v-for="field in section.fields"
                :key="field.label"
                :class="['min-w-0 overflow-hidden', field.wide ? 'col-span-2' : '']"
              >
                <p class="text-xs text-muted mb-0.5">{{ field.label }}</p>
                <div class="flex items-center gap-1.5 min-w-0">
                  <UIcon v-if="field.icon" :name="field.icon" class="size-3.5 text-muted shrink-0" />
                  <p
                    :class="[
                      field.dim ? 'text-xs font-normal' : 'text-sm font-semibold',
                      field.value === '-' ? 'text-muted/50' : (field.dim ? 'text-muted' : 'text-highlighted'),
                      field.multiline ? 'whitespace-pre-line' : 'overflow-hidden whitespace-nowrap'
                    ]"
                    :title="!field.multiline ? field.value : undefined"
                  >{{ field.value }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Sigortalılar (birincil + ek) -->
        <div v-if="policy.additionalInsureds" class="pt-2">
          <p class="text-xs font-semibold text-muted uppercase tracking-wider px-3 py-1.5 mb-3 bg-gray-100 dark:bg-gray-800 rounded-md">Sigortalılar</p>
          <div class="grid grid-cols-2 gap-2">
            <!-- Birincil sigortalı (müşteri) -->
            <div class="flex items-start gap-2 p-2 rounded-lg bg-gray-50 dark:bg-gray-800/50 min-w-0">
              <UIcon name="i-lucide-user" class="size-3.5 text-primary mt-0.5 shrink-0" />
              <div class="min-w-0">
                <p class="text-xs text-muted overflow-hidden whitespace-nowrap">{{ policy.customerIdentity || '—' }}</p>
                <p class="text-sm font-medium overflow-hidden whitespace-nowrap">{{ policy.customerName }}</p>
              </div>
            </div>
            <!-- Ek sigortalılar (JSON veya eski format) -->
            <div
              v-for="(person, idx) in parseAdditionalInsureds(policy.additionalInsureds)"
              :key="idx"
              class="flex items-start gap-2 p-2 rounded-lg bg-gray-50 dark:bg-gray-800/50 min-w-0"
            >
              <UIcon name="i-lucide-user" class="size-3.5 text-muted mt-0.5 shrink-0" />
              <div class="min-w-0">
                <p class="text-xs text-muted overflow-hidden whitespace-nowrap">{{ person.tc || '—' }}</p>
                <p class="text-sm font-medium overflow-hidden whitespace-nowrap">{{ person.name }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-else class="text-center py-16 text-muted">
        <p>Poliçe bilgisi bulunamadı</p>
      </div>

      <!-- Poliçe Dosyaları -->
      <div v-if="policy?.id" class="mt-4">
        <p class="text-xs font-semibold text-muted uppercase tracking-wider px-3 py-1.5 mb-3 bg-gray-100 dark:bg-gray-800 rounded-md flex items-center gap-2">
          <UIcon name="i-lucide-folder" class="size-3.5 text-primary" />
          Dosyalar
        </p>
        <DocumentsSection :policy-id="policy.id" />
      </div>
    </template>
  </USlideover>
</template>
