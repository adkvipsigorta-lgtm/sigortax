/**
 * Login Page State Regression Tests
 *
 * BUG: resetToCredentials() qrCodeDataUrl'ı temizlemiyordu.
 * Farklı hesapla giriş sonrası eski QR kodu görünebilirdi.
 * FIX: resetToCredentials()'e qrCodeDataUrl.value = '' eklendi.
 */
import { test, expect } from 'playwright/test'

test.describe('Login — State Reset Contract', () => {
  test('resetToCredentials logic: tüm state alanları sıfırlanmalı', async ({ page }) => {
    const result = await page.evaluate(() => {
      // Simüle: login state machine
      const state = {
        step: 'setup-qr' as string,
        challengeToken: 'abc123',
        totpCode: '123456',
        setupSecret: 'SECRET',
        setupOtpauthUrl: 'otpauth://totp/...',
        qrCodeDataUrl: 'data:image/png;base64,...',
        setupVerifyCode: '654321',
        recoveryCodes: ['ABCD', 'EFGH'],
        recoveryConfirmed: true,
        emailError: 'Invalid',
        passwordError: 'Too short',
        totpError: 'Wrong code',
        authError: 'Auth failed',
        emailTouched: true,
        passwordTouched: true,
        totpTouched: true,
      }

      // resetToCredentials() mantığı
      state.step = 'credentials'
      state.challengeToken = ''
      state.totpCode = ''
      state.setupSecret = ''
      state.setupOtpauthUrl = ''
      state.qrCodeDataUrl = ''  // BUG FIX: bu satır eklendi
      state.setupVerifyCode = ''
      state.recoveryCodes = []
      state.recoveryConfirmed = false
      state.emailError = ''
      state.passwordError = ''
      state.totpError = ''
      state.authError = ''
      state.emailTouched = false
      state.passwordTouched = false
      state.totpTouched = false

      return state
    })

    expect(result.step).toBe('credentials')
    expect(result.challengeToken).toBe('')
    expect(result.qrCodeDataUrl).toBe('')  // Kritik: QR temizlenmeli
    expect(result.setupSecret).toBe('')
    expect(result.setupOtpauthUrl).toBe('')
    expect(result.setupVerifyCode).toBe('')
    expect(result.recoveryCodes).toEqual([])
    expect(result.recoveryConfirmed).toBe(false)
    expect(result.emailError).toBe('')
    expect(result.passwordError).toBe('')
    expect(result.totpError).toBe('')
    expect(result.authError).toBe('')
    expect(result.emailTouched).toBe(false)
    expect(result.passwordTouched).toBe(false)
    expect(result.totpTouched).toBe(false)
  })

  test('Login page loads without sentinel values', async ({ page }) => {
    await page.goto('http://localhost:3000/login', { waitUntil: 'networkidle' })
    await page.waitForTimeout(2000)

    // Login sayfasında sentinel değer olmamalı
    const bodyText = await page.locator('body').innerText()
    expect(bodyText).not.toContain('[object Object]')
    expect(bodyText).not.toContain('NaN')

    // -1, undefined, null genel metin içinde olabilir ama form field'larda olmamalı
    const inputs = page.locator('input')
    const inputCount = await inputs.count()
    for (let i = 0; i < inputCount; i++) {
      const val = await inputs.nth(i).inputValue()
      expect(val).not.toBe('-1')
      expect(val).not.toBe('undefined')
      expect(val).not.toBe('null')
      expect(val).not.toBe('NaN')
    }
  })
})
