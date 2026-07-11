import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

export const usePermissionsStore = defineStore('permissions', () => {
  const permissions = ref([])
  const roles = ref([])
  const search = ref('')
  const module = ref('')
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  const selectedPermission = computed(() => permissions.value.find((item) => Number(item.id) === Number(selectedId.value)) || permissions.value[0] || null)
  const modules = computed(() => Array.from(new Set(permissions.value.map((item) => item.module).filter(Boolean))).sort())
  const moduleOptions = computed(() => [{ label: 'Все модули', value: '' }, ...modules.value.map((value) => ({ label: value, value }))])
  const roleOptions = computed(() => roles.value.map((role) => ({ label: role.name, value: role.id, code: role.code })))
  const selectedRoleIds = computed(() => selectedPermission.value?.roles?.map((role) => role.id) || [])

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const [permissionsPayload, rolesPayload] = await Promise.all([
        api.list('admin/permissions', { search: search.value, module: module.value }),
        api.list('admin/permissions/roles/list'),
      ])
      permissions.value = extractRows(permissionsPayload)
      roles.value = extractRows(rolesPayload)
      if (!selectedId.value && permissions.value[0]) selectedId.value = permissions.value[0].id
      if (selectedId.value && !permissions.value.some((item) => Number(item.id) === Number(selectedId.value))) selectedId.value = permissions.value[0]?.id || null
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить разрешения'
    } finally {
      loading.value = false
    }
  }

  async function assignRoles(permission, roleIds) {
    if (!permission?.id) return null
    saving.value = true
    error.value = ''
    try {
      const payload = await api.create(`admin/permissions/${permission.id}/roles`, { role_ids: roleIds })
      await load()
      selectedId.value = payload?.data?.id || permission.id
      return payload?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось назначить роли'
      throw err
    } finally {
      saving.value = false
    }
  }

  return {
    permissions,
    roles,
    search,
    module,
    selectedId,
    loading,
    saving,
    error,
    selectedPermission,
    moduleOptions,
    roleOptions,
    selectedRoleIds,
    load,
    assignRoles,
  }
})
