<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  BookOpenCheck,
  BriefcaseBusiness,
  CalendarDays,
  ClipboardList,
  DoorOpen,
  FileCheck2,
  FileWarning,
  GraduationCap,
  ScrollText,
  Upload,
  UserRound,
  UsersRound,
} from '@lucide/vue'
import AppPage from '../../../components/ui/AppPage.vue'
import PageHeader from '../../../components/ui/PageHeader.vue'
import AppCard from '../../../components/ui/AppCard.vue'
import AppErrorBanner from '../../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../../components/ui/AppStatusBadge.vue'
import { api } from '../../../services/api'
import { useAuthStore } from '../../../stores/auth'
import { useSettingsStore } from '../../../stores/settings'
import StatsWidget from '../widgets/StatsWidget.vue'
import QuickActionsWidget from '../widgets/QuickActionsWidget.vue'
import RecentActivityWidget from '../widgets/RecentActivityWidget.vue'
import PersonalDashboardLayout from '../../../components/dashboard/PersonalDashboardLayout.vue'
import { currentDateRu } from './dashboardData'

const auth = useAuthStore()
const settingsStore = useSettingsStore()
const loading = ref(false)
const error = ref('')
const analytics = ref(null)

const roleLabel = computed(() => auth.user?.role?.code === 'director' ? 'директора' : 'администратора')
const currentDate = computed(currentDateRu)
const userName = computed(() => auth.user?.name || 'пользователь')
const dashboardSubtitle = computed(() => `Рабочий стол ${roleLabel.value} · ${settingsStore.publicValue('general', 'college_short_name', 'CollegePortal')}`)
const adminDashboardType = computed(() => auth.user?.role?.code === 'director' ? 'director' : 'admin')
const dashboardWidgets = [
  { id: 'stats', title: 'Ключевые показатели', defaultSize: 'full' },
  { id: 'actions', title: 'Быстрые действия', defaultSize: 'medium' },
  { id: 'attendance', title: 'Посещаемость сегодня', defaultSize: 'medium' },
  { id: 'attention', title: 'Что требует внимания', defaultSize: 'medium' },
  { id: 'charts', title: 'Мини-графики', defaultSize: 'medium' },
  { id: 'admissions', title: 'Приемная комиссия', defaultSize: 'small' },
  { id: 'integrations', title: 'ФРДО / ФИС', defaultSize: 'medium' },
  { id: 'system', title: 'Система', defaultSize: 'medium' },
  { id: 'audit', title: 'Последние действия', defaultSize: 'medium' },
  { id: 'access', title: 'Проходная', defaultSize: 'small' },
]
const payload = computed(() => analytics.value?.data || {})
const kpi = computed(() => payload.value.kpi || {})
const contingent = computed(() => kpi.value.contingent || {})
const teachers = computed(() => kpi.value.teachers || {})
const hr = computed(() => kpi.value.hr || {})
const learning = computed(() => kpi.value.learning || {})
const access = computed(() => kpi.value.access || {})
const attendance = computed(() => kpi.value.attendance || {})
const attendanceTeachers = computed(() => attendance.value.teachers || {})
const attendanceStudents = computed(() => attendance.value.students || {})
const admissions = computed(() => kpi.value.admissions || {})
const frdo = computed(() => kpi.value.frdo || {})
const fis = computed(() => kpi.value.fis || {})
const system = computed(() => kpi.value.system || {})
const version = computed(() => system.value.version || {})
const attentionItems = computed(() => payload.value.attention || [])
const charts = computed(() => payload.value.charts || {})
const latestPackages = computed(() => frdo.value.latest_packages || [])

const statItems = computed(() => [
  { label: 'Всего студентов', value: contingent.value.students_total || 0, icon: GraduationCap },
  { label: 'Активных студентов', value: contingent.value.students_active || 0, icon: UsersRound },
  { label: 'Выпускников', value: contingent.value.graduates || 0, icon: GraduationCap },
  { label: 'Абитуриентов', value: contingent.value.applicants || 0, icon: FileCheck2 },
  { label: 'Преподавателей', value: teachers.value.teachers_total || 0, icon: UserRound },
  { label: 'Сотрудников', value: hr.value.employees_total || 0, icon: BriefcaseBusiness },
  { label: 'Активных сотрудников', value: hr.value.employees_active || 0, icon: BriefcaseBusiness },
  { label: 'Занятий сегодня', value: learning.value.lessons_today || 0, icon: BookOpenCheck },
  { label: 'Экзаменов сегодня', value: learning.value.exams_today || 0, icon: CalendarDays },
  { label: 'Свободных аудиторий', value: learning.value.free_classrooms || 0, icon: DoorOpen },
  { label: 'Сейчас в колледже', value: access.value.inside_now || 0, icon: DoorOpen },
  { label: 'Входов сегодня', value: access.value.entries_today || 0, icon: DoorOpen },
  { label: 'Выходов сегодня', value: access.value.exits_today || 0, icon: DoorOpen },
  { label: 'Отказанных проходов', value: access.value.denied_today || 0, icon: FileWarning },
  { label: 'Преподаватели опоздали', value: attendanceTeachers.value.late || 0, icon: UserRound },
  { label: 'Студенты опоздали', value: attendanceStudents.value.late || 0, icon: GraduationCap },
])

const quickActionsSource = [
  { label: 'Студенты', description: 'Контингент и карточки', icon: GraduationCap, to: '/students' },
  { label: 'Приемная комиссия', description: 'Заявления абитуриентов', icon: FileCheck2, to: '/admissions' },
  { label: 'Расписание', description: 'Занятия и аудитории', icon: CalendarDays, to: '/schedule' },
  { label: 'Проходная', description: 'Сканирование QR', icon: DoorOpen, to: '/access/gate' },
  { label: 'Посещаемость', description: 'Опоздания и отсутствия', icon: ClipboardList, to: '/attendance' },
  { label: 'Импорт', description: 'Загрузка реальных данных', icon: Upload, to: '/admin/import' },
  { label: 'ФРДО', description: 'Пакеты и ошибки', icon: FileWarning, to: '/frdo' },
  { label: 'ФИС', description: 'Пакеты приема и ГИА', icon: FileWarning, to: '/fis' },
  { label: 'Аудит', description: 'Действия пользователей', icon: ScrollText, to: '/admin/audit' },
  { label: 'Сотрудники', description: 'Кадровый контур', icon: BriefcaseBusiness, to: '/hr/employees' },
]

const quickActionPermissions = {
  '/students': 'students.view',
  '/admissions': 'admissions.view',
  '/schedule': 'schedule.view',
  '/access/gate': 'gate.scan',
  '/attendance': 'attendance.reports',
  '/admin/import': 'import.manage',
  '/frdo': 'frdo.view',
  '/fis': 'fis.view',
  '/admin/audit': 'audit.view',
  '/hr/employees': 'hr.employees.view',
}
const quickActions = computed(() => quickActionsSource.filter((action) => !quickActionPermissions[action.to] || auth.can(quickActionPermissions[action.to])))

const auditActivity = computed(() => (payload.value.audit || []).map((item) => ({
  id: item.id,
  title: item.title || 'Событие аудита',
  description: [item.description, item.user].filter(Boolean).join(' · ') || 'Детали события',
  time: item.time || '—',
})))

const chartGroups = computed(() => [
  { title: 'Заявления', subtitle: 'последние 7 дней', items: charts.value.applications_7_days || [] },
  { title: 'Входы', subtitle: 'последние 7 дней', items: charts.value.access_7_days || [] },
  { title: 'Занятия', subtitle: 'последние 7 дней', items: charts.value.lessons_7_days || [] },
])

const integrationRows = computed(() => [
  { label: 'ФРДО готово к выгрузке', value: frdo.value.ready || 0, tone: 'success' },
  { label: 'Ошибки ФРДО', value: frdo.value.errors || 0, tone: (frdo.value.errors || 0) > 0 ? 'danger' : 'success' },
  { label: 'ФИС: прием', value: fis.value.admission_packages || 0, tone: 'info' },
  { label: 'ФИС: ГИА', value: fis.value.gia_packages || 0, tone: 'info' },
  { label: 'Ошибки ФИС', value: fis.value.errors || 0, tone: (fis.value.errors || 0) > 0 ? 'danger' : 'success' },
])

function chartMax(items) {
  return Math.max(1, ...items.map((item) => Number(item.value || 0)))
}

function chartHeight(item, items) {
  return `${Math.max(12, Math.round((Number(item.value || 0) / chartMax(items)) * 72))}px`
}

function formatChartDate(value) {
  if (!value) {
    return ''
  }

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit' }).format(date)
}

async function loadDashboard() {
  loading.value = true
  error.value = ''

  try {
    analytics.value = await api.list('dashboard/analytics/executive')
  } catch (err) {
    error.value = err.message || 'Не удалось загрузить аналитический Dashboard'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  settingsStore.loadPublic().catch(() => {})
  loadDashboard()
})
</script>

<template>
  <AppPage>
    <PageHeader title="Панель" :subtitle="dashboardSubtitle">
      <template #actions>
        <q-btn flat :loading="loading" @click="loadDashboard">Обновить</q-btn>
      </template>
    </PageHeader>

    <section class="dashboard-hero dashboard-hero--admin">
      <div>
        <span>{{ currentDate }}</span>
        <h2>Добро пожаловать, {{ userName }}</h2>
        <p>Исполнительная аналитика: контингент, учебный процесс, проходная, приемная кампания, интеграции и аудит.</p>
      </div>
    </section>

    <AppErrorBanner :message="error" />

    <PersonalDashboardLayout :dashboard-type="adminDashboardType" :widgets="dashboardWidgets">
      <template #stats>
        <StatsWidget :items="statItems" :loading="loading" />
      </template>

      <template #actions>
        <QuickActionsWidget :actions="quickActions" />
      </template>

      <template #attendance>
        <AppCard title="Посещаемость сегодня" subtitle="Проходная + расписание">
          <div class="executive-attendance-grid">
            <div class="executive-attendance-block">
              <h3>Преподаватели сегодня</h3>
              <button type="button" @click="$router.push('/attendance?type=teachers&status=on_time')"><span>Вовремя</span><strong>{{ attendanceTeachers.on_time || 0 }}</strong></button>
              <button type="button" @click="$router.push('/attendance?type=teachers&status=late')"><span>Опоздали</span><strong>{{ attendanceTeachers.late || 0 }}</strong></button>
              <button type="button" @click="$router.push('/attendance?type=teachers&status=absent')"><span>Не пришли</span><strong>{{ attendanceTeachers.absent || 0 }}</strong></button>
              <button type="button" @click="$router.push('/attendance?type=teachers')"><span>Сейчас в здании</span><strong>{{ attendanceTeachers.inside_now || 0 }}</strong></button>
            </div>
            <div class="executive-attendance-block">
              <h3>Студенты сегодня</h3>
              <button type="button" @click="$router.push('/attendance?type=students')"><span>Вошли</span><strong>{{ attendanceStudents.entered || 0 }}</strong></button>
              <button type="button" @click="$router.push('/attendance?type=students&status=late')"><span>Опоздали</span><strong>{{ attendanceStudents.late || 0 }}</strong></button>
              <button type="button" @click="$router.push('/attendance?type=students&status=absent')"><span>Не вошли</span><strong>{{ attendanceStudents.absent || 0 }}</strong></button>
              <button type="button" @click="$router.push('/attendance?type=students')"><span>Сейчас в здании</span><strong>{{ attendanceStudents.inside_now || 0 }}</strong></button>
            </div>
          </div>
        </AppCard>
      </template>

      <template #attention>
        <AppCard title="Что требует внимания" subtitle="Контроль качества данных и критичных процессов">
          <div class="executive-attention">
            <button
              v-for="item in attentionItems"
              :key="item.title"
              type="button"
              class="executive-attention__item"
              @click="$router.push(item.to)"
            >
              <span>
                <strong>{{ item.title }}</strong>
                <small>Требует проверки</small>
              </span>
              <AppStatusBadge :label="String(item.value)" :tone="item.tone" />
            </button>
          </div>
        </AppCard>
      </template>

      <template #charts>
        <AppCard title="Мини-графики" subtitle="Динамика по доступным данным">
          <div class="executive-chart-list">
            <div v-for="chart in chartGroups" :key="chart.title" class="executive-chart">
              <div class="executive-chart__header">
                <strong>{{ chart.title }}</strong>
                <small>{{ chart.subtitle }}</small>
              </div>
              <div class="executive-chart__bars">
                <div v-for="point in chart.items" :key="`${chart.title}-${point.date}`" class="executive-chart__point">
                  <span class="executive-chart__bar" :style="{ height: chartHeight(point, chart.items) }" />
                  <small>{{ formatChartDate(point.date) }}</small>
                </div>
              </div>
              <AppStatusBadge v-if="chart.items.some((point) => point.is_demo)" label="DEV" tone="warning" />
            </div>
          </div>
        </AppCard>
      </template>

      <template #admissions>
        <AppCard title="Приемная комиссия" subtitle="Состояние заявлений">
          <div class="dashboard-role-list dashboard-role-list--compact">
            <div><span>Новые</span><strong>{{ admissions.new_applications || 0 }}</strong></div>
            <div><span>Без документов</span><strong>{{ admissions.no_documents || 0 }}</strong></div>
            <div><span>Неполный комплект</span><strong>{{ admissions.incomplete_documents || 0 }}</strong></div>
            <div><span>Полный комплект</span><strong>{{ admissions.complete_documents || 0 }}</strong></div>
            <div><span>Получение подтверждено</span><strong>{{ admissions.documents_confirmed || 0 }}</strong></div>
            <div><span>Без паспорта</span><strong>{{ admissions.without_passport || 0 }}</strong></div>
            <div><span>Без документа об образовании</span><strong>{{ admissions.without_education_document || 0 }}</strong></div>
            <div><span>Без согласия на ПДн</span><strong>{{ admissions.without_personal_data_consent || 0 }}</strong></div>
            <div><span>Ожидают проверки</span><strong>{{ admissions.documents_under_review || 0 }}</strong></div>
            <div><span>Отклоненные документы</span><strong>{{ admissions.documents_rejected || 0 }}</strong></div>
            <div><span>Подтвержденные комплекты</span><strong>{{ admissions.verified_complete_documents || 0 }}</strong></div>
            <div><span>Зачислено</span><strong>{{ admissions.enrolled || 0 }}</strong></div>
          </div>
        </AppCard>
      </template>

      <template #integrations>
        <AppCard title="ФРДО / ФИС" subtitle="Пакеты, готовность и ошибки проверки">
          <div class="executive-integration-grid">
            <div v-for="row in integrationRows" :key="row.label" class="executive-integration-card">
              <span>{{ row.label }}</span>
              <strong>{{ row.value }}</strong>
              <AppStatusBadge :label="row.value > 0 ? 'Есть данные' : 'Нет'" :tone="row.tone" />
            </div>
          </div>
          <div v-if="latestPackages.length" class="executive-package-list">
            <div v-for="pkg in latestPackages" :key="pkg.id">
              <strong>{{ pkg.name }}</strong>
              <small>{{ pkg.updated_at }}</small>
              <AppStatusBadge :label="pkg.status" tone="info" />
            </div>
          </div>
        </AppCard>
      </template>

      <template #system>
        <AppCard title="Система" subtitle="Версия, сборка и состояние платформы">
          <dl class="executive-system-list">
            <div><dt>Версия</dt><dd>{{ version.version || '0.7.0-dev' }}</dd></div>
            <div><dt>Релиз</dt><dd>{{ version.release || 'Release 0.7' }}</dd></div>
            <div><dt>Build</dt><dd>{{ version.build || 'unknown' }}</dd></div>
            <div><dt>Дата сборки</dt><dd>{{ version.buildDate || '—' }}</dd></div>
            <div><dt>Окружение</dt><dd>{{ version.environment || 'development' }}</dd></div>
            <div><dt>Статус</dt><dd><AppStatusBadge label="Работает" tone="success" /></dd></div>
          </dl>
        </AppCard>
      </template>

      <template #audit>
        <RecentActivityWidget :items="auditActivity" />
      </template>

      <template #access>
        <section class="dashboard-role-card">
          <h3>Проходная</h3>
          <div class="dashboard-role-list">
            <div><span>Сейчас в колледже</span><strong>{{ access.inside_now || 0 }}</strong></div>
            <div><span>Входов сегодня</span><strong>{{ access.entries_today || 0 }}</strong></div>
            <div><span>Отказов</span><strong>{{ access.denied_today || 0 }}</strong></div>
          </div>
          <AppStatusBadge label="Контроль доступа" tone="info" />
        </section>
      </template>
    </PersonalDashboardLayout>
  </AppPage>
</template>
