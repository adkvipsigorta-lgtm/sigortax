/**
 * Global otomatik buyuk harf: kullanici yazdikca tum metin tipi input'lari
 * Turkce-uyumlu (i -> Ä°, Ä± -> I) sekilde buyuk harfe cevirir.
 *
 * Atlanan input'lar:
 *  - type: email, password, number, tel, date/time variantlari, file, vb.
 *  - inputmode: numeric, decimal, tel
 *  - <textarea> (uzun metinlerde rahatsiz edici)
 *  - data-no-uppercase attribute'u olan input'lar (opt-out)
 */
export default defineNuxtPlugin(() => {
  const SKIP_TYPES = new Set([
    'email', 'password', 'number', 'tel',
    'date', 'time', 'datetime-local', 'month', 'week',
    'checkbox', 'radio', 'hidden', 'file', 'color', 'range',
    'submit', 'button', 'reset', 'image'
  ])

  document.addEventListener('input', (e) => {
    const el = e.target as HTMLInputElement | null
    if (!el || el.tagName !== 'INPUT') return

    const type = (el.type || 'text').toLowerCase()
    if (SKIP_TYPES.has(type)) return

    const mode = (el.getAttribute('inputmode') || '').toLowerCase()
    if (mode === 'numeric' || mode === 'decimal' || mode === 'tel') return

    if (el.dataset.noUppercase !== undefined) return

    const old = el.value
    if (!old) return
    const upper = old.toLocaleUpperCase('tr-TR')
    if (old === upper) return

    const start = el.selectionStart
    const end = el.selectionEnd
    el.value = upper
    // v-model'in degisikligi gormesi icin tekrar dispatch et
    el.dispatchEvent(new Event('input', { bubbles: true }))
    if (start !== null && end !== null) {
      try { el.setSelectionRange(start, end) } catch { /* bazi input type'lari selection desteklemez */ }
    }
  }, true)
})
