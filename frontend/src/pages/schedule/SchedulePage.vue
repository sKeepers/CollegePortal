<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CalendarDays, ChevronLeft, ChevronRight, RefreshCw } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import ScheduleDetailsPanel from './ScheduleDetailsPanel.vue'
import ScheduleFilters from './ScheduleFilters.vue'
import {
  classroomLabel,
  lessonTypeLabels,
  lessonTypeTones,
  teacherName,
  useScheduleStore,
} from '../../stores/schedule'

const store = useScheduleStore()
const route = useRoute()
const router = useRouter()
const syncingQueryFromUi = ref(false)

const activeView = ref(route.query.view ? String(route.query.view) : 'week')
const selectedDate = ref(route.query.date ? String(route.query.date) : todayString())

const viewOptions = [
  { label: 'День', value: 'day' },
  { label: 'Неделя', value: 'week' },
  { label: 'Преподаватель', value: 'teacher' },
  { label: 'Группа', value: 'group' },
  { label: 'Аудитория', value: 'classroom' },
]

const dayFormatter = new Intl.DateTimeFormat('ru-RU', {
  weekday: 'short',
  day: '2-digit',
  month: '2-digit',
})

const longDateFormatter = new Intl.DateTimeFormat('ru-RU', {
  day: '2-digit',
  month: 'long',
  year: 'numeric',
})

const selectedWeekDays = computed(() => {
  const start = startOfWeek(parseLocalDate(selectedDate.value))

  return Array.from({ length: 7 }, (_, index) => {
    const date = addDays(start, index)
    return {
      date,
      value: formatDate(date),
      label: capitalize(dayFormatter.format(date)),
    }
  })
})

const periodTitle = computed(() => {
  if (activeView.value === 'day') {
    return longDateFormatter.format(parseLocalDate(selectedDate.value))
  }

  const days = selectedWeekDays.value
  return `${formatShortDate(days[0].date)} - ${formatShortDate(days[6].date)}`
})

const periodLessons = computed(() => {
  if (activeView.value === 'day') {
    return store.filteredLessons.filter((lesson) => lesson.lesson_date === selectedDate.value)
  }

  if (activeView.value === 'week') {
    const values = new Set(selectedWeekDays.value.map((day) => day.value))
    return store.filteredLessons.filter((lesson) => values.has(lesson.lesson_date))
  }

  return store.filteredLessons
})

const viewGroups = computed(() => {
  if (activeView.value === 'day') {
    return [{
      key: selectedDate.value,
      title: longDateFormatter.format(parseLocalDate(selectedDate.value)),
      subtitle: 'Занятия выбранного дня',
      lessons: sortLessons(periodLessons.value),
    }]
  }

  if (activeView.value === 'week') {
    return selectedWeekDays.value.map((day) => ({
      key: day.value,
      title: day.label,
      subtitle: day.value,
      lessons: sortLessons(periodLessons.value.filter((lesson) => lesson.lesson_date === day.value)),
    }))
  }

  return groupedLessons.value
})

const groupedLessons = computed(() => {
  const keyByView = {
    teacher: (lesson) => lesson.teacher_id || 'none',
    group: (lesson) => lesson.group_id || 'none',
    classroom: (lesson) => lesson.classroom_id || 'none',
  }
  const titleByView = {
    teacher: (lesson) => teacherName(lesson.teacher) || 'Преподаватель не указан',
    group: (lesson) => lesson.group?.name || 'Группа не указана',
    classroom: (lesson) => classroomLabel(lesson.classroom) || 'Аудитория не указана',
  }
  const subtitleByView = {
    teacher: 'Расписание преподавателя',
    group: 'Расписание группы',
    classroom: 'Занятость аудитории',
  }
  const buckets = new Map()

  periodLessons.value.forEach((lesson) => {
    const key = keyByView[activeView.value]?.(lesson) || 'none'

    if (!buckets.has(key)) {
      buckets.set(key, {
        key,
        title: titleByView[activeView.value]?.(lesson) || 'Без названия',
        subtitle: subtitleByView[activeView.value],
        lessons: [],
      })
    }

    buckets.get(key).lessons.push(lesson)
  })

  return [...buckets.values()]
    .map((group) => ({ ...group, lessons: sortLessons(group.lessons) }))
    .sort((left, right) => left.title.localeCompare(right.title, 'ru'))
})

const totalLessons = computed(() => periodLessons.value.length)
const totalGroups = computed(() => new Set(periodLessons.value.map((lesson) => lesson.group_id).filter(Boolean)).size)
const totalTeachers = computed(() => new Set(periodLessons.value.map((lesson) => lesson.teacher_id).filter(Boolean)).size)
const totalClassrooms = computed(() => new Set(periodLessons.value.map((lesson) => lesson.classroom_id).filter(Boolean)).size)

function todayString() {
  return formatDate(new Date())
}

function parseLocalDate(value) {
  const [year, month, day] = String(value || todayString()).split('-').map(Number)
  return new Date(year, (month || 1) - 1, day || 1)
}

function formatDate(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function startOfWeek(date) {
  const copy = new Date(date)
  const day = copy.getDay() || 7
  copy.setDate(copy.getDate() - day + 1)
  return copy
}

function addDays(date, count) {
  const copy = new Date(date)
  copy.setDate(copy.getDate() + count)
  return copy
}

function formatShortDate(date) {
  return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
}

function capitalize(value) {
  return value ? value.charAt(0).toUpperCase() + value.slice(1) : value
}

function sortLessons(lessons) {
  return [...lessons].sort((left, right) => (
    `${left.lesson_date || ''} ${left.starts_at || ''}`.localeCompare(`${right.lesson_date || ''} ${right.starts_at || ''}`)
  ))
}

function lessonTypeText(type) {
  return lessonTypeLabels[type] || type || 'Тип не указан'
}

function lessonTypeTone(type) {
  return lessonTypeTones[type] || 'neutral'
}

function isSelected(lesson) {
  return Number(lesson.id) === Number(store.selectedId)
}

async function syncQuery() {
  const query = {
    ...route.query,
    view: activeView.value,
    date: selectedDate.value,
  }

  if (store.selectedId) {
    query.selected = store.selectedId
  } else {
    delete query.selected
  }

  syncingQueryFromUi.value = true
  await router.replace({ path: '/schedule', query })
  syncingQueryFromUi.value = false
}

async function selectLesson(lesson) {
  store.selectLesson(lesson)
  await syncQuery()
}

async function applyFilters(filters) {
  store.setFilters(filters)
  store.selectLessonById(null)
  await store.load()
  await syncQuery()
}

async function resetFilters() {
  store.resetFilters()
  store.selectLessonById(null)
  await store.load()
  await syncQuery()
}

async function refresh() {
  await store.load()
}

async function changePeriod(days) {
  selectedDate.value = formatDate(addDays(parseLocalDate(selectedDate.value), days))
  await syncQuery()
}

async function setToday() {
  selectedDate.value = todayString()
  await syncQuery()
}

watch(activeView, syncQuery)
watch(selectedDate, syncQuery)

watch(
  () => [route.query.view, route.query.date, route.query.selected],
  () => {
    if (syncingQueryFromUi.value) {
      return
    }

    activeView.value = route.query.view ? String(route.query.view) : 'week'
    selectedDate.value = route.query.date ? String(route.query.date) : todayString()
    store.selectLessonById(route.query.selected ? String(route.query.selected) : '')
  },
)

onMounted(async () => {
  store.selectLessonById(route.query.selected ? String(route.query.selected) : '')
  await store.load()
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Расписание"
      subtitle="Недельное, дневное и ресурсное представление занятий."
    />

    <div class="schedule-viewbar">
      <q-btn-toggle
        v-model="activeView"
        unelevated
        no-caps
        toggle-color="primary"
        color="white"
        text-color="secondary"
        :options="viewOptions"
      />

      <div class="schedule-period">
        <q-btn flat round dense :disable="store.loading" @click="changePeriod(activeView === 'day' ? -1 : -7)">
          <ChevronLeft :size="17" />
        </q-btn>
        <q-input
          v-model="selectedDate"
          dense
          outlined
          type="date"
          class="schedule-period__date"
        />
        <q-btn flat round dense :disable="store.loading" @click="changePeriod(activeView === 'day' ? 1 : 7)">
          <ChevronRight :size="17" />
        </q-btn>
        <q-btn flat no-caps :disable="store.loading" @click="setToday">Сегодня</q-btn>
      </div>
    </div>

    <ScheduleFilters
      :model-value="store.filters"
      :academic-year-options="store.academicYearOptions"
      :group-options="store.groupOptions"
      :teacher-options="store.teacherOptions"
      :classroom-options="store.classroomOptions"
      :subject-options="store.subjectOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ periodTitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка расписания..." />
        <q-btn flat :disable="store.loading" @click="refresh">
          <template #default>
            <RefreshCw :size="16" />
            <span>Обновить</span>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <div class="schedule-summary">
      <div>
        <CalendarDays :size="18" />
        <span>Занятий</span>
        <strong>{{ totalLessons }}</strong>
      </div>
      <div>
        <span>Групп</span>
        <strong>{{ totalGroups }}</strong>
      </div>
      <div>
        <span>Преподавателей</span>
        <strong>{{ totalTeachers }}</strong>
      </div>
      <div>
        <span>Аудиторий</span>
        <strong>{{ totalClassrooms }}</strong>
      </div>
    </div>

    <div class="schedule-layout">
      <div class="schedule-main">
        <div v-if="viewGroups.length" class="schedule-board" :class="`schedule-board--${activeView}`">
          <section v-for="group in viewGroups" :key="group.key" class="schedule-column">
            <header>
              <div>
                <h2>{{ group.title }}</h2>
                <p>{{ group.subtitle }}</p>
              </div>
              <span>{{ group.lessons.length }}</span>
            </header>

            <div v-if="group.lessons.length" class="schedule-column__items">
              <button
                v-for="lesson in group.lessons"
                :key="lesson.id"
                type="button"
                class="schedule-lesson-card"
                :class="[
                  `schedule-lesson-card--${lesson.lesson_type || 'lesson'}`,
                  { 'schedule-lesson-card--selected': isSelected(lesson) },
                ]"
                @click="selectLesson(lesson)"
              >
                <span class="schedule-lesson-card__time">
                  {{ lesson.starts_at || '—' }}–{{ lesson.ends_at || '—' }}
                </span>
                <strong>{{ lesson.subject?.name || 'Дисциплина не указана' }}</strong>
                <small>{{ lesson.group?.name || 'Группа не указана' }} · {{ teacherName(lesson.teacher) || 'Преподаватель не указан' }}</small>
                <small>{{ classroomLabel(lesson.classroom) || 'Аудитория не указана' }}</small>
                <AppStatusBadge :label="lessonTypeText(lesson.lesson_type)" :tone="lessonTypeTone(lesson.lesson_type)" />
              </button>
            </div>

            <div v-else class="schedule-column__empty">
              Занятий нет
            </div>
          </section>
        </div>

        <AppEmptyState
          v-else
          title="Расписание не найдено"
          description="Измените фильтры или выберите другой период."
        />
      </div>

      <aside class="schedule-side">
        <ScheduleDetailsPanel
          :lesson="store.selectedLesson"
          :conflicts="store.selectedLessonConflicts"
        />
      </aside>
    </div>
  </AppPage>
</template>
