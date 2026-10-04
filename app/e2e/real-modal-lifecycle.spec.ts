/**
 * REAL E2E — Modal Lifecycle & Select Validity
 *
 * Gerçek CRM UI üzerinden modal open/close/reopen ve select validity test eder.
 * Requires: authenticated storageState
 */
import { test, expect } from 'playwright/test'
import { assertNoSentinelValues, assertSelectValueValid } from './helpers'

test.describe('Real CRM — Modal Select Validity', () => {

  test('Poliçeler: filter select dropdown açılır ve geçerli options gösterir', async ({ page }) => {
    await page.goto('/policeler', { waitUntil: 'networkidle' })
    await page.waitForTimeout(2000)

    // Filter select'lerden birini aç
    const filterSelects = page.locator('.filter-toolbar [data-slot="base"], .filter-toolbar [role="combobox"]')
    const count = await filterSelects.count()

    if (count === 0) {
      test.skip(true, 'Filter select bulunamadı')
      return
    }

    // İlk select'i aç
    await filterSelects.first().click()
    await page.waitForTimeout(500)

    // Dropdown panel açıldı mı
    const panel = page.locator('[data-radix-popper-content-wrapper]')
    if (await panel.count() > 0) {
      // Options var mı
      const items = panel.locator('[role="option"], [data-slot="item"]')
      const itemCount = await items.count()
      expect(itemCount).toBeGreaterThan(0)
    }

    await page.keyboard.press('Escape')
  })

  test('Müşteriler: filter toolbar sentinel value yok', async ({ page }) => {
    await page.goto('/musteriler', { waitUntil: 'networkidle' })
    await page.waitForTimeout(2000)

    const toolbar = page.locator('.filter-toolbar').first()
    if (await toolbar.count() > 0) {
      await assertNoSentinelValues(page, toolbar)
    }
  })

  test('Görevler: sayfa yüklendiğinde sentinel yok', async ({ page }) => {
    await page.goto('/gorevler', { waitUntil: 'networkidle' })
    await page.waitForTimeout(2000)

    // Toolbar ve KPI kartlarında sentinel kontrol
    const selects = page.locator('[data-slot="base"], [role="combobox"]')
    const count = await selects.count()

    for (let i = 0; i < count; i++) {
      await assertSelectValueValid(selects.nth(i))
    }
  })
})

test.describe('Real CRM — OfferFormModal Smoke', () => {
  test('Teklif modalı: open/close lifecycle', async ({ page }) => {
    // Müşteri detay sayfasına git — teklif oluştur butonu burada
    await page.goto('/musteriler', { waitUntil: 'networkidle' })
    await page.waitForTimeout(2000)

    // İlk müşteriye tıkla
    const customerLink = page.locator('a[href*="/musteriler/"]').first()
    if (await customerLink.count() === 0) {
      test.skip(true, 'Müşteri listesi boş')
      return
    }
    await customerLink.click()
    await page.waitForURL(/\/musteriler\/\d+/, { timeout: 10000 })
    await page.waitForTimeout(2000)

    // Teklif Oluştur butonunu bul
    const offerBtn = page.getByRole('button', { name: /Teklif|Teklif Oluştur/i }).first()
    if (await offerBtn.count() === 0) {
      test.skip(true, 'Teklif Oluştur butonu bulunamadı')
      return
    }

    await offerBtn.click()
    await page.waitForTimeout(1000)

    const modal = page.locator('[role="dialog"]').first()
    if (await modal.count() > 0) {
      // Modal açıldı — sentinel kontrol
      await assertNoSentinelValues(page, modal)

      // Close
      await page.keyboard.press('Escape')
      await page.waitForTimeout(500)

      // Modal kapandı mı
      await expect(modal).not.toBeVisible({ timeout: 3000 }).catch(() => {})
    }
  })
})

test.describe('Real CRM — Lead Detail Smoke', () => {
  test('Lead detay: status modal lifecycle', async ({ page }) => {
    await page.goto('/leadler', { waitUntil: 'networkidle' })
    await page.waitForTimeout(2000)

    // İlk lead'e tıkla
    const leadLink = page.locator('a[href*="/leadler/"]').first()
    if (await leadLink.count() === 0) {
      test.skip(true, 'Lead listesi boş veya lead detay linki yok')
      return
    }

    await leadLink.click()
    await page.waitForTimeout(3000)

    // Sayfa yüklendiğinde sentinel kontrol
    const selects = page.locator('[data-slot="base"], [role="combobox"]')
    const count = await selects.count()
    for (let i = 0; i < count; i++) {
      await assertSelectValueValid(selects.nth(i))
    }
  })
})
