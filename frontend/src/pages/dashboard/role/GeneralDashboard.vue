<script setup>
import { computed, onMounted, ref } from 'vue'
import { BookOpenCheck, CalendarDays, GraduationCap, NotebookTabs, Plus, School, UserRound, UsersRound } from '@lucide/vue'
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
import { currentDateRu, extractTotal, todayIso } from './dashboardData'

const auth = useAuthStore()
const settingsStore = useSettingsStore()
const loading = ref(false)
const error = ref('')
const totals = ref({ students: 0, groups: 0, teachers: 0, todayLessons: 0 })

const mockRecentActivity = [
  { id: 1, title: 'Обновлена карточка студента', description: 'Изменены контактные данные и статус обучения', time: 'Сегодня' },
  { id: 2, title: 'Импортированы группы', description: 'CSV-обмен завершен без критических ошибок', time: 'Вчера' },
  { id: 3, title: 'Подготовлен отчет', description: 'Сводка по посещаемости готова к проверке', time: '2 дня назад' },
]
const mockNotifications = [
  { id: 1, title: 'ФРДО', description: 'Интеграция запланирована после расширения карточки студента', status: 'План', tone: 'info' },
  { id: 2, title: 'Расписание', description: 'Проверьте заполнение аудиторий для занятий на неделю', status: 'Внимание', tone: 'warning' },
  { id: 3, title: 'Moodle', description: 'Подключение будет выполняться отдельным этапом', status: 'Ожидает', tone: 'neutral' },
]
const mockTasks = [
  { id: 1, title: 'Проверить список студентов для ФРДО', due: 'До конца недели', status: 'В работе', tone: 'warning', done: false },
  { id: 2, title: 'Сверить группы и образовательные программы', due: 'Сегодня', status: 'Важно', tone: 'danger', done: false },
  { id: 3, title: 'Подготовить перенос раздела “Преподаватели”', due: 'Следующий этап', status: 'План', tone: 'info', done: false },
]

const currentDate = computed(currentDateRu)
const userName = computed(() => auth.user?.name || 'пользователь')
const dashboardSubtitle = computed(() => `Рабочая сводка ${settingsStore.publicValue('general', 'college_short_name', 'CollegePortal')}`)
const statItems = computed(() => [
  { label: 'Студенты', value: totals.value.students, icon: GraduationCap },
  { label: 'Группы', value: totals.value.groups, icon: UsersRound },
  { label: 'Преподаватели', value: totals.value.teachers, icon: UserRound },
  { label: 'Занятия сегодня', value: totals.value.todayLessons, icon: BookOpenCheck },
])
const quickActions = [
  { label: 'Добавить студента', description: 'Открыть форму создания', icon: Plus, to: { path: '/students', query: { action: 'create' } } },
  { label: 'Добавить группу', description: 'Открыть форму создания', icon: School, to: { path: '/groups', query: { action: 'create' } } },
  { label: 'Открыть расписание', description: 'План занятий и аудиторий', icon: CalendarDays, to: '/schedule' },
  { label: 'Открыть журнал', description: 'Посещаемость и оценки', icon: NotebookTabs, to: '/journal' },
]

async function loadDashboard() {
  loading.value = true
  error.value = ''

  try {
    const [studentsResult, groupsResult, teachersResult, lessonsResult] = await Promise.allSettled([
      api.list('students'),
      api.list('groups'),
      api.list('teachers', { active_only: 1 }),
      api.list('schedule-lessons', { date: todayIso() }),
    ])

    if (studentsResult.status === 'fulfilled') totals.value.students = extractTotal(studentsResult.value)
    if (groupsResult.status === 'fulfilled') totals.value.groups = extractTotal(groupsResult.value)
    if (teachersResult.status === 'fulfilled') totals.value.teachers = extractTotal(teachersResult.value)
    if (lessonsResult.status === 'fulfilled') totals.value.todayLessons = extractTotal(lessonsResult.value)

    if ([studentsResult, groupsResult, teachersResult].some((result) => result.status === 'rejected')) {
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
    <section class="dashboard-hero"><div><span>{{ currentDate }}</span><h2>Добро пожаловать, {{ userName }}</h2><p>Здесь собраны основные показатели, быстрые действия и рабочие уведомления.</p></div></section>
    <AppErrorBanner :message="error" />
    <div class="dashboard-grid"><section class="dashboard-grid__full"><StatsWidget :items="statItems" :loading="loading" /></section><QuickActionsWidget :actions="quickActions" /><TasksWidget :items="mockTasks" /><RecentActivityWidget :items="mockRecentActivity" /><NotificationsWidget :items="mockNotifications" /></div>
  </AppPage>
</template>
