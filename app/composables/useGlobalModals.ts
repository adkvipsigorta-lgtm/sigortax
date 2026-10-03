import { reactive } from 'vue'

const customerModalState = reactive({
  open: false,
  customer: null as any,
  onSaved: null as ((customer?: any) => void) | null
})

const policyModalState = reactive({
  open: false,
  customerId: undefined as number | undefined,
  policy: null as any,
  onSaved: null as (() => void) | null
})

export function useGlobalModals() {
  function openCustomerModal(options?: { customer?: any; onSaved?: (customer?: any) => void }) {
    customerModalState.customer = options?.customer ?? null
    customerModalState.onSaved = options?.onSaved ?? null
    customerModalState.open = true
  }

  function openPolicyModal(options?: { customerId?: number; policy?: any; onSaved?: () => void }) {
    policyModalState.customerId = options?.customerId
    policyModalState.policy = options?.policy ?? null
    policyModalState.onSaved = options?.onSaved ?? null
    policyModalState.open = true
  }

  return {
    customerModal: customerModalState,
    policyModal: policyModalState,
    openCustomerModal,
    openPolicyModal
  }
}
