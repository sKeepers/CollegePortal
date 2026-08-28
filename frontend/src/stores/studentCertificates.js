import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '../services/api'

function rows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

/**
 * Справки студентам и их реестр.
 *
 * Списки берутся через `listAll`: реестр справок читают целиком, страница из
 * пятидесяти строк тут была бы тем же обманом, что и «1 - 20 из 20» на
 * студентах.
 */
export const useStudentCertificatesStore = defineStore('studentCertificates', () => {
  const certificates = ref([])
  const registryYears = ref([])
  const students = ref([])
  const groups = ref([])
  const filters = ref({ year: null, group_id: null, number: null })
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const lastIssued = ref([])

  const groupOptions = computed(() => groups.value.map((group) => ({ label: group.name, value: group.id })))

  const studentOptions = computed(() => students.value.map((student) => ({
    label: [student.last_name, student.first_name, student.middle_name].filter(Boolean).join(' ')
      + (student.group?.name ? ` — ${student.group.name}` : ''),
    value: student.id,
  })))

  const yearOptions = computed(() => registryYears.value.map((year) => ({ label: String(year), value: year })))

  async function load() {
    loading.value = true
    error.value = ''

    try {
      const query = {}
      // Номер отменяет остальные отборы на стороне сервера: он единственен, и
      // год с группой при найденном номере только мешают.
      if (filters.value.number) query.number = filters.value.number
      if (filters.value.year) query.year = filters.value.year
      if (filters.value.group_id) query.group_id = filters.value.group_id

      const [registry, studentList, groupList] = await Promise.all([
        api.get('student-certificates/registry', query),
        api.listAll('students'),
        api.listAll('groups'),
      ])

      certificates.value = rows(registry)
      registryYears.value = registry?.meta?.years || []
      students.value = rows(studentList)
      groups.value = rows(groupList)
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить реестр справок'
    } finally {
      loading.value = false
    }
  }

  async function issue(studentId, copies = 2) {
    saving.value = true
    error.value = ''

    try {
      const response = await api.create(`students/${studentId}/certificates`, { copies })
      lastIssued.value = rows(response)
      await load()
      return lastIssued.value
    } catch (err) {
      error.value = err.message || 'Не удалось выдать справку'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function markReceived(certificate, receivedOn) {
    saving.value = true
    error.value = ''

    try {
      await api.patch(`student-certificates/${certificate.id}/received`, { received_on: receivedOn || null })
      await load()
    } catch (err) {
      error.value = err.message || 'Не удалось отметить получение'
      throw err
    } finally {
      saving.value = false
    }
  }

  function setFilters(next) {
    filters.value = { ...filters.value, ...next }
    return load()
  }

  return {
    certificates, registryYears, students, groups, filters, loading, saving, error, lastIssued,
    groupOptions, studentOptions, yearOptions,
    load, issue, markReceived, setFilters,
  }
})
