import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

export const ENTITY_OPTIONS = [
  { label: 'Студент', value: 'student' },
  { label: 'Преподаватель', value: 'teacher' },
]

export const STATUS_OPTIONS = [
  { label: 'Активен', value: 'active', tone: 'success' },
  { label: 'Приостановлен', value: 'suspended', tone: 'warning' },
  { label: 'Отозван', value: 'revoked', tone: 'danger' },
  { label: 'Истек', value: 'expired', tone: 'neutral' },
]

function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
function fullName(person) { return [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ') }
export function entityTypeLabel(type) { return ENTITY_OPTIONS.find((option) => option.value === type)?.label || type || '—' }
export function ownerName(identity) { return fullName(identity?.owner) || `${entityTypeLabel(identity?.entity_type)} #${identity?.entity_id}` }
export function statusLabel(status) { return STATUS_OPTIONS.find((option) => option.value === status)?.label || status || '—' }
export function statusTone(status) { return STATUS_OPTIONS.find((option) => option.value === status)?.tone || 'neutral' }
export function formatDateTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

export const useDigitalPassesStore = defineStore('digitalPasses', () => {
  const identities = ref([])
  const students = ref([])
  const teachers = ref([])
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const qrSvg = ref('')
  const qrValueVisible = ref(false)

  const selectedIdentity = computed(() => identities.value.find((identity) => Number(identity.id) === Number(selectedId.value)) || null)
  const studentOptions = computed(() => students.value.map((student) => ({ label: [fullName(student), student.group?.name].filter(Boolean).join(' · '), value: student.id })))
  const teacherOptions = computed(() => teachers.value.map((teacher) => ({ label: [fullName(teacher), teacher.department, teacher.position].filter(Boolean).join(' · '), value: teacher.id })))
  const ownerOptions = computed(() => ({ student: studentOptions.value, teacher: teacherOptions.value }))

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const [identitiesPayload, studentsPayload, teachersPayload] = await Promise.all([
        api.list('digital-identities'), api.list('students'), api.list('teachers'),
      ])
      identities.value = extractRows(identitiesPayload)
      students.value = extractRows(studentsPayload)
      teachers.value = extractRows(teachersPayload)
      if (selectedId.value && !selectedIdentity.value) { selectedId.value = null; qrSvg.value = '' }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить цифровые пропуска'
    } finally { loading.value = false }
  }

  async function issue(payload) {
    saving.value = true
    error.value = ''
    try {
      const response = await api.create('digital-identities/issue', {
        entity_type: payload.entity_type,
        entity_id: Number(payload.entity_id),
        expires_at: payload.expires_at || null,
      })
      await load()
      selectedId.value = response?.data?.id || null
      await loadQr()
      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось выпустить цифровой пропуск'
      throw err
    } finally { saving.value = false }
  }

  async function revoke(identity) {
    if (!identity?.id) return null
    saving.value = true
    error.value = ''
    try {
      const response = await api.create(`digital-identities/${identity.id}/revoke`, {})
      await load()
      selectedId.value = response?.data?.id || identity.id
      await loadQr()
      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось отозвать цифровой пропуск'
      throw err
    } finally { saving.value = false }
  }

  async function loadQr(identity = selectedIdentity.value) {
    qrSvg.value = ''
    if (!identity?.id) return ''
    const token = api.token()
    const response = await fetch(`${api.baseUrl}/digital-identities/${identity.id}/qr?format=svg`, {
      headers: { Accept: 'image/svg+xml', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    })
    if (!response.ok) throw new Error('Не удалось загрузить QR-код')
    qrSvg.value = await response.text()
    return qrSvg.value
  }

  async function downloadQrPng(identity = selectedIdentity.value) {
    if (!identity?.id) return null
    const token = api.token()
    const response = await fetch(`${api.baseUrl}/digital-identities/${identity.id}/qr?format=png`, {
      headers: { Accept: 'image/png', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    })
    if (!response.ok) throw new Error('Не удалось скачать PNG QR-код')
    return await response.blob()
  }

  async function select(identity) { selectedId.value = identity?.id || null; qrValueVisible.value = false; await loadQr(identity) }

  return { identities, students, teachers, selectedId, selectedIdentity, studentOptions, teacherOptions, ownerOptions, loading, saving, error, qrSvg, qrValueVisible, load, issue, revoke, loadQr, downloadQrPng, select }
})
