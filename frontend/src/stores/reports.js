import { computed, reactive, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { useAuthStore } from './auth'

function rows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
function isoDate(date) { return date.toISOString().slice(0, 10) }

function defaultPeriod() {
  const to = new Date()
  const from = new Date(to)
  from.setDate(to.getDate() - 30)
  return { date_from: isoDate(from), date_to: isoDate(to) }
}

/**
 * Раздел «Отчёты».
 *
 * До 10.08.2026 раздел открывал заглушку с обещанием перенести его «на
 * следующих этапах», хотя четыре отчёта на бэкенде работали с rc2: посещаемость
 * и оценки по группе с выгрузками, выгрузки журнала по группе и по
 * преподавателю, кадровая выгрузка отсутствий. Право «Журнал: экспорт» было
 * выдано пяти ролям и не вело никуда.
 *
 * Справочники грузятся по отдельности и переживают отказ: у части ролей нет
 * права на дисциплины, и отчёт по посещаемости не должен из-за этого падать
 * целиком.
 */
export const useReportsStore = defineStore('reports', () => {
  const groups = ref([])
  const subjects = ref([])
  const teachers = ref([])
  const attendanceReport = ref(null)
  const gradesReport = ref(null)
  const loading = ref(false)
  const exporting = ref('')
  const error = ref('')

  const attendanceFilters = reactive({ group_id: '', ...defaultPeriod() })
  const gradesFilters = reactive({ group_id: '', subject_id: '', ...defaultPeriod() })
  const journalFilters = reactive({ group_id: '', teacher_id: '', ...defaultPeriod() })
  const absenceFilters = reactive({ ...defaultPeriod() })

  const groupOptions = computed(() => groups.value.map((group) => ({ label: group.name, value: group.id })))
  const subjectOptions = computed(() => subjects.value.map((subject) => ({ label: subject.name, value: subject.id })))
  const teacherOptions = computed(() => teachers.value.map((teacher) => ({
    label: [teacher.last_name, teacher.first_name, teacher.middle_name].filter(Boolean).join(' '),
    value: teacher.id,
  })))

  const canExportJournal = computed(() => useAuthStore().can('journal.export'))
  const canExportAbsences = computed(() => useAuthStore().can('hr.reports.view'))
  const canReadSubjects = computed(() => useAuthStore().can('subjects.view'))

  async function loadDictionaries() {
    const [groupsPayload, subjectsPayload, teachersPayload] = await Promise.all([
      api.list('groups').catch(() => null),
      canReadSubjects.value ? api.list('subjects').catch(() => null) : Promise.resolve(null),
      canExportJournal.value ? api.list('teachers', { active_only: 1 }).catch(() => null) : Promise.resolve(null),
    ])

    groups.value = rows(groupsPayload)
    subjects.value = rows(subjectsPayload)
    teachers.value = rows(teachersPayload)
  }

  function query(filters) {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null && value !== undefined))
  }

  async function loadAttendance() {
    if (!attendanceFilters.group_id) {
      error.value = 'Выберите группу.'
      return
    }

    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('reports/attendance-by-group', query(attendanceFilters))
      attendanceReport.value = payload?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось построить отчёт по посещаемости'
    } finally {
      loading.value = false
    }
  }

  async function loadGrades() {
    if (!gradesFilters.group_id || !gradesFilters.subject_id) {
      error.value = 'Выберите группу и дисциплину.'
      return
    }

    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('reports/grades-by-group', query(gradesFilters))
      gradesReport.value = payload?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось построить отчёт по оценкам'
    } finally {
      loading.value = false
    }
  }

  /**
   * Скачивание одним путём для всех четырёх выгрузок: имя файла и адрес
   * приходят снаружи, остальное одинаково.
   */
  async function download(key, path, filters, filename) {
    exporting.value = key
    error.value = ''
    try {
      const params = new URLSearchParams(query(filters))
      const blob = await api.download(`/${path}?${params.toString()}`)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
    } catch (err) {
      error.value = err.message || 'Файл не удалось скачать'
      throw err
    } finally {
      exporting.value = ''
    }
  }

  function exportAttendance() {
    return download('attendance', 'reports/attendance-by-group/export', attendanceFilters, `attendance-${attendanceFilters.date_from}.csv`)
  }

  function exportGrades() {
    return download('grades', 'reports/grades-by-group/export', gradesFilters, `grades-${gradesFilters.date_from}.csv`)
  }

  function exportJournalGroup() {
    return download('journal-group', 'journal/export/group.csv', { group_id: journalFilters.group_id, date_from: journalFilters.date_from, date_to: journalFilters.date_to }, `journal-group-${journalFilters.date_from}.csv`)
  }

  function exportJournalTeacher() {
    return download('journal-teacher', 'journal/export/teacher.csv', { teacher_id: journalFilters.teacher_id, date_from: journalFilters.date_from, date_to: journalFilters.date_to }, `journal-teacher-${journalFilters.date_from}.csv`)
  }

  function exportAbsences() {
    return download('absences', 'hr/reports/absences.csv', absenceFilters, `hr-absences-${absenceFilters.date_from}.csv`)
  }

  return {
    groups, subjects, teachers, attendanceReport, gradesReport, loading, exporting, error,
    attendanceFilters, gradesFilters, journalFilters, absenceFilters,
    groupOptions, subjectOptions, teacherOptions,
    canExportJournal, canExportAbsences, canReadSubjects,
    loadDictionaries, loadAttendance, loadGrades,
    exportAttendance, exportGrades, exportJournalGroup, exportJournalTeacher, exportAbsences,
  }
})
