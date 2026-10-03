import { reactive } from 'vue'

interface CustomerOption {
  label: string
  value: number
}

const state = reactive({
  list: [] as CustomerOption[],
  loaded: false,
  loading: false
})

export function useCustomerList() {
  const { get } = useApi()

  async function fetchCustomerList(force = false) {
    if ((state.loaded || state.loading) && !force) return
    state.loading = true
    try {
      const res = await get<any>('customers/list-all')
      state.list = (res.data || []).map((c: any) => ({
        label: c.identityNo ? `${c.name} (${c.identityNo})` : c.name,
        value: c.id
      }))
      state.loaded = true
    } catch {}
    state.loading = false
  }

  function addCustomer(customer: { id: number; name: string; identityNo?: string }) {
    const label = customer.identityNo ? `${customer.name} (${customer.identityNo})` : customer.name
    state.list.unshift({ label, value: customer.id })
  }

  function filterCustomers(term: string, selectedId?: number): CustomerOption[] {
    const t = term.toLowerCase().trim()
    if (t) {
      return state.list.filter(c => c.label.toLowerCase().includes(t))
    }
    const list = state.list.slice(0, 200)
    if (selectedId && !list.some(c => c.value === selectedId)) {
      const found = state.list.find(c => c.value === selectedId)
      if (found) list.unshift(found)
    }
    return list
  }

  return {
    customerListLoaded: computed(() => state.loaded),
    customerListLoading: computed(() => state.loading),
    fetchCustomerList,
    filterCustomers,
    addCustomer
  }
}
