import { expect, type Page, type Locator } from 'playwright/test'

/**
 * Sentinel değerlerin UI'da render edilmediğini doğrular.
 * Form/select alanlarındaki visible text'te -1, undefined, null, NaN, [object Object] arar.
 */
export async function assertNoSentinelValues(page: Page, scope?: Locator) {
  const container = scope || page
  const sentinels = ['-1', 'undefined', 'null', 'NaN', '[object Object]']

  // Select trigger'lardaki visible text'leri kontrol et
  const triggers = container.locator('[data-slot="base"], [role="combobox"]')
  const count = await triggers.count()

  for (let i = 0; i < count; i++) {
    const text = await triggers.nth(i).innerText().catch(() => '')
    const trimmed = text.trim()
    if (!trimmed) continue // boş/placeholder OK

    for (const sentinel of sentinels) {
      // Tam eşleşme: sadece sentinel değerinin kendisi
      if (trimmed === sentinel) {
        throw new Error(`Sentinel value "${sentinel}" found in select trigger #${i}: "${trimmed}"`)
      }
    }
  }

  // Input value'larını kontrol et
  const inputs = container.locator('input[type="text"], input:not([type])')
  const inputCount = await inputs.count()

  for (let i = 0; i < inputCount; i++) {
    const val = await inputs.nth(i).inputValue().catch(() => '')
    if (!val) continue

    for (const sentinel of sentinels) {
      if (val === sentinel) {
        throw new Error(`Sentinel value "${sentinel}" found in input #${i}: "${val}"`)
      }
    }
  }
}

/**
 * Select trigger'ın visible text'inin geçerli olduğunu doğrular.
 * Boş/placeholder OK, ama raw ID veya sentinel değer kabul edilmez.
 */
export async function assertSelectValueValid(trigger: Locator) {
  const text = await trigger.innerText().catch(() => '')
  const trimmed = text.trim()

  // Boş veya placeholder: OK
  if (!trimmed || trimmed === ' ') return

  // Sentinel check
  const sentinels = ['-1', 'undefined', 'null', 'NaN', '[object Object]']
  for (const s of sentinels) {
    expect(trimmed, `Select shows sentinel value "${s}"`).not.toBe(s)
  }

  // Sadece sayı (raw ID olabilir) — 5+ haneli ise şüpheli
  // Not: 4 haneli sayılar yıl olabilir (2024, 2025) — false positive önleme
  if (/^\d{5,}$/.test(trimmed)) {
    throw new Error(`Select might be showing raw ID: "${trimmed}"`)
  }
}

/**
 * Login helper — 2FA gerektirebilir, bu durumda test skip edilir.
 * Dönen değer: login başarılı mı.
 */
export async function tryLogin(page: Page, email: string, password: string): Promise<boolean> {
  await page.goto('/login', { waitUntil: 'networkidle' })
  await page.waitForTimeout(2000)

  // Zaten giriş yapılmışsa
  if (!page.url().includes('/login')) return true

  // Email ve şifre gir
  const emailInput = page.locator('input[type="email"]').first()
  const passInput = page.locator('input[type="password"]').first()

  if (await emailInput.count() === 0) return false

  await emailInput.fill(email)
  await passInput.fill(password)

  // Submit
  await page.locator('button[type="submit"]').first().click()
  await page.waitForTimeout(3000)

  // 2FA adımına gittiyse veya dashboard'a yönlendiyse
  return !page.url().includes('/login')
}
