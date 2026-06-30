<script setup>
import { computed, onMounted, ref } from 'vue'
import { RefreshCw } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import JournalFilters from './JournalFilters.vue'
import JournalLessonPanel from './JournalLessonPanel.vue'
import { useJournalStore } from '../../stores/journal'

const store = useJournalStore()
const selectedStudent = ref(null)

const tableSubtitle = computed(() => {
  const studentCount = store.studentRows.length
  const lessonCount = store.journalLessons.length
  return `Студентов: ${studentCount}; занятий: ${lessonCount}`
})

function markTone(cell) {
  if (cell.type === 'grade') {
    return Number(cell.value) >= 4 ? 'success' : 'warning'
  }

  const tones = {
    present: 'success',
    absent: 'danger',
    late: 'warning',
    excused: 'info',
    empty: 'neutral',
  }

  return tones[cell.type] || 'neutral'
}

function shortLessonTitle(lesson) {
  return [
    lesson.lesson_date,
    lesson.starts_at,
  ].filter(Boolean).join(' ')
}

function selectStudent(student) {
  selectedStudent.value = student
}

async function selectLesson(lesson) {
  await store.selectLesson(lesson)
}

async function applyFilters(filters) {
  selectedStudent.value = null
  store.setFilters(filters)
  await store.load()
}

async function resetFilters() {
  selectedStudent.value = null
  store.resetFilters()
  await store.load()
}

async function refresh() {
  await store.load()
}

onMounted(async () => {
  await store.load()
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Журнал"
      subtitle="Электронный журнал группы: занятия, оценки и отметки посещаемости."
    />

    <JournalFilters
      :model-value="store.filters"
      :academic-year-options="store.academicYearOptions"
      :group-options="store.groupOptions"
      :subject-options="store.subjectOptions"
      :teacher-options="store.teacherOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading || store.detailsLoading" label="Загрузка журнала..." />
        <q-btn flat :disable="store.loading" @click="refresh">
          <template #default>
            <RefreshCw :size="16" />
            <span>Обновить</span>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <div class="journal-layout">
      <div class="journal-main">
        <div v-if="store.journalLessons.length && store.studentRows.length" class="journal-grid-card">
          <div class="journal-grid-scroll">
            <table class="journal-grid">
              <thead>
                <tr>
                  <th class="journal-grid__student-col">Студент</th>
                  <th
                    v-for="lesson in store.journalLessons"
                    :key="lesson.id"
                    :class="{ 'journal-grid__lesson--selected': Number(lesson.id) === Number(store.selectedLesson?.id) }"
                  >
                    <button type="button" class="journal-grid__lesson-button" @click="selectLesson(lesson)">
                      <span>{{ shortLessonTitle(lesson) }}</span>
                      <strong>{{ lesson.subject?.name || 'Дисциплина' }}</strong>
                    </button>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in store.studentRows"
                  :key="row.student.id"
                  :class="{ 'journal-grid__row--selected': Number(selectedStudent?.id) === Number(row.student.id) }"
                >
                  <th class="journal-grid__student-col">
                    <button type="button" class="journal-grid__student-button" @click="selectStudent(row.student)">
                      <strong>{{ row.fullName }}</strong>
                      <span>{{ row.student.group?.name || 'Группа не указана' }}</span>
                    </button>
                  </th>
                  <td v-for="(cell, index) in row.cells" :key="`${row.student.id}-${store.journalLessons[index]?.id}`">
                    <AppStatusBadge
                      v-if="cell.value"
                      :label="cell.value"
                      :tone="markTone(cell)"
                      class="journal-mark"
                    />
                    <span v-else class="journal-mark journal-mark--empty">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="journal-legend">
            <span><strong>5/4/3/2</strong> оценки</span>
            <span><strong>Н</strong> отсутствовал</span>
            <span><strong>П</strong> присутствовал</span>
            <span><strong>У</strong> уважительно/опоздал</span>
          </div>
        </div>

        <AppEmptyState
          v-else
          title="Данные журнала не найдены"
          description="Выберите группу, дату или дисциплину. Для первой версии отображаются первые найденные занятия."
        />
      </div>

      <aside class="journal-side">
        <JournalLessonPanel
          :lesson="store.selectedLesson"
          :student="selectedStudent"
        />
      </aside>
    </div>
  </AppPage>
</template>
