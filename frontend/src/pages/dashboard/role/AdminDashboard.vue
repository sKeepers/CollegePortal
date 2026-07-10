<script setup>
import { computed, onMounted, ref } from 'vue'
import { BookOpenCheck, CalendarDays, DoorOpen, FileCheck2, FileWarning, GraduationCap, ScrollText, ShieldAlert, UserRound, UsersRound } from '@lucide/vue'
import AppPage from '../../../components/ui/AppPage.vue'
import PageHeader from '../../../components/ui/PageHeader.vue'
import AppErrorBanner from '../../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../../components/ui/AppStatusBadge.vue'
import { api } from '../../../services/api'
import { useAuthStore } from '../../../stores/auth'
import { useSettingsStore } from '../../../stores/settings'
import StatsWidget from '../widgets/StatsWidget.vue'
import QuickActionsWidget from '../widgets/QuickActionsWidget.vue'
import RecentActivityWidget from '../widgets/RecentActivityWidget.vue'
import NotificationsWidget from '../widgets/NotificationsWidget.vue'
import { currentDateRu, extractRows, extractTotal, formatShortDateTime, todayIso } from './dashboardData'

const auth = useAuthStore()
const settingsStore = useSettingsStore()
const loading = ref(false)
const error = ref('')
const totals = ref({
  students: 0,
  teachers: 0,
  todayLessons: 0,
  insideNow: 0,
  late: 0,
  denied: 0,
  newApplications: 0,
  integrationErrors: 0,
})
const auditItems = ref([])
const integrationAlerts = ref([])

const roleLabel = computed(() => auth.user?.role?.code === 'director' ? 'директора' : 'администратора')
const currentDate = computed(currentDateRu)
const userName = computed(() => auth.user?.name || 'пользователь')
const dashboardSubtitle = computed(() => `Рабочий стол ${roleLabel.value} · ${settingsStore.publicValue('general', 'college_short_name', 'CollegePortal')}`)
const statItems = computed(() => [
  { label: 'Студенты', value: totals.value.students, icon: GraduationCap },
  { label: 'Преподаватели', value: totals.value.teachers, icon: UserRound },
  { label: 'Занятия сегодня', value: totals.value.todayLessons, icon: BookOpenCheck },
  { label: 'Сейчас в здании', value: totals.value.insideNow, icon: DoorOpen },
  { label: 'Опоздания', value: totals.value.late, icon: ShieldAlert },
  { label: 'Отказанные проходы', value: totals.value.denied, icon: ShieldAlert },
  { label: 'Новые заявления', value: totals.value.newApplications, icon: FileCheck2 },
  { label: 'Ошибки ФРДО/ФИС', value: totals.value.integrationErrors, icon: FileWarning },
])
const quickActions = [
  { label: 'Студенты', description: 'Контингент и карточки', icon: GraduationCap, to: '/students' },
  { label: 'Приемная комиссия', description: 'Заявления абитуриентов', icon: FileCheck2, to: '/admissions' },
  { label: 'Расписание', description: 'Занятия и аудитории', icon: CalendarDays, to: '/schedule' },
  { label: 'Проходная', description: 'Сканирование QR', icon: DoorOpen, to: '/access/gate' },
  { label: 'ФРДО', description: 'Пакеты и ошибки', icon: FileWarning, to: '/frdo' },
  { label: 'ФИС', description: 'Пакеты приема и ГИА', icon: FileWarning, to: '/fis' },
  { label: 'Аудит', description: 'Действия пользователей', icon: ScrollText, to: '/admin/audit' },
]
const auditActivity = computed(() => auditItems.value.map((item) => ({
  id: item.id,
  title: item.action || 'Событие аудита',
  description: [item.module, item.entity_type].filter(Boolean).join(' · ') || 'Детали события',
  time: formatShortDateTime(item.created_at),
})))
const notifications = computed(() => integrationAlerts.value.length ? integrationAlerts.value : [
  { id: 'ok', title: 'Интеграции', description: 'Критических ошибок ФРДО/ФИС в загруженных пакетах не найдено', status: 'Норма', tone: 'success' },
])

async function loadDashboard() {
  loading.value = true
  error.value = ''

  try {
    const today = todayIso()
    const results = await Promise.allSettled([
      api.list('students'),
      api.list('teachers', { active_only: 1 }),
      api.list('schedule-lessons', { date: today }),
      api.list('access/reports/summary'),
      api.list('access/reports/events', { result: 'denied', date_from: today, date_to: today }),
      api.list('applicant-applications', { status: 'new' }),
      api.list('frdo-packages'),
      api.list('fis-packages'),
      api.list('admin/audit'),
    ])

    const [students, teachers, lessons, accessSummary, deniedEvents, applications, frdo, fis, audit] = results
    if (students.status === 'fulfilled') totals.value.students = extractTotal(students.value)
    if (teachers.status === 'fulfilled') totals.value.teachers = extractTotal(teachers.value)
    if (lessons.status === 'fulfilled') totals.value.todayLessons = extractTotal(lessons.value)
    if (accessSummary.status === 'fulfilled') {
      const summary = accessSummary.value?.data || accessSummary.value || {}
      totals.value.insideNow = Number(summary.inside_now || 0)
      totals.value.late = Number(summary.late || 0)
      totals.value.denied = Number(summary.denied || 0)
    }
    if (deniedEvents.status === 'fulfilled') totals.value.denied = totals.value.denied || extractTotal(deniedEvents.value)
    if (applications.status === 'fulfilled') totals.value.newApplications = extractTotal(applications.value)

    const alerts = []
    let errorCount = 0
    if (frdo.status === 'fulfilled') {
      extractRows(frdo.value).forEach((pkg) => {
        const count = Number(pkg.validation_errors_count || 0)
        if (count > 0 || pkg.status === 'validation_failed') {
          errorCount += count || 1
          alerts.push({ id: `frdo-${pkg.id}`, title: 'ФРДО', description: `${pkg.name}: ошибок ${count || 'есть'}`, status: 'Ошибка', tone: 'danger' })
        }
      })
    }
    if (fis.status === 'fulfilled') {
      extractRows(fis.value).forEach((pkg) => {
        const count = Number(pkg.validation_errors_count || 0)
        if (count > 0 || pkg.status === 'validation_failed') {
          errorCount += count || 1
          alerts.push({ id: `fis-${pkg.id}`, title: 'ФИС', description: `${pkg.name}: ошибок ${count || 'есть'}`, status: 'Ошибка', tone: 'danger' })
        }
      })
    }
    totals.value.integrationErrors = errorCount
    integrationAlerts.value = alerts
    if (audit.status === 'fulfilled') auditItems.value = extractRows(audit.value).slice(0, 6)

    if (results.some((result) => result.status === 'rejected')) {
      error.value = 'Часть административных показателей не удалось загрузить'
    }
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
    <PageHeader title="Панель" :subtitle="dashboardSubtitle"><template #actions><q-btn flat :loading="loading" @click="loadDashboard">Обновить</q-btn></template></PageHeader>
    <section class="dashboard-hero dashboard-hero--admin"><div><span>{{ currentDate }}</span><h2>Добро пожаловать, {{ userName }}</h2><p>Административная сводка: контингент, проходная, приемная кампания, интеграционные ошибки и аудит.</p></div></section>
    <AppErrorBanner :message="error" />
    <div class="dashboard-grid dashboard-grid--role"><section class="dashboard-grid__full"><StatsWidget :items="statItems" :loading="loading" /></section><QuickActionsWidget :actions="quickActions" /><NotificationsWidget :items="notifications" /><RecentActivityWidget :items="auditActivity" /><section class="dashboard-role-card"><h3>Контроль доступа</h3><div class="dashboard-role-list"><div><span>В здании</span><strong>{{ totals.insideNow }}</strong></div><div><span>Отказы</span><strong>{{ totals.denied }}</strong></div><div><span>Опоздания</span><strong>{{ totals.late }}</strong></div></div><AppStatusBadge label="Проходная" tone="info" /></section></div>
  </AppPage>
</template>
