<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Copy, Download, ExternalLink, Maximize2, Plus, RefreshCw } from '@lucide/vue'
import AppPage from '../../../components/ui/AppPage.vue'
import PageHeader from '../../../components/ui/PageHeader.vue'
import AppToolbar from '../../../components/ui/AppToolbar.vue'
import AppErrorBanner from '../../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../../components/ui/AppStatusBadge.vue'
import AppEmptyState from '../../../components/ui/AppEmptyState.vue'
import AppCard from '../../../components/ui/AppCard.vue'
import AppFilterBar from '../../../components/ui/AppFilterBar.vue'
import { api } from '../../../services/api'
import { roleLabels, statusLabels, statusTone, useUatStore } from '../../../stores/uat'

const store = useUatStore()
const route = useRoute()
const router = useRouter()
const createDialog = ref(false)
const completeDialog = ref(false)
const screenshotDialog = ref(false)
const screenshotUrl = ref('')
const selectedFeedbackId = ref(null)
const summary = ref('')
const resultDraft = reactive({})
const newRun = reactive({ title: '', role_code: 'study', tester_user_id: null })
const filters = reactive({ status: '', category: '', severity: '', author_id: null, page: '', date_from: '', date_to: '', version: '', q: '' })
const feedbackDraft = reactive({ status: '', status_comment: '', assigned_to: null, resolution: '', github_issue_number: null, github_issue_url: '', github_issue_status: '' })
const commentDraft = reactive({ type: 'admin', comment: '' })

const feedbackStatuses = ['new', 'confirmed', 'in_progress', 'needs_info', 'fixed', 'retest', 'closed', 'rejected']
const categoryOptions = [
  { label: 'Ошибка', value: 'error' },
  { label: 'Неудобство', value: 'ux' },
  { label: 'Предложение', value: 'suggestion' },
  { label: 'Данные', value: 'data' },
  { label: 'Права доступа', value: 'access' },
]
const severityOptions = [
  { label: 'Critical', value: 'critical' },
  { label: 'High', value: 'high' },
  { label: 'Medium', value: 'medium' },
  { label: 'Low', value: 'low' },
  { label: 'UX', value: 'ux' },
]
const commentTypeOptions = [
  { label: 'Администратор', value: 'admin' },
  { label: 'Разработчик', value: 'developer' },
  { label: 'Тестировщик', value: 'tester' },
]

const selectedResults = computed(() => store.selectedRun?.results || [])
const selectedFeedback = computed(() => store.feedback.find((item) => Number(item.id) === Number(selectedFeedbackId.value)) || store.feedback[0] || null)
const progressPercent = computed(() => {
  const total = store.selectedRun?.progress?.total || 0
  const done = (store.selectedRun?.progress?.passed || 0) + (store.selectedRun?.progress?.failed || 0) + (store.selectedRun?.progress?.blocked || 0) + (store.selectedRun?.progress?.skipped || 0)
  return total ? Math.round((done / total) * 100) : 0
})
const authorOptions = computed(() => {
  const authors = new Map()
  store.feedback.forEach((item) => {
    if (item.user?.id) authors.set(item.user.id, { label: `${item.user.name || item.user.email} · ${item.user.email}`, value: item.user.id })
  })
  return [...authors.values()]
})
const versionOptions = computed(() => [...new Set(store.feedback.map((item) => item.app_version).filter(Boolean))].map((value) => ({ label: value, value })))
const feedbackUrl = computed(() => selectedFeedback.value ? `${window.location.origin}${route.path}?feedback=${selectedFeedback.value.id}` : '')
const screenshotPath = computed(() => selectedFeedback.value?.has_screenshot ? `/admin/uat/feedback/${selectedFeedback.value.id}/screenshot` : '')

function scenario(result) { return store.scenariosByCode[result.scenario_code] || { title: result.scenario_code, route: '', steps: '', expected: '' } }
function resultModel(result) {
  if (!resultDraft[result.id]) resultDraft[result.id] = { status: result.status, comment: result.comment || '', actual_result: result.actual_result || '', screenshot: null }
  return resultDraft[result.id]
}
function syncFeedbackDraft(item) {
  if (!item) return
  feedbackDraft.status = item.status || ''
  feedbackDraft.status_comment = ''
  feedbackDraft.assigned_to = item.assigned_to || null
  feedbackDraft.resolution = item.resolution || ''
  feedbackDraft.github_issue_number = item.github_issue_number || null
  feedbackDraft.github_issue_url = item.github_issue_url || ''
  feedbackDraft.github_issue_status = item.github_issue_status || ''
}
async function refresh() {
  await store.load(filters)
  const queryId = Number(route.query.feedback)
  const candidate = queryId || selectedFeedbackId.value || store.feedback[0]?.id
  if (candidate) await selectFeedback(candidate, false)
}
async function applyFilters() {
  selectedFeedbackId.value = null
  await router.replace({ path: route.path, query: {} })
  await refresh()
}
async function resetFilters() {
  Object.assign(filters, { status: '', category: '', severity: '', author_id: null, page: '', date_from: '', date_to: '', version: '', q: '' })
  await applyFilters()
}
async function createRun() {
  await store.createRun({ ...newRun, title: newRun.title || `UAT ${roleLabels[newRun.role_code] || newRun.role_code}` })
  createDialog.value = false
}
async function saveResult(result) { await store.updateResult(store.selectedRun.id, result.id, resultModel(result)) }
async function completeRun() { await store.completeRun(store.selectedRun.id, summary.value); completeDialog.value = false }
async function downloadWithAuth(path, filename, accept = 'application/octet-stream') {
  const response = await fetch(`${api.baseUrl}${path}`, {
    headers: { Accept: accept, ...(api.token() ? { Authorization: `Bearer ${api.token()}` } : {}) },
  })
  if (!response.ok) throw new Error('Файл не удалось скачать')
  const url = URL.createObjectURL(await response.blob())
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
function downloadCsv(path, filename) { return downloadWithAuth(path, filename, 'text/csv') }
async function selectFeedback(id, updateRoute = true) {
  selectedFeedbackId.value = id
  if (updateRoute) await router.replace({ path: route.path, query: { ...route.query, feedback: id } })
  const detailed = await store.loadFeedback(id)
  syncFeedbackDraft(detailed)
}
async function saveFeedback() {
  if (!selectedFeedback.value) return
  const saved = await store.updateFeedback(selectedFeedback.value.id, {
    status: feedbackDraft.status,
    status_comment: feedbackDraft.status_comment,
    assigned_to: feedbackDraft.assigned_to,
    resolution: feedbackDraft.resolution,
    github_issue_number: feedbackDraft.github_issue_number || null,
    github_issue_url: feedbackDraft.github_issue_url || null,
    github_issue_status: feedbackDraft.github_issue_status || null,
  })
  syncFeedbackDraft(saved)
}
async function addComment() {
  if (!selectedFeedback.value || !commentDraft.comment.trim()) return
  const saved = await store.addFeedbackComment(selectedFeedback.value.id, { ...commentDraft })
  commentDraft.type = 'admin'
  commentDraft.comment = ''
  syncFeedbackDraft(saved)
}
async function copyFeedbackLink() {
  if (!feedbackUrl.value) return
  await navigator.clipboard?.writeText(feedbackUrl.value)
}
async function feedbackScreenshotBlobUrl() {
  const response = await fetch(`${api.baseUrl}${screenshotPath.value}`, {
    headers: { Accept: 'image/*', ...(api.token() ? { Authorization: `Bearer ${api.token()}` } : {}) },
  })
  if (!response.ok) throw new Error('Скриншот не удалось загрузить')
  return URL.createObjectURL(await response.blob())
}
async function openScreenshot() {
  if (!screenshotPath.value) return
  if (screenshotUrl.value) URL.revokeObjectURL(screenshotUrl.value)
  screenshotUrl.value = await feedbackScreenshotBlobUrl()
  screenshotDialog.value = true
}
function closeScreenshot() {
  if (screenshotUrl.value) URL.revokeObjectURL(screenshotUrl.value)
  screenshotUrl.value = ''
}
async function downloadScreenshot() {
  if (!selectedFeedback.value) return
  await downloadWithAuth(screenshotPath.value, `uat-feedback-${selectedFeedback.value.id}-screenshot`, 'image/*')
}
function formatDate(value) {
  return value ? new Date(value).toLocaleString('ru-RU') : '—'
}

watch(selectedFeedback, syncFeedbackDraft, { immediate: true })
onMounted(refresh)
</script>

<template>
  <AppPage>
    <PageHeader title="UAT" subtitle="Закрытое пользовательское тестирование по ролям: сценарии, результаты и обращения.">
      <template #actions>
        <q-btn color="primary" @click="createDialog = true"><Plus :size="16" />Новый прогон</q-btn>
      </template>
    </PageHeader>
    <AppToolbar>
      <span>UAT Center хранит прогоны, обращения, историю статусов, комментарии и скриншоты в private storage.</span>
      <template #actions>
        <q-btn flat :loading="store.loading" @click="refresh"><RefreshCw :size="16" />Обновить</q-btn>
        <q-btn outline color="primary" @click="downloadCsv('/admin/uat/export/results.csv', 'uat-results.csv')"><Download :size="16" />Результаты CSV</q-btn>
        <q-btn outline color="primary" @click="downloadCsv('/admin/uat/export/feedback.csv', 'uat-feedback.csv')"><Download :size="16" />Обращения CSV</q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />

    <AppFilterBar>
      <q-select v-model="filters.status" dense outlined clearable emit-value map-options label="Статус" :options="feedbackStatuses.map((value) => ({ label: statusLabels[value], value }))" />
      <q-select v-model="filters.category" dense outlined clearable emit-value map-options label="Категория" :options="categoryOptions" />
      <q-select v-model="filters.severity" dense outlined clearable emit-value map-options label="Важность" :options="severityOptions" />
      <q-select v-model="filters.author_id" dense outlined clearable emit-value map-options label="Автор" :options="authorOptions" />
      <q-input v-model="filters.page" dense outlined clearable label="Страница" />
      <q-select v-model="filters.version" dense outlined clearable emit-value map-options label="Версия" :options="versionOptions" />
      <q-input v-model="filters.date_from" dense outlined type="date" label="С даты" />
      <q-input v-model="filters.date_to" dense outlined type="date" label="По дату" />
      <q-input v-model="filters.q" dense outlined clearable label="Поиск" />
      <template #actions>
        <q-btn color="primary" @click="applyFilters">Применить</q-btn>
        <q-btn flat @click="resetFilters">Сбросить</q-btn>
      </template>
    </AppFilterBar>

    <div class="uat-layout">
      <section class="uat-runs">
        <h3>Прогоны</h3>
        <button v-for="run in store.runs" :key="run.id" type="button" class="uat-run" :class="{ 'uat-run--active': Number(run.id) === Number(store.selectedRun?.id) }" @click="store.selectedRunId = run.id">
          <strong>{{ run.title }}</strong>
          <span>{{ roleLabels[run.role_code] || run.role_code }} · {{ run.tester?.email || 'тестировщик не назначен' }}</span>
          <AppStatusBadge :label="statusLabels[run.status] || run.status" :tone="statusTone(run.status)" />
        </button>
        <AppEmptyState v-if="!store.runs.length" title="Прогонов пока нет" description="Создайте первый UAT-прогон для выбранной роли." />
      </section>

      <section class="uat-main">
        <template v-if="store.selectedRun">
          <div class="uat-summary">
            <div><strong>{{ store.selectedRun.title }}</strong><span>{{ roleLabels[store.selectedRun.role_code] }}</span></div>
            <q-linear-progress :value="progressPercent / 100" color="primary" rounded />
            <div class="uat-kpis">
              <span>Progress {{ progressPercent }}%</span>
              <span>Passed {{ store.selectedRun.progress?.passed || 0 }}</span>
              <span>Failed {{ store.selectedRun.progress?.failed || 0 }}</span>
              <span>Blocked {{ store.selectedRun.progress?.blocked || 0 }}</span>
            </div>
            <q-btn color="positive" :disable="store.selectedRun.status === 'completed'" @click="completeDialog = true">Завершить тестирование</q-btn>
          </div>

          <div class="uat-scenarios">
            <article v-for="result in selectedResults" :key="result.id" class="uat-scenario">
              <div class="uat-scenario-head">
                <div>
                  <strong>{{ scenario(result).title }}</strong>
                  <span>{{ scenario(result).route }}</span>
                </div>
                <AppStatusBadge :label="statusLabels[result.status] || result.status" :tone="statusTone(result.status)" />
              </div>
              <p><b>Что сделать:</b> {{ scenario(result).steps }}</p>
              <p><b>Успех:</b> {{ scenario(result).expected }}</p>
              <div class="uat-result-grid">
                <q-select v-model="resultModel(result).status" dense outlined emit-value map-options label="Статус" :options="['not_started','passed','failed','blocked','skipped'].map((value) => ({ label: statusLabels[value], value }))" />
                <q-input v-model="resultModel(result).actual_result" dense outlined autogrow label="Фактический результат" />
                <q-input v-model="resultModel(result).comment" dense outlined autogrow label="Комментарий" />
                <q-file v-model="resultModel(result).screenshot" dense outlined clearable label="Скриншот" accept=".jpg,.jpeg,.png,.webp" />
              </div>
              <div class="uat-scenario-actions">
                <q-btn color="primary" @click="saveResult(result)">Сохранить результат</q-btn>
                <q-btn v-if="result.has_screenshot" flat color="primary" @click="downloadWithAuth(`/admin/uat/results/${result.id}/screenshot`, `uat-result-${result.id}-screenshot`, 'image/*')">Скачать скриншот</q-btn>
              </div>
            </article>
          </div>
        </template>
        <AppEmptyState v-else title="Прогон не выбран" description="Создайте или выберите UAT-прогон." />
      </section>

      <aside class="uat-feedback-list">
        <h3>Обращения</h3>
        <button v-for="item in store.feedback" :key="item.id" type="button" class="uat-feedback-item" :class="{ 'uat-feedback-item--active': Number(item.id) === Number(selectedFeedback?.id) }" @click="selectFeedback(item.id)">
          <div><strong>#{{ item.id }} {{ item.title }}</strong><span>{{ item.page_url || 'страница не указана' }}</span></div>
          <div class="uat-feedback-meta">
            <AppStatusBadge :label="statusLabels[item.status] || item.status" :tone="statusTone(item.status)" />
            <span>{{ item.severity }}</span>
          </div>
        </button>
        <AppEmptyState v-if="!store.feedback.length" title="Обращений нет" description="Измените фильтры или дождитесь первого сообщения." />
      </aside>
    </div>

    <AppCard v-if="selectedFeedback" class="uat-feedback-card" :title="`Обращение #${selectedFeedback.id}`" :subtitle="selectedFeedback.title">
      <template #actions>
        <q-btn flat round dense title="Скопировать ссылку" @click="copyFeedbackLink"><Copy :size="16" /></q-btn>
        <q-btn v-if="selectedFeedback.github_issue_url" flat round dense title="Открыть GitHub Issue" :href="selectedFeedback.github_issue_url" target="_blank"><ExternalLink :size="16" /></q-btn>
      </template>

      <div class="uat-feedback-card__grid">
        <dl class="uat-fields">
          <div><dt>Дата</dt><dd>{{ formatDate(selectedFeedback.created_at) }}</dd></div>
          <div><dt>Пользователь</dt><dd>{{ selectedFeedback.user?.name || selectedFeedback.user?.email || '—' }}</dd></div>
          <div><dt>Роль</dt><dd>{{ roleLabels[selectedFeedback.role_code] || selectedFeedback.role_code || '—' }}</dd></div>
          <div><dt>Страница</dt><dd>{{ selectedFeedback.page_url || '—' }}</dd></div>
          <div><dt>Браузер</dt><dd>{{ selectedFeedback.browser || selectedFeedback.user_agent || '—' }}</dd></div>
          <div><dt>Версия</dt><dd>{{ selectedFeedback.app_version || '—' }}</dd></div>
          <div><dt>Build</dt><dd>{{ selectedFeedback.build_hash || '—' }}</dd></div>
          <div><dt>Категория</dt><dd>{{ selectedFeedback.category }}</dd></div>
          <div><dt>Важность</dt><dd>{{ selectedFeedback.severity }}</dd></div>
          <div><dt>Статус</dt><dd><AppStatusBadge :label="statusLabels[selectedFeedback.status] || selectedFeedback.status" :tone="statusTone(selectedFeedback.status)" /></dd></div>
        </dl>

        <section class="uat-feedback-card__body">
          <h3>Описание</h3>
          <p>{{ selectedFeedback.description }}</p>
          <h3>Ожидаемое поведение</h3>
          <p>{{ selectedFeedback.expected_result || '—' }}</p>
          <h3>Фактическое поведение</h3>
          <p>{{ selectedFeedback.actual_result || '—' }}</p>
        </section>

        <section class="uat-feedback-card__actions">
          <q-select v-model="feedbackDraft.status" outlined emit-value map-options label="Статус" :options="feedbackStatuses.map((value) => ({ label: statusLabels[value], value }))" />
          <q-select v-model="feedbackDraft.assigned_to" outlined clearable emit-value map-options label="Ответственный" :options="store.accountOptions" />
          <q-input v-model="feedbackDraft.status_comment" outlined autogrow label="Комментарий к изменению статуса" />
          <q-input v-model="feedbackDraft.resolution" outlined autogrow label="Решение" />
          <q-input v-model.number="feedbackDraft.github_issue_number" outlined type="number" label="GitHub Issue #" />
          <q-input v-model="feedbackDraft.github_issue_url" outlined label="GitHub Issue URL" />
          <q-input v-model="feedbackDraft.github_issue_status" outlined label="GitHub Issue статус" />
          <q-btn color="primary" @click="saveFeedback">Сохранить обращение</q-btn>
        </section>

        <section class="uat-feedback-card__screenshot">
          <h3>Скриншот</h3>
          <template v-if="selectedFeedback.has_screenshot">
            <div class="uat-screenshot-actions">
              <q-btn outline color="primary" @click="openScreenshot"><Maximize2 :size="16" />Просмотреть</q-btn>
              <q-btn flat color="primary" @click="downloadScreenshot"><Download :size="16" />Скачать оригинал</q-btn>
            </div>
          </template>
          <p v-else>Скриншот не приложен.</p>
        </section>

        <section>
          <h3>История статусов</h3>
          <ol class="uat-timeline">
            <li v-for="event in selectedFeedback.status_history || []" :key="event.id">
              <strong>{{ statusLabels[event.old_status] || event.old_status || 'Создано' }} → {{ statusLabels[event.new_status] || event.new_status }}</strong>
              <span>{{ formatDate(event.created_at) }} · {{ event.user?.name || event.user?.email || 'система' }}</span>
              <p v-if="event.comment">{{ event.comment }}</p>
            </li>
          </ol>
          <p v-if="!(selectedFeedback.status_history || []).length">История пока пуста.</p>
        </section>

        <section>
          <h3>Комментарии</h3>
          <div class="uat-comment-form">
            <q-select v-model="commentDraft.type" outlined dense emit-value map-options label="Тип" :options="commentTypeOptions" />
            <q-input v-model="commentDraft.comment" outlined dense autogrow label="Комментарий" />
            <q-btn color="primary" :disable="!commentDraft.comment.trim()" @click="addComment">Добавить</q-btn>
          </div>
          <article v-for="comment in selectedFeedback.comments || []" :key="comment.id" class="uat-comment">
            <strong>{{ commentTypeOptions.find((item) => item.value === comment.type)?.label || comment.type }}</strong>
            <span>{{ formatDate(comment.created_at) }} · {{ comment.user?.name || comment.user?.email || '—' }}</span>
            <p>{{ comment.comment }}</p>
          </article>
        </section>
      </div>
    </AppCard>

    <q-dialog v-model="screenshotDialog" maximized @hide="closeScreenshot">
      <q-card class="uat-screenshot-dialog">
        <q-card-section class="uat-screenshot-dialog__header">
          <h3>Скриншот обращения #{{ selectedFeedback?.id }}</h3>
          <q-btn flat v-close-popup>Закрыть</q-btn>
        </q-card-section>
        <q-card-section><img v-if="screenshotUrl" :src="screenshotUrl" alt="Скриншот обращения" /></q-card-section>
      </q-card>
    </q-dialog>

    <q-dialog v-model="createDialog">
      <q-card class="uat-dialog">
        <q-card-section><h3>Новый UAT-прогон</h3></q-card-section>
        <q-card-section class="uat-dialog-form">
          <q-input v-model="newRun.title" outlined label="Название" />
          <q-select v-model="newRun.role_code" outlined emit-value map-options label="Роль" :options="store.roleOptions" />
          <q-select v-model="newRun.tester_user_id" outlined clearable emit-value map-options label="Тестировщик" :options="store.accountOptions" />
        </q-card-section>
        <q-card-actions align="right"><q-btn flat v-close-popup>Отмена</q-btn><q-btn color="primary" @click="createRun">Создать</q-btn></q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="completeDialog">
      <q-card class="uat-dialog">
        <q-card-section><h3>Завершить тестирование</h3></q-card-section>
        <q-card-section><q-input v-model="summary" outlined autogrow label="Итоговое резюме" /></q-card-section>
        <q-card-actions align="right"><q-btn flat v-close-popup>Отмена</q-btn><q-btn color="positive" @click="completeRun">Завершить</q-btn></q-card-actions>
      </q-card>
    </q-dialog>
  </AppPage>
</template>

<style scoped>
.uat-layout { display: grid; grid-template-columns: 260px minmax(0, 1fr) 340px; gap: 16px; align-items: start; }
.uat-runs, .uat-main, .uat-feedback-list { display: grid; gap: 10px; min-width: 0; }
.uat-runs h3, .uat-feedback-list h3, .uat-feedback-card h3 { margin: 0; font-size: 16px; }
.uat-run, .uat-scenario, .uat-feedback-item, .uat-summary { border: 1px solid #d9dee8; background: #fff; border-radius: 8px; padding: 12px; }
.uat-run, .uat-feedback-item { text-align: left; display: grid; gap: 6px; cursor: pointer; }
.uat-run span, .uat-summary span, .uat-scenario-head span, .uat-feedback-item span, .uat-fields dt, .uat-timeline span, .uat-comment span { color: #64748b; font-size: 13px; }
.uat-run--active, .uat-feedback-item--active { border-color: #2563eb; box-shadow: 0 0 0 1px #2563eb; }
.uat-summary { display: grid; grid-template-columns: minmax(0, 1fr) 220px auto; gap: 12px; align-items: center; }
.uat-kpis, .uat-scenario-actions, .uat-screenshot-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.uat-scenarios { display: grid; gap: 12px; }
.uat-scenario { display: grid; gap: 10px; }
.uat-scenario-head, .uat-feedback-meta { display: flex; justify-content: space-between; gap: 12px; align-items: center; }
.uat-scenario p, .uat-feedback-card p, .uat-comment p, .uat-timeline p { margin: 0; color: #334155; overflow-wrap: anywhere; }
.uat-result-grid { display: grid; grid-template-columns: 180px 1fr 1fr 220px; gap: 8px; align-items: start; }
.uat-feedback-card { margin-top: 16px; }
.uat-feedback-card__grid { display: grid; grid-template-columns: minmax(260px, 360px) minmax(0, 1fr) minmax(280px, 360px); gap: 16px; align-items: start; }
.uat-feedback-card__body, .uat-feedback-card__actions, .uat-feedback-card__screenshot, .uat-comment-form { display: grid; gap: 10px; }
.uat-fields { display: grid; gap: 10px; margin: 0; }
.uat-fields div { display: grid; gap: 2px; }
.uat-fields dd { margin: 0; overflow-wrap: anywhere; }
.uat-timeline { display: grid; gap: 10px; margin: 0; padding-left: 20px; }
.uat-timeline li, .uat-comment { border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; display: grid; gap: 4px; }
.uat-dialog { min-width: 520px; }
.uat-dialog-form { display: grid; gap: 12px; }
.uat-screenshot-dialog { background: #0f172a; color: #fff; }
.uat-screenshot-dialog__header { display: flex; justify-content: space-between; align-items: center; }
.uat-screenshot-dialog h3 { margin: 0; }
.uat-screenshot-dialog img { display: block; max-width: 100%; max-height: calc(100vh - 120px); margin: 0 auto; background: #fff; }
@media (max-width: 1439px) { .uat-layout, .uat-feedback-card__grid { grid-template-columns: 1fr; } .uat-summary, .uat-result-grid { grid-template-columns: 1fr; } }
</style>
