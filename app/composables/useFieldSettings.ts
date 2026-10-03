const allFieldKeys = [
  'individual_phone_2', 'individual_marital_status', 'individual_job',
  'individual_number_of_children', 'individual_address', 'individual_city', 'individual_note',
  'allow_passport_id',
  'company_phone_2', 'company_sector', 'company_number_of_employees',
  'company_address', 'company_city', 'company_note',
  'policy_insured_name', 'policy_insured_no',
  'chassis_no', 'engine_no', 'policy_brand',
  'policy_model', 'vehicle_year', 'policy_uavt',
  'dask_no', 'policy_network',
  'policy_no_renewal_reminder',
  'commission_as_amount',
  'branch_commission_input',
  'require_task_note',
  'policy_zeyil_checkbox',
  'import_production_type',
  'import_branch'
]

// Varsayilan olarak KAPALI gelmesi gereken alanlar
const defaultOffFields = new Set(['policy_no_renewal_reminder', 'allow_passport_id', 'commission_as_amount', 'require_task_note', 'policy_zeyil_checkbox'])

function defaultSettings(): Record<string, boolean> {
  const obj: Record<string, boolean> = {}
  allFieldKeys.forEach(k => { obj[k] = !defaultOffFields.has(k) })
  return obj
}

const useFieldSettingsState = () => {
  const settings = useState<Record<string, boolean>>('field-settings', () => defaultSettings())
  const loaded = useState('field-settings-loaded', () => false)
  const pdfParsingEnabled = useState('pdf-parsing-enabled', () => false)
  return { settings, loaded, pdfParsingEnabled }
}

export function useFieldSettings() {
  const { settings, loaded, pdfParsingEnabled } = useFieldSettingsState()

  async function fetchFieldSettings() {
    if (loaded.value) return
    try {
      const response = await fetch('/api/settings')
      const data = await response.json()
      if (data.success && data.data) {
        allFieldKeys.forEach(key => {
          if (data.data[key] !== undefined) {
            const val = data.data[key]
            settings.value[key] = val === '1' || val === 1 || val === true
          }
        })
        pdfParsingEnabled.value = !!data.data.pdf_parsing_enabled
      }
      loaded.value = true
    } catch {
      loaded.value = true
    }
  }

  async function saveFieldSettings() {
    const { token } = useAuth()
    const payload: Record<string, string> = {}
    allFieldKeys.forEach(key => {
      payload[key] = settings.value[key] ? '1' : '0'
    })

    const response = await fetch('/api/settings', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token.value}`
      },
      body: JSON.stringify(payload)
    })

    const data = await response.json()
    if (!response.ok || !data.success) {
      throw new Error(data.message || 'Ayarlar kaydedilemedi')
    }
  }

  function isFieldEnabled(key: string): boolean {
    return settings.value[key] !== false
  }

  return {
    settings,
    loaded,
    pdfParsingEnabled,
    fetchFieldSettings,
    saveFieldSettings,
    isFieldEnabled
  }
}
