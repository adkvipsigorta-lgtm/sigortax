/**
 * Global notification sound manager.
 * Supports: off, önce, twice, loop modes.
 * Reads preference from localStorage.
 */
let currentAudio: HTMLAudioElement | null = null
let looping = false
let playCount = 0
let maxPlays = 0

function getSoundPref(): string {
  if (!import.meta.client) return 'loop'
  return localStorage.getItem('notif_sound_pref') || 'loop'
}

function playNew(onEnded?: () => void): HTMLAudioElement {
  const a = new Audio('/notification.mp3')
  a.volume = 0.7
  if (onEnded) a.onended = onEnded
  a.play().catch(() => {})
  return a
}

export function useNotificationSound() {
  function handleEnded() {
    playCount++
    if (looping) {
      if (maxPlays > 0 && playCount >= maxPlays) {
        looping = false
        return
      }
      setTimeout(() => {
        if (looping) {
          currentAudio = playNew(handleEnded)
        }
      }, 2000)
    }
  }

  function startSound() {
    const pref = getSoundPref()
    if (pref === 'off') return

    stop()

    playCount = 0
    looping = true

    if (pref === 'önce') maxPlays = 1
    else if (pref === 'twice') maxPlays = 2
    else maxPlays = 0 // loop

    currentAudio = playNew(handleEnded)
  }

  function stop() {
    looping = false
    playCount = 0
    if (currentAudio) {
      currentAudio.pause()
      currentAudio.onended = null
      currentAudio = null
    }
  }

  function setPref(pref: string) {
    if (import.meta.client) {
      localStorage.setItem('notif_sound_pref', pref)
    }
  }

  return { startSound, stop, setPref }
}
