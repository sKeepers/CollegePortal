import { computed } from 'vue'
import { useAuthStore } from '../stores/auth'

function normalize(codes) {
  if (!codes) return []
  return Array.isArray(codes) ? codes.filter(Boolean) : [codes]
}

export function usePermissions() {
  const auth = useAuthStore()
  const permissionCodes = computed(() => auth.permissions || [])

  function hasPermission(code) {
    if (!code) return true
    return auth.can(code)
  }

  function hasAnyPermission(codes) {
    const values = normalize(codes)
    return values.length === 0 || values.some((code) => hasPermission(code))
  }

  function hasAllPermissions(codes) {
    const values = normalize(codes)
    return values.length === 0 || values.every((code) => hasPermission(code))
  }

  return {
    permissionCodes,
    hasPermission,
    hasAnyPermission,
    hasAllPermissions,
  }
}
