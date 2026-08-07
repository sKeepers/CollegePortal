import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = {
  search: '',
  status: '',
}

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function cleanPayload(payload) {
  return {
    name: payload.name?.trim() || '',
    email: payload.email?.trim() || '',
    password: payload.password?.trim() || undefined,
    role_id: payload.role_id || null,
    is_active: Boolean(payload.is_active),
    person_type: payload.person_type || null,
    person_id: payload.person_id || null,
  }
}

export const useUsersStore = defineStore('users', () => {
  const users = ref([])
  const roles = ref([])
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  const selectedUser = computed(() => users.value.find((user) => Number(user.id) === Number(selectedId.value)) || null)

  const roleOptions = computed(() => roles.value.map((role) => ({
    label: role.name,
    value: role.id,
    code: role.code,
  })))

  const statusOptions = [
    { label: 'Активные', value: 'active' },
    { label: 'Заблокированные', value: 'blocked' },
  ]

  /*
   * Поиск людей для форм создания учетной записи. Раньше в обеих формах нужно
   * было ввести числовой ID профиля, а взять его было неоткуда.
   */
  const PROFILE_SOURCES = {
    person: { resource: 'people', label: (row) => [row.last_name, row.first_name, row.middle_name].filter(Boolean).join(' ') },
    student: { resource: 'students', label: (row) => [row.last_name, row.first_name, row.middle_name].filter(Boolean).join(' ') + (row.group?.name ? ` · ${row.group.name}` : '') },
    teacher: { resource: 'teachers', label: (row) => [row.last_name, row.first_name, row.middle_name].filter(Boolean).join(' ') + (row.position ? ` · ${row.position}` : '') },
    employee: { resource: 'employees', label: (row) => (row.full_name || '').trim() + (row.employee_number ? ` · ${row.employee_number}` : '') },
  }

  async function searchProfiles(profileType, query = '') {
    const source = PROFILE_SOURCES[profileType]
    if (!source) {
      return []
    }

    const payload = await api.list(source.resource, { search: query, per_page: 20 })
    const rows = Array.isArray(payload?.data) ? payload.data : []

    return rows.map((row) => ({
      label: source.label(row) || `#${row.id}`,
      value: row.id,
      // Форма создания подставляет ФИО и почту из карточки, чтобы не вводить их заново.
      fullName: [row.last_name, row.first_name, row.middle_name].filter(Boolean).join(' ') || row.full_name || '',
      email: row.email || row.person?.email || '',
    }))
  }

  const personTypeOptions = [
    { label: 'Не связана', value: null },
    // Портал сам связывает учетную запись с личной карточкой, поэтому этот тип
    // должен быть в списке: без него в поле показывался служебный код «person».
    { label: 'Личная карточка', value: 'person' },
    { label: 'Студент', value: 'student' },
    { label: 'Преподаватель', value: 'teacher' },
    { label: 'Сотрудник', value: 'employee' },
    { label: 'Абитуриент', value: 'applicant' },
    { label: 'Гость', value: 'guest' },
    { label: 'Выпускник', value: 'alumni' },
  ]

  async function load() {
    loading.value = true
    error.value = ''

    try {
      const [usersPayload, rolesPayload] = await Promise.all([
        api.list('admin/users', { search: filters.value.search, status: filters.value.status, per_page: 200 }),
        api.list('admin/users/roles'),
      ])
      users.value = extractRows(usersPayload)
      roles.value = extractRows(rolesPayload)

      if (selectedId.value && !selectedUser.value) {
        selectedId.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить пользователей'
    } finally {
      loading.value = false
    }
  }

  async function save(payload, id = null) {
    saving.value = true
    error.value = ''

    try {
      const data = cleanPayload(payload)
      if (id && !data.password) {
        delete data.password
      }
      const response = id
        ? await api.update('admin/users', id, data)
        : await api.create('admin/users', data)

      await load()
      selectedId.value = response?.data?.id || id
      return response?.data || null
    } catch (err) {
      if (err.status !== 422 || !err.errors) {
        error.value = err.message || 'Не удалось сохранить пользователя'
      }
      throw err
    } finally {
      saving.value = false
    }
  }

  async function remove(user) {
    if (!user?.id) return
    saving.value = true
    error.value = ''

    try {
      await api.delete('admin/users', user.id)
      if (Number(selectedId.value) === Number(user.id)) {
        selectedId.value = null
      }
      await load()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить пользователя'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function block(user) {
    if (!user?.id) return null
    saving.value = true
    error.value = ''

    try {
      const payload = await api.create(`admin/users/${user.id}/block`, {})
      await load()
      selectedId.value = payload?.data?.id || user.id
      return payload?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось заблокировать пользователя'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function unblock(user) {
    if (!user?.id) return null
    saving.value = true
    error.value = ''

    try {
      const payload = await api.create(`admin/users/${user.id}/unblock`, {})
      await load()
      selectedId.value = payload?.data?.id || user.id
      return payload?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось разблокировать пользователя'
      throw err
    } finally {
      saving.value = false
    }
  }


  async function assignRoles(user, roleIds, primaryRoleId = null) {
    if (!user?.id || !roleIds?.length) return null
    saving.value = true
    error.value = ''

    try {
      const payload = await api.create(`admin/users/${user.id}/roles`, {
        role_ids: roleIds,
        primary_role_id: primaryRoleId || roleIds[0],
      })
      await load()
      selectedId.value = payload?.data?.id || user.id
      return payload?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось назначить роли'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function provision(profileType, profileId) {
    saving.value = true
    error.value = ''
    try {
      const response = await api.create('admin/users/provision', { profile_type: profileType, profile_id: Number(profileId) })
      await load()
      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось создать учетную запись'
      throw err
    } finally {
      saving.value = false
    }
  }

  function resetFilters() {
    filters.value = { ...initialFilters }
  }

  return {
    users,
    roles,
    filters,
    selectedId,
    loading,
    saving,
    error,
    selectedUser,
    roleOptions,
    statusOptions,
    personTypeOptions,
    searchProfiles,
    load,
    save,
    remove,
    block,
    unblock,
    assignRoles,
    provision,
    resetFilters,
  }
})
