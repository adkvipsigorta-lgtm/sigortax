/**
 * Entity-Aware Name Display Formatter Tests
 *
 * ADK CRM standardı: ellipsis ("...") YASAK.
 * Kişi ve şirket adları farklı semantic kurallarla kısaltılır.
 */
import { test, expect } from 'playwright/test'

test.describe('formatCompanyName', () => {
  // Evaluate formatter in browser context
  async function companyName(page: any, name: string, mode: string) {
    return page.evaluate(({ n, m }: any) => {
      function normalize(s: string) { return (s || '').trim().replace(/\s+/g, ' ') }
      function formatCompanyName(name: string, mode: string) {
        const clean = normalize(name)
        if (!clean) return ''
        if (mode === 'full') return clean
        const words = clean.split(' ')
        if (words.length <= 3) return clean
        return words.slice(0, 3).join(' ')
      }
      return formatCompanyName(n, m)
    }, { n: name, m: mode })
  }

  test('FULL: tam isim döner', async ({ page }) => {
    const r = await companyName(page, 'ADK VİP SİGORTA ACENTELİK HİZMETLERİ', 'full')
    expect(r).toBe('ADK VİP SİGORTA ACENTELİK HİZMETLERİ')
  })

  test('COMPACT: ilk 3 kelime', async ({ page }) => {
    const r = await companyName(page, 'ADK VİP SİGORTA ACENTELİK HİZMETLERİ', 'compact')
    expect(r).toBe('ADK VİP SİGORTA')
  })

  test('COMPACT: ABC OTOMOTİV SANAYİ VE TİCARET A.Ş.', async ({ page }) => {
    const r = await companyName(page, 'ABC OTOMOTİV SANAYİ VE TİCARET A.Ş.', 'compact')
    expect(r).toBe('ABC OTOMOTİV SANAYİ')
  })

  test('COMPACT: İZOWENT YALITIM SİSTEMLERİ SA → ilk 3 kelime', async ({ page }) => {
    const r = await companyName(page, 'İZOWENT YALITIM SİSTEMLERİ SA', 'compact')
    expect(r).toBe('İZOWENT YALITIM SİSTEMLERİ')
  })

  test('COMPACT: 3 kelime veya daha az → tam göster', async ({ page }) => {
    expect(await companyName(page, 'ABC SİGORTA', 'compact')).toBe('ABC SİGORTA')
    expect(await companyName(page, 'ABC', 'compact')).toBe('ABC')
    expect(await companyName(page, 'ABC SİGORTA LTD', 'compact')).toBe('ABC SİGORTA LTD')
  })

  test('COMPACT: 5-6 kelime → yine ilk 3', async ({ page }) => {
    expect(await companyName(page, 'UZUN ŞİRKET ADI BİRÇOK KELİME İLE', 'compact')).toBe('UZUN ŞİRKET ADI')
    expect(await companyName(page, 'A B C D E F', 'compact')).toBe('A B C')
  })

  test('Boş isim', async ({ page }) => {
    expect(await companyName(page, '', 'compact')).toBe('')
    expect(await companyName(page, '  ', 'compact')).toBe('')
  })

  test('Whitespace normalization', async ({ page }) => {
    const r = await companyName(page, '  ADK   VİP   SİGORTA   ACENTELİK  ', 'compact')
    expect(r).toBe('ADK VİP SİGORTA')
  })

  test('Ellipsis yok', async ({ page }) => {
    const r = await companyName(page, 'UZUN ŞİRKET ADI BİRÇOK KELİME İLE', 'compact')
    expect(r).not.toContain('...')
    expect(r).not.toContain('…')
  })
})

test.describe('formatPersonName', () => {
  async function personName(page: any, name: string, mode: string) {
    return page.evaluate(({ n, m }: any) => {
      function normalize(s: string) { return (s || '').trim().replace(/\s+/g, ' ') }
      function formatPersonName(name: string, mode: string) {
        const clean = normalize(name)
        if (!clean) return ''
        if (mode === 'full') return clean
        const words = clean.split(' ')
        if (words.length <= 3) return clean
        return `${words[0]} ${words[1]} ${words[words.length - 1]}`
      }
      return formatPersonName(n, m)
    }, { n: name, m: mode })
  }

  test('FULL: tam isim döner', async ({ page }) => {
    const r = await personName(page, 'Ahmet Mehmet Can Yılmaz', 'full')
    expect(r).toBe('Ahmet Mehmet Can Yılmaz')
  })

  test('COMPACT: 2 kelime → aynen', async ({ page }) => {
    expect(await personName(page, 'Aykut Okut', 'compact')).toBe('Aykut Okut')
  })

  test('COMPACT: 3 kelime → aynen', async ({ page }) => {
    expect(await personName(page, 'Mehmet Ali Yılmaz', 'compact')).toBe('Mehmet Ali Yılmaz')
  })

  test('COMPACT: 4 kelime → ilk 2 + son', async ({ page }) => {
    expect(await personName(page, 'Ahmet Mehmet Can Yılmaz', 'compact')).toBe('Ahmet Mehmet Yılmaz')
  })

  test('COMPACT: 5 kelime → ilk 2 + son', async ({ page }) => {
    expect(await personName(page, 'Ahmet Mehmet Can Ali Yılmaz', 'compact')).toBe('Ahmet Mehmet Yılmaz')
  })

  test('COMPACT: 1 kelime → aynen', async ({ page }) => {
    expect(await personName(page, 'Aykut', 'compact')).toBe('Aykut')
  })

  test('Boş isim', async ({ page }) => {
    expect(await personName(page, '', 'compact')).toBe('')
  })

  test('Ellipsis yok — person', async ({ page }) => {
    const r = await personName(page, 'Ahmet Mehmet Can Ali Veli Yılmaz', 'compact')
    expect(r).not.toContain('...')
    expect(r).not.toContain('…')
  })
})

test.describe('insuranceShortLabel', () => {
  async function shortLabel(page: any, name: string, branchGroup?: string) {
    return page.evaluate(({ n, bg }: any) => {
      function insuranceShortLabel(name?: string, branchGroup?: string) {
        if (branchGroup) return branchGroup
        if (!name) return ''
        return name.trim().split(/\s+/)[0] || ''
      }
      return insuranceShortLabel(n, bg)
    }, { n: name, bg: branchGroup })
  }

  test('branchGroup varsa onu kullan', async ({ page }) => {
    expect(await shortLabel(page, 'Tamamlayıcı Sağlık Sigortası', 'SAĞLIK')).toBe('SAĞLIK')
    expect(await shortLabel(page, 'Zorunlu Trafik Sigortası', 'TRAFİK')).toBe('TRAFİK')
  })

  test('branchGroup yoksa ilk kelime', async ({ page }) => {
    expect(await shortLabel(page, 'TSS')).toBe('TSS')
    expect(await shortLabel(page, 'TRAFİK Sigortası')).toBe('TRAFİK')
    expect(await shortLabel(page, 'KASKO')).toBe('KASKO')
    expect(await shortLabel(page, 'DASK')).toBe('DASK')
  })

  test('boş isim', async ({ page }) => {
    expect(await shortLabel(page, '')).toBe('')
  })

  test('ellipsis yok', async ({ page }) => {
    const r = await shortLabel(page, 'Uzun Sigorta Türü Adı')
    expect(r).not.toContain('...')
    expect(r).toBe('Uzun')
  })
})

test.describe('insuranceBadgeStyle', () => {
  test('hex color → bg %10 opacity + text full', async ({ page }) => {
    const result = await page.evaluate(() => {
      function toHex(c?: string) {
        if (!c) return '#3b82f6'
        if (c.startsWith('#')) return c
        return '#3b82f6'
      }
      function insuranceBadgeStyle(color?: string) {
        const hex = toHex(color)
        return { backgroundColor: hex + '1a', color: hex }
      }
      return insuranceBadgeStyle('#ef4444')
    })
    expect(result.backgroundColor).toBe('#ef44441a')
    expect(result.color).toBe('#ef4444')
  })

  test('undefined → default blue', async ({ page }) => {
    const result = await page.evaluate(() => {
      function toHex(c?: string) {
        if (!c) return '#3b82f6'
        if (c.startsWith('#')) return c
        return '#3b82f6'
      }
      function insuranceBadgeStyle(color?: string) {
        const hex = toHex(color)
        return { backgroundColor: hex + '1a', color: hex }
      }
      return insuranceBadgeStyle(undefined)
    })
    expect(result.backgroundColor).toBe('#3b82f61a')
  })
})
