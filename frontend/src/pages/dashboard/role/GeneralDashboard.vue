<script setup>
import { computed, onMounted, ref } from 'vue'
import { BookOpenCheck, BriefcaseBusiness, CalendarDays, DoorOpen, GraduationCap, NotebookTabs, Plus, School, UserRound, UsersRound } from '@lucide/vue'
import AppPage from '../../../components/ui/AppPage.vue'
import PageHeader from '../../../components/ui/PageHeader.vue'
import AppErrorBanner from '../../../components/ui/AppErrorBanner.vue'
import { api } from '../../../services/api'
import { useAuthStore } from '../../../stores/auth'
import { useSettingsStore } from '../../../stores/settings'
import StatsWidget from '../widgets/StatsWidget.vue'
import QuickActionsWidget from '../widgets/QuickActionsWidget.vue'
import RecentActivityWidget from '../widgets/RecentActivityWidget.vue'
import NotificationsWidget from '../widgets/NotificationsWidget.vue'
import TasksWidget from '../widgets/TasksWidget.vue'
import PersonalDashboardLayout from '../../../components/dashboard/PersonalDashboardLayout.vue'
import { currentDateRu, extractTotal, todayIso } from './dashboardData'

const props = defineProps({
  primaryRole: {
    type: String,
    default: 'guest',
  },
})

const auth = useAuthStore()
const settingsStore = useSettingsStore()
const loading = ref(false)
const error = ref('')
const totals = ref({ students: 0, groups: 0, teachers: 0, employees: 0, todayLessons: 0, applications: 0, insideNow: 0, denied: 0, cardsIssued: 0, cardsStock: 0 })

// Придуманных уведомлений и задач здесь больше нет. Три блока-заглушки —
// «ФРДО», «Расписание», «Moodle» плюс список выдуманных дел — показывались
// **всем**, у кого есть рабочий стол, независимо от роли и прав: комендант
// видел напоминание про ФРДО, которого ему не открыть. На портале с живыми
// сотрудниками это не украшение, а ложь: по такому напоминанию однажды пойдут
// что-то делать. Уведомление теперь либо выведено из настоящих чисел, которые
// человеку и так видны, либо его нет вовсе.

/**
 * Уведомления собираются из того, что человеку и так видно.
 *
 * Пустой список — нормальный исход: лучше не показать ничего, чем показать
 * придуманное. Виджет в этом случае с рабочего стола убирается.
 */
const notifications = computed(() => {
  if (isStudent.value) {
    return [{ id: 'lessons', title: 'Расписание', description: 'Проверьте ближайшие занятия в личном кабинете.', status: 'Учебное', tone: 'info' }]
  }

  if (isAdmission.value) {
    return [{ id: 'documents', title: 'Комплектность', description: 'Проверяйте документы и готовность заявлений перед регистрацией.', status: 'Приём', tone: 'info' }]
  }

  const items = []

  if (isHr.value) {
    items.push({ id: 'gate', title: 'Проходная', description: `Сейчас на территории: ${totals.value.insideNow}. Отказов: ${totals.value.denied}.`, status: 'Контроль', tone: 'info' })
  }

  if (auth.can('rfid.cards.view')) {
    items.push({
      id: 'cards',
      title: 'RFID-карты',
      description: `На руках: ${totals.value.cardsIssued}. На складе: ${totals.value.cardsStock}.`,
      status: 'Учёт',
      tone: totals.value.cardsStock === 0 ? 'warning' : 'info',
    })
  }

  return items
})

const currentDate = computed(currentDateRu)
const userName = computed(() => auth.user?.name || 'пользователь')
const dashboardSubtitle = computed(() => `Рабочая сводка ${settingsStore.publicValue('general', 'college_short_name', 'CollegePortal')}`)
const isStudent = computed(() => props.primaryRole === 'student')
const isAdmission = computed(() => props.primaryRole === 'admission')
const isHr = computed(() => props.primaryRole === 'hr')
const dashboardWidgets = computed(() => [
  { id: 'stats', title: isAdmission.value ? 'Приёмная комиссия' : 'Ключевые показатели', defaultSize: 'full' },
  { id: 'actions', title: 'Быстрые действия', defaultSize: 'medium' },
  notifications.value.length ? { id: 'notifications', title: isStudent.value ? 'Учебные уведомления' : isHr.value ? 'Кадровая сводка' : 'Рабочие уведомления', defaultSize: 'medium' } : null,
].filter(Boolean))
const statItems = computed(() => [
  isAdmission.value ? { label: 'Заявления', value: totals.value.applications, icon: NotebookTabs } : null,
  auth.can('students.view') ? { label: 'Студенты', value: totals.value.students, icon: GraduationCap } : null,
  auth.can('groups.view') ? { label: 'Группы', value: totals.value.groups, icon: UsersRound } : null,
  auth.can('teachers.view') ? { label: 'Преподаватели', value: totals.value.teachers, icon: UserRound } : null,
  isHr.value ? { label: 'Сотрудники', value: totals.value.employees, icon: BriefcaseBusiness } : null,
  isHr.value ? { label: 'На территории', value: totals.value.insideNow, icon: DoorOpen } : null,
  isHr.value ? { label: 'Отказы проходной', value: totals.value.denied, icon: DoorOpen } : null,
  auth.can('schedule.view') ? { label: 'Занятия сегодня', value: totals.value.todayLessons, icon: BookOpenCheck } : null,
].filter((item) => item && (!isAdmission.value || item.label === 'Заявления')))
const quickActionPermissions = {
  '/students': 'students.view',
  '/groups': 'groups.view',
  '/schedule': 'schedule.view',
  '/journal': 'journal.view',
  '/identity/my-pass': 'view_own_data',
  '/admissions/foundation': 'admissions.application.view',
  '/hr/employees': 'hr.employees.view',
  '/teachers': 'teachers.view',
  '/access/reports': 'gate.reports',
}
const quickActionsSource = [
  { label: 'Добавить студента', description: 'Открыть форму создания', icon: Plus, to: { path: '/students', query: { action: 'create' } }, permission: 'students.create' },
  { label: 'Добавить группу', description: 'Открыть форму создания', icon: School, to: { path: '/groups', query: { action: 'create' } }, permission: 'groups.create' },
  { label: 'Открыть расписание', description: 'План занятий и аудиторий', icon: CalendarDays, to: '/schedule' },
  { label: 'Открыть журнал', description: 'Посещаемость и оценки', icon: NotebookTabs, to: '/journal' },
  { label: 'Мой QR-пропуск', description: 'Цифровой пропуск', icon: GraduationCap, to: '/identity/my-pass' },
  { label: 'Заявления', description: 'Рабочее место приёмной комиссии', icon: NotebookTabs, to: '/admissions/foundation' },
  { label: 'Сотрудники', description: 'Кадровые карточки и графики', icon: BriefcaseBusiness, to: '/hr/employees' },
  { label: 'Преподаватели', description: 'Связанные преподавательские профили', icon: UserRound, to: '/teachers' },
  { label: 'Отчеты по проходам', description: 'Контроль входов, выходов и отказов', icon: DoorOpen, to: '/access/reports' },
]
const quickActions = computed(() => quickActionsSource.filter((action) => {
  if (isStudent.value && !['/schedule', '/identity/my-pass'].includes(typeof action.to === 'string' ? action.to : action.to?.path)) return false
  if (isAdmission.value && !['/admissions/foundation'].includes(typeof action.to === 'string' ? action.to : action.to?.path)) return false
  if (isHr.value && !['/hr/employees', '/teachers', '/access/reports', '/identity/my-pass'].includes(typeof action.to === 'string' ? action.to : action.to?.path)) return false
  if (action.permission && !auth.can(action.permission)) return false

  const path = typeof action.to === 'string' ? action.to : action.to?.path
  const permission = quickActionPermissions[path]
  return !permission || auth.can(permission)
}))

async function loadDashboard() {
  loading.value = true
  error.value = ''

  try {
    const applicationsResult = isAdmission.value && auth.can('admissions.application.view') ? await api.list('admissions/applications', { per_page: 1 }).then((value) => ({ status: 'fulfilled', value })).catch((reason) => ({ status: 'rejected', reason })) : null
    const studentsResult = !isAdmission.value && auth.can('students.view') ? await api.list('students').then((value) => ({ status: 'fulfilled', value })).catch((reason) => ({ status: 'rejected', reason })) : null
    const groupsResult = !isAdmission.value && auth.can('groups.view') ? await api.list('groups', { per_page: 200 }).then((value) => ({ status: 'fulfilled', value })).catch((reason) => ({ status: 'rejected', reason })) : null
    const teachersResult = !isAdmission.value && auth.can('teachers.view') ? await api.list('teachers', { active_only: 1 }).then((value) => ({ status: 'fulfilled', value })).catch((reason) => ({ status: 'rejected', reason })) : null
    const employeesResult = isHr.value ? await api.list('employees', { per_page: 1 }).then((value) => ({ status: 'fulfilled', value })).catch((reason) => ({ status: 'rejected', reason })) : null
    const accessResult = isHr.value ? await api.list('access/reports/summary').then((value) => ({ status: 'fulfilled', value })).catch((reason) => ({ status: 'rejected', reason })) : null
    const lessonsResult = !isAdmission.value && auth.can('schedule.view') ? await api.list('schedule-lessons', { date: todayIso() }).then((value) => ({ status: 'fulfilled', value })).catch((reason) => ({ status: 'rejected', reason })) : null
    // Карты спрашиваем только у того, кто их ведёт: остальным этот запрос вернул бы отказ.
    const cardsResult = auth.can('rfid.cards.view') ? await api.list('rfid-cards', { per_page: 200 }).then((value) => ({ status: 'fulfilled', value })).catch((reason) => ({ status: 'rejected', reason })) : null

    if (applicationsResult?.status === 'fulfilled') totals.value.applications = extractTotal(applicationsResult.value)
    if (studentsResult?.status === 'fulfilled') totals.value.students = extractTotal(studentsResult.value)
    if (groupsResult?.status === 'fulfilled') totals.value.groups = extractTotal(groupsResult.value)
    if (teachersResult?.status === 'fulfilled') totals.value.teachers = extractTotal(teachersResult.value)
    if (employeesResult?.status === 'fulfilled') totals.value.employees = extractTotal(employeesResult.value)
    if (accessResult?.status === 'fulfilled') { totals.value.insideNow = accessResult.value?.data?.inside_now || 0; totals.value.denied = accessResult.value?.data?.denied || 0 }
    if (lessonsResult?.status === 'fulfilled') totals.value.todayLessons = extractTotal(lessonsResult.value)
    if (cardsResult?.status === 'fulfilled') {
      const cards = Array.isArray(cardsResult.value?.data) ? cardsResult.value.data : []
      totals.value.cardsIssued = cards.filter((card) => card.status === 'issued').length
      totals.value.cardsStock = cards.filter((card) => card.status === 'stock').length
    }

    if (!isStudent.value && !isAdmission.value && [studentsResult, groupsResult, teachersResult, lessonsResult, employeesResult, accessResult].filter(Boolean).some((result) => result.status === 'rejected')) {
      error.value = 'Часть показателей не удалось загрузить'
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
    <section class="dashboard-hero"><div><span>{{ currentDate }}</span><h2>Добро пожаловать, {{ userName }}</h2><p>{{ isStudent ? 'Личный учебный кабинет: расписание, ближайшие занятия и QR-пропуск.' : isAdmission ? 'Рабочая панель приемной комиссии: заявления, документы и комплектность.' : isHr ? 'Кадровая сводка сотрудников, преподавателей и проходной.' : 'Здесь собраны основные показатели, быстрые действия и рабочие уведомления.' }}</p></div></section>
    <AppErrorBanner :message="error" />
    <PersonalDashboardLayout :dashboard-type="`general-${primaryRole}`" :widgets="dashboardWidgets">
      <template #stats>
        <StatsWidget :items="statItems" :loading="loading" />
      </template>
      <template #actions>
        <QuickActionsWidget :actions="quickActions" />
      </template>
      <template #tasks>
        <TasksWidget :items="[]" />
      </template>
      <template #activity>
        <RecentActivityWidget :items="[]" />
      </template>
      <template #notifications>
        <NotificationsWidget :items="notifications" />
      </template>
    </PersonalDashboardLayout>
  </AppPage>
</template>
