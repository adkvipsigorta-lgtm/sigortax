interface PaginatedResponse<T> {
  data: T[]
  total: number
}

interface PaginatedOptions {
  endpoint: string
  defaultLimit?: number
  defaultSort?: string
  defaultOrder?: 'asc' | 'desc'
  extraParams?: Record<string, any>
  immediate?: boolean
}

// Global loading sayacı — tüm usePaginatedData örnekleri arasında paylaşılır
const globalLoadingCount = useState('paginated-loading-count', () => 0)
export const useGlobalLoading = () => computed(() => globalLoadingCount.value > 0)

export function usePaginatedData<T = any>(options: PaginatedOptions) {
  const { get } = useApi()

  const data = ref<T[]>([]) as Ref<T[]>
  const total = ref(0)
  const page = ref(1)
  const limit = ref(options.defaultLimit || 15)
  const search = ref('')
  const sortBy = ref(options.defaultSort || '')
  const sortOrder = ref<'asc' | 'desc'>(options.defaultOrder || 'desc')
  const loading = ref(options.immediate !== false)
  const filters = ref<Record<string, any>>({})

  const totalPages = computed(() => Math.max(1, Math.ceil(total.value / limit.value)))

  async function fetchData() {
    loading.value = true
    globalLoadingCount.value++
    try {
      const query: Record<string, any> = {
        page: page.value,
        limit: limit.value,
        ...options.extraParams,
        ...filters.value
      }
      if (search.value) query.search = search.value
      if (sortBy.value) {
        query.sort = sortBy.value
        query.order = sortOrder.value
      }
      for (const key of Object.keys(query)) {
        if (query[key] === '' || query[key] === undefined || query[key] === null) {
          delete query[key]
        }
      }

      const res = await get<any>(options.endpoint, query)
      data.value = res.data || []
      total.value = res.pagination?.total ?? res.total ?? data.value.length
    } catch (e) {
      console.error(`${options.endpoint} verileri yüklenemedi:`, e)
      data.value = []
      total.value = 0
    } finally {
      loading.value = false
      globalLoadingCount.value = Math.max(0, globalLoadingCount.value - 1)
    }
  }

  function setPage(p: number) {
    page.value = p
    fetchData()
  }

  function setSort(field: string, order?: 'asc' | 'desc') {
    if (order) {
      sortBy.value = field
      sortOrder.value = order
    } else if (sortBy.value === field) {
      sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
    } else {
      sortBy.value = field
      sortOrder.value = 'asc'
    }
    page.value = 1
    fetchData()
  }

  function setSearch(q: string) {
    search.value = q
    page.value = 1
    fetchData()
  }

  function setFilter(key: string, value: any) {
    filters.value[key] = value
    page.value = 1
    fetchData()
  }

  function setFilters(newFilters: Record<string, any>) {
    filters.value = { ...newFilters }
    page.value = 1
    fetchData()
  }

  function refresh() {
    fetchData()
  }

  return {
    data,
    total,
    page,
    limit,
    search,
    sortBy,
    sortOrder,
    loading,
    filters,
    totalPages,
    fetchData,
    setPage,
    setSort,
    setSearch,
    setFilter,
    setFilters,
    refresh
  }
}
