const permissions = ref<Record<string, boolean>>({})
const permissionsLoaded = ref(false)

export function usePermissions() {
  const { get } = useApi()

  async function loadPermissions() {
    if (permissionsLoaded.value) return
    try {
      const res = await get<any>('users/my-permissions')
      permissions.value = res.data || {}
      permissionsLoaded.value = true
    } catch {
      permissions.value = {}
    }
  }

  function can(key: string): boolean {
    // Henuz yuklenmemisse izin ver (SSR/ilk render icin)
    if (!permissionsLoaded.value) return true
    return permissions.value[key] !== false
  }

  function resetPermissions() {
    permissions.value = {}
    permissionsLoaded.value = false
  }

  return {
    permissions,
    permissionsLoaded,
    loadPermissions,
    can,
    resetPermissions,
  }
}
