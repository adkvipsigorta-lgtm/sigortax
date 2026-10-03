export interface AgencyInfo {
  name: string
  logo: string | null
  description: string
}

const useAgencyState = () => {
  const agency = useState<AgencyInfo>('agency-info', () => ({
    name: 'Sigorta Takip',
    logo: null,
    description: 'Hesabınıza giriş yapın'
  }))
  const loaded = useState('agency-loaded', () => false)
  return { agency, loaded }
}

export function useAgency() {
  const { agency, loaded } = useAgencyState()

  async function fetchAgency() {
    if (loaded.value) return
    try {
      const { token } = useAuth()
      const response = await fetch('/api/settings', {
        headers: token.value ? { Authorization: `Bearer ${token.value}` } : {}
      })
      const data = await response.json()
      if (data.success && data.data) {
        agency.value = {
          name: data.data.agency_name || 'Sigorta Takip',
          logo: data.data.agency_logo || null,
          description: data.data.agency_description || 'Hesabınıza giriş yapın'
        }
      }
      loaded.value = true
    } catch {
      // silent fail, defaults kalsin
      loaded.value = true
    }
  }

  function setAgency(info: Partial<AgencyInfo>) {
    agency.value = { ...agency.value, ...info }
  }

  return {
    agency,
    loaded,
    fetchAgency,
    setAgency
  }
}
