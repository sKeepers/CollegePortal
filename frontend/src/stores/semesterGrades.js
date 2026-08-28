import { computed, reactive, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { buildGroupOptions } from '../utils/groupOptions'

/**
 * Ведомость итоговых оценок за семестр.
 *
 * Это не журнал: в журнале оценка за занятие, здесь — итог дисциплины, который ставит
 * преподаватель и из которого потом собирается приложение к диплому. Своего счёта тут нет
 * и быть не должно: средний балл — не оценка.
 *
 * Ведомость приходит от состава группы, а не от списка оценок: преподавателю нужен весь
 * курс, включая тех, кому он ещё ничего не поставил, — иначе он не увидит, кого пропустил.
 */

/** Учебный год так же, как его считает сервер: год начинается в сентябре. */
function currentAcademicYear() {
  const now = new Date()
  const start = now.getMonth() + 1 >= 9 ? now.getFullYear() : now.getFullYear() - 1

  return `${start}/${start + 1}`
}

/** Семестр так же, как его считает сервер: сентябрь-январь — первый. */
function currentSemester() {
  const month = new Date().getMonth() + 1

  return month >= 9 || month <= 1 ? 1 : 2
}

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : (payload?.data?.data || [])
}

export const useSemesterGradesStore = defineStore('semesterGrades', () => {
  const groups = ref([])
  const subjects = ref([])
  const students = ref([])
  const controlType = ref(null)

  const referencesLoading = ref(false)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const notice = ref('')

  const filters = reactive({
    group_id: null,
    subject_id: null,
    academic_year: currentAcademicYear(),
    semester: currentSemester(),
  })

  const groupOptions = computed(() => buildGroupOptions(groups.value))
  const subjectOptions = computed(() => subjects.value.map((subject) => ({
    label: [subject.code, subject.name].filter(Boolean).join(' · '),
    value: subject.id,
  })))
  const semesterOptions = [1, 2, 3, 4, 5, 6, 7, 8].map((n) => ({ label: `${n} семестр`, value: n }))

  const ready = computed(() => Boolean(filters.group_id && filters.subject_id && filters.academic_year && filters.semester))
  const filled = computed(() => students.value.filter((row) => String(row.value || '').trim() !== '').length)

  async function loadReferences() {
    referencesLoading.value = true
    error.value = ''

    try {
      const [groupsPayload, subjectsPayload] = await Promise.all([
        api.listAll('groups'),
        api.listAll('subjects'),
      ])
      groups.value = extractRows(groupsPayload)
      subjects.value = extractRows(subjectsPayload)
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить справочники'
    } finally {
      referencesLoading.value = false
    }
  }

  async function loadSheet() {
    if (!ready.value) return

    loading.value = true
    error.value = ''
    notice.value = ''

    try {
      const payload = await api.get('semester-grades', { ...filters })
      const data = payload?.data || {}
      controlType.value = data.control_type || null
      // Строки правятся на месте, поэтому каждая получает собственную копию значений:
      // без этого правка одной клетки меняла бы ответ сервера, и «отменить» стало бы нечем.
      students.value = (data.students || []).map((row) => ({ ...row, value: row.value ?? '' }))
    } catch (err) {
      error.value = err.status === 403
        ? 'Ведомость этой группы вам недоступна: вы не ведёте в ней занятий и не курируете её.'
        : (err.message || 'Не удалось загрузить ведомость')
      students.value = []
    } finally {
      loading.value = false
    }
  }

  async function save() {
    if (!ready.value || students.value.length === 0) return false

    saving.value = true
    error.value = ''
    notice.value = ''

    try {
      const payload = await api.post('semester-grades', {
        ...filters,
        grades: students.value.map((row) => ({
          student_id: row.student_id,
          value: String(row.value || '').trim(),
          comment: row.comment || null,
        })),
      })
      const result = payload?.data || {}
      notice.value = `Сохранено: ${result.saved ?? 0}, снято: ${result.removed ?? 0}`
      await loadSheet()

      return true
    } catch (err) {
      error.value = err.status === 403
        ? 'Итоговую оценку по дисциплине ставит преподаватель, который её вёл, или учебная часть.'
        : (err.message || 'Не удалось сохранить ведомость')

      return false
    } finally {
      saving.value = false
    }
  }

  return {
    groups, subjects, students, controlType,
    referencesLoading, loading, saving, error, notice,
    filters, groupOptions, subjectOptions, semesterOptions, ready, filled,
    loadReferences, loadSheet, save,
  }
})
