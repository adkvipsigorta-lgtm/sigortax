/**
 * REAL E2E — TaskFormModal Tab Transitions
 *
 * Gerçek CRM UI üzerinden Teklif Ekle modalının tab geçişlerini test eder.
 * Requires: authenticated storageState (run auth-setup.ts first)
 */
import { test, expect } from 'playwright/test'
import { assertNoSentinelValues, assertSelectValueValid } from './helpers'

test.describe('Real CRM — TaskFormModal Tab Transitions', () => {
  test.beforeEach(async ({ page }) => {
    // Görevler sayfasına git (TaskFormModal burada açılıyor)
    await page.goto('/gorevler', { waitUntil: 'networkidle' })
    await page.waitForTimeout(2000)
  })

  test('OFFER→QUICK→OFFER: Atanan Kişi "-1" göstermez', async ({ page }) => {
    // Yeni Görev butonunu bul ve tıkla
    const newTaskBtn = page.getByRole('button', { name: /Yeni Görev|Teklif Ekle/i }).first()
    if (await newTaskBtn.count() === 0) {
      test.skip(true, 'Yeni Görev butonu bulunamadı — yetki sorunu olabilir')
      return
    }
    await newTaskBtn.click()
    await page.waitForTimeout(1000)

    // Modal açıldı mı kontrol
    const modal = page.locator('[role="dialog"]').first()
    await expect(modal).toBeVisible({ timeout: 5000 })

    // Tab butonlarını bul
    const tabs = modal.locator('button').filter({ hasText: /Teklif Oluştur|Yeni Lead|Hızlı Lead/i })
    const tabCount = await tabs.count()

    if (tabCount < 3) {
      test.skip(true, 'TaskFormModal 3 tab bulunamadı')
      return
    }

    // 1. Teklif Oluştur tabında başla (varsayılan)
    // 2. Hızlı Lead Oluştur tabına geç
    const quickTab = modal.getByText('Hızlı Lead', { exact: false })
    await quickTab.click()
    await page.waitForTimeout(500)

    // 3. Teklif Oluştur tabına geri dön
    const offerTab = modal.getByText('Teklif Oluştur', { exact: false })
    await offerTab.click()
    await page.waitForTimeout(500)

    // ASSERT: Modal içinde sentinel value yok
    await assertNoSentinelValues(page, modal)

    // Atanan Kişi select'ini bul
    const assignedSelect = modal.locator('[role="combobox"]').last()
    if (await assignedSelect.count() > 0) {
      await assertSelectValueValid(assignedSelect)
    }

    // Kapatma
    await page.keyboard.press('Escape')
  })

  test('OFFER→LEAD→QUICK→OFFER: Sentinel leak yok', async ({ page }) => {
    const newTaskBtn = page.getByRole('button', { name: /Yeni Görev|Teklif Ekle/i }).first()
    if (await newTaskBtn.count() === 0) {
      test.skip(true, 'Yeni Görev butonu bulunamadı')
      return
    }
    await newTaskBtn.click()
    await page.waitForTimeout(1000)

    const modal = page.locator('[role="dialog"]').first()
    await expect(modal).toBeVisible({ timeout: 5000 })

    // OFFER → LEAD
    const leadTab = modal.getByText('Yeni Lead', { exact: false })
    if (await leadTab.count() > 0) {
      await leadTab.click()
      await page.waitForTimeout(300)
    }

    // LEAD → QUICK
    const quickTab = modal.getByText('Hızlı Lead', { exact: false })
    if (await quickTab.count() > 0) {
      await quickTab.click()
      await page.waitForTimeout(300)
    }

    // QUICK → OFFER
    const offerTab = modal.getByText('Teklif Oluştur', { exact: false })
    if (await offerTab.count() > 0) {
      await offerTab.click()
      await page.waitForTimeout(500)
    }

    // ASSERT
    await assertNoSentinelValues(page, modal)

    await page.keyboard.press('Escape')
  })

  test('Close/Reopen: state reset doğru çalışır', async ({ page }) => {
    const newTaskBtn = page.getByRole('button', { name: /Yeni Görev|Teklif Ekle/i }).first()
    if (await newTaskBtn.count() === 0) {
      test.skip(true, 'Yeni Görev butonu bulunamadı')
      return
    }

    // Open
    await newTaskBtn.click()
    await page.waitForTimeout(1000)
    const modal = page.locator('[role="dialog"]').first()
    await expect(modal).toBeVisible({ timeout: 5000 })

    // Tab değiştir (QUICK → -1 set)
    const quickTab = modal.getByText('Hızlı Lead', { exact: false })
    if (await quickTab.count() > 0) {
      await quickTab.click()
      await page.waitForTimeout(300)
    }

    // Close
    await page.keyboard.press('Escape')
    await page.waitForTimeout(500)

    // Reopen
    await newTaskBtn.click()
    await page.waitForTimeout(1000)

    const modal2 = page.locator('[role="dialog"]').first()
    await expect(modal2).toBeVisible({ timeout: 5000 })

    // ASSERT: Fresh state — sentinel yok
    await assertNoSentinelValues(page, modal2)

    await page.keyboard.press('Escape')
  })
})
