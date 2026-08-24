import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function rows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }

export const roleLabels = {
  admin: 'Администратор', director: 'Директор', deputy: 'Заместитель директора', study: 'Учебная часть', admission: 'Приёмная комиссия', teacher: 'Преподаватель', security: 'Охрана', student: 'Студент',
}

export const statusLabels = {
  not_started: 'Не начато', in_progress: 'В работе', completed: 'Завершено', passed: 'Пройдено', failed: 'Ошибка', blocked: 'Блокировано', skipped: 'Пропущено', new: 'Новое', confirmed: 'Подтверждено', fixed: 'Исправлено', rejected: 'Отклонено', retest: 'Повторная проверка', closed: 'Закрыто',
}

export function statusTone(status) {
  return { passed: 'success', completed: 'success', failed: 'danger', blocked: 'warning', skipped: 'neutral', in_progress: 'info', new: 'warning', confirmed: 'info', fixed: 'success', rejected: 'neutral', retest: 'warning', closed: 'success' }[status] || 'neutral'
}

export const useUatStore = defineStore('uat', () => {
  const config = ref({ roles: [], scenarios: {}, accounts: [] })
  const runs = ref([])
  const feedback = ref([])
  const selectedRunId = ref(null)
  const loading = ref(false)
  const error = ref('')

  const selectedRun = computed(() => runs.value.find((run) => Number(run.id) === Number(selectedRunId.value)) || runs.value[0] || null)
  const selectedScenarios = computed(() => config.value.scenarios?.[selectedRun.value?.role_code] || [])
  const scenariosByCode = computed(() => Object.fromEntries(selectedScenarios.value.map((scenario) => [scenario.code, scenario])))
  const accountOptions = computed(() => (config.value.accounts || []).filter((item) => item.exists).map((item) => ({ label: `${item.email} · ${roleLabels[item.role] || item.role}`, value: item.user_id })))
  const roleOptions = computed(() => (config.value.roles || []).map((role) => ({ label: roleLabels[role] || role, value: role })))

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const [configPayload, runsPayload, feedbackPayload] = await Promise.all([
        api.get('admin/uat/config'),
        api.list('admin/uat/runs', { per_page: 100 }),
        api.list('admin/uat/feedback', { per_page: 100 }),
      ])
      config.value = configPayload
      runs.value = rows(runsPayload)
      feedback.value = rows(feedbackPayload)
      if (!selectedRunId.value && runs.value[0]) selectedRunId.value = runs.value[0].id
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить UAT'
    } finally {
      loading.value = false
    }
  }

  async function createRun(data) {
    const payload = await api.post('admin/uat/runs', data)
    runs.value = [payload.data, ...runs.value]
    selectedRunId.value = payload.data.id
  }

  async function updateResult(runId, resultId, data) {
    const form = new FormData()
    Object.entries(data).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') form.append(key, value)
    })
    const payload = await api.upload(`/admin/uat/runs/${runId}/results/${resultId}`, form)
    runs.value = runs.value.map((run) => Number(run.id) === Number(payload.data.id) ? payload.data : run)
  }

  async function completeRun(runId, summary) {
    const payload = await api.post(`admin/uat/runs/${runId}/complete`, { summary })
    runs.value = runs.value.map((run) => Number(run.id) === Number(payload.data.id) ? payload.data : run)
  }

  async function updateFeedback(id, data) {
    const payload = await api.put(`admin/uat/feedback/${id}`, data)
    feedback.value = feedback.value.map((item) => Number(item.id) === Number(payload.data.id) ? payload.data : item)
  }

  return { config, runs, feedback, selectedRunId, selectedRun, selectedScenarios, scenariosByCode, accountOptions, roleOptions, loading, error, load, createRun, updateResult, completeRun, updateFeedback }
})
