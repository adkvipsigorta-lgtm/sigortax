import { reactive, computed } from 'vue'

interface CustomerOption {
  label: string
  value: number
}

const DEFAULT_LIMIT = 100

const state = reactive({
  list:         [] as CustomerOption[],
  loaded:       false,
  loading:      false,
  limit:        DEFAULT_LIMIT,
  currentTerm:  '',
})

export function useCustomerSearch() {
  const { get } = useApi()

  async function initList() {
    if (state.loaded || state.loading) return
    state.loading = true
    try {
      const res = await get<any>('customers/list-all')
      state.list = (res.data || []).map((c: any) => ({
        label: c.identityNo ? `${c.name} (${c.identityNo})` : c.name,
        value: c.id,
      }))
      state.loaded = true
    } catch {}
    state.loading = false
  }

  function addCustomer(customer: { id: number; name: string; identityNo?: string }) {
    const label = customer.identityNo
      ? `${customer.name} (${customer.identityNo})`
      : customer.name
    if (!state.list.some(c => c.value === customer.id)) {
      state.list.unshift({ label, value: customer.id })
    }
  }

  /** Arama terimi değişince limit sıfırla */
  function resetLimit() {
    state.limit = DEFAULT_LIMIT
  }

  /** Bir sonraki 100'ü göster */
  function loadMore() {
    state.limit += DEFAULT_LIMIT
  }

  function getOptions(term: string, selectedId?: number): CustomerOption[] {
    const t = term.trim().toLowerCase()
    state.currentTerm = t

    const filtered = t
      ? state.list.filter(c => c.label.toLowerCase().includes(t))
      : state.list

    const sliced = filtered.slice(0, state.limit)

    // Seçili müşteri gösterilmiyorsa başa ekle
    if (selectedId && !sliced.some(c => c.value === selectedId)) {
      const found = state.list.find(c => c.value === selectedId)
      if (found) sliced.unshift(found)
    }

    return sliced
  }

  // Filtrelenmiş toplam > limit ise daha fazla var
  const hasMore = computed(() => {
    const t = state.currentTerm
    const total = t
      ? state.list.filter(c => c.label.toLowerCase().includes(t)).length
      : state.list.length
    return total > state.limit
  })

  return {
    customerLoading: computed(() => state.loading),
    hasMore,
    initList,
    loadMore,
    resetLimit,
    addCustomer,
    getOptions,
  }
}
