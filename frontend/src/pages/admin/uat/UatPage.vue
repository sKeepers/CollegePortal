<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { Download, Plus, RefreshCw } from '@lucide/vue'
import AppPage from '../../../components/ui/AppPage.vue'
import PageHeader from '../../../components/ui/PageHeader.vue'
import AppToolbar from '../../../components/ui/AppToolbar.vue'
import AppErrorBanner from '../../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../../components/ui/AppStatusBadge.vue'
import AppEmptyState from '../../../components/ui/AppEmptyState.vue'
import { api } from '../../../services/api'
import { roleLabels, statusLabels, statusTone, useUatStore } from '../../../stores/uat'

const store = useUatStore()
const createDialog = ref(false)
const completeDialog = ref(false)
const summary = ref('')
const resultDraft = reactive({})
const newRun = reactive({ title: '', role_code: 'study', tester_user_id: null })
const categoryLabels = {
  error: 'Ошибка',
  ux: 'Неудобство',
  suggestion: 'Предложение',
  data: 'Данные',
  access: 'Права доступа',
}
const severityLabels = {
  critical: 'Критическая',
  high: 'Высокая',
  medium: 'Средняя',
  low: 'Низкая',
  ux: 'UX',
}

const selectedResults = computed(() => store.selectedRun?.results || [])
const progressPercent = computed(() => {
  const total = store.selectedRun?.progress?.total || 0
  const done = (store.selectedRun?.progress?.passed || 0) + (store.selectedRun?.progress?.failed || 0) + (store.selectedRun?.progress?.blocked || 0) + (store.selectedRun?.progress?.skipped || 0)
  return total ? Math.round((done / total) * 100) : 0
})

function scenario(result) { return store.scenariosByCode[result.scenario_code] || { title: result.scenario_code, route: '', steps: '', expected: '' } }
function resultModel(result) {
  if (!resultDraft[result.id]) resultDraft[result.id] = { status: result.status, comment: result.comment || '', actual_result: result.actual_result || '', screenshot: null }
  return resultDraft[result.id]
}
async function createRun() {
  await store.createRun({ ...newRun, title: newRun.title || `UAT ${roleLabels[newRun.role_code] || newRun.role_code}` })
  createDialog.value = false
}
async function saveResult(result) { await store.updateResult(store.selectedRun.id, result.id, resultModel(result)) }
async function completeRun() { await store.completeRun(store.selectedRun.id, summary.value); completeDialog.value = false }
function formatDateTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
function filenameFromPath(path, fallback = 'uat-download') {
  return String(path || '').split('/').pop()?.split('?')[0] || fallback
}
async function download(path, fallback) {
  const blob = await api.download(path)
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = fallback || filenameFromPath(path)
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

onMounted(store.load)
</script>

<template>
  <AppPage>
    <PageHeader title="UAT" subtitle="Закрытое пользовательское тестирование по ролям: сценарии, результаты и замечания.">
      <template #actions>
        <q-btn color="primary" @click="createDialog = true"><Plus :size="16" />Новый прогон</q-btn>
      </template>
    </PageHeader>
    <AppToolbar>
      <span>Реестр UAT хранит воспроизводимые сценарии, результат, фактическое поведение и скриншоты в private storage.</span>
      <template #actions>
        <q-btn flat :loading="store.loading" @click="store.load"><RefreshCw :size="16" />Обновить</q-btn>
        <q-btn outline color="primary" @click="download('/admin/uat/export/results.csv')"><Download :size="16" />Результаты CSV</q-btn>
        <q-btn outline color="primary" @click="download('/admin/uat/export/feedback.csv')"><Download :size="16" />Замечания CSV</q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />

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

      <section v-if="store.selectedRun" class="uat-main">
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
              <q-btn v-if="result.has_screenshot" flat color="primary" @click="download(`/admin/uat/results/${result.id}/screenshot`)">Скачать скриншот</q-btn>
            </div>
          </article>
        </div>
      </section>
      <AppEmptyState v-else title="Прогон не выбран" description="Создайте или выберите UAT-прогон." />

      <aside class="uat-feedback">
        <h3>Замечания</h3>
        <article v-for="item in store.feedback" :key="item.id" class="uat-feedback-item">
          <div class="uat-feedback-item__head">
            <div>
              <strong>#{{ item.id }} · {{ item.title }}</strong>
              <span>{{ formatDateTime(item.created_at) }} · {{ roleLabels[item.role_code] || item.role_code || 'роль не указана' }}</span>
            </div>
            <AppStatusBadge :label="statusLabels[item.status] || item.status" :tone="statusTone(item.status)" />
          </div>
          <dl class="uat-feedback-item__meta">
            <div><dt>Автор</dt><dd>{{ item.user?.name || item.user?.email || 'Не указан' }}</dd></div>
            <div><dt>Страница</dt><dd>{{ item.page_url || '—' }}</dd></div>
            <div><dt>Категория</dt><dd>{{ categoryLabels[item.category] || item.category }}</dd></div>
            <div><dt>Важность</dt><dd>{{ severityLabels[item.severity] || item.severity }}</dd></div>
            <div><dt>Версия</dt><dd>{{ item.app_version || '—' }}</dd></div>
            <div><dt>Build</dt><dd>{{ item.build_hash || '—' }}</dd></div>
          </dl>
          <p>{{ item.description }}</p>
          <div v-if="item.expected_result || item.actual_result" class="uat-feedback-item__details">
            <div v-if="item.expected_result"><b>Ожидалось:</b> {{ item.expected_result }}</div>
            <div v-if="item.actual_result"><b>Фактически:</b> {{ item.actual_result }}</div>
          </div>
          <q-input
            :model-value="item.resolution"
            dense
            outlined
            autogrow
            label="Решение / комментарий"
            @change="store.updateFeedback(item.id, { resolution: $event })"
          />
          <q-select :model-value="item.status" dense outlined emit-value map-options label="Статус" :options="['new','confirmed','in_progress','fixed','rejected','retest','closed'].map((value) => ({ label: statusLabels[value], value }))" @update:model-value="store.updateFeedback(item.id, { status: $event })" />
          <q-btn v-if="item.has_screenshot" flat dense color="primary" @click="download(`/admin/uat/feedback/${item.id}/screenshot`, `uat-feedback-${item.id}-screenshot`)">Скачать скриншот</q-btn>
        </article>
      </aside>
    </div>

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
.uat-layout { display: grid; grid-template-columns: 280px minmax(0, 1fr) 320px; gap: 16px; align-items: start; }
.uat-runs, .uat-main, .uat-feedback { display: grid; gap: 10px; }
.uat-runs h3, .uat-feedback h3 { margin: 0; font-size: 16px; }
.uat-run, .uat-scenario, .uat-feedback-item, .uat-summary { border: 1px solid #d9dee8; background: #fff; border-radius: 8px; padding: 12px; }
.uat-run { text-align: left; display: grid; gap: 6px; cursor: pointer; }
.uat-run span, .uat-summary span, .uat-scenario-head span, .uat-feedback-item span { color: #64748b; font-size: 13px; }
.uat-run--active { border-color: #2563eb; box-shadow: 0 0 0 1px #2563eb; }
.uat-summary { display: grid; grid-template-columns: 1fr 220px auto; gap: 12px; align-items: center; }
.uat-kpis { display: flex; flex-wrap: wrap; gap: 8px; font-size: 13px; }
.uat-scenarios { display: grid; gap: 12px; }
.uat-scenario { display: grid; gap: 10px; }
.uat-scenario-head { display: flex; justify-content: space-between; gap: 12px; }
.uat-scenario p { margin: 0; color: #334155; }
.uat-result-grid { display: grid; grid-template-columns: 180px 1fr 1fr 220px; gap: 8px; align-items: start; }
.uat-scenario-actions { display: flex; gap: 8px; }
.uat-feedback-item { display: grid; gap: 8px; }
.uat-feedback-item__head { display: flex; justify-content: space-between; gap: 10px; align-items: start; }
.uat-feedback-item__head > div { display: grid; gap: 3px; }
.uat-feedback-item__meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 10px; margin: 0; font-size: 13px; }
.uat-feedback-item__meta div { min-width: 0; }
.uat-feedback-item__meta dt { color: #64748b; }
.uat-feedback-item__meta dd { margin: 0; overflow-wrap: anywhere; }
.uat-feedback-item__details { display: grid; gap: 4px; color: #334155; font-size: 13px; }
.uat-dialog { min-width: 520px; }
.uat-dialog-form { display: grid; gap: 12px; }
@media (max-width: 1439px) { .uat-layout { grid-template-columns: 1fr; } .uat-summary, .uat-result-grid { grid-template-columns: 1fr; } }
</style>
