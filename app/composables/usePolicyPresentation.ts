/**
 * Policy Type & Status Presentation — merkezi source of truth.
 *
 * Badge renkleri, label'lar ve geometry tek yerden yönetilir.
 * Page-specific toHex/legacyColorMap/badge-cell duplicate'ları kaldırıldı.
 *
 * Backend'den gelen insuranceColor (hex veya legacy name) normalize edilir.
 * Badge geometry main.css'teki .badge-cell class'ından gelir.
 */

/** Legacy theme name → hex mapping */
const legacyColorMap: Record<string, string> = {
  primary: '#3b82f6',
  error: '#ef4444',
  success: '#22c55e',
  warning: '#f59e0b',
  info: '#8b5cf6',
  neutral: '#6b7280',
}

/**
 * Backend color → hex normalize.
 * Backend insuranceColor hex (#RRGGBB) veya legacy theme name olabilir.
 */
export function toHex(color?: string): string {
  if (!color) return '#3b82f6'
  if (color.startsWith('#')) return color
  return legacyColorMap[color] || '#3b82f6'
}

/**
 * Insurance badge inline style üretir.
 * Background: %10 opacity, Text: full color.
 */
export function insuranceBadgeStyle(color?: string) {
  const hex = toHex(color)
  return {
    backgroundColor: hex + '1a',
    color: hex,
  }
}

/** Policy status → semantic color mapping */
export function policyStatusColor(status?: string): string {
  switch (status) {
    case 'ACTIVE': return 'success'
    case 'CANCELLED': return 'error'
    case 'EXPIRED': return 'warning'
    default: return 'neutral'
  }
}

/** Policy status → label mapping */
export function policyStatusLabel(status?: string): string {
  switch (status) {
    case 'ACTIVE': return 'Aktif'
    case 'CANCELLED': return 'İptal'
    case 'EXPIRED': return 'Süresi Dolmuş'
    default: return status || ''
  }
}

/**
 * Insurance type short label — badge-cell içinde gösterilecek canonical kısaltma.
 * "Tamamlayıcı Sağlık Sigortası" → "TSS" (branchGroup varsa), yoksa ilk kelime.
 */
export function insuranceShortLabel(name?: string, branchGroup?: string): string {
  if (branchGroup) return branchGroup
  if (!name) return ''
  return name.trim().split(/\s+/)[0] || ''
}

/** Remaining days → badge semantic color */
export function remainingDaysColor(days: number): string {
  if (days <= 0) return 'error'
  if (days <= 7) return 'warning'
  if (days <= 15) return 'info'
  return 'success'
}
