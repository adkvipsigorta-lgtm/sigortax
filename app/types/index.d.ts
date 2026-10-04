export type UserRole = 'admin' | 'acente' | 'kullanici'

export interface User {
  id: number
  name: string
  email: string
  avatar?: string
  role: UserRole
  branchId?: number
  phone?: string
  address?: string
  city?: string
  birthDate?: string
  twoFactorEnabled?: boolean
  twoFactorSecret?: string
  isActive?: boolean
  onboardingCompleted?: boolean
}

export type CustomerType = 'INDIVIDUAL' | 'CORPORATE'

export interface Customer {
  id: number
  customerType: CustomerType
  name: string
  identityNo: string
  taxOffice?: string
  birthDate?: string
  phone: string
  phoneAlt?: string
  email?: string
  contactPerson?: string
  maritalStatus?: string
  job?: string
  dependentsCount?: number
  sector?: string
  countryId?: number | null
  cityId?: string
  districtId?: string
  address?: string
  note?: string
  categoryId?: number
  createdAt?: string
  // Stats (API'den gelebilir)
  policyCount?: number
  activePolicies?: number
  totalGross?: number
  offerCount?: number
}

export interface Company {
  id: number
  name: string
  color: string
  website?: string | null
  logo?: string | null
  policyCount?: number
  createdAt?: string
}

export type InsuranceCode = 'HEALTH' | 'TRAFFIC' | 'HOUSING' | 'DASK' | 'OTHER'
export type InsuranceLevel = 'branch' | 'category' | 'subcategory'

export interface InsuranceType {
  id: number
  name: string
  code: InsuranceCode
  color: string
  defaultCommRate: number
  extraCommRate?: number | null
  level: InsuranceLevel
  parentId?: number | null
  isActive: boolean
  showInCharts?: boolean
  isRenewable?: boolean
  branchGroup?: string | null
  renewalDays?: number
  externalCode?: string | null
}

export type BranchDateType = 'issued_at' | 'starts_at'

export interface Branch {
  id: number
  name: string
  phone?: string | null
  commissionRate: number
  iban?: string | null
  isActive?: boolean
  defaultDateType?: BranchDateType
}

export type MessageChannel = 'sms' | 'email'
export type MessageStatus = 'pending' | 'sent' | 'failed'

export interface Message {
  id: number
  channel: MessageChannel
  recipient: string
  subject?: string | null
  content: string
  customerId?: number | null
  customerName?: string | null
  templateId?: number | null
  status: MessageStatus
  sentBy?: number | null
  sentByName?: string | null
  sentAt?: string | null
  createdAt: string
}

export interface MessageTemplate {
  id: number
  name: string
  channel: MessageChannel
  subject?: string | null
  content: string
  createdAt?: string
}

export interface Notification {
  id: number
  title: string
  message: string
  data?: string | null
  type: 'info' | 'success' | 'warning' | 'error'
  unread: boolean
  date: string
}

export type ProductionType = 'SELF' | 'INCOMING' | 'OUTGOING'

export interface Policy {
  id: number
  productionType: ProductionType
  customerId: number
  customerName: string
  insuranceId: number
  insuranceName: string
  insuranceColor?: string
  companyId: number
  companyName: string
  companyColor?: string
  branchId?: number | null
  branchName?: string | null
  policyNo: string
  insuredName?: string
  issuedAt?: string
  startsAt: string
  expiresAt: string
  grossPremium: number
  netPremium: number
  companyCommRate: number
  branchCommRate: number
  income?: number
  isApproved: boolean
  isCancelled: boolean
  endorsementNo: number
  parentId?: number | null
  plateNo?: string
  registrationNo?: string
  chassisNo?: string
  engineNo?: string
  vehicleBrand?: string
  vehicleModel?: string
  vehicleYear?: string
  uavtCode?: string
  daskNo?: string
  network?: string
  additionalInsureds?: string
  referenceSource?: number | null
  referenceSourceName?: string | null
  createdBy?: number | null
  createdByName?: string | null
  soldBy?: number | null
  soldByName?: string | null
  createdAt: string
  zeyilCount?: number
}
