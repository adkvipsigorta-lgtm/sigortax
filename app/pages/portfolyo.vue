<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Portföyüm' })

const { get } = useApi()

const currentYear = ref(new Date().getFullYear())
const loading = ref(true)
const data = ref<any>(null)
const viewMode = ref<'all' | 'self' | 'incoming' | 'outgoing'>('all')
const premiumMode = ref<'gross' | 'net'>('gross')

const viewOptions = [
  { value: 'all',      label: 'Tümü' },
  { value: 'self',     label: 'Acentem' },
  { value: 'incoming', label: 'Tali Gelen' },
  { value: 'outgoing', label: 'Tali Giden' },
]

// Seçilen moda göre aylık prim değerini döndür
function prem(m: any, prefix: 'current' | 'prev') {
  if (premiumMode.value === 'net') {
    return prefix === 'current' ? (m.currentNetPremium ?? 0) : (m.prevNetPremium ?? 0)
  }
  return prefix === 'current' ? (m.currentPremium ?? 0) : (m.prevPremium ?? 0)
}

function premChange(m: any) {
  return premiumMode.value === 'net' ? (m.netPremiumChange ?? 0) : (m.premiumChange ?? 0)
}

function totalPrem(which: 'current' | 'prev') {
  if (!data.value) return 0
  const t = data.value.totals
  if (premiumMode.value === 'net') {
    return which === 'current' ? (t.currentNetPremium ?? 0) : (t.prevNetPremium ?? 0)
  }
  return which === 'current' ? (t.currentPremium ?? 0) : (t.prevPremium ?? 0)
}

function totalPremChange() {
  if (!data.value) return 0
  const t = data.value.totals
  return premiumMode.value === 'net' ? (t.netPremiumChange ?? 0) : (t.premiumChange ?? 0)
}

function productPrem(group: string, monthRow: any) {
  const key = premiumMode.value === 'net' ? group + '_net' : group
  return monthRow[key] ?? 0
}

function productPremTotal(group: string) {
  if (!data.value) return 0
  if (premiumMode.value === 'net') return data.value.productNetTotals?.[group] ?? 0
  return data.value.productTotals?.[group] ?? 0
}

async function fetchPortfolio() {
  loading.value = true
  try {
    const res = await get(`dashboard/portfolio?year=${currentYear.value}&view=${viewMode.value}`)
    data.value = res.data
  } catch {
    data.value = null
  } finally {
    loading.value = false
  }
}

watch([currentYear, viewMode], () => fetchPortfolio())
onMounted(fetchPortfolio)

function formatCurrency(val: number) {
  return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val)
}

function formatPercent(val: number) {
  const prefix = val > 0 ? '+' : ''
  return `${prefix}${val.toFixed(2)}%`
}

function changeBadgeClass(val: number) {
  if (val > 0) return 'text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900/50'
  if (val < 0) return 'text-red-600 dark:text-red-400 bg-red-100 dark:bg-red-900/50'
  return 'text-muted bg-gray-100 dark:bg-gray-800'
}

const yearOptions = computed(() => {
  const y = new Date().getFullYear()
  return Array.from({ length: 5 }, (_, i) => y - i)
})

// Urun renkleri - index bazli hex, Tailwind purge sorununu onler
const productHexColors = [
  '#3b82f6', // blue-500    - TSS
  '#06b6d4', // cyan-500    - ÖSS
  '#10b981', // emerald-500 - TRAFİK
  '#f97316', // orange-500  - KASKO
  '#f59e0b', // amber-500   - DASK
  '#8b5cf6', // violet-500  - KONUT
  '#f43f5e', // rose-500    - İŞYERİ
  '#9ca3af', // gray-400    - Diğer
]

function productColorHex(group: string) {
  if (!data.value?.productGroups) return '#9ca3af'
  const idx = data.value.productGroups.indexOf(group)
  return productHexColors[idx] ?? '#9ca3af'
}

function productShare(group: string) {
  if (!data.value) return '0'
  const totalsKey = premiumMode.value === 'net' ? 'productNetTotals' : 'productTotals'
  const totals = data.value[totalsKey] || data.value.productTotals
  const total = data.value.productGroups.reduce((s: number, g: string) => s + (totals[g] || 0), 0)
  if (total === 0) return '0'
  return ((totals[group] || 0) / total * 100).toFixed(1)
}

function productPolicyCount(group: string) {
  if (!data.value?.productCountTotals) return '0'
  return (data.value.productCountTotals[group] || 0).toLocaleString('tr-TR')
}

// Yonetici Analiz Yorumlari - dinamik
const portfolioAnalysis = computed(() => {
  if (!data.value) return null
  const months = data.value.months as any[]
  const t = data.value.totals

  // En iyi ve en kotu aylar
  const validMonths = months.filter((m: any) => m.currentPremium > 0 || m.prevPremium > 0)
  const bestMonth = validMonths.reduce((best: any, m: any) => m.currentPremium > (best?.currentPremium || 0) ? m : best, validMonths[0])
  const worstCountMonth = validMonths.reduce((worst: any, m: any) => m.countChange < (worst?.countChange ?? 999) ? m : worst, validMonths[0])

  // Dusus aylarini bul (police adeti azalan)
  const decliningMonths = validMonths.filter((m: any) => m.countChange < 0)
  // Yukselis aylarini bul
  const growingMonths = validMonths.filter((m: any) => m.premiumChange > 50)

  const insights: string[] = []

  // Genel performans
  if (t.premiumChange > 0) {
    insights.push(`${data.value.currentYear} yılının ilk ${months.length} ayında brüt prim üretimi, bir önceki yılın aynı dönemine göre <strong class="text-green-600">%${t.premiumChange.toFixed(1)}</strong> oranında artış göstermiştir.`)
  } else if (t.premiumChange < 0) {
    insights.push(`${data.value.currentYear} yılının ilk ${months.length} ayında brüt prim üretimi, bir önceki yılın aynı dönemine göre <strong class="text-red-600">%${Math.abs(t.premiumChange).toFixed(1)}</strong> oranında düşüş göstermiştir.`)
  }

  // Komisyon analizi
  if (t.currentCommission > 0) {
    const commRate = t.currentPremium > 0 ? (t.currentCommission / t.currentPremium * 100).toFixed(1) : '0'
    insights.push(`Toplam komisyon geliri <strong>${formatCurrency(t.currentCommission)}</strong> olup, ortalama komisyon oranı <strong>%${commRate}</strong>'tir.`)
    if (t.commissionChange > 0) {
      insights.push(`Komisyon gelirinde önceki yıla göre <strong class="text-green-600">%${t.commissionChange.toFixed(1)}</strong> artış sağlanmıştır.`)
    } else if (t.commissionChange < 0) {
      insights.push(`Komisyon gelirinde önceki yıla göre <strong class="text-red-600">%${Math.abs(t.commissionChange).toFixed(1)}</strong> düşüş yaşanmıştır.`)
    }
  }

  // En iyi ay
  if (bestMonth) {
    insights.push(`En yüksek prim üretimi <strong>${bestMonth.month}</strong> ayında <strong>${formatCurrency(bestMonth.currentPremium)}</strong> ile gerçekleşmiştir.`)
  }

  // Police adet degisimi
  if (t.countChange > 0) {
    insights.push(`Poliçe adedinde yıllık bazda <strong class="text-green-600">%${t.countChange.toFixed(1)}</strong> artış sağlanmıştır (${t.currentCount} vs ${t.prevCount}).`)
  } else if (t.countChange < 0) {
    insights.push(`Poliçe adedinde yıllık bazda <strong class="text-red-600">%${Math.abs(t.countChange).toFixed(1)}</strong> düşüş yaşanmıştır (${t.currentCount} vs ${t.prevCount}).`)
  }

  // Alarm: ciddi dususler
  if (decliningMonths.length > 0) {
    const alarms = decliningMonths
      .filter((m: any) => m.countChange < -20)
      .map((m: any) => `${m.month} (%${Math.abs(m.countChange).toFixed(1)})`)
    if (alarms.length > 0) {
      insights.push(`<span class="text-red-600">Dikkat:</span> Poliçe adedinde ciddi düşüş yaşanan aylar: <strong>${alarms.join(', ')}</strong>. Bu aylardaki müşteri kaybı ve yenileme oranları detaylı incelenmelidir.`)
    }
  }

  // Guclu buyume aylari
  if (growingMonths.length > 0) {
    const strong = growingMonths.map((m: any) => `${m.month} (+%${m.premiumChange.toFixed(0)})`).join(', ')
    insights.push(`Güçlü büyüme gösteren aylar: <strong class="text-green-600">${strong}</strong>.`)
  }

  // Tali Giden özel analiz
  if (viewMode.value === 'outgoing' && t.currentCommission > 0) {
    const branchComm = t.currentBranchCommission ?? 0
    const lostComm = t.lostCommission ?? (t.currentCommission - branchComm)
    const companies: any[] = data.value.outgoingByCompany ?? []

    insights.push(`<span class="text-amber-700 dark:text-amber-400">Tali Giden Nedir?</span> Poliçeyi siz kesiyorsunuz, ancak başka bir acentenin tali şubesi olarak üretiyorsunuz. Sigorta şirketinin ödediği tam komisyon önce ana acenteye gider; ana acente anlaşılan oranda size aktarır, kalanı kendinde tutar.`)

    if (branchComm > 0 && lostComm > 0) {
      const rate = Math.round(branchComm / t.currentCommission * 100)
      insights.push(`<span class="text-red-600">Komisyon Kaybı:</span> Sigorta şirketi toplam <strong>${formatCurrency(t.currentCommission)}</strong> komisyon ödedi. Sizin aldığınız pay <strong class="text-green-600">${formatCurrency(branchComm)}</strong> (%${rate}). Ana acenteye kalan: <strong class="text-red-600">${formatCurrency(lostComm)}</strong>. Tüm bu poliçeleri kendi adınıza kesseydiniz <strong class="text-red-600">${formatCurrency(lostComm)}</strong> daha fazla komisyon elde ederdiniz.`)
    }

    // Sirket bazli AI analiz
    if (companies.length > 0) {
      const selfPremium     = data.value.selfPremium     ?? 0
      const taliPremium     = data.value.outgoingPremium ?? 0
      const incomingCount   = data.value.incomingCount   ?? 0
      const totalPremium    = selfPremium + taliPremium

      // SELF vs OUTGOING prim karşılaştırması
      if (totalPremium > 0) {
        const taliPct = Math.round(taliPremium / totalPremium * 100)
        const selfPct = 100 - taliPct
        const incomingNote = incomingCount > 0 ? ` (Ayrıca size tali gelen: ${incomingCount} poliçe)` : ''
        const yorum = taliPct > 50
          ? 'Tali gönderilen prim kendi üretiminizi geçiyor — öncelikli şirketler için acentelik değerlendirin.'
          : taliPct > 30
          ? 'Tali prim oranı ciddi seviyede — kritik şirketler için acentelik başvurusu yapılabilir.'
          : 'Tali prim oranı makul, mevcut yapı dengeli görünüyor.'
        insights.push(`<span class="text-blue-700 dark:text-blue-400">Prim Dağılımı:</span> Kendi acenteliğiniz (Allianz): <strong class="text-green-600">${formatCurrency(selfPremium)} (%${selfPct})</strong> — Tali gönderilen: <strong class="text-amber-600">${formatCurrency(taliPremium)} (%${taliPct})</strong>${incomingNote}. ${yorum}`)
      }

      // AI öneri: trend + kayıp bazlı sıralama
      const sorted = [...companies].filter((c: any) => c.lost > 0).sort((a: any, b: any) => b.lost - a.lost)
      const topLoss = sorted[0]
      const secondLoss = sorted[1]
      const thirdLoss = sorted[2]

      if (topLoss) {
        const prevYear = data.value.prevYear

        // Her şirket için trend yorumu üret
        function trendText(c: any) {
          if (c.prevLost <= 0) return `(${prevYear}'de tali üretim yoktu, bu yıl başlandı)`
          const dir = c.lostChange > 10 ? `📈 geçen yıla göre <strong class="text-red-500">%${Math.abs(c.lostChange)} artış</strong>` :
                      c.lostChange < -10 ? `📉 geçen yıla göre <strong class="text-green-600">%${Math.abs(c.lostChange)} düşüş</strong>` :
                      `geçen yıla göre stabil`
          return `(${prevYear} kaybı: ${formatCurrency(c.prevLost)}, ${dir})`
        }

        const yearlyLoss = topLoss.lost
        const isGrowing = topLoss.lostChange > 10
        const isShrinking = topLoss.lostChange < -10

        let recommendation = ''
        if (yearlyLoss > 50000) {
          const extra = isGrowing ? ' Üstelik kayıp her yıl artıyor — ne kadar erken başvurursanız o kadar iyi.' :
                        isShrinking ? ' Kayıp azalıyor olsa da hâlâ yüksek; acentelik almak mantıklı.' : ''
          recommendation = `<strong>${topLoss.company}</strong> için doğrudan acentelik almak <span class="text-green-600">kesinlikle tavsiye edilir</span>. Bu yıl <strong class="text-red-600">${formatCurrency(yearlyLoss)}</strong> komisyon kaybı ${trendText(topLoss)}.${extra}`
        } else if (yearlyLoss > 15000) {
          const extra = isGrowing ? ' Kayıp büyüyor, erken harekete geçmek avantajlı olabilir.' :
                        isShrinking ? ' Kayıp düşüyor; trend devam ederse tali kalmak tercih edilebilir.' : ''
          recommendation = `<strong>${topLoss.company}</strong> için acentelik almak <span class="text-amber-600">değerlendirilebilir</span>. Bu yıl <strong>${formatCurrency(yearlyLoss)}</strong> kayıp ${trendText(topLoss)}.${extra}`
        } else {
          const extra = isGrowing ? ' Kayıp artış eğiliminde — önümüzdeki yıl tekrar değerlendirin.' : ''
          recommendation = `En yüksek kayıp <strong>${topLoss.company}</strong>'da — <strong>${formatCurrency(yearlyLoss)}</strong>/yıl ${trendText(topLoss)}. Şimdilik tali sürdürülebilir.${extra}`
        }
        insights.push(`<span class="text-violet-700 dark:text-violet-400">Acentelik Önceliği:</span> ${recommendation}`)

        // 2. ve 3. sıra — trend özetiyle
        const others = [secondLoss, thirdLoss].filter(Boolean).filter((c: any) => c.lost > 5000)
        if (others.length > 0) {
          const otherText = others.map((c: any) => {
            const trend = c.lostChange > 10 ? ' ↑' : c.lostChange < -10 ? ' ↓' : ''
            return `<strong>${c.company}</strong> ${formatCurrency(c.lost)}${trend}`
          }).join(' · ')
          insights.push(`<span class="text-muted">Diğer Takip Edilecekler:</span> ${otherText}`)
        }

        // En fazla is yapilan sirketler — brüt prime gore sirala
        const topByVolume = [...companies]
          .filter((c: any) => c.grossPremium > 0)
          .sort((a: any, b: any) => b.grossPremium - a.grossPremium)
          .slice(0, 5)
        if (topByVolume.length > 0) {
          const volText = topByVolume.map((c: any) => {
            const rateNote = c.avgBranchRate > 0
              ? ` <span class="${c.avgBranchRate < 60 ? 'text-red-500' : c.avgBranchRate < 70 ? 'text-amber-500' : 'text-green-600'}">(pay: %${c.avgBranchRate})</span>`
              : ''
            return `<strong>${c.company}</strong>${rateNote}`
          }).join(', ')
          insights.push(`<span class="text-blue-700 dark:text-blue-400">En Fazla İş Yapılan Şirketler (brüt prime göre):</span> ${volText}. ${topByVolume[0].avgBranchRate < 60 ? `<span class="text-red-500">${topByVolume[0].company} en yüksek hacimli şirket olmakla birlikte komisyon payınız %${topByVolume[0].avgBranchRate} — acentelik önceliğiniz olmalı.</span>` : ''}`)
        }
      }
    }
  }

  return insights
})

const productAnalysis = computed(() => {
  if (!data.value?.productGroups || !data.value?.productTotals) return null

  const groups = data.value.productGroups as string[]
  const totals = data.value.productTotals
  const countTotals = data.value.productCountTotals || {}
  const commTotals = data.value.productCommTotals || {}
  const grandTotal = groups.reduce((s: number, g: string) => s + (totals[g] || 0), 0)
  if (grandTotal === 0) return null

  const shares = groups.map((g: string) => ({
    name: g,
    premium: totals[g] || 0,
    count: countTotals[g] || 0,
    commission: commTotals[g] || 0,
    share: ((totals[g] || 0) / grandTotal * 100),
  })).sort((a, b) => b.share - a.share)

  const insights: string[] = []

  // Saglik brans grubu (TSS + OSS)
  const healthProducts = shares.filter(p => ['TSS', 'ÖSS'].some(h => p.name.includes(h)))
  const healthShare = healthProducts.reduce((s, p) => s + p.share, 0)
  if (healthProducts.length > 0 && healthShare > 40) {
    const detail = healthProducts.map(p => `${p.name} %${p.share.toFixed(1)}`).join(', ')
    insights.push(`Toplam üretimin <strong>%${healthShare.toFixed(0)}</strong>'${healthShare >= 70 ? 'ı' : 'i'} <strong>Sağlık</strong> branşında (${detail}) yoğunlaşmıştır. Bu durum portföy çeşitliliği açısından risk oluşturmaktadır.`)
  } else {
    const top = shares[0]
    insights.push(`En yüksek paya sahip branş <strong>${top.name}</strong> olup toplam üretimin <strong>%${top.share.toFixed(1)}</strong>'${top.share >= 10 ? 'ini' : 'ini'} oluşturmaktadır.`)
  }

  // En yuksek komisyon ureten urun
  const topCommProduct = [...shares].sort((a, b) => b.commission - a.commission)[0]
  if (topCommProduct && topCommProduct.commission > 0) {
    const commRate = topCommProduct.premium > 0 ? (topCommProduct.commission / topCommProduct.premium * 100).toFixed(1) : '0'
    insights.push(`En yüksek komisyon geliri <strong>${topCommProduct.name}</strong> branşından <strong>${formatCurrency(topCommProduct.commission)}</strong> (ort. %${commRate}) ile elde edilmiştir.`)
  }

  // Zayif urunler (%5 altinda)
  const weak = shares.filter(p => p.share < 5)
  if (weak.length > 0) {
    const weakNames = weak.map(p => `${p.name} (%${p.share.toFixed(1)})`).join(', ')
    insights.push(`Elementer branşlarda zayıf kalan ürünler: <strong>${weakNames}</strong>. Bu alanlarda çapraz satış kampanyaları ve müşteri bazlı teklif stratejileri uygulanmalıdır.`)
  }

  // En cok police ureten urun
  const topByCount = [...shares].sort((a, b) => b.count - a.count)[0]
  if (topByCount) {
    insights.push(`En fazla poliçe adedi <strong>${topByCount.name}</strong> branşında <strong>${topByCount.count.toLocaleString('tr-TR')}</strong> adet ile gerçekleşmiştir.`)
  }

  // Aylik urun trend analizi
  const productMonths = data.value.productMonths as any[]
  if (productMonths && productMonths.length >= 2) {
    for (const g of groups) {
      const monthValues = productMonths.map((m: any, idx: number) => ({ month: m.month, val: m[g] || 0, idx })).filter(m => m.val > 0)
      if (monthValues.length < 2) continue

      const best = monthValues.reduce((b, m) => m.val > b.val ? m : b, monthValues[0])
      const worst = monthValues.reduce((w, m) => m.val < w.val ? m : w, monthValues[0])

      if (best.val > worst.val * 2 && shares.find(s => s.name === g)!.share >= 5) {
        insights.push(`<strong>${g}</strong> branşında en yüksek üretim <strong>${best.month}</strong> ayında (${formatCurrency(best.val)}), en düşük üretim <strong>${worst.month}</strong> ayında (${formatCurrency(worst.val)}) gerçekleşmiştir.`)
      }
    }

    const lastMonth = productMonths[productMonths.length - 1]
    const lastMonthName = lastMonth.month
    for (const g of groups) {
      const avg = (totals[g] || 0) / productMonths.length
      const lastVal = lastMonth[g] || 0
      if (avg > 0 && lastVal > 0) {
        const diff = ((lastVal - avg) / avg) * 100
        if (diff < -30 && shares.find(s => s.name === g)!.share >= 5) {
          insights.push(`<span class="text-red-600">Uyarı:</span> <strong>${g}</strong> branşında ${lastMonthName} ayı üretimi (${formatCurrency(lastVal)}), dönem ortalamasının (${formatCurrency(avg)}) <strong class="text-red-600">%${Math.abs(diff).toFixed(0)}</strong> altındadır.`)
        }
      }
    }
  }

  // Capraz satis firsati
  const healthTotal = shares.filter(p => ['TSS', 'ÖSS'].some(h => p.name.includes(h))).reduce((s, p) => s + p.count, 0)
  const elementaryTotal = shares.filter(p => !['TSS', 'ÖSS'].some(h => p.name.includes(h))).reduce((s, p) => s + p.count, 0)
  if (healthTotal > 0 && elementaryTotal > 0 && healthTotal > elementaryTotal * 2) {
    insights.push(`Sağlık sigortası müşteri tabanı (${healthTotal.toLocaleString('tr-TR')} poliçe), elementer branşlara (${elementaryTotal.toLocaleString('tr-TR')} poliçe) oranla çok güçlü. Mevcut sağlık müşterilerine <strong>Kasko, Konut ve DASK</strong> çapraz satış fırsatı değerlendirilmelidir.`)
  }

  return insights
})

// ============================================
// TAHMIN MOTORU - Istatistiksel ML
// ============================================
const forecastRaw = ref<any>(null)
const forecastLoading = ref(false)
const selectedForecastMonth = ref(new Date().getMonth() + 2) // gelecek ay (1-indexed), varsayilan

const monthNames = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık']

// Mevcut yil icin tahmin yapilabilir mi
const canForecast = computed(() => {
  return currentYear.value >= new Date().getFullYear()
})

// Secilen ay gecmis mi (gerceklesen veri var mi)
const isPastMonth = computed(() => {
  if (currentYear.value < new Date().getFullYear()) return true
  if (currentYear.value > new Date().getFullYear()) return false
  return selectedForecastMonth.value <= new Date().getMonth() + 1
})

// Ay secenekleri: Ocak-Aralik, sadece mevcut yil icin
const forecastMonthOptions = computed(() => {
  return monthNames.map((name, i) => ({ value: i + 1, label: name }))
})

// Secilen ay icin tahmin hesapla (reactive)
const forecast = computed(() => {
  if (!forecastRaw.value) return null
  return computeForecast(forecastRaw.value, selectedForecastMonth.value)
})

// Gecmis ay ise gerceklesen veriyi al
const actualData = computed(() => {
  if (!isPastMonth.value || !data.value?.months) return null
  const monthRow = data.value.months[selectedForecastMonth.value - 1]
  if (!monthRow) return null

  // Urun bazli gerceklesen
  const productActuals: Record<string, any> = {}
  if (data.value.productMonths) {
    const pRow = data.value.productMonths[selectedForecastMonth.value - 1]
    if (pRow) {
      for (const g of (data.value.productGroups || [])) {
        productActuals[g] = {
          premium: pRow[g] || 0,
          count: pRow[g + '_count'] || 0,
          commission: pRow[g + '_comm'] || 0,
        }
      }
    }
  }

  return {
    premium: monthRow.currentPremium || 0,
    count: monthRow.currentCount || 0,
    commission: monthRow.currentCommission || 0,
    products: productActuals,
  }
})

// Dogruluk yuzdesi hesapla
function accuracy(predicted: number, actual: number) {
  if (actual === 0 && predicted === 0) return 100
  if (actual === 0) return 0
  const error = Math.abs(predicted - actual) / actual * 100
  return Math.max(0, Math.round(100 - error))
}

// Sapma yuzdesi (tahmin vs gerceklesen)
function deviation(predicted: number, actual: number) {
  if (actual === 0) return predicted > 0 ? 100 : 0
  return Math.round((predicted - actual) / actual * 100)
}

function deviationTitle(predicted: number, actual: number) {
  const dev = deviation(predicted, actual)
  const predStr = formatCurrency(predicted)
  const actStr = formatCurrency(actual)
  if (dev > 0) return `Tahmin gerçekleşenden %${dev} yüksek hesaplandı. Sistem fazla öngördü; gerçekleşen beklenenden düşük kaldı. (Tahmin: ${predStr} → Gerçekleşen: ${actStr})`
  if (dev < 0) return `Gerçekleşen tahmini %${Math.abs(dev)} oranında aştı. Beklenenden daha iyi bir performans sergilendi. (Tahmin: ${predStr} → Gerçekleşen: ${actStr})`
  return `Tahmin tam isabet! (${predStr})`
}

function accuracyTitle(predicted: number, actual: number) {
  const acc = accuracy(predicted, actual)
  const level = acc >= 80 ? 'Çok iyi' : acc >= 60 ? 'Kabul edilebilir' : 'Geliştirilmeli'
  const predStr = formatCurrency(predicted)
  const actStr = formatCurrency(actual)
  return `Doğruluk skoru %${acc} — ${level}. Tahmin (${predStr}) ile gerçekleşen (${actStr}) arasındaki sapmanın tersi. %80+ çok iyi, %60-80 kabul edilebilir, %60 altı zayıf.`
}

// Senaryo ozet cumlesi
const scenarioSummary = computed(() => {
  if (!forecast.value || !actualData.value) return null
  const f = forecast.value.general
  const a = actualData.value
  const month = forecast.value.monthName

  // Prim senaryosu
  let premiumScenario = ''
  let premiumColor = ''
  if (a.premium >= f.optimistic) {
    premiumScenario = `İyimser senaryoyu (${formatCurrency(f.optimistic)}) aşarak mükemmel bir performans sergilendi.`
    premiumColor = 'green'
  } else if (a.premium >= f.expected) {
    premiumScenario = `Beklenen değerin üzerinde, iyimser senaryoya yakın kapandı.`
    premiumColor = 'green'
  } else if (a.premium >= f.pessimistic) {
    premiumScenario = `Beklenenin altında kaldı; kötümser (${formatCurrency(f.pessimistic)}) ile beklenen (${formatCurrency(f.expected)}) arasında kapandı.`
    premiumColor = 'amber'
  } else {
    premiumScenario = `Kötümser senaryonun (${formatCurrency(f.pessimistic)}) da altında kapandı.`
    premiumColor = 'red'
  }

  // Komisyon senaryosu
  let commissionScenario = ''
  let commissionColor = ''
  if (a.commission >= f.commissionOptimistic) {
    commissionScenario = `İyimser senaryoyu (${formatCurrency(f.commissionOptimistic)}) aşarak beklentinin üzerinde komisyon geliri elde edildi.`
    commissionColor = 'green'
  } else if (a.commission >= f.commission) {
    commissionScenario = `Beklenen komisyon değerinin üzerinde kapandı.`
    commissionColor = 'green'
  } else if (a.commission >= f.commissionPessimistic) {
    commissionScenario = `Beklenenin altında kaldı; kötümser (${formatCurrency(f.commissionPessimistic)}) ile beklenen (${formatCurrency(f.commission)}) arasında kapandı.`
    commissionColor = 'amber'
  } else {
    commissionScenario = `Kötümser senaryonun (${formatCurrency(f.commissionPessimistic)}) da altında kapandı.`
    commissionColor = 'red'
  }

  return { month, premiumScenario, premiumColor, commissionScenario, commissionColor, f, a }
})

async function fetchForecast() {
  if (!canForecast.value) return
  forecastLoading.value = true
  try {
    const res = await get(`dashboard/portfolio-forecast?year=${currentYear.value}&view=${viewMode.value}`)
    forecastRaw.value = res.data
  } catch {
    forecastRaw.value = null
  } finally {
    forecastLoading.value = false
  }
}

watch([currentYear, viewMode], () => {
  forecastRaw.value = null
  selectedForecastMonth.value = new Date().getMonth() + 2 > 12 ? 12 : new Date().getMonth() + 2
  if (canForecast.value) fetchForecast()
})

onMounted(() => {
  if (selectedForecastMonth.value > 12) selectedForecastMonth.value = 12
  if (canForecast.value) fetchForecast()
})

function computeForecast(raw: any, targetMonth: number) {
  const history = raw.history as { year: number; month: number; premium: number; count: number; commission: number }[]
  const productHistory = raw.productHistory as { year: number; month: number; group: string; premium: number; count: number; commission: number }[]

  if (targetMonth < 1 || targetMonth > 12) return null
  if (history.length === 0) return null

  // Yillara gore grupla - secilen aydan ONCEKI verileri kullan (gelecek tahmini icin)
  // Eger gecmis ay ise, o yilin verisini haric tut (cunku zaten biliyoruz)
  const currentYr = new Date().getFullYear()
  const filteredHistory = history.filter(h => {
    // Mevcut yilin hedef ayi ve sonrasini haric tut (tahmin yaparken bilmememiz gereken veri)
    if (h.year === currentYr && h.month >= targetMonth) return false
    return true
  })

  const years = [...new Set(history.map(h => h.year))].sort()
  if (years.length < 2) return null

  // --- GENEL TAHMIN ---
  const generalForecast = forecastMonth(history, years, targetMonth)

  // --- URUN BAZLI TAHMIN ---
  const groups = raw.productGroups as string[]
  const productForecasts: Record<string, any> = {}

  for (const group of groups) {
    const groupData = productHistory.filter((p: any) => p.group === group)
    if (groupData.length === 0) {
      productForecasts[group] = { expected: 0, optimistic: 0, pessimistic: 0, count: 0, countOptimistic: 0, countPessimistic: 0, commission: 0, commissionOptimistic: 0, commissionPessimistic: 0, confidence: 0 }
      continue
    }
    const groupYears = [...new Set(groupData.map(d => d.year))].sort()
    productForecasts[group] = forecastMonth(groupData, groupYears, targetMonth)
  }

  return {
    month: targetMonth,
    monthName: monthNames[targetMonth - 1],
    general: generalForecast,
    products: productForecasts,
    productGroups: groups,
    yearsUsed: years,
  }
}

function forecastMonth(
  data: { year: number; month: number; premium: number; count: number; commission: number }[],
  years: number[],
  targetMonth: number
) {
  // 1. Mevsimsellik indeksi: bu ayin yillik ortalamaya orani
  const seasonalRatios: number[] = []
  const seasonalCountRatios: number[] = []
  const seasonalCommRatios: number[] = []

  for (const y of years) {
    const yearData = data.filter(d => d.year === y)
    const yearTotal = yearData.reduce((s, d) => s + d.premium, 0)
    const yearCount = yearData.reduce((s, d) => s + d.count, 0)
    const yearComm = yearData.reduce((s, d) => s + (d.commission || 0), 0)
    const monthsInYear = yearData.length

    if (monthsInYear === 0 || yearTotal === 0) continue

    const monthData = yearData.find(d => d.month === targetMonth)
    if (monthData) {
      const avgMonthly = yearTotal / monthsInYear
      seasonalRatios.push(monthData.premium / avgMonthly)
      if (yearCount > 0) {
        seasonalCountRatios.push(monthData.count / (yearCount / monthsInYear))
      }
      if (yearComm > 0) {
        seasonalCommRatios.push((monthData.commission || 0) / (yearComm / monthsInYear))
      }
    }
  }

  // 2. Yillik buyume trendi (lineer regresyon)
  const yearlyTotals = years.map(y => {
    const yd = data.filter(d => d.year === y)
    return { year: y, premium: yd.reduce((s, d) => s + d.premium, 0), count: yd.reduce((s, d) => s + d.count, 0), commission: yd.reduce((s, d) => s + (d.commission || 0), 0), months: yd.length }
  }).filter(y => y.months > 0)

  // Aylik ortalamaya normalize et (eksik aylar icin)
  const normalizedYearly = yearlyTotals.map(y => ({
    year: y.year,
    monthlyAvg: y.premium / y.months,
    monthlyCountAvg: y.count / y.months,
    monthlyCommAvg: y.commission / y.months,
  }))

  // Lineer regresyon: y = a + b*x
  const n = normalizedYearly.length
  if (n < 2) {
    return { expected: 0, optimistic: 0, pessimistic: 0, count: 0, countOptimistic: 0, countPessimistic: 0, commission: 0, commissionOptimistic: 0, commissionPessimistic: 0, confidence: 0 }
  }

  const xValues = normalizedYearly.map((_, i) => i)
  const yValues = normalizedYearly.map(d => d.monthlyAvg)
  const countValues = normalizedYearly.map(d => d.monthlyCountAvg)
  const commValues = normalizedYearly.map(d => d.monthlyCommAvg)

  const regPrem = linearRegression(xValues, yValues)
  const regCount = linearRegression(xValues, countValues)
  const regComm = linearRegression(xValues, commValues)

  // Gelecek yil icin projeksiyon (son indeks + 1 veya ayni yil devam)
  const currentYearIdx = normalizedYearly.findIndex(d => d.year === new Date().getFullYear())
  const projIdx = currentYearIdx >= 0 ? currentYearIdx : n

  const projectedMonthlyAvg = regPrem.predict(projIdx)
  const projectedMonthlyCount = regCount.predict(projIdx)
  const projectedMonthlyComm = regComm.predict(projIdx)

  // 3. Mevsimsellik uygula
  const seasonalIndex = seasonalRatios.length > 0
    ? seasonalRatios.reduce((s, r) => s + r, 0) / seasonalRatios.length
    : 1

  const seasonalCountIndex = seasonalCountRatios.length > 0
    ? seasonalCountRatios.reduce((s, r) => s + r, 0) / seasonalCountRatios.length
    : 1

  const seasonalCommIndex = seasonalCommRatios.length > 0
    ? seasonalCommRatios.reduce((s, r) => s + r, 0) / seasonalCommRatios.length
    : 1

  const expected = Math.max(0, projectedMonthlyAvg * seasonalIndex)
  const expectedCount = Math.max(0, Math.round(projectedMonthlyCount * seasonalCountIndex))
  const expectedComm = Math.max(0, projectedMonthlyComm * seasonalCommIndex)

  // 4. Guven araligi (gecmis verilerdeki varyans)
  const historicalValues = data.filter(d => d.month === targetMonth).map(d => d.premium)
  const historicalCounts = data.filter(d => d.month === targetMonth).map(d => d.count)
  const historicalComms = data.filter(d => d.month === targetMonth).map(d => d.commission || 0)
  let stdDev = 0
  let countStdDev = 0
  let commStdDev = 0
  if (historicalValues.length >= 2) {
    const mean = historicalValues.reduce((s, v) => s + v, 0) / historicalValues.length
    const variance = historicalValues.reduce((s, v) => s + (v - mean) ** 2, 0) / historicalValues.length
    stdDev = Math.sqrt(variance)
  }
  if (historicalCounts.length >= 2) {
    const countMean = historicalCounts.reduce((s, v) => s + v, 0) / historicalCounts.length
    const countVariance = historicalCounts.reduce((s, v) => s + (v - countMean) ** 2, 0) / historicalCounts.length
    countStdDev = Math.sqrt(countVariance)
  }
  if (historicalComms.length >= 2) {
    const commMean = historicalComms.reduce((s, v) => s + v, 0) / historicalComms.length
    const commVariance = historicalComms.reduce((s, v) => s + (v - commMean) ** 2, 0) / historicalComms.length
    commStdDev = Math.sqrt(commVariance)
  }

  // Guven skoru: veri noktasi ve tutarliliga gore 0-100
  const dataPoints = historicalValues.length
  const cv = expected > 0 ? stdDev / expected : 1 // coefficient of variation
  const confidence = Math.min(95, Math.max(20, Math.round((dataPoints / 5) * 100 * (1 - Math.min(cv, 1)))))

  return {
    expected: Math.round(expected * 100) / 100,
    optimistic: Math.round(Math.max(0, expected + stdDev * 0.67) * 100) / 100,
    pessimistic: Math.round(Math.max(0, expected - stdDev * 0.67) * 100) / 100,
    count: expectedCount,
    countOptimistic: Math.max(0, Math.round(expectedCount + countStdDev * 0.67)),
    countPessimistic: Math.max(0, Math.round(expectedCount - countStdDev * 0.67)),
    commission: Math.round(expectedComm * 100) / 100,
    commissionOptimistic: Math.round(Math.max(0, expectedComm + commStdDev * 0.67) * 100) / 100,
    commissionPessimistic: Math.round(Math.max(0, expectedComm - commStdDev * 0.67) * 100) / 100,
    confidence,
    seasonalIndex: Math.round(seasonalIndex * 100) / 100,
    dataPoints,
  }
}

function linearRegression(x: number[], y: number[]) {
  const n = x.length
  const sumX = x.reduce((s, v) => s + v, 0)
  const sumY = y.reduce((s, v) => s + v, 0)
  const sumXY = x.reduce((s, v, i) => s + v * y[i], 0)
  const sumX2 = x.reduce((s, v) => s + v * v, 0)

  const denom = n * sumX2 - sumX * sumX
  const b = denom !== 0 ? (n * sumXY - sumX * sumY) / denom : 0
  const a = (sumY - b * sumX) / n

  return {
    slope: b,
    intercept: a,
    predict: (xVal: number) => a + b * xVal,
  }
}
</script>

<template>
  <div class="space-y-4">
    <!-- KPI Kartlari + Loading -->
    <div v-if="loading">
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 gap-3 mb-4">
        <div v-for="i in 9" :key="i" class="h-20 bg-gray-200 dark:bg-gray-700 rounded-lg animate-pulse" />
      </div>
      <SkeletonTable :rows="12" :cols="7" />
    </div>

    <template v-else-if="data">
      <!-- KPI Kartlari -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 gap-3">
        <!-- Mevcut Yil Prim -->
        <UCard>
          <div class="flex items-center gap-3">
            <div class="size-10 rounded-lg bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center shrink-0">
              <UIcon name="i-lucide-wallet" class="text-blue-600 size-5" />
            </div>
            <div class="min-w-0">
              <p class="text-sm font-bold">{{ formatCurrency(totalPrem('current')) }}</p>
              <p class="text-xs text-muted">{{ data.currentYear }} {{ premiumMode === 'net' ? 'Net' : 'Brüt' }} Prim</p>
            </div>
          </div>
        </UCard>

        <!-- Onceki Yil Prim -->
        <UCard>
          <div class="flex items-center gap-3">
            <div class="size-10 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0">
              <UIcon name="i-lucide-wallet" class="text-gray-500 size-5" />
            </div>
            <div class="min-w-0">
              <p class="text-sm font-bold">{{ formatCurrency(totalPrem('prev')) }}</p>
              <p class="text-xs text-muted">{{ data.prevYear }} {{ premiumMode === 'net' ? 'Net' : 'Brüt' }} Prim</p>
            </div>
          </div>
        </UCard>

        <!-- Prim Degisim -->
        <UCard>
          <div class="flex items-center gap-3">
            <div
              class="size-10 rounded-lg flex items-center justify-center shrink-0"
              :class="totalPremChange() >= 0 ? 'bg-green-100 dark:bg-green-900/50' : 'bg-red-100 dark:bg-red-900/50'"
            >
              <UIcon
                :name="totalPremChange() >= 0 ? 'i-lucide-trending-up' : 'i-lucide-trending-down'"
                class="size-5"
                :class="totalPremChange() >= 0 ? 'text-green-600' : 'text-red-600'"
              />
            </div>
            <div class="min-w-0">
              <p class="text-sm font-bold" :class="totalPremChange() >= 0 ? 'text-green-600' : 'text-red-600'">
                {{ formatPercent(totalPremChange()) }}
              </p>
              <p class="text-xs text-muted">Prim Değişim</p>
            </div>
          </div>
        </UCard>

        <!-- Mevcut Yil Komisyon -->
        <UCard>
          <div class="flex items-center gap-3">
            <div class="size-10 rounded-lg bg-emerald-100 dark:bg-emerald-900/50 flex items-center justify-center shrink-0">
              <UIcon name="i-lucide-hand-coins" class="text-emerald-600 size-5" />
            </div>
            <div class="min-w-0">
              <p class="text-sm font-bold text-emerald-700 dark:text-emerald-400">{{ formatCurrency(data.totals.currentCommission) }}</p>
              <p class="text-xs text-muted">{{ data.currentYear }} Komisyon</p>
            </div>
          </div>
        </UCard>

        <!-- Onceki Yil Komisyon -->
        <UCard>
          <div class="flex items-center gap-3">
            <div class="size-10 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0">
              <UIcon name="i-lucide-hand-coins" class="text-gray-500 size-5" />
            </div>
            <div class="min-w-0">
              <p class="text-sm font-bold">{{ formatCurrency(data.totals.prevCommission) }}</p>
              <p class="text-xs text-muted">{{ data.prevYear }} Komisyon</p>
            </div>
          </div>
        </UCard>

        <!-- Komisyon Degisim -->
        <UCard>
          <div class="flex items-center gap-3">
            <div
              class="size-10 rounded-lg flex items-center justify-center shrink-0"
              :class="data.totals.commissionChange >= 0 ? 'bg-green-100 dark:bg-green-900/50' : 'bg-red-100 dark:bg-red-900/50'"
            >
              <UIcon
                :name="data.totals.commissionChange >= 0 ? 'i-lucide-trending-up' : 'i-lucide-trending-down'"
                class="size-5"
                :class="data.totals.commissionChange >= 0 ? 'text-green-600' : 'text-red-600'"
              />
            </div>
            <div class="min-w-0">
              <p class="text-sm font-bold" :class="data.totals.commissionChange >= 0 ? 'text-green-600' : 'text-red-600'">
                {{ formatPercent(data.totals.commissionChange) }}
              </p>
              <p class="text-xs text-muted">Komisyon Değişim</p>
            </div>
          </div>
        </UCard>

        <!-- Mevcut Yil Police -->
        <UCard>
          <div class="flex items-center gap-3">
            <div class="size-10 rounded-lg bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center shrink-0">
              <UIcon name="i-lucide-file-text" class="text-blue-600 size-5" />
            </div>
            <div class="min-w-0">
              <p class="text-2xl font-bold">{{ data.totals.currentCount.toLocaleString('tr-TR') }}</p>
              <p class="text-xs text-muted">{{ data.currentYear }} Poliçe</p>
            </div>
          </div>
        </UCard>

        <!-- Onceki Yil Police -->
        <UCard>
          <div class="flex items-center gap-3">
            <div class="size-10 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0">
              <UIcon name="i-lucide-file-text" class="text-gray-500 size-5" />
            </div>
            <div class="min-w-0">
              <p class="text-2xl font-bold">{{ data.totals.prevCount.toLocaleString('tr-TR') }}</p>
              <p class="text-xs text-muted">{{ data.prevYear }} Poliçe</p>
            </div>
          </div>
        </UCard>

        <!-- Police Degisim -->
        <UCard>
          <div class="flex items-center gap-3">
            <div
              class="size-10 rounded-lg flex items-center justify-center shrink-0"
              :class="data.totals.countChange >= 0 ? 'bg-green-100 dark:bg-green-900/50' : 'bg-red-100 dark:bg-red-900/50'"
            >
              <UIcon
                :name="data.totals.countChange >= 0 ? 'i-lucide-trending-up' : 'i-lucide-trending-down'"
                class="size-5"
                :class="data.totals.countChange >= 0 ? 'text-green-600' : 'text-red-600'"
              />
            </div>
            <div class="min-w-0">
              <p class="text-sm font-bold" :class="data.totals.countChange >= 0 ? 'text-green-600' : 'text-red-600'">
                {{ formatPercent(data.totals.countChange) }}
              </p>
              <p class="text-xs text-muted">Poliçe Değişim</p>
            </div>
          </div>
        </UCard>
      </div>

      <!-- Aylik Karsilastirma Tablosu -->
      <UCard>
        <template #header>
          <div class="flex flex-col gap-3">
            <div>
              <h3 >Portföyüm</h3>
              <p class="text-xs text-muted">Yıllık prim üretimi, komisyon geliri ve poliçe adet karşılaştırması</p>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
              <div class="flex rounded-md border border-default overflow-hidden text-xs font-semibold h-[30px]">
                <button
                  @click="premiumMode = 'gross'"
                  class="px-3 transition-colors"
                  :class="premiumMode === 'gross' ? 'bg-primary text-white' : 'text-muted hover:bg-gray-50 dark:hover:bg-gray-800'"
                >Brüt Prim</button>
                <button
                  @click="premiumMode = 'net'"
                  class="px-3 border-l border-default transition-colors"
                  :class="premiumMode === 'net' ? 'bg-primary text-white' : 'text-muted hover:bg-gray-50 dark:hover:bg-gray-800'"
                >Net Prim</button>
              </div>
              <USelect
                v-model="viewMode"
                :items="viewOptions"
                size="xs"
                :ui="{ base: 'h-[30px]' }"
                class="w-[180px]"
              />
              <USelect
                v-model="currentYear"
                :items="yearOptions.map(y => ({ label: String(y), value: y }))"
                size="xs"
                :ui="{ base: 'h-[30px]' }"
                class="w-[180px]"
              />
            </div>
          </div>
        </template>

        <div class="border border-default rounded-lg overflow-hidden">
          <table class="text-xs w-full table-fixed">
            <thead class="sticky top-0 z-10">
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted">Ay</th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">
                  <div class="flex items-center justify-end gap-1.5"><span class="size-2 rounded-full bg-blue-500" />{{ data.currentYear }} Prim</div>
                </th>
                <th class="hidden md:table-cell text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">
                  <div class="flex items-center justify-end gap-1.5"><span class="size-2 rounded-full bg-gray-400" />{{ data.prevYear }} Prim</div>
                </th>
                <th class="hidden md:table-cell text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">Değişim</th>
                <th class="hidden md:table-cell text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">
                  <div class="flex items-center justify-end gap-1.5"><span class="size-2 rounded-full bg-emerald-500" />{{ data.currentYear }} Komisyon</div>
                </th>
                <th class="hidden md:table-cell text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">
                  <div class="flex items-center justify-end gap-1.5"><span class="size-2 rounded-full bg-gray-400" />{{ data.prevYear }} Komisyon</div>
                </th>
                <th class="hidden md:table-cell text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">Değişim</th>
                <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">
                  <div class="flex items-center justify-center gap-1.5"><span class="size-2 rounded-full bg-blue-500" />{{ data.currentYear }} Poliçe</div>
                </th>
                <th class="hidden md:table-cell text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">
                  <div class="flex items-center justify-center gap-1.5"><span class="size-2 rounded-full bg-gray-400" />{{ data.prevYear }} Poliçe</div>
                </th>
                <th class="hidden md:table-cell text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">Değişim</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, i) in data.months"
                :key="i"
                class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors"
                :class="prem(row,'current') === 0 && prem(row,'prev') === 0 ? 'opacity-40' : ''"
              >
                <td class="py-2 px-3 font-medium">{{ row.month }}</td>
                <td class="py-2 px-3 text-right tabular-nums">{{ formatCurrency(prem(row,'current')) }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-right tabular-nums text-muted">{{ formatCurrency(prem(row,'prev')) }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-right">
                  <span
                    v-if="prem(row,'current') > 0 || prem(row,'prev') > 0"
                    class="flex items-center justify-center gap-1 w-28 h-6 rounded-full text-xs font-semibold tabular-nums ml-auto cursor-help"
                    :class="changeBadgeClass(premChange(row))"
                    :title="premChange(row) > 0 ? `${row.month} ayında prim geçen yıla göre %${Math.abs(premChange(row)).toFixed(1)} arttı. (${data.prevYear}: ${formatCurrency(prem(row,'prev'))} → ${data.currentYear}: ${formatCurrency(prem(row,'current'))})` : premChange(row) < 0 ? `${row.month} ayında prim geçen yıla göre %${Math.abs(premChange(row)).toFixed(1)} düştü. (${data.prevYear}: ${formatCurrency(prem(row,'prev'))} → ${data.currentYear}: ${formatCurrency(prem(row,'current'))})` : `${row.month} ayında geçen yıla göre değişim yok. (${formatCurrency(prem(row,'current'))})`"
                  >
                    <UIcon :name="premChange(row) > 0 ? 'i-lucide-arrow-up' : premChange(row) < 0 ? 'i-lucide-arrow-down' : 'i-lucide-minus'" class="size-3" />
                    {{ formatPercent(premChange(row)) }}
                  </span>
                </td>
                <td class="hidden md:table-cell py-2 px-3 text-right tabular-nums font-semibold text-emerald-700 dark:text-emerald-400">{{ formatCurrency(row.currentCommission) }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-right tabular-nums text-muted">{{ formatCurrency(row.prevCommission) }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-right">
                  <span
                    v-if="row.currentCommission > 0 || row.prevCommission > 0"
                    class="flex items-center justify-center gap-1 w-28 h-6 rounded-full text-xs font-semibold tabular-nums ml-auto cursor-help"
                    :class="changeBadgeClass(row.commissionChange)"
                    :title="row.commissionChange > 0 ? `${row.month} ayında komisyon geçen yıla göre %${Math.abs(row.commissionChange).toFixed(1)} arttı. (${data.prevYear}: ${formatCurrency(row.prevCommission)} → ${data.currentYear}: ${formatCurrency(row.currentCommission)})` : row.commissionChange < 0 ? `${row.month} ayında komisyon geçen yıla göre %${Math.abs(row.commissionChange).toFixed(1)} düştü. (${data.prevYear}: ${formatCurrency(row.prevCommission)} → ${data.currentYear}: ${formatCurrency(row.currentCommission)})` : `${row.month} ayında komisyon değişim yok. (${formatCurrency(row.currentCommission)})`"
                  >
                    <UIcon :name="row.commissionChange > 0 ? 'i-lucide-arrow-up' : row.commissionChange < 0 ? 'i-lucide-arrow-down' : 'i-lucide-minus'" class="size-3" />
                    {{ formatPercent(row.commissionChange) }}
                  </span>
                </td>
                <td class="py-2 px-3 text-center tabular-nums">{{ row.currentCount }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-center tabular-nums text-muted">{{ row.prevCount }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-right">
                  <span
                    v-if="row.currentCount > 0 || row.prevCount > 0"
                    class="flex items-center justify-center gap-1 w-28 h-6 rounded-full text-xs font-semibold tabular-nums ml-auto cursor-help"
                    :class="changeBadgeClass(row.countChange)"
                    :title="row.countChange > 0 ? `${row.month} ayında poliçe adedi geçen yıla göre %${Math.abs(row.countChange).toFixed(1)} arttı. (${data.prevYear}: ${row.prevCount} adet → ${data.currentYear}: ${row.currentCount} adet)` : row.countChange < 0 ? `${row.month} ayında poliçe adedi geçen yıla göre %${Math.abs(row.countChange).toFixed(1)} düştü. (${data.prevYear}: ${row.prevCount} adet → ${data.currentYear}: ${row.currentCount} adet)` : `${row.month} ayında poliçe adedi değişim yok. (${row.currentCount} adet)`"
                  >
                    <UIcon :name="row.countChange > 0 ? 'i-lucide-arrow-up' : row.countChange < 0 ? 'i-lucide-arrow-down' : 'i-lucide-minus'" class="size-3" />
                    {{ formatPercent(row.countChange) }}
                  </span>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="bg-primary/5 border-t-2 border-primary/30 font-bold">
                <td class="py-2 px-3 text-primary">Toplam</td>
                <td class="py-2 px-3 text-right tabular-nums text-primary">{{ formatCurrency(totalPrem('current')) }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-right tabular-nums text-primary/70">{{ formatCurrency(totalPrem('prev')) }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-right">
                  <span
                    class="flex items-center justify-center gap-1 w-28 h-6 rounded-full text-xs font-bold tabular-nums ml-auto cursor-help"
                    :class="changeBadgeClass(totalPremChange())"
                    :title="totalPremChange() > 0 ? `Yıllık toplam prim %${Math.abs(totalPremChange()).toFixed(1)} arttı. (${data.prevYear}: ${formatCurrency(totalPrem('prev'))} → ${data.currentYear}: ${formatCurrency(totalPrem('current'))})` : totalPremChange() < 0 ? `Yıllık toplam prim %${Math.abs(totalPremChange()).toFixed(1)} düştü. (${data.prevYear}: ${formatCurrency(totalPrem('prev'))} → ${data.currentYear}: ${formatCurrency(totalPrem('current'))})` : 'Yıllık toplam primde değişim yok.'"
                  >
                    <UIcon :name="totalPremChange() > 0 ? 'i-lucide-arrow-up' : totalPremChange() < 0 ? 'i-lucide-arrow-down' : 'i-lucide-minus'" class="size-3" />
                    {{ formatPercent(totalPremChange()) }}
                  </span>
                </td>
                <td class="hidden md:table-cell py-2 px-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400 font-bold">{{ formatCurrency(data.totals.currentCommission) }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-right tabular-nums text-primary/70">{{ formatCurrency(data.totals.prevCommission) }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-right">
                  <span
                    class="flex items-center justify-center gap-1 w-28 h-6 rounded-full text-xs font-bold tabular-nums ml-auto cursor-help"
                    :class="changeBadgeClass(data.totals.commissionChange)"
                    :title="data.totals.commissionChange > 0 ? `Yıllık toplam komisyon %${Math.abs(data.totals.commissionChange).toFixed(1)} arttı. (${data.prevYear}: ${formatCurrency(data.totals.prevCommission)} → ${data.currentYear}: ${formatCurrency(data.totals.currentCommission)})` : data.totals.commissionChange < 0 ? `Yıllık toplam komisyon %${Math.abs(data.totals.commissionChange).toFixed(1)} düştü. (${data.prevYear}: ${formatCurrency(data.totals.prevCommission)} → ${data.currentYear}: ${formatCurrency(data.totals.currentCommission)})` : 'Yıllık toplam komisyonda değişim yok.'"
                  >
                    <UIcon :name="data.totals.commissionChange > 0 ? 'i-lucide-arrow-up' : data.totals.commissionChange < 0 ? 'i-lucide-arrow-down' : 'i-lucide-minus'" class="size-3" />
                    {{ formatPercent(data.totals.commissionChange) }}
                  </span>
                </td>
                <td class="py-2 px-3 text-center tabular-nums text-primary">{{ data.totals.currentCount }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-center tabular-nums text-primary/70">{{ data.totals.prevCount }}</td>
                <td class="hidden md:table-cell py-2 px-3 text-right">
                  <span
                    class="flex items-center justify-center gap-1 w-28 h-6 rounded-full text-xs font-bold tabular-nums ml-auto cursor-help"
                    :class="changeBadgeClass(data.totals.countChange)"
                    :title="data.totals.countChange > 0 ? `Yıllık toplam poliçe adedi %${Math.abs(data.totals.countChange).toFixed(1)} arttı. (${data.prevYear}: ${data.totals.prevCount} adet → ${data.currentYear}: ${data.totals.currentCount} adet)` : data.totals.countChange < 0 ? `Yıllık toplam poliçe adedi %${Math.abs(data.totals.countChange).toFixed(1)} düştü. (${data.prevYear}: ${data.totals.prevCount} adet → ${data.currentYear}: ${data.totals.currentCount} adet)` : 'Yıllık toplam poliçe adedinde değişim yok.'"
                  >
                    <UIcon :name="data.totals.countChange > 0 ? 'i-lucide-arrow-up' : data.totals.countChange < 0 ? 'i-lucide-arrow-down' : 'i-lucide-minus'" class="size-3" />
                    {{ formatPercent(data.totals.countChange) }}
                  </span>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </UCard>

      <!-- Analiz Yorumu 1: Prim & Police -->
      <div v-if="portfolioAnalysis && portfolioAnalysis.length > 0" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
        <div class="flex items-start gap-3">
          <div class="size-8 rounded-lg bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center shrink-0 mt-0.5">
            <UIcon name="i-lucide-lightbulb" class="text-blue-600 size-4" />
          </div>
          <div>
            <p class="text-sm font-semibold text-blue-900 dark:text-blue-200 mb-2">Yönetici Analiz Özeti</p>
            <ul class="space-y-1.5">
              <li v-for="(insight, idx) in portfolioAnalysis" :key="idx" class="text-sm text-blue-800 dark:text-blue-300 flex items-start gap-2">
                <span class="text-blue-400 mt-1 shrink-0">&#8226;</span>
                <span v-html="insight" />
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Urun Bazli Aylik Uretim -->
      <UCard>
        <template #header>
          <div>
            <h3 >Ürün Bazında Aylık Üretim</h3>
            <p class="text-xs text-muted">{{ data.currentYear }} Brüt Prim</p>
          </div>
        </template>

        <!-- Mobilde yalnızca bilgi mesajı -->
        <div class="flex items-center gap-2 p-3 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 md:hidden mb-4">
          <UIcon name="i-lucide-monitor" class="size-4 text-blue-500 shrink-0" />
          <p class="text-xs text-blue-700 dark:text-blue-300">Bu tablo tablet veya masaüstünde görüntülenebilir.</p>
        </div>

        <div class="hidden md:block border border-default rounded-lg overflow-hidden">
          <table class="text-xs w-full table-fixed">
            <thead class="sticky top-0 z-10">
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted">Ay</th>
                <th
                  v-for="group in data.productGroups"
                  :key="group"
                  class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted"
                >
                  <div class="flex items-center justify-end gap-1.5">
                    <span class="size-2.5 rounded-full" :style="{ backgroundColor: productColorHex(group) }" />
                    {{ group }}
                  </div>
                </th>
                <th class="text-right py-2 px-3 text-xs font-semibold tracking-wide text-muted">TOPLAM</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, i) in data.productMonths"
                :key="i"
                class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors"
              >
                <td class="py-2 px-3 font-medium">{{ row.month }}</td>
                <td
                  v-for="group in data.productGroups"
                  :key="group"
                  class="py-2 px-3 text-right tabular-nums"
                  :class="[
                    productPrem(group, row) > 0 ? '' : 'text-muted opacity-40',
                    i === data.productMonths.length - 1 && productPrem(group, row) > 0 ? 'font-semibold' : ''
                  ]"
                >
                  {{ formatCurrency(productPrem(group, row)) }}
                  <div v-if="row[group + '_count']" class="text-[10px] text-muted font-normal">{{ row[group + '_count'] }} adet</div>
                  <div v-if="row[group + '_comm']" class="text-[10px] text-emerald-600 dark:text-emerald-400 font-normal">K: {{ formatCurrency(row[group + '_comm']) }}</div>
                </td>
                <td class="py-2 px-3 text-right tabular-nums" :class="i === data.productMonths.length - 1 ? 'font-bold' : 'font-medium'">
                  {{ formatCurrency(data.productGroups.reduce((sum: number, g: string) => sum + productPrem(g, row), 0)) }}
                  <div class="text-[10px] text-muted font-normal">{{ data.productGroups.reduce((sum: number, g: string) => sum + (row[g + '_count'] || 0), 0) }} adet</div>
                  <div class="text-[10px] text-emerald-600 dark:text-emerald-400 font-normal">K: {{ formatCurrency(data.productGroups.reduce((sum: number, g: string) => sum + (row[g + '_comm'] || 0), 0)) }}</div>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="bg-primary/5 border-t-2 border-primary/30 font-bold">
                <td class="py-2 px-3 text-primary">Toplam</td>
                <td
                  v-for="group in data.productGroups"
                  :key="group"
                  class="py-2 px-3 text-right tabular-nums text-primary"
                >
                  {{ formatCurrency(productPremTotal(group)) }}
                  <div class="text-[10px] text-primary/60">{{ (data.productCountTotals?.[group] || 0).toLocaleString('tr-TR') }} adet</div>
                  <div class="text-[10px] text-emerald-600 dark:text-emerald-400">K: {{ formatCurrency(data.productCommTotals?.[group] || 0) }}</div>
                </td>
                <td class="py-2 px-3 text-right tabular-nums text-primary font-extrabold">
                  {{ formatCurrency(data.productGroups.reduce((sum: number, g: string) => sum + productPremTotal(g), 0)) }}
                  <div class="text-[10px] text-primary/60">{{ data.productGroups.reduce((sum: number, g: string) => sum + (data.productCountTotals?.[g] || 0), 0).toLocaleString('tr-TR') }} adet</div>
                  <div class="text-[10px] text-emerald-600 dark:text-emerald-400">K: {{ formatCurrency(data.productGroups.reduce((sum: number, g: string) => sum + (data.productCommTotals?.[g] || 0), 0)) }}</div>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Prim Dagilimi Progress Barlar -->
        <div class="border-t border-default pt-5 px-1 pb-1 mt-1">
          <div class="flex items-center gap-2 mb-4 px-3">
            <UIcon name="i-lucide-pie-chart" class="size-4 text-primary" />
            <span class="text-sm">{{ currentYear }} Prim Dağılımı</span>
          </div>
          <div class="space-y-3 px-3">
            <div v-for="group in data.productGroups" :key="group" class="flex items-center gap-3">
              <div class="w-16 flex items-center gap-1.5 shrink-0">
                <span class="size-2.5 rounded-full shrink-0" :style="{ backgroundColor: productColorHex(group) }" />
                <span class="text-sm">{{ group }}</span>
              </div>

              <div class="flex-1 h-3 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                <div
                  class="h-full rounded-full transition-all duration-700"
                  :style="{ width: Math.max(Number(productShare(group)), 2) + '%', backgroundColor: productColorHex(group) }"
                />
              </div>

              <div class="flex items-center gap-3 shrink-0">
                <span class="text-sm font-bold tabular-nums w-32 text-right">
                  {{ formatCurrency(productPremTotal(group)) }}
                </span>
                <span class="text-xs font-semibold tabular-nums w-12 text-right text-muted">
                  %{{ productShare(group) }}
                </span>
                <span class="text-xs text-emerald-600 dark:text-emerald-400 tabular-nums w-24 text-right">
                  K: {{ formatCurrency(data.productCommTotals?.[group] || 0) }}
                </span>
                <span class="text-xs text-muted tabular-nums w-16 text-right">
                  {{ productPolicyCount(group) }} poliçe
                </span>
              </div>
            </div>
          </div>
        </div>
      </UCard>
      <!-- Analiz Yorumu 2: Urun Dagilimi -->
      <div v-if="productAnalysis && productAnalysis.length > 0" class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
        <div class="flex items-start gap-3">
          <div class="size-8 rounded-lg bg-amber-100 dark:bg-amber-900/50 flex items-center justify-center shrink-0 mt-0.5">
            <UIcon name="i-lucide-target" class="text-amber-600 size-4" />
          </div>
          <div>
            <p class="text-sm font-semibold text-amber-900 dark:text-amber-200 mb-2">Ürün Portföy Analizi</p>
            <ul class="space-y-1.5">
              <li v-for="(insight, idx) in productAnalysis" :key="idx" class="text-sm text-amber-800 dark:text-amber-300 flex items-start gap-2">
                <span class="text-amber-400 mt-1 shrink-0">&#8226;</span>
                <span v-html="insight" />
              </li>
            </ul>
          </div>
        </div>
      </div>
      <!-- TAHMIN BÖLÜMÜ -->
      <UCard v-if="canForecast">
        <template #header>
          <div class="flex items-center justify-between flex-wrap gap-2">
            <div>
              <h3 >Aylık Tahmin & Doğruluk Analizi</h3>
              <p class="text-xs text-muted">İstatistiksel tahmin ve gerçekleşen karşılaştırma</p>
            </div>
            <div class="flex items-center gap-2">
              <USelect
                v-model="selectedForecastMonth"
                :items="forecastMonthOptions"
                size="xs"
                :ui="{ base: 'h-[30px]' }"
                class="w-[180px]"
              />
              <span class="text-[10px] px-2 py-0.5 rounded-full"
                :class="isPastMonth
                  ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300'
                  : 'bg-violet-100 dark:bg-violet-900/50 text-violet-700 dark:text-violet-300'"
              >
                {{ isPastMonth ? 'Gerçekleşen Karşılaştırma' : 'İstatistiksel ML' }}
              </span>
            </div>
          </div>
        </template>

        <!-- Loading -->
        <div v-if="forecastLoading" class="flex items-center justify-center py-8">
          <div class="animate-spin rounded-full h-6 w-6 border-2 border-violet-500 border-t-transparent" />
          <span class="ml-3 text-sm text-muted">Tahmin hesaplanıyor...</span>
        </div>

        <!-- Tahmin Sonuçları -->
        <div v-else-if="forecast" class="space-y-5">

          <!-- GECMIS AY: Gerceklesen vs Tahmin Karsilastirmasi -->
          <div v-if="isPastMonth && actualData" class="space-y-5">
            <!-- Dogruluk Ozet Kartlari -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
              <!-- Gerçekleşen -->
              <div
                class="rounded-lg border-2 border-blue-300 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20 p-4 cursor-help"
                :title="`Bu ay gerçekte elde edilen toplam brüt prim tutarıdır. ${forecast.monthName} ayında toplam ${formatCurrency(actualData.premium)} prim üretilmiş, ${actualData.count} adet poliçe düzenlenmiş ve ${formatCurrency(actualData.commission)} komisyon geliri elde edilmiştir.`"
              >
                <div class="flex items-center gap-2 mb-2">
                  <UIcon name="i-lucide-check-circle-2" class="size-4 text-blue-600" />
                  <span class="text-xs font-semibold text-blue-700 dark:text-blue-400">Gerçekleşen</span>
                </div>
                <p class="text-xl font-bold text-blue-700 dark:text-blue-300 tabular-nums">{{ formatCurrency(actualData.premium) }}</p>
                <p class="text-xs text-blue-600/70 mt-1">{{ actualData.count }} poliçe</p>
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-0.5">K: {{ formatCurrency(actualData.commission) }}</p>
              </div>

              <!-- Tahmin Edilen (Beklenen) -->
              <div
                class="rounded-lg border border-violet-200 dark:border-violet-800 bg-violet-50 dark:bg-violet-900/20 p-4 cursor-help"
                :title="`Geçmiş ${forecast.yearsUsed.length} yılın verisi kullanılarak ${forecast.monthName} ayı için hesaplanan istatistiksel tahmindir. Lineer regresyon ile büyüme trendi ve mevsimsellik indeksi (${forecast.general.seasonalIndex}) baz alınarak ${formatCurrency(forecast.general.expected)} prim öngörülmüştür.`"
              >
                <div class="flex items-center gap-2 mb-2">
                  <UIcon name="i-lucide-target" class="size-4 text-violet-600" />
                  <span class="text-xs font-semibold text-violet-700 dark:text-violet-400">Tahmin (Beklenen)</span>
                </div>
                <p class="text-xl font-bold text-violet-700 dark:text-violet-300 tabular-nums">{{ formatCurrency(forecast.general.expected) }}</p>
                <p class="text-xs text-violet-600/70 mt-1">~{{ forecast.general.count }} poliçe</p>
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-0.5">K: {{ formatCurrency(forecast.general.commission) }}</p>
              </div>

              <!-- Sapma -->
              <div
                class="rounded-lg border border-default bg-gray-50 dark:bg-gray-800/50 p-4 cursor-help"
                :title="deviationTitle(forecast.general.expected, actualData.premium)"
              >
                <div class="flex items-center gap-2 mb-2">
                  <UIcon name="i-lucide-git-compare-arrows" class="size-4 text-gray-500" />
                  <span class="text-xs font-semibold tracking-wide text-muted">Sapma</span>
                </div>
                <p class="text-xl font-bold tabular-nums" :class="Math.abs(deviation(forecast.general.expected, actualData.premium)) <= 10 ? 'text-green-600' : Math.abs(deviation(forecast.general.expected, actualData.premium)) <= 25 ? 'text-amber-600' : 'text-red-600'">
                  {{ deviation(forecast.general.expected, actualData.premium) > 0 ? '+' : '' }}{{ deviation(forecast.general.expected, actualData.premium) }}%
                </p>
                <p class="text-xs text-muted mt-1">
                  {{ forecast.general.expected > actualData.premium ? 'Tahmin yüksek kaldı' : forecast.general.expected < actualData.premium ? 'Tahmin düşük kaldı' : 'Tam isabet' }}
                </p>
              </div>

              <!-- Doğruluk Skoru -->
              <div class="rounded-lg border p-4 cursor-help"
                :class="accuracy(forecast.general.expected, actualData.premium) >= 80
                  ? 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20'
                  : accuracy(forecast.general.expected, actualData.premium) >= 60
                    ? 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20'
                    : 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20'"
                :title="accuracyTitle(forecast.general.expected, actualData.premium)"
              >
                <div class="flex items-center gap-2 mb-2">
                  <UIcon name="i-lucide-gauge" class="size-4"
                    :class="accuracy(forecast.general.expected, actualData.premium) >= 80 ? 'text-green-600' : accuracy(forecast.general.expected, actualData.premium) >= 60 ? 'text-amber-600' : 'text-red-600'"
                  />
                  <span class="text-xs"
                    :class="accuracy(forecast.general.expected, actualData.premium) >= 80 ? 'text-green-700 dark:text-green-400' : accuracy(forecast.general.expected, actualData.premium) >= 60 ? 'text-amber-700 dark:text-amber-400' : 'text-red-700 dark:text-red-400'"
                  >Doğruluk</span>
                </div>
                <p class="text-3xl font-bold tabular-nums"
                  :class="accuracy(forecast.general.expected, actualData.premium) >= 80 ? 'text-green-700 dark:text-green-300' : accuracy(forecast.general.expected, actualData.premium) >= 60 ? 'text-amber-700 dark:text-amber-300' : 'text-red-700 dark:text-red-300'"
                >
                  %{{ accuracy(forecast.general.expected, actualData.premium) }}
                </p>
                <div class="w-full h-2 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden mt-2">
                  <div class="h-full rounded-full transition-all duration-700"
                    :class="accuracy(forecast.general.expected, actualData.premium) >= 80 ? 'bg-green-500' : accuracy(forecast.general.expected, actualData.premium) >= 60 ? 'bg-amber-500' : 'bg-red-500'"
                    :style="{ width: accuracy(forecast.general.expected, actualData.premium) + '%' }"
                  />
                </div>
              </div>
            </div>

            <!-- Senaryo Ozet Metni -->
            <div v-if="scenarioSummary" class="space-y-3">
              <!-- Prim -->
              <div class="rounded-lg border p-4"
                :class="scenarioSummary.premiumColor === 'green' ? 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20'
                  : scenarioSummary.premiumColor === 'amber' ? 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20'
                  : 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20'"
              >
                <div class="flex items-center gap-2 mb-2">
                  <UIcon name="i-lucide-banknote" class="size-4"
                    :class="scenarioSummary.premiumColor === 'green' ? 'text-green-600' : scenarioSummary.premiumColor === 'amber' ? 'text-amber-600' : 'text-red-600'"
                  />
                  <span class="text-xs"
                    :class="scenarioSummary.premiumColor === 'green' ? 'text-green-700 dark:text-green-400' : scenarioSummary.premiumColor === 'amber' ? 'text-amber-700 dark:text-amber-400' : 'text-red-700 dark:text-red-400'"
                  >Prim Değerlendirmesi</span>
                </div>
                <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                  <strong>{{ scenarioSummary.month }}</strong> ayında beklenen prim
                  <strong>{{ formatCurrency(scenarioSummary.f.expected) }}</strong> iken
                  <strong>{{ formatCurrency(scenarioSummary.a.premium) }}</strong> olarak gerçekleşti.
                  {{ scenarioSummary.premiumScenario }}
                </p>
                <p class="text-xs text-muted mt-1.5">
                  Senaryo aralığı: {{ formatCurrency(scenarioSummary.f.pessimistic) }} (kötümser) — {{ formatCurrency(scenarioSummary.f.optimistic) }} (iyimser)
                </p>
              </div>
              <!-- Komisyon -->
              <div class="rounded-lg border p-4"
                :class="scenarioSummary.commissionColor === 'green' ? 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20'
                  : scenarioSummary.commissionColor === 'amber' ? 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20'
                  : 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20'"
              >
                <div class="flex items-center gap-2 mb-2">
                  <UIcon name="i-lucide-wallet" class="size-4"
                    :class="scenarioSummary.commissionColor === 'green' ? 'text-green-600' : scenarioSummary.commissionColor === 'amber' ? 'text-amber-600' : 'text-red-600'"
                  />
                  <span class="text-xs"
                    :class="scenarioSummary.commissionColor === 'green' ? 'text-green-700 dark:text-green-400' : scenarioSummary.commissionColor === 'amber' ? 'text-amber-700 dark:text-amber-400' : 'text-red-700 dark:text-red-400'"
                  >Komisyon Değerlendirmesi</span>
                </div>
                <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                  <strong>{{ scenarioSummary.month }}</strong> ayında beklenen komisyon
                  <strong>{{ formatCurrency(scenarioSummary.f.commission) }}</strong> iken
                  <strong>{{ formatCurrency(scenarioSummary.a.commission) }}</strong> olarak gerçekleşti.
                  {{ scenarioSummary.commissionScenario }}
                </p>
                <p class="text-xs text-muted mt-1.5">
                  Senaryo aralığı: {{ formatCurrency(scenarioSummary.f.commissionPessimistic) }} (kötümser) — {{ formatCurrency(scenarioSummary.f.commissionOptimistic) }} (iyimser) · Doğruluk: %{{ accuracy(scenarioSummary.f.commission, scenarioSummary.a.commission) }}
                </p>
              </div>
            </div>

            <!-- Urun Bazli Gerceklesen vs Tahmin Tablosu -->
            <div class="border-t border-default pt-4">
              <div class="flex items-center gap-2 mb-3">
                <UIcon name="i-lucide-layers" class="size-4 text-blue-600" />
                <span class="text-xs">{{ forecast.monthName }} - Ürün Bazlı Karşılaştırma</span>
              </div>
              <div class="border border-default rounded-lg overflow-hidden">
                <table class="text-xs w-full table-fixed">
                  <thead class="sticky top-0 z-10">
                    <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                      <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted">Ürün</th>
                      <th class="text-right py-2 px-3 text-xs font-semibold text-blue-600">Gerçekleşen</th>
                      <th class="text-right py-2 px-3 text-xs font-semibold text-violet-600">Tahmin</th>
                      <th class="hidden sm:table-cell text-right py-2 px-3 text-xs font-semibold text-emerald-600">Gerç. Kom.</th>
                      <th class="hidden sm:table-cell text-right py-2 px-3 text-xs font-semibold text-emerald-500">Tahm. Kom.</th>
                      <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Sapma</th>
                      <th class="hidden md:table-cell text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Doğruluk</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="group in forecast.productGroups"
                      :key="group"
                      class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/30"
                    >
                      <td class="py-2 px-3 font-medium">
                        <div class="flex items-center gap-1.5">
                          <span class="size-2.5 rounded-full" :style="{ backgroundColor: productColorHex(group) }" />
                          {{ group }}
                        </div>
                      </td>
                      <td class="py-2 px-3 text-right tabular-nums font-semibold text-blue-700 dark:text-blue-300">
                        {{ formatCurrency(actualData.products[group]?.premium || 0) }}
                        <div class="text-[10px] text-muted font-normal">{{ actualData.products[group]?.count || 0 }} adet</div>
                      </td>
                      <td class="py-2 px-3 text-right tabular-nums text-violet-700 dark:text-violet-300">
                        {{ formatCurrency(forecast.products[group]?.expected || 0) }}
                        <div class="text-[10px] text-muted font-normal">~{{ forecast.products[group]?.count || 0 }} adet</div>
                      </td>
                      <td class="hidden sm:table-cell py-2 px-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400">
                        {{ formatCurrency(actualData.products[group]?.commission || 0) }}
                      </td>
                      <td class="hidden sm:table-cell py-2 px-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                        {{ formatCurrency(forecast.products[group]?.commission || 0) }}
                      </td>
                      <td class="py-2 px-3 text-center"
                        :title="deviationTitle(forecast.products[group]?.expected || 0, actualData.products[group]?.premium || 0)"
                      >
                        <span class="text-xs font-bold tabular-nums cursor-help"
                          :class="Math.abs(deviation(forecast.products[group]?.expected || 0, actualData.products[group]?.premium || 0)) <= 10 ? 'text-green-600' : Math.abs(deviation(forecast.products[group]?.expected || 0, actualData.products[group]?.premium || 0)) <= 25 ? 'text-amber-600' : 'text-red-600'"
                        >
                          {{ deviation(forecast.products[group]?.expected || 0, actualData.products[group]?.premium || 0) > 0 ? '+' : '' }}{{ deviation(forecast.products[group]?.expected || 0, actualData.products[group]?.premium || 0) }}%
                        </span>
                      </td>
                      <td class="hidden md:table-cell py-2 px-3 text-center"
                        :title="accuracyTitle(forecast.products[group]?.expected || 0, actualData.products[group]?.premium || 0)"
                      >
                        <span
                          class="text-xs font-bold px-2 py-0.5 rounded-full cursor-help"
                          :class="accuracy(forecast.products[group]?.expected || 0, actualData.products[group]?.premium || 0) >= 80
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400'
                            : accuracy(forecast.products[group]?.expected || 0, actualData.products[group]?.premium || 0) >= 60
                              ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400'
                              : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400'"
                        >
                          %{{ accuracy(forecast.products[group]?.expected || 0, actualData.products[group]?.premium || 0) }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot>
                    <tr class="bg-blue-50 dark:bg-blue-900/20 border-t-2 border-blue-200 dark:border-blue-800 font-bold">
                      <td class="py-2 px-3 text-blue-700 dark:text-blue-300">Toplam</td>
                      <td class="py-2 px-3 text-right tabular-nums text-blue-700 dark:text-blue-300">{{ formatCurrency(actualData.premium) }}</td>
                      <td class="py-2 px-3 text-right tabular-nums text-violet-700 dark:text-violet-300">{{ formatCurrency(forecast.general.expected) }}</td>
                      <td class="hidden sm:table-cell py-2 px-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ formatCurrency(actualData.commission) }}</td>
                      <td class="hidden sm:table-cell py-2 px-3 text-right tabular-nums text-emerald-600">{{ formatCurrency(forecast.general.commission) }}</td>
                      <td class="py-2 px-3 text-center"
                        :title="deviationTitle(forecast.general.expected, actualData.premium)"
                      >
                        <span class="text-xs font-bold tabular-nums cursor-help"
                          :class="Math.abs(deviation(forecast.general.expected, actualData.premium)) <= 10 ? 'text-green-600' : Math.abs(deviation(forecast.general.expected, actualData.premium)) <= 25 ? 'text-amber-600' : 'text-red-600'"
                        >
                          {{ deviation(forecast.general.expected, actualData.premium) > 0 ? '+' : '' }}{{ deviation(forecast.general.expected, actualData.premium) }}%
                        </span>
                      </td>
                      <td class="hidden md:table-cell py-2 px-3 text-center"
                        :title="accuracyTitle(forecast.general.expected, actualData.premium)"
                      >
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full cursor-help"
                          :class="accuracy(forecast.general.expected, actualData.premium) >= 80
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400'
                            : accuracy(forecast.general.expected, actualData.premium) >= 60
                              ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400'
                              : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400'"
                        >
                          %{{ accuracy(forecast.general.expected, actualData.premium) }}
                        </span>
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </div>

          <!-- GELECEK AY: Standart Tahmin Gosterimi -->
          <template v-else>
            <!-- 3 Senaryo Kartları -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <!-- Kötümser -->
              <div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4">
                <div class="flex items-center gap-2 mb-2">
                  <UIcon name="i-lucide-arrow-down-circle" class="size-4 text-red-500" />
                  <span class="text-xs font-semibold text-red-700 dark:text-red-400">Kötümser Senaryo</span>
                </div>
                <p class="text-lg font-bold text-red-700 dark:text-red-300 tabular-nums">{{ formatCurrency(forecast.general.pessimistic) }}</p>
                <p class="text-xs text-red-600/70 dark:text-red-400/70 mt-1">~{{ forecast.general.countPessimistic }} poliçe</p>
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-0.5">K: {{ formatCurrency(forecast.general.commissionPessimistic) }}</p>
              </div>

              <!-- Beklenen -->
              <div class="rounded-lg border-2 border-violet-300 dark:border-violet-700 bg-violet-50 dark:bg-violet-900/20 p-4 ring-2 ring-violet-200/50 dark:ring-violet-800/50">
                <div class="flex items-center gap-2 mb-2">
                  <UIcon name="i-lucide-target" class="size-4 text-violet-600" />
                  <span class="text-xs font-semibold text-violet-700 dark:text-violet-400">Beklenen Senaryo</span>
                </div>
                <p class="text-2xl font-bold text-violet-700 dark:text-violet-300 tabular-nums">{{ formatCurrency(forecast.general.expected) }}</p>
                <p class="text-xs text-violet-600/70 dark:text-violet-400/70 mt-1">~{{ forecast.general.count }} poliçe</p>
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-0.5">K: {{ formatCurrency(forecast.general.commission) }}</p>
              </div>

              <!-- İyimser -->
              <div class="rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 p-4">
                <div class="flex items-center gap-2 mb-2">
                  <UIcon name="i-lucide-arrow-up-circle" class="size-4 text-green-500" />
                  <span class="text-xs font-semibold text-green-700 dark:text-green-400">İyimser Senaryo</span>
                </div>
                <p class="text-lg font-bold text-green-700 dark:text-green-300 tabular-nums">{{ formatCurrency(forecast.general.optimistic) }}</p>
                <p class="text-xs text-green-600/70 dark:text-green-400/70 mt-1">~{{ forecast.general.countOptimistic }} poliçe</p>
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-0.5">K: {{ formatCurrency(forecast.general.commissionOptimistic) }}</p>
              </div>
            </div>

            <!-- Güven Skoru & Bilgi -->
            <div class="flex items-center gap-4 px-1">
              <UTooltip
                :text="`Tahminin güvenilirliğini gösterir (0-100). Veri sayısı ve geçmiş yıllardaki tutarlılığa göre hesaplanır. %70+ yeşil (yüksek güven), %40-70 sarı (orta güven), %40 altı kırmızı (düşük güven). Mevcut skor: %${forecast.general.confidence}`"
                :delay-duration="200"
              >
                <div class="flex items-center gap-2 cursor-help">
                  <span class="text-xs text-muted">Güven Skoru:</span>
                  <div class="w-24 h-2 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                    <div
                      class="h-full rounded-full transition-all duration-700"
                      :class="forecast.general.confidence >= 70 ? 'bg-green-500' : forecast.general.confidence >= 40 ? 'bg-amber-500' : 'bg-red-500'"
                      :style="{ width: forecast.general.confidence + '%' }"
                    />
                  </div>
                  <span class="text-xs font-bold" :class="forecast.general.confidence >= 70 ? 'text-green-600' : forecast.general.confidence >= 40 ? 'text-amber-600' : 'text-red-600'">
                    %{{ forecast.general.confidence }}
                  </span>
                </div>
              </UTooltip>
              <div class="flex items-center gap-2 text-[10px] text-muted">
                <UTooltip text="Tahmin hesaplanırken kullanılan geçmiş yıl sayısı. Daha fazla yıl = daha güvenilir tahmin." :delay-duration="200">
                  <span class="cursor-help underline decoration-dotted">{{ forecast.yearsUsed.length }} yıl verisi</span>
                </UTooltip>
                <span>&middot;</span>
                <UTooltip :text="`Bu ayın yıllık aylık ortalamaya oranı. 1.0 = ortalama ay, 1.3 = yoğun sezon (%30 fazla), 0.7 = düşük sezon (%30 az). Mevcut değer ${forecast.general.seasonalIndex} → bu ay yıllık ortalamanın %${Math.round(forecast.general.seasonalIndex * 100)}'i kadar üretim beklenir.`" :delay-duration="200">
                  <span class="cursor-help underline decoration-dotted">Mevsimsellik indeksi: {{ forecast.general.seasonalIndex }}</span>
                </UTooltip>
                <span>&middot;</span>
                <UTooltip :text="`Bu ay için elimizdeki gerçek geçmiş kayıt sayısı. ${forecast.general.dataPoints} veri noktası = ${forecast.general.dataPoints} yılda bu ayda üretim kaydı var. Sayı azaldıkça güven skoru düşer.`" :delay-duration="200">
                  <span class="cursor-help underline decoration-dotted">{{ forecast.general.dataPoints }} veri noktası</span>
                </UTooltip>
              </div>
            </div>

            <!-- Ürün Bazlı Tahmin Tablosu -->
            <div class="border-t border-default pt-4">
              <div class="flex items-center gap-2 mb-3">
                <UIcon name="i-lucide-layers" class="size-4 text-violet-600" />
                <span class="text-xs">{{ forecast.monthName }} - Ürün Bazlı Tahmin</span>
              </div>
              <div class="border border-default rounded-lg overflow-hidden">
                <table class="text-xs w-full table-fixed">
                  <thead class="sticky top-0 z-10">
                    <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-default">
                      <th class="text-left py-2 px-3 text-xs font-semibold tracking-wide text-muted">Ürün</th>
                      <th class="hidden sm:table-cell text-right py-2 px-3 text-xs font-semibold text-red-500">Kötümser</th>
                      <th class="text-right py-2 px-3 text-xs font-semibold text-violet-600">Beklenen</th>
                      <th class="hidden sm:table-cell text-right py-2 px-3 text-xs font-semibold text-green-500">İyimser</th>
                      <th class="hidden md:table-cell text-right py-2 px-3 text-xs font-semibold text-emerald-600">Komisyon</th>
                      <th class="hidden md:table-cell text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Adet</th>
                      <th class="text-center py-2 px-3 text-xs font-semibold tracking-wide text-muted">Güven</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="group in forecast.productGroups"
                      :key="group"
                      class="border-b border-default hover:bg-gray-50 dark:hover:bg-gray-800/30"
                    >
                      <td class="py-2 px-3 font-medium">
                        <div class="flex items-center gap-1.5">
                          <span class="size-2.5 rounded-full" :style="{ backgroundColor: productColorHex(group) }" />
                          {{ group }}
                        </div>
                      </td>
                      <td class="hidden sm:table-cell py-2 px-3 text-right tabular-nums text-red-600 dark:text-red-400">{{ formatCurrency(forecast.products[group]?.pessimistic || 0) }}</td>
                      <td class="py-2 px-3 text-right tabular-nums font-semibold text-violet-700 dark:text-violet-300">{{ formatCurrency(forecast.products[group]?.expected || 0) }}</td>
                      <td class="hidden sm:table-cell py-2 px-3 text-right tabular-nums text-green-600 dark:text-green-400">{{ formatCurrency(forecast.products[group]?.optimistic || 0) }}</td>
                      <td class="hidden md:table-cell py-2 px-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatCurrency(forecast.products[group]?.commission || 0) }}</td>
                      <td class="hidden md:table-cell py-2 px-3 text-center tabular-nums">{{ forecast.products[group]?.count || 0 }}</td>
                      <td class="py-2 px-3 text-center">
                        <span
                          class="text-xs font-bold px-2 py-0.5 rounded-full"
                          :class="(forecast.products[group]?.confidence || 0) >= 70
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400'
                            : (forecast.products[group]?.confidence || 0) >= 40
                              ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400'
                              : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400'"
                        >
                          %{{ forecast.products[group]?.confidence || 0 }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot>
                    <tr class="bg-violet-50 dark:bg-violet-900/20 border-t-2 border-violet-200 dark:border-violet-800 font-bold">
                      <td class="py-2 px-3 text-violet-700 dark:text-violet-300">Toplam</td>
                      <td class="hidden sm:table-cell py-2 px-3 text-right tabular-nums text-red-600">
                        {{ formatCurrency(forecast.productGroups.reduce((s: number, g: string) => s + (forecast.products[g]?.pessimistic || 0), 0)) }}
                      </td>
                      <td class="py-2 px-3 text-right tabular-nums text-violet-700 dark:text-violet-300">
                        {{ formatCurrency(forecast.productGroups.reduce((s: number, g: string) => s + (forecast.products[g]?.expected || 0), 0)) }}
                      </td>
                      <td class="hidden sm:table-cell py-2 px-3 text-right tabular-nums text-green-600">
                        {{ formatCurrency(forecast.productGroups.reduce((s: number, g: string) => s + (forecast.products[g]?.optimistic || 0), 0)) }}
                      </td>
                      <td class="hidden md:table-cell py-2 px-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                        {{ formatCurrency(forecast.productGroups.reduce((s: number, g: string) => s + (forecast.products[g]?.commission || 0), 0)) }}
                      </td>
                      <td class="hidden md:table-cell py-2 px-3 text-center tabular-nums">
                        {{ forecast.productGroups.reduce((s: number, g: string) => s + (forecast.products[g]?.count || 0), 0) }}
                      </td>
                      <td class="py-2 px-3 text-center">
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-violet-100 text-violet-700 dark:bg-violet-900/50 dark:text-violet-300">
                          %{{ forecast.general.confidence }}
                        </span>
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </template>

          <!-- Metodoloji Notu -->
          <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-3 mt-1">
            <p class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">
              <strong>Metodoloji:</strong> Son {{ forecast.yearsUsed.length }} yılın ({{ forecast.yearsUsed.join(', ') }}) aylık verileri kullanılarak
              lineer regresyon ile büyüme trendi ve mevsimsellik indeksi hesaplanmıştır.
              <template v-if="isPastMonth">
                Doğruluk skoru, tahmin edilen değerin gerçekleşen değere yakınlığını yüzde olarak gösterir (%80+ yeşil, %60-80 sarı, %60- kırmızı).
              </template>
              <template v-else>
                Güven skoru, veri noktası sayısı ve tarihsel tutarlılığa (varyasyon katsayısı) göre belirlenmektedir.
              </template>
              Bu tahmin istatistiksel bir projeksiyondur, kesin sonuç garantisi değildir.
            </p>
          </div>
        </div>

        <!-- Tahmin verisi yoksa -->
        <div v-else class="text-center py-8">
          <UIcon name="i-lucide-brain-circuit" class="size-8 text-muted mx-auto mb-2" />
          <p class="text-sm text-muted">Tahmin için yeterli geçmiş veri bulunamadı.</p>
        </div>
      </UCard>
    </template>
  </div>
</template>
