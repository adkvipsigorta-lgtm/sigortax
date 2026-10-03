<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Allianz XML Import' })

const toast = useToast()
const { get, post } = useApi()
const { token } = useAuth()
const { insurances, fetchInsurances } = useInsuranceTypes()
const { can } = usePermissions()
const { isFieldEnabled, fetchFieldSettings } = useFieldSettings()

const showProductionType = computed(() => isFieldEnabled('import_production_type'))
const showBranch = computed(() => isFieldEnabled('import_branch'))

function formatPhoneNumber(val: string): string {
  const d = val.replace(/\D/g, '').slice(0, 10)
  const parts: string[] = []
  if (d.length > 0) parts.push(d.slice(0, 3))
  if (d.length > 3) parts.push(d.slice(3, 6))
  if (d.length > 6) parts.push(d.slice(6, 8))
  if (d.length > 8) parts.push(d.slice(8, 10))
  return parts.join(' ')
}

const _phoneCountryCodes = [
  '+90',  // Türkiye
  '+1',   // ABD / Kanada
  '+7',   // Rusya / Kazakistan
  '+20',  // Mısır
  '+27',  // Güney Afrika
  '+30',  // Yunanistan
  '+31',  // Hollanda
  '+32',  // Belçika
  '+33',  // Fransa
  '+34',  // İspanya
  '+36',  // Macaristan
  '+39',  // İtalya
  '+40',  // Romanya
  '+41',  // İsviçre
  '+43',  // Avusturya
  '+44',  // İngiltere
  '+45',  // Danimarka
  '+46',  // İsveç
  '+47',  // Norveç
  '+48',  // Polonya
  '+49',  // Almanya
  '+51',  // Peru
  '+52',  // Meksika
  '+54',  // Arjantin
  '+55',  // Brezilya
  '+56',  // Şili
  '+57',  // Kolombiya
  '+58',  // Venezuela
  '+60',  // Malezya
  '+61',  // Avustralya
  '+62',  // Endonezya
  '+63',  // Filipinler
  '+64',  // Yeni Zelanda
  '+65',  // Singapur
  '+66',  // Tayland
  '+81',  // Japonya
  '+82',  // Güney Kore
  '+84',  // Vietnam
  '+86',  // Çin
  '+90',  // Türkiye (zaten başta var, tekrar eklenmiyor)
  '+91',  // Hindistan
  '+92',  // Pakistan
  '+93',  // Afganistan
  '+94',  // Sri Lanka
  '+95',  // Myanmar
  '+98',  // İran
  '+212', // Fas
  '+213', // Cezayir
  '+216', // Tunus
  '+218', // Libya
  '+220', // Gambiya
  '+221', // Senegal
  '+222', // Moritanya
  '+223', // Mali
  '+224', // Gine
  '+225', // Fildişi Sahili
  '+226', // Burkina Faso
  '+227', // Nijer
  '+228', // Togo
  '+229', // Benin
  '+230', // Mauritius
  '+231', // Liberya
  '+232', // Sierra Leone
  '+233', // Gana
  '+234', // Nijerya
  '+235', // Çad
  '+236', // Orta Afrika
  '+237', // Kamerun
  '+238', // Yeşil Burun
  '+239', // São Tomé
  '+240', // Ekvator Ginesi
  '+241', // Gabon
  '+242', // Kongo
  '+243', // DR Kongo
  '+244', // Angola
  '+245', // Gine-Bissau
  '+246', // Britanya Hint Okyanusu
  '+247', // Ascension
  '+248', // Seyşeller
  '+249', // Sudan
  '+250', // Ruanda
  '+251', // Etiyopya
  '+252', // Somali
  '+253', // Cibuti
  '+254', // Kenya
  '+255', // Tanzanya
  '+256', // Uganda
  '+257', // Burundi
  '+258', // Mozambik
  '+260', // Zambiya
  '+261', // Madagaskar
  '+262', // Réunion
  '+263', // Zimbabve
  '+264', // Namibya
  '+265', // Malavi
  '+266', // Lesoto
  '+267', // Botsvana
  '+268', // Esvatini
  '+269', // Komorlar
  '+290', // St. Helena
  '+291', // Eritre
  '+297', // Aruba
  '+298', // Faroe Adaları
  '+299', // Grönland
  '+350', // Cebelitarık
  '+351', // Portekiz
  '+352', // Lüksemburg
  '+353', // İrlanda
  '+354', // İzlanda
  '+355', // Arnavutluk
  '+356', // Malta
  '+357', // Kıbrıs
  '+358', // Finlandiya
  '+359', // Bulgaristan
  '+370', // Litvanya
  '+371', // Letonya
  '+372', // Estonya
  '+373', // Moldova
  '+374', // Ermenistan
  '+375', // Belarus
  '+376', // Andorra
  '+377', // Monako
  '+378', // San Marino
  '+380', // Ukrayna
  '+381', // Sırbistan
  '+382', // Karadağ
  '+383', // Kosova
  '+385', // Hırvatistan
  '+386', // Slovenya
  '+387', // Bosna-Hersek
  '+389', // Kuzey Makedonya
  '+420', // Çek Cumhuriyeti
  '+421', // Slovakya
  '+423', // Lihtenştayn
  '+500', // Falkland Adaları
  '+501', // Belize
  '+502', // Guatemala
  '+503', // El Salvador
  '+504', // Honduras
  '+505', // Nikaragua
  '+506', // Kosta Rika
  '+507', // Panama
  '+508', // St. Pierre
  '+509', // Haiti
  '+590', // Guadeloupe
  '+591', // Bolivya
  '+592', // Guyana
  '+593', // Ekvador
  '+594', // Fransız Guyanası
  '+595', // Paraguay
  '+596', // Martinik
  '+597', // Surinam
  '+598', // Uruguay
  '+599', // Hollanda Antilleri
  '+670', // Doğu Timor
  '+672', // Norfolk Adası
  '+673', // Brunei
  '+674', // Nauru
  '+675', // Papua Yeni Gine
  '+676', // Tonga
  '+677', // Solomon Adaları
  '+678', // Vanuatu
  '+679', // Fiji
  '+680', // Palau
  '+681', // Wallis ve Futuna
  '+682', // Cook Adaları
  '+683', // Niue
  '+685', // Samoa
  '+686', // Kiribati
  '+687', // Yeni Kaledonya
  '+688', // Tuvalu
  '+689', // Fransız Polinezyası
  '+690', // Tokelau
  '+691', // Mikronezya
  '+692', // Marshall Adaları
  '+850', // Kuzey Kore
  '+852', // Hong Kong
  '+853', // Makao
  '+855', // Kamboçya
  '+856', // Laos
  '+880', // Bangladeş
  '+886', // Tayvan
  '+960', // Maldivler
  '+961', // Lübnan
  '+962', // Ürdün
  '+963', // Suriye
  '+964', // Irak
  '+965', // Kuveyt
  '+966', // Suudi Arabistan
  '+967', // Yemen
  '+968', // Umman
  '+970', // Filistin
  '+971', // BAE
  '+972', // İsrail
  '+973', // Bahreyn
  '+974', // Katar
  '+975', // Butan
  '+976', // Moğolistan
  '+977', // Nepal
  '+992', // Tacikistan
  '+993', // Türkmenistan
  '+994', // Azerbaycan
  '+995', // Gürcistan
  '+996', // Kırgızistan
  '+998', // Özbekistan
].filter((v, i, a) => a.indexOf(v) === i) // tekrarları kaldır

if (!can('tools.allianz_import')) {
  navigateTo('/araclar')
}

const fileInput = ref<HTMLInputElement>()
const isPreviewOpen = ref(false)
const importedPolicies = ref<any[]>([])
const importing = ref(false)
const importProgress = ref(0)
const importProgressText = ref('')

// Countdown / işlem durumu
const isProcessing = ref(false)
const isDone = ref(false)
const remainingCount = ref(0)
const processedCount = ref(0)
const totalToProcess = ref(0)
const savedCount = ref(0)
const updatedCount = ref(0)
const errorCount = ref(0)
const processErrors = ref<string[]>([])
const progressPercent = computed(() =>
  totalToProcess.value > 0 ? Math.round((processedCount.value / totalToProcess.value) * 100) : 0
)

const branches = ref<{ id: number; name: string; commissionRate: number }[]>([])
async function fetchBranches() {
  try {
    const res = await get<any>('branches?all=1')
    branches.value = (res.data || res || []).map((b: any) => ({ id: b.id, name: b.name, commissionRate: Number(b.commissionRate) || 0 }))
  } catch {}
}

onMounted(() => {
  fetchInsurances()
  fetchBranches()
  fetchFieldSettings()
})

const prodOptions = [
  { label: 'Acentem', value: 'SELF' },
  { label: 'Tali Gelen', value: 'INCOMING' },
  { label: 'Tali Giden', value: 'OUTGOING' },
]

const branchOptions = computed(() =>
  branches.value.map(b => ({ label: b.name, value: b.id }))
)

// Sigorta türü eşleştirme
function findInsuranceByCode(code: string, all: any[]): any {
  if (!code) return null
  const sub = all.find(i => i.level === 'subcategory' && i.externalCode === code)
  if (sub) return sub
  const cat = all.find(i => i.level === 'category' && i.externalCode === code)
  if (cat) {
    const children = all.filter(i => i.level === 'subcategory' && i.parentId === cat.id)
    return children.length > 0 ? children[0] : cat
  }
  const branch = all.find(i => i.level === 'branch' && i.externalCode === code)
  if (branch) {
    const cats = all.filter(i => i.level === 'category' && i.parentId === branch.id)
    for (const c of cats) {
      const subs = all.filter(i => i.level === 'subcategory' && i.parentId === c.id)
      if (subs.length > 0) return subs[0]
    }
    return branch
  }
  return null
}

async function handleFileUpload(event: Event) {
  const input = event.target as HTMLInputElement
  if (!input.files?.length) return
  const file = input.files[0]
  if (!file.name.endsWith('.xml')) {
    toast.add({ title: 'Sadece XML dosyaları yüklenebilir', color: 'error' })
    return
  }
  await processFile(file)
  input.value = ''
}

async function processFile(file: File) {
  importing.value = true
  isPreviewOpen.value = false
  isDone.value = false
  importedPolicies.value = []
  importProgress.value = 20
  importProgressText.value = 'XML sunucuya gönderiliyor...'
  await new Promise(r => setTimeout(r, 0))

  try {
    // 1. PHP sunucu taraflı XML parse
    const formData = new FormData()
    formData.append('file', file)
    const response = await fetch('/api/allianz/upload', {
      method: 'POST',
      headers: { Authorization: `Bearer ${token.value}` },
      body: formData,
    })

    importProgress.value = 60
    importProgressText.value = 'XML ayrıştırılıyor...'
    await new Promise(r => setTimeout(r, 0))

    if (!response.ok) {
      const err = await response.json().catch(() => ({}))
      toast.add({ title: err.message || 'XML yüklenemedi', color: 'error' })
      importing.value = false
      return
    }

    const uploadRes = await response.json()
    const rawPolicies: any[] = uploadRes.data?.policies || []

    if (rawPolicies.length === 0) {
      toast.add({ title: 'XML dosyasında poliçe bulunamadı', color: 'warning' })
      importing.value = false
      return
    }

    importProgress.value = 80
    importProgressText.value = `${rawPolicies.length} poliçe için sigorta türü eşleştiriliyor...`
    await new Promise(r => setTimeout(r, 0))

    // 2. Sigorta türü eşleştirme
    const policies = rawPolicies.map((p: any) => {
      const matched = findInsuranceByCode(p.altProductCode, insurances.value)
        || findInsuranceByCode(p.saglikTipi, insurances.value)
        || findInsuranceByCode(p.productCode, insurances.value)
        || findInsuranceByCode(p.branchCode, insurances.value)
      return {
        selected: true,
        productionType: 'SELF',
        branchId: undefined as number | undefined,
        branchCommRate: 0,
        branchCommAmount: 0,
        ...p,
        insuranceName: matched?.name || `${p.branchCode}/${p.productCode}`,
        insuranceId: matched?.id,
      }
    })

    const unmatched = policies.filter((p: any) => !p.insuranceId)
    if (unmatched.length > 0) {
      const codes = [...new Set(unmatched.map((p: any) => p.insuranceName))].join(', ')
      toast.add({ title: `${unmatched.length} poliçede sigorta türü eşleştirilemedi: ${codes}`, color: 'error' })
      importing.value = false
      return
    }

    // 3. Mükerrer kontrol — tek sorgu, simüle sayaç
    importProgress.value = 0
    importProgressText.value = `0 / ${policies.length} poliçe kontrol ediliyor...`
    await new Promise(r => setTimeout(r, 0))

    let simCount = 0
    const simTotal = policies.length
    const simInterval = setInterval(() => {
      simCount = Math.min(simCount + Math.max(1, Math.ceil(simTotal / 40)), simTotal - 1)
      importProgress.value = Math.round((simCount / simTotal) * 95)
      importProgressText.value = `${simCount} / ${simTotal} poliçe kontrol ediliyor...`
    }, 80)

    try {
      const dupRes = await post<any>('allianz/check-duplicates', {
        policies: policies.map((p: any) => ({
          policyNo: p.policyNo,
          endorsementNo: p.zeyilNo,
          identityNumber: p.identityNumber,
        })),
      })
      clearInterval(simInterval)
      importProgress.value = 100
      importProgressText.value = `${simTotal} / ${simTotal} poliçe kontrol edildi`
      await new Promise(r => setTimeout(r, 0))

      const dupList: string[] = dupRes.data?.duplicates || []
      const customerMap: Record<string, { id: number; phone: string; birthDate: string }> =
        dupRes.data?.customers || {}

      policies.forEach((p: any) => {
        const key = `${p.policyNo}/${p.zeyilNo}`
        p.isDuplicate = dupList.includes(key)
        if (p.isDuplicate) p.selected = false
        const existing = p.identityNumber ? customerMap[p.identityNumber] : null
        if (existing) {
          p.existingCustomer = true
          p.existingCustomerId = existing.id
          if (existing.phone) p.phone = existing.phone
          if (existing.birthDate) p.birthDate = existing.birthDate
        }
      })

      const dupCount = policies.filter((p: any) => p.isDuplicate).length
      const existingCount = policies.filter((p: any) => p.existingCustomer).length
      if (dupCount > 0) toast.add({ title: `${dupCount} poliçe zaten kayıtlı (kırmızı)`, color: 'warning' })
      if (existingCount > 0) toast.add({ title: `${existingCount} müşteri sistemde mevcut`, color: 'info' })
    } catch {
      clearInterval(simInterval)
    }

    // Telefon numaralarını kod + numara olarak ayır (varsayılan +90)
    // Uzun kodlar önce gelmeli ki kısa kod yanlış eşleşmesin (+1 vs +1xxx)
    const knownCodes = _phoneCountryCodes.slice().sort((a, b) => b.length - a.length)
    policies.forEach((p: any) => {
      const raw = String(p.phone || '')
      const match = knownCodes.find(c => raw.startsWith(c))
      p.phoneCode   = match || '+90'
      p.phoneNumber = formatPhoneNumber((match ? raw.slice(match.length) : raw).replace(/\D/g, '').slice(0, 10))
    })

    importedPolicies.value = policies
    isPreviewOpen.value = true
    toast.add({ title: `${policies.length} poliçe bulundu`, color: 'success' })
  } catch (e: any) {
    toast.add({ title: e?.message || 'XML işleme hatası', color: 'error' })
  }

  importing.value = false
}

function mapPolicyPayload(p: any) {
  return {
    customerName: p.customerName,
    insuredName: p.insuredName,
    identityNumber: p.identityNumber,
    customerType: p.customerType,
    phone: p.phoneCode && p.phoneNumber ? p.phoneCode + p.phoneNumber.replace(/\s/g, '') : (p.phone || ''),
    birthDate: p.birthDate,
    customerAddress: p.customerAddress,
    taxOffice: p.taxOffice,
    insuranceId: p.insuranceId,
    insuranceName: p.insuranceName,
    insuredNo: p.insuredNo,
    policyNo: p.policyNo,
    endorsementNo: p.zeyilNo,
    issuedAt: p.issuedAt,
    startsAt: p.startsAt,
    expiresAt: p.expiresAt,
    grossPremium: p.grossPremium,
    netPremium: p.netPremium,
    companyCommRate: p.companyCommRate,
    branchCommRate: p.branchCommRate ?? 0,
    branchCommAmount: p.branchCommAmount || null,
    companyCommAmount: p.companyCommissionAmount ?? null,
    isCancelled: p.isCancelled,
    productionType: p.productionType,
    branchId: p.branchId,
    plateNo: p.plateNo,
    uavt: p.uavt,
    daskNo: p.daskNo,
    riskAddress: p.riskAddress,
    chassisNo: p.chassisNo,
    engineNo: p.engineNo,
    vehicleYear: p.vehicleYear,
    registrationNo: p.registrationNo,
    additionalInsureds: p.additionalInsureds,
  }
}

async function runCountdown(policies: any[], label: string) {
  isPreviewOpen.value = false
  totalToProcess.value = policies.length
  remainingCount.value = policies.length
  processedCount.value = 0
  savedCount.value = 0
  updatedCount.value = 0
  errorCount.value = 0
  processErrors.value = []
  isProcessing.value = true
  isDone.value = false
  await new Promise(r => setTimeout(r, 0))

  for (const p of policies) {
    try {
      const res = await post<any>('allianz/save', { policies: [mapPolicyPayload(p)] })
      const data = res.data || res
      if ((data.saved || 0) > 0) savedCount.value++
      else updatedCount.value++
    } catch (e: any) {
      errorCount.value++
      const msg = e?.data?.message || e?.message || `${label} hatası`
      if (processErrors.value.length < 50) processErrors.value.push(msg)
    }
    processedCount.value++
    remainingCount.value--
  }

  isProcessing.value = false
  isDone.value = true
}

async function saveImport() {
  const toImport = importedPolicies.value.filter(p => p.selected)
  if (toImport.length === 0) {
    toast.add({ title: 'En az bir poliçe seçin', color: 'warning' })
    return
  }
  await runCountdown(toImport, 'Kaydetme')
}

async function updateExisting() {
  const duplicates = importedPolicies.value.filter(p => p.isDuplicate)
  if (duplicates.length === 0) return
  await runCountdown(duplicates, 'Güncelleme')
}

function toggleAll(val: boolean) {
  importedPolicies.value.forEach(p => { if (!p.isDuplicate) p.selected = val })
}

function resetState() {
  isPreviewOpen.value = false
  importedPolicies.value = []
  importing.value = false
  importProgress.value = 0
  importProgressText.value = ''
  isProcessing.value = false
  isDone.value = false
  if (fileInput.value) fileInput.value.value = ''
}

const tableColCount = computed(() => 10 + (showProductionType.value ? 1 : 0) + (showBranch.value ? 1 : 0) + (showBranchCommCol.value ? 1 : 0))
const selectedCount = computed(() => importedPolicies.value.filter(p => p.selected).length)
const duplicateCount = computed(() => importedPolicies.value.filter(p => p.isDuplicate).length)

const totalGrossPremium = computed(() => importedPolicies.value.reduce((s, p) => s + (p.grossPremium || 0), 0))
const totalNetPremium = computed(() => importedPolicies.value.reduce((s, p) => s + (p.netPremium || 0), 0))
const totalCommission = computed(() => importedPolicies.value.reduce((s, p) => s + calcIncome(p), 0))

const groupedPolicies = computed(() => {
  const groups: { name: string; policies: any[] }[] = []
  const map = new Map<string, any[]>()
  for (const p of importedPolicies.value) {
    const key = p.insuranceName || 'Diğer'
    if (!map.has(key)) {
      map.set(key, [])
      groups.push({ name: key, policies: map.get(key)! })
    }
    map.get(key)!.push(p)
  }
  return groups
})

// Doğum tarihi yardımcıları
const birthDateDisplays = ref<Record<number, string>>({})

function autoFormatDate(raw: string): string {
  const d = raw.replace(/\D/g, '').slice(0, 8)
  if (d.length <= 2) return d
  if (d.length <= 4) return `${d.slice(0, 2)}.${d.slice(2)}`
  return `${d.slice(0, 2)}.${d.slice(2, 4)}.${d.slice(4)}`
}

function displayToIso(display: string): string | null {
  const d = display.replace(/\D/g, '')
  if (d.length !== 8) return null
  const day = parseInt(d.slice(0, 2))
  const month = parseInt(d.slice(2, 4))
  const year = parseInt(d.slice(4, 8))
  if (day < 1 || day > 31 || month < 1 || month > 12 || year < 1900) return null
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

function isoToDisplay(iso: string): string {
  if (!iso) return ''
  const [y, m, d] = iso.split('-')
  return `${d}.${m}.${y}`
}

function onBirthDateInput(idx: number, val: string) {
  const formatted = autoFormatDate(val)
  birthDateDisplays.value[idx] = formatted
  const iso = displayToIso(formatted)
  if (iso) {
    const p = importedPolicies.value[idx]
    p.birthDate = iso
    // Aynı müşterinin tüm poliçelerine yay
    if (p.identityNumber) {
      importedPolicies.value.forEach((other, i) => {
        if (i !== idx && other.identityNumber === p.identityNumber) {
          other.birthDate = iso
          birthDateDisplays.value[i] = formatted
        }
      })
    }
  }
}

function getBirthDateDisplay(idx: number): string {
  if (birthDateDisplays.value[idx] !== undefined) return birthDateDisplays.value[idx]
  const iso = importedPolicies.value[idx]?.birthDate
  if (iso) {
    const d = isoToDisplay(iso)
    birthDateDisplays.value[idx] = d
    return d
  }
  return ''
}

function formatCurrency(val: number) {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(val)
}

function calcIncome(p: any): number {
  if (p.companyCommissionAmount != null) return Number(p.companyCommissionAmount) || 0
  return (Number(p.netPremium) || 0) * (Number(p.companyCommRate) || 0) / 100
}

// Komisyon giris modu — police formuyla ayni ayara bagli (Alan Ayarlari)
const commissionAsAmount = computed(() => isFieldEnabled('commission_as_amount'))
// Tali acente komisyonu elle girilebilir mi? Kapaliysa kolon gizlenir ve
// acentenin sistemde tanimli orani kullanilir (onRowBranchChange uygular).
const branchCommInputEnabled = computed(() => isFieldEnabled('branch_commission_input'))
const showBranchCommCol = computed(() => showBranch.value && branchCommInputEnabled.value)

// Tali acente komisyonunun hesap tabani = sirket komisyonu tutari
function branchCommBase(p: any): number {
  return Math.round(calcIncome(p) * 100) / 100
}
function syncRowBranchFromRate(p: any) {
  p.branchCommAmount = Math.round(branchCommBase(p) * (Number(p.branchCommRate) || 0) / 100 * 100) / 100
}
function syncRowBranchFromAmount(p: any) {
  const base = branchCommBase(p)
  p.branchCommRate = base !== 0 ? Math.round((Number(p.branchCommAmount) || 0) / base * 100 * 100) / 100 : 0
}
// Tali acente secilince o acentenin varsayilan komisyon oranini doldur
function onRowBranchChange(p: any) {
  if (!p.branchId) {
    p.branchCommRate = 0
    p.branchCommAmount = 0
    return
  }
  const br = branches.value.find(b => b.id === p.branchId)
  if (br) p.branchCommRate = br.commissionRate
  syncRowBranchFromRate(p)
}
// Tablo icinde dar alana sigan kisa tutar: "1.200 TL"
function formatCompactTry(val: number) {
  return new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 }).format(val || 0) + ' ₺'
}
// Tek input — aktif moda gore orani ya da tutari yazar, digerini turetir
function onRowBranchCommInput(p: any, v: any) {
  const num = v === '' ? 0 : Number(v)
  if (commissionAsAmount.value) {
    p.branchCommAmount = num
    syncRowBranchFromAmount(p)
  } else {
    p.branchCommRate = num
    syncRowBranchFromRate(p)
  }
}
// Input'un icinde gosterilen karsilik degeri
function branchCommHint(p: any): string {
  return commissionAsAmount.value
    ? '%' + (p.branchCommRate || 0)
    : formatCompactTry(p.branchCommAmount || 0)
}

// Acentem (SELF) secilirse tali acente alanlarini temizle
function onRowProductionTypeChange(p: any, val: any) {
  if (val !== 'SELF') return
  p.branchId = undefined
  p.branchCommRate = 0
  p.branchCommAmount = 0
}

// Drag & drop
const isDragging = ref(false)
function onDrop(e: DragEvent) {
  e.preventDefault()
  isDragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (!file?.name.endsWith('.xml')) {
    toast.add({ title: 'Sadece XML dosyaları yüklenebilir', color: 'error' })
    return
  }
  processFile(file)
}
function onDragOver(e: DragEvent) { e.preventDefault(); isDragging.value = true }
function onDragLeave() { isDragging.value = false }
</script>

<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="pb-4 border-b border-default">
      <h1 class="text-xl font-semibold">Allianz XML Import</h1>
      <p class="text-sm text-muted mt-1">Allianz sisteminden indirilen XML dosyasını içe aktarın.</p>
    </div>

    <!-- ─── UPLOAD EKRANI ─── -->
    <UCard v-if="!importing && !isPreviewOpen && !isProcessing && !isDone">
      <template #header>
        <h3 class="font-semibold">XML Dosyası Yükle</h3>
      </template>
      <div
        class="flex flex-col items-center justify-center py-12 border-2 border-dashed rounded-lg transition-colors cursor-pointer"
        :class="isDragging ? 'border-primary bg-primary/5' : 'border-default'"
        @drop="onDrop"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @click="fileInput?.click()"
      >
        <UIcon name="i-lucide-upload-cloud" class="size-12 mb-4" :class="isDragging ? 'text-primary' : 'text-muted'" />
        <p class="text-sm mb-4" :class="isDragging ? 'text-primary font-medium' : 'text-muted'">
          {{ isDragging ? 'Dosyayı bırakın...' : 'XML dosyasını sürükleyin veya tıklayın' }}
        </p>
        <UButton
          v-if="!isDragging"
          label="Dosya Seç"
          icon="i-lucide-file-up"
          size="xl"
          class="font-semibold"
          @click.stop="fileInput?.click()"
        />
        <input ref="fileInput" type="file" accept=".xml" class="hidden" @change="handleFileUpload" />
        <p class="text-xs text-muted mt-3">Sadece .xml dosyaları kabul edilir</p>
      </div>
      <div class="mt-6 p-4 bg-elevated rounded-lg">
        <h4 class="font-medium text-sm mb-2">Nasıl Kullanılır?</h4>
        <ol class="text-sm text-muted space-y-1 list-decimal list-inside">
          <li>Allianz sisteminden XML dosyasını indirin</li>
          <li>Yükle butonuna tıklayarak dosyayı seçin</li>
          <li>Önizleme tablosunda telefon / doğum tarihi ekleyin</li>
          <li>Import butonuna tıklayın — geriye sayım ile canlı takip edin</li>
        </ol>
      </div>
    </UCard>

    <!-- ─── YÜKLENIYOR ─── -->
    <template v-if="importing && !isPreviewOpen">
      <div class="mb-4 space-y-1">
        <div class="flex items-center justify-between text-sm">
          <p class="text-muted">{{ importProgressText || 'XML işleniyor...' }}</p>
          <p class="font-medium">{{ importProgress }}%</p>
        </div>
        <div class="w-full bg-neutral-200 rounded-full h-2">
          <div class="bg-primary h-2 rounded-full transition-all duration-300" :style="{ width: importProgress + '%' }" />
        </div>
      </div>
      <UCard v-for="i in 3" :key="'sk'+i" :ui="{ body: 'p-0' }">
        <template #header>
          <div class="flex items-center justify-between animate-pulse">
            <div class="h-4 bg-neutral-200 rounded w-32" />
            <div class="h-5 bg-neutral-200 rounded w-16" />
          </div>
        </template>
        <SkeletonTable :rows="4" :cols="10" />
      </UCard>
    </template>

    <!-- ─── ÖNİZLEME TABLOSU ─── -->
    <template v-if="isPreviewOpen && !isProcessing && !isDone">
      <!-- Başlık + butonlar -->
      <div class="flex items-center justify-between mb-4">
        <div>
          <h3 class="font-semibold">Import Önizleme</h3>
          <p class="text-xs text-muted mt-0.5">
            {{ selectedCount }}/{{ importedPolicies.length }} poliçe seçili
            <span v-if="duplicateCount > 0" class="text-error ml-1">({{ duplicateCount }} kayıtlı)</span>
          </p>
        </div>
        <div class="flex items-center gap-2">
          <UButton
            label="Vazgeç"
            color="neutral"
            variant="outline"
            size="xl"
            class="font-semibold"
            @click="resetState()"
          />
          <UButton
            v-if="selectedCount === 0 && duplicateCount > 0"
            :label="`${duplicateCount} Poliçenin Eksik Alanlarını Güncelle`"
            icon="i-lucide-refresh-cw"
            size="xl"
            class="font-semibold"
            color="warning"
            @click="updateExisting()"
          />
          <UButton
            v-else
            :label="`${selectedCount} Poliçe Import Et`"
            icon="i-lucide-download"
            size="xl"
            class="font-semibold"
            @click="saveImport()"
          />
        </div>
      </div>

      <!-- Tek tablo: tüm gruplar aynı sütun hizasında -->
      <UCard :ui="{ body: 'p-0' }">
        <div class="overflow-x-auto">
          <table class="w-full text-sm" :style="{ tableLayout: 'fixed', minWidth: (showBranchCommCol ? 1265 : 1140) + 'px' }">
            <colgroup>
              <col v-if="showProductionType" style="width:110px" />
              <col v-if="showBranch" style="width:130px" />
              <col v-if="showBranchCommCol" style="width:125px" />
              <col style="width:140px" />
              <col style="width:70px" />
              <col style="width:165px" />
              <col style="width:90px" />
              <col style="width:65px" />
              <col style="width:75px" />
              <col style="width:135px" />
              <col style="width:90px" />
              <col style="width:50px" />
              <col style="width:90px" />
            </colgroup>
            <thead class="sticky top-0 z-10">
              <tr class="border-b border-default bg-gray-50 dark:bg-gray-800/50">
                <th v-if="showProductionType" class="p-2 text-left text-xs font-semibold text-muted uppercase">Üretim</th>
                <th v-if="showBranch" class="p-2 text-left text-xs font-semibold text-muted uppercase">Acente</th>
                <th v-if="showBranchCommCol" class="p-2 text-right text-xs font-semibold text-muted uppercase">Tali Kom.</th>
                <th class="p-2 text-left text-xs font-semibold text-muted uppercase">Müşteri</th>
                <th class="p-2 text-left text-xs font-semibold text-muted uppercase">Durum</th>
                <th class="p-2 text-left text-xs font-semibold text-muted uppercase">Telefon</th>
                <th class="p-2 text-left text-xs font-semibold text-muted uppercase">D. Tarihi</th>
                <th class="p-2 text-left text-xs font-semibold text-muted uppercase">Tür</th>
                <th class="p-2 text-left text-xs font-semibold text-muted uppercase">Plaka</th>
                <th class="p-2 text-left text-xs font-semibold text-muted uppercase">Poliçe No</th>
                <th class="p-2 text-right text-xs font-semibold text-muted uppercase">Net Prim</th>
                <th class="p-2 text-right text-xs font-semibold text-muted uppercase">Oran</th>
                <th class="p-2 text-right text-xs font-semibold text-muted uppercase">Gelir</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(group, gi) in groupedPolicies" :key="group.name">
                <tr v-if="gi > 0" aria-hidden="true">
                  <td :colspan="tableColCount" class="py-2 bg-transparent" />
                </tr>
                <tr class="bg-gray-100 dark:bg-gray-800/80 border-y border-default">
                  <td :colspan="tableColCount" class="px-3 py-2">
                    <div class="flex items-center justify-between">
                      <span class="text-xs font-semibold uppercase tracking-wide">{{ group.name }}</span>
                      <UBadge color="neutral" variant="solid" size="sm">{{ group.policies.length }} poliçe</UBadge>
                    </div>
                  </td>
                </tr>
                <tr
                  v-for="p in group.policies"
                  :key="p.policyNo + '/' + p.zeyilNo"
                  class="border-b border-default"
                  :class="p.isDuplicate
                    ? 'bg-red-50 dark:bg-red-900/10'
                    : p.isCancelled
                      ? 'bg-orange-50 dark:bg-orange-900/10'
                      : 'hover:bg-gray-50 dark:hover:bg-gray-800/30'"
                >
                  <td v-if="showProductionType" class="p-2">
                    <USelect
                      v-model="p.productionType"
                      :items="prodOptions"
                      size="sm"
                      class="w-full"
                      @update:model-value="onRowProductionTypeChange(p, $event)"
                    />
                  </td>
                  <td v-if="showBranch" class="p-2">
                    <USelect
                      v-if="p.productionType === 'INCOMING' || p.productionType === 'OUTGOING'"
                      v-model="p.branchId"
                      :items="branchOptions"
                      placeholder="Acente seç"
                      size="sm"
                      class="w-full"
                      @update:model-value="onRowBranchChange(p)"
                    />
                    <span v-else class="text-xs text-muted">—</span>
                  </td>
                  <td v-if="showBranchCommCol" class="p-2">
                    <UInput
                      v-if="p.productionType === 'INCOMING' || p.productionType === 'OUTGOING'"
                      :model-value="(commissionAsAmount ? p.branchCommAmount : p.branchCommRate) || ''"
                      type="number"
                      step="0.01"
                      size="sm"
                      :placeholder="commissionAsAmount ? '₺' : '%'"
                      class="w-full"
                      :title="commissionAsAmount ? ('Oran: %' + (p.branchCommRate || 0)) : ('Acente payı: ' + formatCurrency(p.branchCommAmount || 0))"
                      :ui="{ base: 'pe-14' }"
                      @update:model-value="(v) => onRowBranchCommInput(p, v)"
                    >
                      <template #trailing>
                        <span class="text-[10px] text-muted whitespace-nowrap">{{ branchCommHint(p) }}</span>
                      </template>
                    </UInput>
                    <span v-else class="text-xs text-muted">—</span>
                  </td>
                  <td class="p-2 overflow-hidden">
                    <p class="font-semibold text-xs uppercase truncate" :title="p.customerName">{{ p.customerName }}</p>
                    <p class="text-xs text-muted truncate">{{ p.identityNumber }}</p>
                  </td>
                  <td class="p-2">
                    <UBadge
                      v-if="p.existingCustomer"
                      color="success"
                      variant="soft"
                      size="sm"
                      label="Mevcut"
                      :title="`Müşteri sistemde kayıtlı (#${p.existingCustomerId})`"
                    />
                    <UBadge v-else color="neutral" variant="soft" size="sm" label="Yeni" />
                  </td>
                  <td class="p-2 overflow-hidden">
                    <div class="flex gap-1">
                      <UInput v-model="p.phoneCode" size="sm" class="w-16 shrink-0" :ui="{ base: 'text-center font-semibold' }" />
                      <UInput :model-value="p.phoneNumber" placeholder="" size="sm" class="flex-1 min-w-0" :ui="{ base: 'font-semibold' }" @update:model-value="p.phoneNumber = formatPhoneNumber(String($event))" />
                    </div>
                  </td>
                  <td class="p-2">
                    <UInput
                      :model-value="getBirthDateDisplay(importedPolicies.indexOf(p))"
                      placeholder="GG.AA.YYYY"
                      maxlength="10"
                      class="w-full"
                      size="sm"
                      @update:model-value="onBirthDateInput(importedPolicies.indexOf(p), $event)"
                    />
                  </td>
                  <td class="p-2 text-xs">
                    <span v-if="p.isDuplicate" class="text-error font-medium">Kayıtlı</span>
                    <span v-else-if="p.isCancelled" class="text-warning font-medium">İptal</span>
                    <span v-else-if="p.zeyilNo > 1" class="text-muted">Zeyil</span>
                    <span v-else>Yeni</span>
                  </td>
                  <td class="p-2 text-xs font-mono truncate" :title="p.plateNo">{{ p.plateNo || '—' }}</td>
                  <td class="p-2 text-xs font-mono truncate" :title="p.policyNo">{{ p.policyNo }}</td>
                  <td class="p-2 text-right text-xs" :class="p.netPremium < 0 ? 'text-error' : ''">
                    {{ formatCurrency(p.netPremium) }}
                  </td>
                  <td class="p-2 text-right text-xs font-medium">{{ p.companyCommRate }}%</td>
                  <td class="p-2 text-right text-xs font-medium" :class="calcIncome(p) < 0 ? 'text-error' : 'text-success'">
                    {{ formatCurrency(calcIncome(p)) }}
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </UCard>

      <!-- Özet -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
        <UCard>
          <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-primary/10">
              <UIcon name="i-lucide-file-text" class="size-5 text-primary" />
            </div>
            <div>
              <p class="text-xs text-muted">Toplam Poliçe</p>
              <p class="text-xl font-bold">{{ importedPolicies.length }}</p>
            </div>
          </div>
        </UCard>
        <UCard>
          <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-primary/10">
              <UIcon name="i-lucide-banknote" class="size-5 text-primary" />
            </div>
            <div>
              <p class="text-xs text-muted">Toplam Brüt Prim</p>
              <p class="text-xl font-bold">{{ formatCurrency(totalGrossPremium) }}</p>
            </div>
          </div>
        </UCard>
        <UCard>
          <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-primary/10">
              <UIcon name="i-lucide-wallet" class="size-5 text-primary" />
            </div>
            <div>
              <p class="text-xs text-muted">Toplam Net Prim</p>
              <p class="text-xl font-bold">{{ formatCurrency(totalNetPremium) }}</p>
            </div>
          </div>
        </UCard>
        <UCard>
          <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-success/10">
              <UIcon name="i-lucide-coins" class="size-5 text-success" />
            </div>
            <div>
              <p class="text-xs text-muted">Toplam Komisyon</p>
              <p class="text-xl font-bold" :class="totalCommission < 0 ? 'text-error' : 'text-success'">
                {{ formatCurrency(totalCommission) }}
              </p>
            </div>
          </div>
        </UCard>
      </div>
    </template>

    <!-- ─── GERİYE SAYIM ─── -->
    <UCard v-if="isProcessing">
      <div class="flex flex-col items-center justify-center py-16 gap-10">
        <div class="text-center select-none">
          <p class="text-xs font-semibold text-muted uppercase tracking-widest mb-4">Kalan Poliçe</p>
          <div class="text-[7rem] font-black leading-none tabular-nums text-primary transition-all duration-100">
            {{ remainingCount }}
          </div>
          <p class="text-sm text-muted mt-3">{{ processedCount }} / {{ totalToProcess }} işlendi</p>
        </div>

        <div class="w-full max-w-sm space-y-2">
          <div class="flex justify-between text-xs text-muted">
            <span>İlerleme</span>
            <span class="font-semibold tabular-nums">{{ progressPercent }}%</span>
          </div>
          <div class="w-full bg-neutral-200 rounded-full h-3 overflow-hidden">
            <div
              class="bg-primary h-3 rounded-full transition-all duration-100"
              :style="{ width: progressPercent + '%' }"
            />
          </div>
        </div>

        <div class="flex items-center gap-8 text-sm">
          <div class="flex items-center gap-2 text-success">
            <UIcon name="i-lucide-check-circle" class="size-4 shrink-0" />
            <span><strong class="tabular-nums">{{ savedCount }}</strong> yeni eklendi</span>
          </div>
          <div class="flex items-center gap-2 text-warning">
            <UIcon name="i-lucide-refresh-cw" class="size-4 shrink-0" />
            <span><strong class="tabular-nums">{{ updatedCount }}</strong> güncellendi</span>
          </div>
          <div v-if="errorCount > 0" class="flex items-center gap-2 text-error">
            <UIcon name="i-lucide-x-circle" class="size-4 shrink-0" />
            <span><strong class="tabular-nums">{{ errorCount }}</strong> hata</span>
          </div>
        </div>
      </div>
    </UCard>

    <!-- ─── TAMAMLANDI ─── -->
    <UCard v-if="isDone">
      <div class="flex flex-col items-center justify-center py-16 gap-8">
        <div
          class="size-24 rounded-full flex items-center justify-center"
          :class="errorCount === 0 ? 'bg-success/10' : 'bg-warning/10'"
        >
          <UIcon
            :name="errorCount === 0 ? 'i-lucide-check-circle-2' : 'i-lucide-alert-circle'"
            class="size-14"
            :class="errorCount === 0 ? 'text-success' : 'text-warning'"
          />
        </div>

        <div class="text-center">
          <h2 class="text-2xl font-bold">
            {{ errorCount === 0 ? 'Tüm poliçeler başarıyla işlendi!' : 'İşlem tamamlandı' }}
          </h2>
          <p class="text-muted text-sm mt-1">Toplam {{ totalToProcess }} poliçe işlendi</p>
        </div>

        <div class="grid grid-cols-3 gap-4 w-full max-w-md">
          <div class="text-center p-5 rounded-xl bg-success/10 border border-success/20">
            <p class="text-4xl font-black text-success tabular-nums">{{ savedCount }}</p>
            <p class="text-xs text-muted mt-1.5">Yeni Eklendi</p>
          </div>
          <div class="text-center p-5 rounded-xl bg-warning/10 border border-warning/20">
            <p class="text-4xl font-black text-warning tabular-nums">{{ updatedCount }}</p>
            <p class="text-xs text-muted mt-1.5">Güncellendi</p>
          </div>
          <div
            class="text-center p-5 rounded-xl border"
            :class="errorCount > 0 ? 'bg-error/10 border-error/20' : 'bg-neutral-100 border-transparent'"
          >
            <p class="text-4xl font-black tabular-nums" :class="errorCount > 0 ? 'text-error' : 'text-muted'">
              {{ errorCount }}
            </p>
            <p class="text-xs text-muted mt-1.5">Hata</p>
          </div>
        </div>

        <div
          v-if="processErrors.length > 0"
          class="w-full max-w-md max-h-40 overflow-y-auto border border-error/30 rounded-lg p-3 bg-error/5"
        >
          <p class="text-xs font-semibold text-error mb-2">Hata Detayları:</p>
          <ul class="text-xs space-y-1">
            <li v-for="(err, i) in processErrors" :key="i" class="text-error">{{ err }}</li>
          </ul>
        </div>

        <div class="flex gap-3">
          <UButton label="Yeni XML Yükle" icon="i-lucide-upload" size="xl" class="font-semibold" @click="resetState()" />
          <UButton label="Poliçelere Git" icon="i-lucide-arrow-right" color="neutral" variant="outline" size="xl" class="font-semibold" to="/policeler" />
        </div>
      </div>
    </UCard>

  </div>
</template>
