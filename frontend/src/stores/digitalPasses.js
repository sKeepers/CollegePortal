import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { loadReferences } from '../services/referenceLoader'
import { formatDate } from '../utils/datetime'

export const ENTITY_OPTIONS = [
  { label: 'Студент', value: 'student' },
  { label: 'Преподаватель', value: 'teacher' },
  { label: 'Сотрудник', value: 'employee' },
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
/**
 * Подпись владельца пропуска.
 *
 * Случаев три, и до 24.08.2026 они были смешаны в один. Имя есть — пишем имя.
 * Сервер ответил `owner: null` — владельца НЕТ, и место имени занимает прямая
 * надпись: «Преподаватель #77» читается как преподаватель с номером, ровно так
 * же, как «0» на экране коменданта читается как «все на месте». А если ключа
 * `owner` в ответе нет вовсе, связь просто не запрашивали — там «Вид #номер»
 * уместен: это техническая заглушка, и номер в ней настоящий.
 *
 * Различить их можно только потому, что `DigitalIdentityResource` отдаёт ключ
 * `owner` всегда. Уберут ключ ради экономии ответа — отсутствие снова станет
 * неотличимо от умолчания.
 */
export function ownerName(identity) {
  if (!identity) return '—'
  const name = fullName(identity.owner)
  if (name) return name
  if (ownerMissing(identity)) return `${entityTypeLabel(identity.entity_type)}: владелец удалён`
  return `${entityTypeLabel(identity.entity_type)} #${identity.entity_id}`
}

/** Сервер сказал, что владельца нет, — а не промолчал о нём. */
export function ownerMissing(identity) {
  return Boolean(identity) && 'owner' in identity && !identity.owner
}

/**
 * Значение для поиска по журналу проходов.
 *
 * У пропуска без владельца его нет: журнал ищет по ФИО, а искать нечего.
 * Подставить сюда номер значило бы снова выдать отсутствие за данные, поэтому
 * возвращается `null`, а вызывающий убирает переход.
 */
export function ownerSearchQuery(identity) {
  return fullName(identity?.owner) || null
}
export function statusLabel(status) { return STATUS_OPTIONS.find((option) => option.value === status)?.label || status || '—' }
export function statusTone(status) { return STATUS_OPTIONS.find((option) => option.value === status)?.tone || 'neutral' }
export function formatDateTime(value) {
  // Имя досталось по наследству: показывается здесь только дата.
  return formatDate(value)
}

export const useDigitalPassesStore = defineStore('digitalPasses', () => {
  const identities = ref([])
  const students = ref([])
  const teachers = ref([])
  const employees = ref([])
  const selectedId = ref(null)
  // Список получен — только тогда его длина что-то значит. При оборванном
  // запросе экран писал «Цифровые пропуска не найдены. Выпустите первый
  // QR-пропуск» при 861 живом пропуске на стенде: замер 03.09.2026.
  const loaded = ref(false)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const qrSvg = ref('')
  const qrExpiresAt = ref(null)
  const qrValueVisible = ref(false)

  const selectedIdentity = computed(() => identities.value.find((identity) => Number(identity.id) === Number(selectedId.value)) || null)
  const studentOptions = computed(() => students.value.map((student) => ({ label: [fullName(student), student.group?.name].filter(Boolean).join(' · '), value: student.id })))
  const teacherOptions = computed(() => teachers.value.map((teacher) => ({ label: [fullName(teacher), teacher.department, teacher.position].filter(Boolean).join(' · '), value: teacher.id })))
  const employeeOptions = computed(() => employees.value.map((employee) => ({ label: [employee.full_name || fullName(employee.person), employee.employee_number, employee.primary_position?.name].filter(Boolean).join(' · '), value: employee.id })))
  const ownerOptions = computed(() => ({ student: studentOptions.value, teacher: teacherOptions.value, employee: employeeOptions.value }))

  async function load(options = {}) {
    loading.value = true
    error.value = ''
    try {
      const identitiesParams = options.mine ? { mine: 1, status: options.status || 'active' } : {}
      // Пропуска — сам экран, владельцы — справочник для выдачи: охрана без прав
      // на реестры обязана видеть пропуска, а не пустой экран.
      const { payloads } = await loadReferences({
        identities: api.listAll('digital-identities', identitiesParams),
        students: options.includeOwners === false ? Promise.resolve({ data: [] }) : api.listAll('students'),
        teachers: options.includeOwners === false ? Promise.resolve({ data: [] }) : api.listAll('teachers'),
        employees: options.includeOwners === false ? Promise.resolve({ data: [] }) : api.listAll('employees'),
      })
      loaded.value = true
      identities.value = extractRows(payloads.identities)
      students.value = extractRows(payloads.students)
      teachers.value = extractRows(payloads.teachers)
      employees.value = extractRows(payloads.employees)
      if (selectedId.value && !selectedIdentity.value) { selectedId.value = null; qrSvg.value = ''; qrExpiresAt.value = null }
      if (!identities.value.length) { selectedId.value = null; qrSvg.value = ''; qrExpiresAt.value = null }
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
    qrExpiresAt.value = null
    if (!identity?.id) return ''
    const response = await api.authFetch(`${api.baseUrl}/digital-identities/${identity.id}/qr?format=svg`, {
      headers: { Accept: 'image/svg+xml' },
    })
    if (!response.ok) throw new Error('Не удалось загрузить QR-код')
    qrSvg.value = await response.text()
    qrExpiresAt.value = response.headers.get('X-QR-Expires-At')
    return qrSvg.value
  }

  async function downloadQrPng(identity = selectedIdentity.value) {
    if (!identity?.id) return null
    const response = await api.authFetch(`${api.baseUrl}/digital-identities/${identity.id}/qr?format=png`, {
      headers: { Accept: 'image/png' },
    })
    if (!response.ok) throw new Error('Не удалось скачать PNG QR-код')
    return await response.blob()
  }

  async function select(identity) { selectedId.value = identity?.id || null; qrValueVisible.value = false; await loadQr(identity) }

  return { identities, students, teachers, employees, loaded, selectedId, selectedIdentity, studentOptions, teacherOptions, employeeOptions, ownerOptions, loading, saving, error, qrSvg, qrExpiresAt, qrValueVisible, load, issue, revoke, loadQr, downloadQrPng, select }
})
