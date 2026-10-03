import type { Policy } from '~/types'

export type PolicyStatus = 'aktif' | 'pasif' | 'iptal' | 'vadesi_gecmis'

export function usePolicyHelpers() {
  function formatCurrency(amount: number) {
    return new Intl.NumberFormat('tr-TR', { style: 'decimal', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount)
  }

  function getPolicyStatus(policy: Policy): PolicyStatus {
    // Backend'den gelen status varsa onu kullan
    if ((policy as any).status) {
      const s = (policy as any).status
      if (s === 'CANCELLED') return 'iptal'
      if (s === 'EXPIRED') return 'vadesi_gecmis'
      if (s === 'ACTIVE') return 'aktif'
    }

    // Fallback: zeyil detay sayfasi için (backend status gondermezse)
    if (policy.isCancelled) return 'iptal'
    const expiresAt = (policy as any).effectiveExpiresAt || policy.expiresAt
    const now = new Date()
    const finish = new Date(expiresAt)
    if (finish < now) return 'vadesi_gecmis'
    if (policy.isApproved) return 'aktif'
    return 'pasif'
  }

  function calculateCommission(policy: Policy): number {
    if (policy.productionType === 'SELF') {
      return (policy.netPremium / 100) * policy.companyCommRate
    }
    const compComm = (policy.netPremium / 100) * policy.companyCommRate
    return compComm * (policy.branchCommRate / 100)
  }

  function getStatusColor(status: string) {
    const colors: Record<string, string> = {
      aktif: 'success',
      pasif: 'neutral',
      iptal: 'error',
      vadesi_gecmis: 'warning'
    }
    return colors[status] || 'neutral'
  }

  function getStatusLabel(status: string) {
    const labels: Record<string, string> = {
      aktif: 'Aktif',
      pasif: 'Pasif',
      iptal: 'İptal',
      vadesi_gecmis: 'Vadesi Geçmiş'
    }
    return labels[status] || status
  }

  function getProdLabel(prod: string) {
    const labels: Record<string, string> = { SELF: 'Acentem', INCOMING: 'Tali Gelen', OUTGOING: 'Tali Giden' }
    return labels[prod] || prod
  }

  function getDaysRemaining(finishDate: string): number {
    const now = new Date()
    const finish = new Date(finishDate)
    return Math.ceil((finish.getTime() - now.getTime()) / (1000 * 60 * 60 * 24))
  }

  return {
    formatCurrency,
    getPolicyStatus,
    calculateCommission,
    getStatusColor,
    getStatusLabel,
    getProdLabel,
    getDaysRemaining
  }
}
