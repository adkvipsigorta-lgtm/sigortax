export interface CustomerCategory {
  id: number
  name: string
  color: string
  isDefault: boolean
  description?: string
  minAmount?: number | null
  maxAmount?: number | null
  customerCount?: number
}

const categories = ref<CustomerCategory[]>([])
const totalCustomers = ref(0)
const loaded = ref(false)

export function useCustomerCategories() {
  const { get } = useApi()

  async function fetchCategories(force = false) {
    if (loaded.value && !force) return
    try {
      const res = await get('customer-categories', { system: 1 })
      categories.value = res.data?.categories || res.data || []
      totalCustomers.value = res.data?.totalCustomers ?? 0
      loaded.value = true
    } catch {
      // Fallback
      categories.value = [
        { id: 1, name: 'Standart', color: '#3B82F6', isDefault: true },
        { id: 2, name: 'Kurumsal', color: '#06B6D4', isDefault: false },
        { id: 3, name: 'Potansiyel', color: '#22C55E', isDefault: false }
      ]
      loaded.value = true
    }
  }

  function getDefault() {
    return categories.value.find(c => c.isDefault) || categories.value[0]
  }

  function getCategoryById(id?: number) {
    if (!id) return null
    return categories.value.find(c => c.id === id) || null
  }

  return {
    categories,
    totalCustomers,
    fetchCategories,
    getDefault,
    getCategoryById
  }
}
