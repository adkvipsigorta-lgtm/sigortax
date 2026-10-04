/**
 * Auth Setup — Playwright storageState oluşturma.
 *
 * 2FA korumalı CRM'e güvenli şekilde test session oluşturur.
 * Production 2FA mantığı DEĞİŞTİRİLMEZ.
 *
 * Kullanım:
 *   1. Dev server başlat: cd app && npx nuxi dev
 *   2. Bu script'i çalıştır: npx playwright test e2e/auth-setup.ts --headed
 *   3. Açılan browser'da manuel login + 2FA yap
 *   4. Dashboard'a ulaştığında script otomatik storageState kaydeder
 *   5. Sonraki testler bu state'i kullanır
 *
 * storageState dosyası .gitignore'da — commit edilmez.
 */
import { test as setup } from 'playwright/test'
import path from 'path'

const AUTH_FILE = path.join(__dirname, '.auth', 'user.json')

setup('authenticate', async ({ page }) => {
  await page.goto('/login')

  // Kullanıcı manuel login + 2FA yapar
  // Dashboard'a yönlendirilene kadar bekle (max 120 saniye)
  await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 120000 })

  // Session başarılı — storageState kaydet
  await page.context().storageState({ path: AUTH_FILE })
  console.log(`\n✓ Auth state saved to ${AUTH_FILE}`)
})
