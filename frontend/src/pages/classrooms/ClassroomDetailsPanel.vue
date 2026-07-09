<script setup>
import { computed } from 'vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'

const props = defineProps({ classroom: { type: Object, default: null }, lessons: { type: Array, default: () => [] } })

function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}
function lessonDate(lesson) {
  return [lesson?.lesson_date, lesson?.starts_at].filter(Boolean).join(' · ')
}

const classroomTitle = computed(() => {
  if (!props.classroom) return ''
  return [props.classroom.number, props.classroom.building ? `корп. ${props.classroom.building}` : ''].filter(Boolean).join(' · ')
})
const scheduleLink = computed(() => ({ path: '/schedule', query: { classroom: props.classroom?.id } }))
const journalLink = computed(() => ({ path: '/journal', query: { classroom: props.classroom?.id } }))
const classroomMetrics = computed(() => [
  { label: 'Вместимость', value: props.classroom?.capacity || '—' },
  { label: 'Тип', value: props.classroom?.type || '—' },
  { label: 'Корпус', value: props.classroom?.building || '—' },
  { label: 'Занятий', value: props.lessons.length },
])
const classroomActions = computed(() => [
  { label: 'Расписание аудитории', to: scheduleLink.value },
  { label: 'История использования', to: journalLink.value },
])
const classroomEvents = computed(() => props.lessons.slice(0, 3).map((lesson) => ({
  id: lesson.id,
  title: lesson.subject?.name || 'Занятие',
  description: [lessonDate(lesson), lesson.group?.name, lesson.teacher ? teacherName(lesson.teacher) : null].filter(Boolean).join(' · ') || 'Детали занятия не указаны',
})))
</script>

<template>
  <AppEmptyState v-if="!classroom" title="Аудитория не выбрана" description="Выберите строку в таблице, чтобы открыть карточку аудитории." />

  <WorkspacePanel v-else class="classroom-details-card" :title="classroomTitle" :subtitle="classroom.description || 'Описание не указано'" :metrics="classroomMetrics" :events="classroomEvents" :actions="classroomActions">
    <template #status>
      <AppStatusBadge label="Активна" tone="success" />
      <AppStatusBadge v-if="classroom.type" :label="classroom.type" tone="info" />
    </template>

    <div class="classroom-details">
      <section class="classroom-details__section">
        <h3>Основное</h3>
        <dl class="classroom-details__list">
          <div><dt>Номер</dt><dd>{{ classroom.number }}</dd></div>
          <div><dt>Корпус</dt><dd>{{ classroom.building || '—' }}</dd></div>
          <div><dt>Этаж</dt><dd>{{ classroom.floor ?? '—' }}</dd></div>
          <div><dt>Тип</dt><dd>{{ classroom.type || '—' }}</dd></div>
          <div><dt>Вместимость</dt><dd>{{ classroom.capacity || '—' }}</dd></div>
        </dl>
      </section>

      <section class="classroom-details__section">
        <h3>Занятия</h3>
        <div v-if="lessons.length" class="classroom-details__lesson-list">
          <div v-for="lesson in lessons.slice(0, 6)" :key="lesson.id"><strong>{{ lesson.subject?.name || 'Занятие' }}</strong><span>{{ lessonDate(lesson) || 'Дата не указана' }}<template v-if="lesson.group"> · {{ lesson.group.name }}</template><template v-if="lesson.teacher"> · {{ teacherName(lesson.teacher) }}</template></span></div>
        </div>
        <p v-else class="classroom-details__muted">Связанные занятия пока не найдены.</p>
      </section>
    </div>
  </WorkspacePanel>
</template>
