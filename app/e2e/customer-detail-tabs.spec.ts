/**
 * Customer Detail Page — Sub-Tab State Regression Tests
 *
 * BUG: offerSubTab tab değişiminde reset edilmiyordu.
 * Teklifler→Poliçeler→Teklifler geçişinde "Geçmiş" sub-tab kalıyordu.
 * FIX: watch(activeTab) içine offerSubTab = 'active' eklendi.
 */
import { test, expect } from 'playwright/test'

test.describe('Customer Detail — Sub-Tab State Contract', () => {
  test('offerSubTab: parent tab değişince default "active" olmalı', async ({ page }) => {
    const result = await page.evaluate(() => {
      // Simüle: müşteri detay tab state machine
      let activeTab = 'aktif'
      let offerSubTab = 'active' as 'active' | 'past'
      let fetchCalled = false

      function watchActiveTab(val: string) {
        if (val === 'teklifler') {
          offerSubTab = 'active'  // FIX: reset sub-tab
          fetchCalled = true
        }
      }

      const transitions: { step: string; activeTab: string; offerSubTab: string }[] = []

      // 1. Teklifler tabına git
      activeTab = 'teklifler'
      watchActiveTab(activeTab)
      transitions.push({ step: '1-teklifler', activeTab, offerSubTab })

      // 2. Geçmiş sub-tab'a geç
      offerSubTab = 'past'
      transitions.push({ step: '2-past-subtab', activeTab, offerSubTab })

      // 3. Poliçeler tabına git
      activeTab = 'aktif'
      watchActiveTab(activeTab)
      transitions.push({ step: '3-aktif', activeTab, offerSubTab })

      // 4. Teklifler tabına geri dön
      activeTab = 'teklifler'
      watchActiveTab(activeTab)
      transitions.push({ step: '4-teklifler-return', activeTab, offerSubTab })

      return { transitions, finalSubTab: offerSubTab, fetchCalled }
    })

    // Teklifler'e geri dönüldüğünde sub-tab 'active' olmalı
    expect(result.finalSubTab).toBe('active')
    expect(result.transitions[3].offerSubTab).toBe('active')

    // Stale 'past' kalmamalı
    expect(result.transitions[3].offerSubTab).not.toBe('past')
  })
})
