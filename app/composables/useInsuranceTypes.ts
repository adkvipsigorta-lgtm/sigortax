import type { InsuranceType } from '~/types'

const insurances = ref<InsuranceType[]>([])
const fetched = ref(false)

export function useInsuranceTypes() {
  const { get } = useApi()

  async function fetchInsurances() {
    if (fetched.value) return
    try {
      const res = await get<any>('insurance-types?all=1')
      insurances.value = res.data || []
      fetched.value = true
    } catch {}
  }

  const subcategories = computed(() =>
    insurances.value.filter(i => i.level === 'subcategory' && i.isActive)
  )

  const groupedSubcategories = computed(() => {
    const groups: Record<string, InsuranceType[]> = {}
    for (const i of subcategories.value) {
      const g = i.branchGroup || 'DİĞER'
      if (!groups[g]) groups[g] = []
      groups[g].push(i)
    }
    // Sort items within each group
    for (const g in groups) {
      groups[g].sort((a, b) => a.name.localeCompare(b.name, 'tr'))
    }
    return groups
  })

  return { insurances, subcategories, groupedSubcategories, fetchInsurances }
}
