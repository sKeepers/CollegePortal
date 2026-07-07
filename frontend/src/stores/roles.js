import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function cleanRole(payload) {
  return {
    name: payload.name?.trim() || '',
    code: payload.code?.trim() || '',
    description: payload.description?.trim() || '',
  }
}

export const useRolesStore = defineStore('roles', () => {
  const roles = ref([])
  const users = ref([])
  const search = ref('')
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  const selectedRole = computed(() => roles.value.find((role) => Number(role.id) === Number(selectedId.value)) || null)
  const roleOptions = computed(() => roles.value.map((role) => ({ label: `${role.name} (${role.code})`, value: role.id })))
  const userOptions = computed(() => users.value.map((user) => ({ label: `${user.name} · ${user.email}`, value: user.id, user })))

  async function load() {
    loading.value = true
    error.value = ''

    try {
      const [rolesPayload, usersPayload] = await Promise.all([
        api.list('admin/roles', { search: search.value }),
        api.list('admin/users', { per_page: 300 }),
      ])
      roles.value = extractRows(rolesPayload)
      users.value = extractRows(usersPayload)

      if (selectedId.value && !selectedRole.value) {
        selectedId.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить роли'
    } finally {
      loading.value = false
    }
  }

  async function save(payload, id = null) {
    saving.value = true
    error.value = ''

    try {
      const data = cleanRole(payload)
      const response = id ? await api.update('admin/roles', id, data) : await api.create('admin/roles', data)
      await load()
      selectedId.value = response?.data?.id || id
      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить роль'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function remove(role) {
    if (!role?.id) return
    saving.value = true
    error.value = ''

    try {
      await api.delete('admin/roles', role.id)
      if (Number(selectedId.value) === Number(role.id)) {
        selectedId.value = null
      }
      await load()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить роль'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function assignUserRoles(userId, roleIds, primaryRoleId = null) {
    if (!userId || !roleIds?.length) return null
    saving.value = true
    error.value = ''

    try {
      const payload = await api.create(`admin/users/${userId}/roles`, {
        role_ids: roleIds,
        primary_role_id: primaryRoleId || roleIds[0],
      })
      await load()
      return payload?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось назначить роли пользователю'
      throw err
    } finally {
      saving.value = false
    }
  }

  return {
    roles,
    users,
    search,
    selectedId,
    loading,
    saving,
    error,
    selectedRole,
    roleOptions,
    userOptions,
    load,
    save,
    remove,
    assignUserRoles,
  }
})
