<script setup lang="ts">
import * as XLSX from 'xlsx'

definePageMeta({ layout: 'default', middleware: 'auth' })
useSeoMeta({ title: 'Excel İçe Aktarım' })

const toast = useToast()
const { get, post, del } = useApi()
const { insurances, fetchInsurances } = useInsuranceTypes()

// CRM lookup verileri
const companies = ref<any[]>([])
async function fetchCompanies() {
  try {
    const res = await get<any>('companies?all=1')
    companies.value = res.data || []
  } catch {}
}

// CRM hedef alanlari — kullanicinin Excel kolonlarini bunlara baglayacagi liste
type TargetField = {
  key: string
  label: string
  required?: boolean
  group: 'customer' | 'policy' | 'amount' | 'vehicle' | 'extra'
}

const targetFields: TargetField[] = [
  { key: 'customerName', label: 'Müşteri Adı', required: true, group: 'customer' },
  { key: 'identityNumber', label: 'TC / Vergi No', group: 'customer' },
  { key: 'phone', label: 'Telefon', group: 'customer' },
  { key: 'birthDate', label: 'Doğum Tarihi', group: 'customer' },
  { key: 'customerAddress', label: 'Adres', group: 'customer' },
  { key: 'taxOffice', label: 'Vergi Dairesi', group: 'customer' },

  { key: 'policyNo', label: 'Poliçe No', required: true, group: 'policy' },
  { key: 'endorsementNo', label: 'Zeyil No', group: 'policy' },
  { key: 'companyName', label: 'Şirket', group: 'policy' },
  { key: 'insuranceName', label: 'Sigorta Türü', group: 'policy' },
  { key: 'issuedAt', label: 'Tanzim Tarihi', group: 'policy' },
  { key: 'startsAt', label: 'Başlangıç Tarihi', required: true, group: 'policy' },
  { key: 'expiresAt', label: 'Bitiş Tarihi', required: true, group: 'policy' },
  { key: 'insuredName', label: 'Sigorta Ettiren', group: 'policy' },

  { key: 'grossPremium', label: 'Brüt Prim', group: 'amount' },
  { key: 'netPremium', label: 'Net Prim', group: 'amount' },
  { key: 'companyCommRate', label: 'Komisyon Oranı (%)', group: 'amount' },

  { key: 'plateNo', label: 'Plaka', group: 'vehicle' },
  { key: 'registrationNo', label: 'Ruhsat Seri No', group: 'vehicle' },
  { key: 'chassisNo', label: 'Şasi No', group: 'vehicle' },
  { key: 'engineNo', label: 'Motor No', group: 'vehicle' },
  { key: 'vehicleBrand', label: 'Marka', group: 'vehicle' },
  { key: 'vehicleModel', label: 'Model', group: 'vehicle' },
  { key: 'vehicleYear', label: 'Model Yılı', group: 'vehicle' },

  { key: 'uavt', label: 'UAVT Kodu', group: 'extra' },
  { key: 'daskNo', label: 'DASK Poliçe No', group: 'extra' },
  { key: 'network', label: 'Network', group: 'extra' }
]

const groupLabels: Record<string, string> = {
  customer: 'Müşteri',
  policy: 'Poliçe',
  amount: 'Tutar',
  vehicle: 'Araç',
  extra: 'Ek Alanlar'
}

const groupedTargets = computed(() => {
  const groups: Record<string, TargetField[]> = {}
  for (const f of targetFields) {
    if (!groups[f.group]) groups[f.group] = []
    groups[f.group].push(f)
  }
  return groups
})

// State
const sheetColumns = ref<string[]>([])
const sheetRows = ref<Record<string, any>[]>([])
const fileName = ref('')
const uploading = ref(false)

// mapping: { targetKey: [sourceColumn1, sourceColumn2, ...] }
// Birden cok kolon secilebilir; ilk dolu olan satir bazinda kullanilir.
const mapping = ref<Record<string, string[]>>({})

// valueMap: { fieldKey: { excelValue: crmId } } — sadece insuranceName / companyName icin
const valueMap = ref<{ insuranceName: Record<string, number>, companyName: Record<string, number> }>({
  insuranceName: {},
  companyName: {}
})

// Profil yonetimi
type TemplateMapping = {
  // columnMap'te degerler string[] olabilir (yeni format) veya string (eski format)
  columnMap: Record<string, string | string[]>
  valueMap?: { insuranceName?: Record<string, number>, companyName?: Record<string, number> }
}
type Template = { id: number, name: string, mapping: TemplateMapping | Record<string, string>, updatedAt: string }

// Eski/yeni formatlari her zaman { columnMap: { key: string[] } } haline cevir
function normalizeMappingShape(m: any): { columnMap: Record<string, string[]>, valueMap: any } {
  let rawCols: Record<string, any> = {}
  let valueMap: any = {}
  if (m && typeof m === 'object' && 'columnMap' in m) {
    rawCols = m.columnMap || {}
    valueMap = m.valueMap || {}
  } else if (m && typeof m === 'object') {
    rawCols = m
  }
  const cols: Record<string, string[]> = {}
  for (const [k, v] of Object.entries(rawCols)) {
    if (Array.isArray(v)) cols[k] = v.filter(x => typeof x === 'string' && x !== '')
    else if (typeof v === 'string' && v !== '') cols[k] = [v]
  }
  return { columnMap: cols, valueMap }
}
const templates = ref<Template[]>([])
const selectedTemplateId = ref<number | null>(null)
const newTemplateName = ref('')
const savingTemplate = ref(false)

async function fetchTemplates() {
  try {
    const res = await get<any>('import-mappings')
    templates.value = res.data || []
  } catch {}
}

function applyTemplate(t: Template) {
  selectedTemplateId.value = t.id
  newTemplateName.value = t.name
  const shape = normalizeMappingShape(t.mapping)
  // Sadece mevcut sheet kolonlariyla eslesenleri uygula
  const next: Record<string, string[]> = {}
  for (const [k, arr] of Object.entries(shape.columnMap)) {
    const filtered = arr.filter(c => sheetColumns.value.includes(c))
    if (filtered.length) next[k] = filtered
  }
  mapping.value = next
  valueMap.value = {
    insuranceName: { ...(shape.valueMap?.insuranceName || {}) },
    companyName: { ...(shape.valueMap?.companyName || {}) }
  }
  // Otomatik eslesme YOK — sablonda olanlar uygulanir, gerisi bos kalir.
}

async function saveTemplate() {
  const name = newTemplateName.value.trim()
  if (!name) {
    toast.add({ title: 'Şablon adı zorunlu', color: 'warning' })
    return
  }
  savingTemplate.value = true
  try {
    const payload: TemplateMapping = {
      columnMap: mapping.value,
      valueMap: {
        insuranceName: valueMap.value.insuranceName,
        companyName: valueMap.value.companyName
      }
    }
    const res = await post<any>('import-mappings', { name, mapping: payload })
    toast.add({ title: 'Şablon kaydedildi', color: 'success' })
    await fetchTemplates()
    if (res.data?.id) selectedTemplateId.value = res.data.id
  } catch (e: any) {
    toast.add({ title: e.message || 'Kaydedilemedi', color: 'error' })
  } finally {
    savingTemplate.value = false
  }
}

async function deleteTemplate(t: Template) {
  if (!confirm(`"${t.name}" şablonu silinsin mi?`)) return
  try {
    await del(`import-mappings/${t.id}`)
    toast.add({ title: 'Şablon silindi', color: 'success' })
    await fetchTemplates()
    if (selectedTemplateId.value === t.id) {
      selectedTemplateId.value = null
      newTemplateName.value = ''
    }
  } catch (e: any) {
    toast.add({ title: e.message || 'Silinemedi', color: 'error' })
  }
}

// Dosya yukle (input veya drag&drop)
const fileInput = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)

function isExcel(name: string) {
  const n = name.toLowerCase()
  return n.endsWith('.xlsx') || n.endsWith('.xls')
}

async function processFile(file: File) {
  if (!isExcel(file.name)) {
    toast.add({ title: 'Sadece .xlsx veya .xls dosyalari kabul edilir', color: 'error' })
    return
  }
  uploading.value = true
  fileName.value = file.name
  try {
    const buf = await file.arrayBuffer()
    const wb = XLSX.read(buf, { type: 'array', cellDates: true })
    const sheet = wb.Sheets[wb.SheetNames[0]]
    const rows = XLSX.utils.sheet_to_json<Record<string, any>>(sheet, { defval: '', raw: false })
    if (rows.length === 0) {
      toast.add({ title: 'Dosya boş', color: 'warning' })
      sheetColumns.value = []
      sheetRows.value = []
      return
    }
    // Tum kolonlari topla (ilk satirin keylerini al + sonrakileri de gez)
    const colSet = new Set<string>()
    rows.forEach(r => Object.keys(r).forEach(k => colSet.add(k)))
    sheetColumns.value = Array.from(colSet)
    sheetRows.value = rows
    // Otomatik kolon eslesmesi YOK — kullanici elle secer ya da kayitli sablon uygular.
    toast.add({ title: `${rows.length} satır okundu (${sheetColumns.value.length} kolon)`, color: 'success' })
  } catch (err: any) {
    toast.add({ title: 'Dosya okunamadı: ' + (err.message || ''), color: 'error' })
  } finally {
    uploading.value = false
  }
}

function onFileInput(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (file) processFile(file)
  input.value = ''
}

function onDrop(e: DragEvent) {
  e.preventDefault()
  isDragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file) processFile(file)
}

function onDragOver(e: DragEvent) {
  e.preventDefault()
  isDragging.value = true
}

function onDragLeave() {
  isDragging.value = false
}

function normalize(s: string) {
  return s.toLocaleLowerCase('tr-TR').replace(/[^a-z0-9çğıöşü]/g, '')
}

function autoMatch() {
  const next: Record<string, string[]> = { ...mapping.value }
  const normCols = sheetColumns.value.map(c => ({ raw: c, norm: normalize(c) }))
  for (const f of targetFields) {
    if (next[f.key]?.length) continue
    const candidates = [f.label, f.key]
    for (const cand of candidates) {
      const n = normalize(cand)
      const found = normCols.find(c => c.norm === n || c.norm.includes(n) || n.includes(c.norm))
      if (found) {
        next[f.key] = [found.raw]
        break
      }
    }
  }
  mapping.value = next
}

// Bir satirdan, hedef alana atanmis kolonlardan ILK dolu olani dondurur
function pickFirstNonEmpty(row: Record<string, any>, cols: string[]): any {
  if (!cols || cols.length === 0) return ''
  for (const c of cols) {
    const v = row[c]
    if (v !== undefined && v !== null && String(v).trim() !== '') return v
  }
  return ''
}

// === Benzersiz Excel degerleri + otomatik eslesme ===

function uniqueExcelValues(sourceCols: string[]): string[] {
  if (!sourceCols || sourceCols.length === 0) return []
  const set = new Set<string>()
  for (const row of sheetRows.value) {
    const v = String(pickFirstNonEmpty(row, sourceCols) ?? '').trim()
    if (v) set.add(v)
  }
  return Array.from(set).sort((a, b) => a.localeCompare(b, 'tr'))
}

const distinctInsuranceValues = computed(() => uniqueExcelValues(mapping.value.insuranceName || []))
const distinctCompanyValues = computed(() => uniqueExcelValues(mapping.value.companyName || []))

function findInsuranceMatch(excelValue: string): number | null {
  const n = normalize(excelValue)
  if (!n) return null
  const subs = insurances.value.filter(i => i.level === 'subcategory')
  // Once tam eslesme: name, code, externalCode
  for (const i of subs) {
    if (normalize(i.name) === n) return i.id
    if (i.code && normalize(i.code) === n) return i.id
    if ((i as any).externalCode && normalize((i as any).externalCode) === n) return i.id
  }
  // Sonra substring eslesme (her iki yon)
  for (const i of subs) {
    const ni = normalize(i.name)
    if (ni && (ni.includes(n) || n.includes(ni))) return i.id
  }
  return null
}

function findCompanyMatch(excelValue: string): number | null {
  const n = normalize(excelValue)
  if (!n) return null
  for (const c of companies.value) {
    if (normalize(c.name) === n) return c.id
  }
  for (const c of companies.value) {
    const nc = normalize(c.name)
    if (nc && (nc.includes(n) || n.includes(nc))) return c.id
  }
  return null
}

function autoMatchValues() {
  // Insurance
  const insNext = { ...valueMap.value.insuranceName }
  for (const v of distinctInsuranceValues.value) {
    if (insNext[v]) continue // kullanici/sablon zaten secmis
    const id = findInsuranceMatch(v)
    if (id) insNext[v] = id
  }
  valueMap.value.insuranceName = insNext

  // Company
  const coNext = { ...valueMap.value.companyName }
  for (const v of distinctCompanyValues.value) {
    if (coNext[v]) continue
    const id = findCompanyMatch(v)
    if (id) coNext[v] = id
  }
  valueMap.value.companyName = coNext
}

// Otomatik eslesme YOK — kullanici "Otomatik Eşleştir" butonuna basarsa veya sablon uygularsa tetiklenir.

const insuranceOptionsCrm = computed(() =>
  insurances.value
    .filter(i => i.level === 'subcategory')
    .map(i => ({ label: i.name, value: i.id }))
    .sort((a, b) => a.label.localeCompare(b.label, 'tr'))
)
const companyOptionsCrm = computed(() =>
  companies.value.map(c => ({ label: c.name, value: c.id }))
    .sort((a, b) => a.label.localeCompare(b.label, 'tr'))
)

const unmappedInsuranceCount = computed(() => distinctInsuranceValues.value.filter(v => !valueMap.value.insuranceName[v]).length)
const unmappedCompanyCount = computed(() => distinctCompanyValues.value.filter(v => !valueMap.value.companyName[v]).length)

const dateLike = new Set(['issuedAt', 'startsAt', 'expiresAt', 'birthDate'])
const numericLike = new Set(['grossPremium', 'netPremium', 'companyCommRate', 'endorsementNo'])

function toIsoDate(v: any): string {
  if (!v) return ''
  if (v instanceof Date) {
    const y = v.getFullYear()
    const m = String(v.getMonth() + 1).padStart(2, '0')
    const d = String(v.getDate()).padStart(2, '0')
    return `${y}-${m}-${d}`
  }
  const s = String(v).trim()
  if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.substring(0, 10)
  const m = s.match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})/)
  if (m) {
    let [, d, mo, y] = m
    if (y.length === 2) y = '20' + y
    return `${y}-${mo.padStart(2, '0')}-${d.padStart(2, '0')}`
  }
  return ''
}

function toNumber(v: any): number {
  if (typeof v === 'number') return v
  const s = String(v ?? '').trim()
  if (!s) return 0
  const cleaned = s.replace(/\./g, '').replace(',', '.').replace(/[^\d.\-]/g, '')
  const n = parseFloat(cleaned)
  return isNaN(n) ? 0 : n
}

// Mapping'i sheet satirlarina uygulayarak preview
const mappedPolicies = computed(() => {
  return sheetRows.value.map(row => {
    const out: any = { productionType: 'SELF', branchCommRate: 0 }
    for (const [tKey, sCols] of Object.entries(mapping.value)) {
      if (!sCols || sCols.length === 0) continue
      let val: any = pickFirstNonEmpty(row, sCols)
      if (dateLike.has(tKey)) val = toIsoDate(val)
      else if (numericLike.has(tKey)) val = toNumber(val)
      else val = val ?? ''
      out[tKey] = val
    }
    // Value-map'i uygula: text -> id
    if (out.insuranceName) {
      const id = valueMap.value.insuranceName[String(out.insuranceName).trim()]
      if (id) out.insuranceId = id
    }
    if (out.companyName) {
      const id = valueMap.value.companyName[String(out.companyName).trim()]
      if (id) out.companyId = id
    }
    // TC uzunluguna gore customer type
    const idn = String(out.identityNumber ?? '').replace(/\D/g, '')
    out.customerType = idn.length === 11 ? 'INDIVIDUAL' : 'CORPORATE'
    out.identityNumber = idn || ''
    out.endorsementNo = out.endorsementNo || 1
    return out
  })
})

const requiredOk = computed(() => targetFields.filter(f => f.required).every(f => mapping.value[f.key]?.length))

const saving = ref(false)
async function importAll() {
  if (!requiredOk.value) {
    toast.add({ title: 'Zorunlu alanlar eşleştirilmemiş', color: 'warning' })
    return
  }
  saving.value = true
  try {
    const res = await post<any>('import-mappings/run', { policies: mappedPolicies.value })
    const data = res.data || res
    const ok = data.saved || 0
    const errs: string[] = data.errors || []
    if (ok > 0 && errs.length === 0) {
      toast.add({ title: `${ok} poliçe kaydedildi`, color: 'success' })
      sheetColumns.value = []
      sheetRows.value = []
      fileName.value = ''
    } else if (ok > 0) {
      toast.add({ title: `${ok} kaydedildi, ${errs.length} hata`, color: 'warning' })
    } else {
      toast.add({ title: `Hata: ${errs.slice(0, 2).join(' | ')}`, color: 'error' })
    }
  } catch (e: any) {
    toast.add({ title: e.message || 'Import hatası', color: 'error' })
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchInsurances()
  fetchCompanies()
  fetchTemplates()
})

const sourceColumnOptions = computed(() =>
  sheetColumns.value.map(c => ({ label: c, value: c }))
)

function clearMapping(key: string) {
  delete mapping.value[key]
  mapping.value = { ...mapping.value }
}

// Bir hedef alan icin atanmis kolon listesinden tek bir kolonu cikar
function removeMappingCol(key: string, col: string) {
  const list = (mapping.value[key] || []).filter(c => c !== col)
  if (list.length === 0) delete mapping.value[key]
  else mapping.value[key] = list
  mapping.value = { ...mapping.value }
}
</script>

<template>
  <div class="space-y-4">
    <UCard>
      <template #header>
        <div class="flex items-center justify-between">
          <div>
            <p >Excel İçe Aktarım</p>
            <p class="text-xs text-muted mt-0.5">XLSX/XLS dosyasını yükle, kolonları CRM alanlarına eşleştir, şablon kaydet — bir daha tek tıkla uygulanır.</p>
          </div>
          <div v-if="fileName" class="flex items-center gap-2">
            <span class="text-xs text-muted">{{ fileName }}</span>
            <UButton v-if="sheetColumns.length" icon="i-lucide-x" size="xs" color="neutral" variant="ghost" title="Temizle" @click="sheetColumns = []; sheetRows = []; fileName = ''" />
          </div>
        </div>
      </template>

      <!-- Drag & drop alani -->
      <div v-if="!sheetColumns.length"
        class="flex flex-col items-center justify-center py-12 border-2 border-dashed rounded-lg transition-colors cursor-pointer"
        :class="isDragging ? 'border-primary bg-primary/5' : 'border-default'"
        @drop="onDrop"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @click="fileInput?.click()"
      >
        <UIcon name="i-lucide-file-spreadsheet" class="size-12 mb-4" :class="isDragging ? 'text-primary' : 'text-muted'" />
        <p class="text-sm mb-4" :class="isDragging ? 'text-primary font-medium' : 'text-muted'">
          {{ isDragging ? 'Dosyayi birakin...' : 'Excel dosyasini buraya surukleyin veya tiklayin' }}
        </p>
        <UButton
          v-if="!isDragging"
          label="Dosya Seç"
          icon="i-lucide-file-up"
          :loading="uploading"
          @click.stop="fileInput?.click()"
        />
        <input ref="fileInput" type="file" accept=".xlsx,.xls" class="hidden" @change="onFileInput">
        <p class="text-xs text-muted mt-3">Sadece .xlsx ve .xls dosyalari kabul edilir</p>
      </div>

      <!-- Sablon yonetimi -->
      <div v-if="sheetColumns.length" class="space-y-3 mb-4">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="text-sm font-medium">Şablon:</span>
          <UButton
            v-for="t in templates"
            :key="t.id"
            :color="selectedTemplateId === t.id ? 'primary' : 'neutral'"
            :variant="selectedTemplateId === t.id ? 'solid' : 'soft'"
            size="xs"
            @click="applyTemplate(t)"
          >
            {{ t.name }}
            <UIcon name="i-lucide-x" class="size-3 ml-1 hover:text-red-500" @click.stop="deleteTemplate(t)" />
          </UButton>
          <span v-if="templates.length === 0" class="text-xs text-muted">Henüz kaydedilmiş şablon yok.</span>
        </div>
        <div class="flex items-center gap-2">
          <UInput v-model="newTemplateName" placeholder="Şablon adı (örn. Anadolu Aylık)" class="flex-1 max-w-xs" />
          <UButton :loading="savingTemplate" icon="i-lucide-save" size="sm" :disabled="!Object.keys(mapping).length" @click="saveTemplate">
            {{ selectedTemplateId ? 'Şablonu Güncelle / Yeni' : 'Şablon Kaydet' }}
          </UButton>
        </div>
      </div>

      <!-- Mapping arayuzu -->
      <div v-if="sheetColumns.length" class="space-y-4">
        <div v-for="(fields, group) in groupedTargets" :key="group">
          <h4 class="text-xs font-semibold text-muted uppercase tracking-wide mb-2">{{ groupLabels[group] }}</h4>
          <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            <div v-for="f in fields" :key="f.key" class="flex items-start gap-2">
              <label class="text-xs w-32 shrink-0 pt-2">
                {{ f.label }}
                <span v-if="f.required" class="text-red-500">*</span>
              </label>
              <div class="flex-1 min-w-0">
                <USelectMenu
                  v-model="mapping[f.key]"
                  :items="sourceColumnOptions"
                  value-key="value"
                  label-key="label"
                  placeholder="— seçin —"
                  multiple
                  searchable
                  :search-input="{ placeholder: 'Ara...' }"
                  class="w-full"
                  :ui="{ base: 'text-xs' }"
                />
                <p v-if="(mapping[f.key]?.length ?? 0) > 1" class="text-[10px] text-muted mt-1">
                  Birden fazla kolon seçildi — her satırda <strong>ilk dolu</strong> olan kullanılır.
                </p>
              </div>
              <UButton
                v-if="mapping[f.key]?.length"
                icon="i-lucide-x"
                size="xs"
                color="neutral"
                variant="ghost"
                title="Tüm eşleştirmeleri kaldır"
                @click="clearMapping(f.key)"
              />
            </div>
          </div>
        </div>
      </div>

    </UCard>

    <!-- Deger eslestirme (sigorta turu & sirket) -->
    <UCard v-if="distinctInsuranceValues.length || distinctCompanyValues.length">
      <template #header>
        <div class="flex items-center justify-between">
          <div>
            <p >Değer Eşleştirme</p>
            <p class="text-xs text-muted mt-0.5">Excel'deki metin değerlerini CRM kayıtlarına eşle. Boş kalanlar import sırasında atlanır.</p>
          </div>
          <div class="flex items-center gap-2">
            <UBadge v-if="unmappedInsuranceCount > 0" color="warning" variant="solid" size="sm">
              {{ unmappedInsuranceCount }} sigorta türü eşleşmedi
            </UBadge>
            <UBadge v-if="unmappedCompanyCount > 0" color="warning" variant="solid" size="sm">
              {{ unmappedCompanyCount }} şirket eşleşmedi
            </UBadge>
            <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-wand-sparkles" @click="autoMatchValues">
              Otomatik Eşleştir
            </UButton>
          </div>
        </div>
      </template>

      <div class="grid md:grid-cols-2 gap-6">
        <!-- Insurance type -->
        <div v-if="distinctInsuranceValues.length">
          <h4 class="text-xs font-semibold text-muted uppercase tracking-wide mb-2">
            Sigorta Türü ({{ distinctInsuranceValues.length }} benzersiz değer)
          </h4>
          <div class="space-y-2 max-h-96 overflow-y-auto pr-2">
            <div v-for="v in distinctInsuranceValues" :key="'ins-' + v"
              class="flex items-center gap-2 text-xs"
            >
              <span class="font-mono bg-elevated px-2 py-1 rounded w-40" :title="v">{{ v }}</span>
              <UIcon name="i-lucide-arrow-right" class="size-3 text-muted shrink-0" />
              <USelectMenu
                v-model="valueMap.insuranceName[v]"
                :items="insuranceOptionsCrm"
                value-key="value"
                label-key="label"
                placeholder="— CRM türü seç —"
                searchable
                :search-input="{ placeholder: 'Ara...' }"
                class="flex-1"
              />
              <UIcon v-if="valueMap.insuranceName[v]" name="i-lucide-check-circle" class="size-4 text-green-500 shrink-0" />
              <UIcon v-else name="i-lucide-alert-circle" class="size-4 text-orange-500 shrink-0" />
            </div>
          </div>
        </div>

        <!-- Company -->
        <div v-if="distinctCompanyValues.length">
          <h4 class="text-xs font-semibold text-muted uppercase tracking-wide mb-2">
            Şirket ({{ distinctCompanyValues.length }} benzersiz değer)
          </h4>
          <div class="space-y-2 max-h-96 overflow-y-auto pr-2">
            <div v-for="v in distinctCompanyValues" :key="'co-' + v"
              class="flex items-center gap-2 text-xs"
            >
              <span class="font-mono bg-elevated px-2 py-1 rounded w-40" :title="v">{{ v }}</span>
              <UIcon name="i-lucide-arrow-right" class="size-3 text-muted shrink-0" />
              <USelectMenu
                v-model="valueMap.companyName[v]"
                :items="companyOptionsCrm"
                value-key="value"
                label-key="label"
                placeholder="— CRM şirketi seç —"
                searchable
                :search-input="{ placeholder: 'Ara...' }"
                class="flex-1"
              />
              <UIcon v-if="valueMap.companyName[v]" name="i-lucide-check-circle" class="size-4 text-green-500 shrink-0" />
              <UIcon v-else name="i-lucide-alert-circle" class="size-4 text-orange-500 shrink-0" />
            </div>
          </div>
        </div>
      </div>
    </UCard>

    <!-- Preview -->
    <UCard v-if="mappedPolicies.length">
      <template #header>
        <div class="flex items-center justify-between">
          <p >Ön İzleme ({{ mappedPolicies.length }} satır)</p>
          <UButton
            :loading="saving"
            :disabled="!requiredOk"
            icon="i-lucide-database-zap"
            color="primary"
            @click="importAll"
          >
            Tümünü Aktar
          </UButton>
        </div>
      </template>
      <div class="overflow-x-auto">
        <table class="w-full text-xs">
          <thead>
            <tr class="border-b border-default">
              <th class="text-left p-2 font-medium text-muted">Müşteri</th>
              <th class="text-left p-2 font-medium text-muted">TC</th>
              <th class="text-left p-2 font-medium text-muted">Poliçe No</th>
              <th class="text-left p-2 font-medium text-muted">Şirket</th>
              <th class="text-left p-2 font-medium text-muted">Tür</th>
              <th class="text-left p-2 font-medium text-muted">Başlangıç</th>
              <th class="text-left p-2 font-medium text-muted">Bitiş</th>
              <th class="text-left p-2 font-medium text-muted">Brüt</th>
              <th class="text-left p-2 font-medium text-muted">Net</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(p, i) in mappedPolicies.slice(0, 50)" :key="i" class="border-b border-default">
              <td class="p-2">{{ p.customerName || '-' }}</td>
              <td class="p-2">{{ p.identityNumber || '-' }}</td>
              <td class="p-2">{{ p.policyNo || '-' }}</td>
              <td class="p-2">
                <span :class="p.companyId ? '' : 'text-orange-500'">{{ p.companyName || '-' }}</span>
                <UIcon v-if="p.companyName && !p.companyId" name="i-lucide-alert-circle" class="size-3 text-orange-500 ml-1" />
              </td>
              <td class="p-2">
                <span :class="p.insuranceId ? '' : 'text-orange-500'">{{ p.insuranceName || '-' }}</span>
                <UIcon v-if="p.insuranceName && !p.insuranceId" name="i-lucide-alert-circle" class="size-3 text-orange-500 ml-1" />
              </td>
              <td class="p-2">{{ p.startsAt || '-' }}</td>
              <td class="p-2">{{ p.expiresAt || '-' }}</td>
              <td class="p-2 text-right">{{ p.grossPremium || 0 }}</td>
              <td class="p-2 text-right">{{ p.netPremium || 0 }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="mappedPolicies.length > 50" class="text-xs text-muted text-center py-2">
          ... ilk 50 satır gösteriliyor, toplam {{ mappedPolicies.length }}
        </p>
      </div>
    </UCard>
  </div>
</template>
