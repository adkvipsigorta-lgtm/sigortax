/**
 * Global notification sound manager.
 * Supports: off, önce, twice, loop modes.
 * Reads preference from localStorage.
 *
 * AudioContext kullanır — iOS'ta kilit ekranı media player widget'ı oluşturmaz.
 */
let audioCtx: AudioContext | null = null
let audioBuffer: AudioBuffer | null = null
let currentSource: AudioBufferSourceNode | null = null
let unlocked = false
let listenersBound = false
let looping = false
let playCount = 0
let maxPlays = 0
let bufferLoading = false

function getSoundPref(): string {
  if (!import.meta.client) return 'loop'
  return localStorage.getItem('notif_sound_pref') || 'loop'
}

async function ensureContext() {
  if (!import.meta.client) return
  if (!audioCtx) {
    audioCtx = new AudioContext()
  }
  if (!audioBuffer && !bufferLoading) {
    bufferLoading = true
    try {
      const res = await fetch('/notification.mp3')
      const arrayBuf = await res.arrayBuffer()
      audioBuffer = await audioCtx.decodeAudioData(arrayBuf)
    } catch {
      // sessizce geç
    } finally {
      bufferLoading = false
    }
  }
}

function playOnce(onEnded?: () => void) {
  if (!audioCtx || !audioBuffer) return
  // Suspended context'i resume et (iOS autoplay policy)
  if (audioCtx.state === 'suspended') {
    audioCtx.resume()
  }
  // Önceki source varsa durdur
  if (currentSource) {
    try { currentSource.stop() } catch {}
    currentSource = null
  }
  const source = audioCtx.createBufferSource()
  source.buffer = audioBuffer
  const gain = audioCtx.createGain()
  gain.gain.value = 0.7
  source.connect(gain)
  gain.connect(audioCtx.destination)
  source.onended = () => {
    currentSource = null
    onEnded?.()
  }
  source.start()
  currentSource = source
}

function bindUnlockListeners() {
  if (listenersBound || !import.meta.client) return
  listenersBound = true

  const unlock = () => {
    if (unlocked) return
    ensureContext().then(() => {
      if (audioCtx && audioCtx.state === 'suspended') {
        audioCtx.resume()
      }
      unlocked = true
    })
  }

  ;['click', 'touchstart', 'keydown'].forEach(evt => {
    document.addEventListener(evt, unlock, { capture: true, passive: true })
  })
}

export function useNotificationSound() {
  onMounted(() => {
    ensureContext()
    bindUnlockListeners()
  })

  function handleEnded() {
    playCount++
    if (looping) {
      if (maxPlays > 0 && playCount >= maxPlays) {
        looping = false
        return
      }
      setTimeout(() => {
        if (looping) playOnce(handleEnded)
      }, 2000)
    }
  }

  function startSound() {
    const pref = getSoundPref()
    if (pref === 'off') return

    ensureContext()
    if (!audioBuffer) return

    playCount = 0
    looping = true

    if (pref === 'önce') maxPlays = 1
    else if (pref === 'twice') maxPlays = 2
    else maxPlays = 0 // loop

    playOnce(handleEnded)
  }

  function stop() {
    looping = false
    playCount = 0
    if (currentSource) {
      try { currentSource.stop() } catch {}
      currentSource = null
    }
  }

  function setPref(pref: string) {
    if (import.meta.client) {
      localStorage.setItem('notif_sound_pref', pref)
    }
  }

  return { startSound, stop, setPref }
}
