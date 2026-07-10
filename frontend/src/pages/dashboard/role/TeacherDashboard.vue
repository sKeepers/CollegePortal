<script setup>
import { computed, onMounted, ref } from 'vue'
import { BadgeCheck, BookOpen, CalendarDays, ClipboardList, GraduationCap, NotebookTabs } from '@lucide/vue'
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
import PersonalDashboardLayout from '../../../components/dashboard/PersonalDashboardLayout.vue'
import { currentDateRu, extractRows, extractTotal, groupName, teacherName, todayIso } from './dashboardData'

const auth = useAuthStore()
const settingsStore = useSettingsStore()
const loading = ref(false)
const error = ref('')
const teacher = ref(null)
const todayLessons = ref([])
const teachingLoads = ref([])
const exams = ref([])
const journals = ref([])
const teacherGroups = ref([])

const currentDate = computed(currentDateRu)
const userName = computed(() => teacherName(teacher.value) || auth.user?.name || 'преподаватель')
const dashboardSubtitle = computed(() => `Рабочий стол преподавателя · ${settingsStore.publicValue('general', 'college_short_name', 'CollegePortal')}`)
const dashboardWidgets = [
  { id: 'stats', title: 'Ключевые показатели', defaultSize: 'full' },
  { id: 'actions', title: 'Быстрые действия', defaultSize: 'medium' },
  { id: 'notifications', title: 'Уведомления', defaultSize: 'medium' },
  { id: 'activity', title: 'Сегодняшние занятия', defaultSize: 'medium' },
  { id: 'nearest', title: 'Ближайшее занятие', defaultSize: 'medium' },
]
const teacherId = computed(() => teacher.value?.id || auth.user?.person_id || null)
const nearestLesson = computed(() => todayLessons.value[0] || null)
const statItems = computed(() => [
  { label: 'Мои занятия сегодня', value: todayLessons.value.length, icon: CalendarDays },
  { label: 'Мои группы', value: teacherGroups.value.length, icon: GraduationCap },
  { label: 'Моя нагрузка', value: teachingLoads.value.length, icon: ClipboardList },
  { label: 'Ближайшие экзамены', value: exams.value.length, icon: BookOpen },
])
const quickActions = computed(() => [
  { label: 'Мое расписание', description: 'Занятия преподавателя', icon: CalendarDays, to: teacherId.value ? { path: '/schedule', query: { teacher: teacherId.value } } : '/schedule' },
  { label: 'Журнал', description: 'Посещаемость и оценки', icon: NotebookTabs, to: teacherId.value ? { path: '/journal', query: { teacher: teacherId.value } } : '/journal' },
  { label: 'Нагрузка', description: 'Учебная нагрузка', icon: ClipboardList, to: teacherId.value ? { path: '/teaching-load', query: { teacher: teacherId.value } } : '/teaching-load' },
  { label: 'Экзамены', description: 'Экзамены и ГИА', icon: BookOpen, to: teacherId.value ? { path: '/exams', query: { teacher: teacherId.value } } : '/exams' },
  { label: 'Мой QR-пропуск', description: 'Цифровая идентификация', icon: BadgeCheck, to: teacherId.value ? { path: '/identity/digital-passes', query: { owner: 'teacher', selected: teacherId.value } } : '/identity/digital-passes' },
])
const lessonActivity = computed(() => todayLessons.value.slice(0, 5).map((lesson) => ({
  id: lesson.id,
  title: lesson.subject?.name || 'Занятие',
  description: [groupName(lesson.group), lesson.classroom?.number, `${lesson.starts_at || '—'}–${lesson.ends_at || '—'}`].filter(Boolean).join(' · '),
  time: lesson.lesson_date || 'Сегодня',
})))
const notifications = computed(() => {
  const items = []
  if (nearestLesson.value) {
    items.push({ id: 'nearest', title: 'Ближайшее занятие', description: `${nearestLesson.value.subject?.name || 'Занятие'} · ${nearestLesson.value.starts_at || '—'}`, status: 'Сегодня', tone: 'info' })
  }
  if (!teacher.value) {
    items.push({ id: 'no-teacher', title: 'Связь с преподавателем', description: 'Пользователь пока не связан с карточкой преподавателя. Показана общая преподавательская панель.', status: 'Настроить', tone: 'warning' })
  }
  if (!items.length) {
    items.push({ id: 'empty', title: 'Расписание', description: 'На сегодня занятия не найдены', status: 'Свободно', tone: 'success' })
  }
  return items
})

function findTeacher(teachers) {
  if (auth.user?.person_type === 'teacher' && auth.user?.person_id) {
    return teachers.find((item) => Number(item.id) === Number(auth.user.person_id)) || null
  }

  const email = String(auth.user?.email || '').toLowerCase()
  if (email) {
    return teachers.find((item) => String(item.email || '').toLowerCase() === email) || null
  }

  return null
}

async function loadDashboard() {
  loading.value = true
  error.value = ''

  try {
    const teachersPayload = await api.list('teachers', { active_only: 1 }).catch(() => ({ data: [] }))
    teacher.value = findTeacher(extractRows(teachersPayload))
    const id = teacherId.value
    const today = todayIso()
    const [lessonsResult, loadsResult, examsResult, journalResult] = await Promise.allSettled([
      api.list('schedule-lessons', id ? { teacher: id, date: today } : { date: today }),
      api.list('teaching-loads', id ? { teacher_id: id } : {}),
      api.list('exams', id ? { teacher_id: id } : {}),
      api.list('schedule-lessons', id ? { teacher: id } : {}),
    ])

    if (lessonsResult.status === 'fulfilled') todayLessons.value = extractRows(lessonsResult.value)
    if (loadsResult.status === 'fulfilled') teachingLoads.value = extractRows(loadsResult.value)
    if (examsResult.status === 'fulfilled') exams.value = extractRows(examsResult.value)
    if (journalResult.status === 'fulfilled') journals.value = extractRows(journalResult.value)

    const groups = new Map()
    ;[...todayLessons.value, ...journals.value].forEach((lesson) => {
      if (lesson.group?.id) groups.set(lesson.group.id, lesson.group)
    })
    teachingLoads.value.forEach((load) => {
      ;(load.items || []).forEach((item) => {
        if (item.group?.id) groups.set(item.group.id, item.group)
      })
    })
    teacherGroups.value = Array.from(groups.values())

    if ([lessonsResult, loadsResult, examsResult].some((result) => result.status === 'rejected')) {
      error.value = 'Часть показателей преподавателя не удалось загрузить'
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
    <section class="dashboard-hero dashboard-hero--teacher"><div><span>{{ currentDate }}</span><h2>Добро пожаловать, {{ userName }}</h2><p>Персональная преподавательская сводка: занятия, журнал, нагрузка, группы и экзамены.</p></div></section>
    <AppErrorBanner :message="error" />
    <PersonalDashboardLayout dashboard-type="teacher" :widgets="dashboardWidgets">
      <template #stats>
        <StatsWidget :items="statItems" :loading="loading" />
      </template>
      <template #actions>
        <QuickActionsWidget :actions="quickActions" />
      </template>
      <template #notifications>
        <NotificationsWidget :items="notifications" />
      </template>
      <template #activity>
        <RecentActivityWidget :items="lessonActivity" />
      </template>
      <template #nearest>
        <section class="dashboard-role-card">
          <h3>Ближайшее занятие</h3>
          <div v-if="nearestLesson" class="dashboard-role-next">
            <strong>{{ nearestLesson.subject?.name || 'Занятие' }}</strong>
            <span>{{ groupName(nearestLesson.group) }}</span>
            <span>{{ nearestLesson.starts_at || '—' }}–{{ nearestLesson.ends_at || '—' }}</span>
            <AppStatusBadge label="Сегодня" tone="info" />
          </div>
          <p v-else class="dashboard-role-empty">На сегодня ближайшее занятие не найдено.</p>
        </section>
      </template>
    </PersonalDashboardLayout>
  </AppPage>
</template>
