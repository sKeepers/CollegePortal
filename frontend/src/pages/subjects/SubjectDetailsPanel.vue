<script setup>
import { computed } from 'vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'

const props = defineProps({
  subject: { type: Object, default: null },
  teachers: { type: Array, default: () => [] },
  lessons: { type: Array, default: () => [] },
})

function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}
function lessonDate(lesson) {
  return [lesson?.lesson_date, lesson?.starts_at].filter(Boolean).join(' · ')
}

const scheduleLink = computed(() => ({ path: '/schedule', query: { subject: props.subject?.id } }))
const journalLink = computed(() => ({ path: '/journal', query: { subject: props.subject?.id } }))
const teachersLink = computed(() => ({ path: '/teachers', query: { subject: props.subject?.id } }))
const subjectMetrics = computed(() => [
  { label: 'Код', value: props.subject?.code || '—' },
  { label: 'Преподавателей', value: props.teachers.length, to: teachersLink.value },
  { label: 'Учебных планов', value: '—' },
  { label: 'Занятий', value: props.lessons.length },
])
const subjectActions = computed(() => [
  { label: 'Расписание', to: scheduleLink.value },
  { label: 'Преподаватели', to: teachersLink.value },
  { label: 'Учебные планы', to: '/curricula' },
])
const subjectEvents = computed(() => props.lessons.slice(0, 3).map((lesson) => ({
  id: lesson.id,
  title: lesson.group?.name || 'Занятие',
  description: [lessonDate(lesson), lesson.teacher ? teacherName(lesson.teacher) : null].filter(Boolean).join(' · ') || 'Детали занятия не указаны',
})))
</script>

<template>
  <AppEmptyState v-if="!subject" title="Дисциплина не выбрана" description="Выберите строку в таблице, чтобы открыть карточку дисциплины." />

  <WorkspacePanel v-else class="subject-details-card" :title="subject.name" :subtitle="subject.description || subject.department || 'Описание не указано'" :metrics="subjectMetrics" :events="subjectEvents" :actions="subjectActions">
    <template #status>
      <AppStatusBadge label="Активна" tone="success" />
      <AppStatusBadge v-if="subject.department" :label="subject.department" tone="info" />
    </template>

    <div class="subject-details">
      <section class="subject-details__section">
        <h3>Основное</h3>
        <dl class="subject-details__list">
          <div><dt>Название</dt><dd>{{ subject.name }}</dd></div>
          <div><dt>Код</dt><dd>{{ subject.code || '—' }}</dd></div>
          <div><dt>Отделение</dt><dd>{{ subject.department || '—' }}</dd></div>
        </dl>
      </section>

      <section class="subject-details__section">
        <h3>Преподаватели</h3>
        <div v-if="teachers.length" class="subject-details__tags"><q-chip v-for="teacher in teachers.slice(0, 8)" :key="teacher.id" dense>{{ teacherName(teacher) }}</q-chip></div>
        <p v-else class="subject-details__muted">Связанные преподаватели пока не указаны.</p>
      </section>

      <section class="subject-details__section">
        <h3>Группы и занятия</h3>
        <div v-if="lessons.length" class="subject-details__lesson-list">
          <div v-for="lesson in lessons.slice(0, 5)" :key="lesson.id"><strong>{{ lesson.group?.name || 'Группа не указана' }}</strong><span>{{ lessonDate(lesson) || 'Дата не указана' }}<template v-if="lesson.teacher"> · {{ teacherName(lesson.teacher) }}</template></span></div>
        </div>
        <p v-else class="subject-details__muted">Связанные занятия пока не найдены.</p>
      </section>
    </div>
  </WorkspacePanel>
</template>
