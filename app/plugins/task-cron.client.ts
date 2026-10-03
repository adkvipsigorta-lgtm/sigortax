/**
 * Arka planda dakikada bir /api/tasks/cron endpoint'ini cagiran client plugin.
 *
 * - Sadece kullanici login iken aktif (token degistiginde otomatik baslatip durdurur).
 * - Backend cron'u idempotent (NOT EXISTS kontrolu var) — ayni gorev iki kez olusturulmaz,
 *   bu yuzden birden fazla kullanici aciksa cakisma olmaz.
 * - Tarayici kapatilinca/yenilenince natural olarak durur.
 */
export default defineNuxtPlugin(() => {
  const CRON_SECRET = 'sigorta_cron_2024'
  const INTERVAL_MS = 60_000 // dakikada bir
  const MIN_GAP_MS = 30_000 // ayni session'da minimum 30sn ara (page focus/blur kaynakli tekrarlari onler)

  const { token } = useAuth()
  let timer: ReturnType<typeof setInterval> | null = null
  let lastRunAt = 0

  async function runCron() {
    const now = Date.now()
    if (now - lastRunAt < MIN_GAP_MS) return
    lastRunAt = now
    try {
      await $fetch('/api/tasks/cron', { query: { secret: CRON_SECRET } })
    } catch {
      // sessizce gec, bir sonraki tetiklemede tekrar denenir
    }
  }

  function start() {
    if (timer) return
    runCron() // hemen ilk tetikleme
    timer = setInterval(runCron, INTERVAL_MS)
  }

  function stop() {
    if (timer) {
      clearInterval(timer)
      timer = null
    }
  }

  // Token varsa baslat, yoksa durdur
  watch(
    () => !!token.value,
    (loggedIn) => {
      if (loggedIn) start()
      else stop()
    },
    { immediate: true }
  )

  // Sekme tekrar gorunur olunca anında bir kez tetikle (kullanici tarayiciya geri donduginde guncel olsun)
  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible' && token.value) {
        runCron()
      }
    })
  }
})
