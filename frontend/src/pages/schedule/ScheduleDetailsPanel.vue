<script setup>
import { computed } from 'vue'
import { AlertTriangle } from '@lucide/vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import { classroomLabel, lessonTypeLabels, lessonTypeTones, teacherName } from '../../stores/schedule'
import { useAuthStore } from '../../stores/auth'

const props = defineProps({ lesson: { type: Object, default: null }, conflicts: { type: Array, default: () => [] } })
const auth = useAuthStore()

const lessonTypeText = computed(() => lessonTypeLabels[props.lesson?.lesson_type] || props.lesson?.lesson_type || 'Тип не указан')
const lessonTypeTone = computed(() => lessonTypeTones[props.lesson?.lesson_type] || 'neutral')
const teacherText = computed(() => teacherName(props.lesson?.teacher) || 'Преподаватель не указан')
const classroomText = computed(() => classroomLabel(props.lesson?.classroom) || 'Аудитория не указана')
const groupText = computed(() => props.lesson?.group?.name || 'Группа не указана')
const subjectText = computed(() => props.lesson?.subject?.name || 'Дисциплина не указана')
const lessonMetrics = computed(() => [
  { label: 'Группа', value: groupText.value, to: auth.can('groups.view') && props.lesson?.group_id ? { path: '/groups', query: { selected: props.lesson.group_id } } : null },
  { label: 'Преподаватель', value: teacherText.value, to: auth.can('teachers.view') && props.lesson?.teacher_id ? { path: '/teachers', query: { selected: props.lesson.teacher_id } } : null },
  { label: 'Аудитория', value: classroomText.value, to: auth.can('classrooms.view') && props.lesson?.classroom_id ? { path: '/classrooms', query: { selected: props.lesson.classroom_id } } : null },
  { label: 'Время', value: `${props.lesson?.starts_at || '—'}–${props.lesson?.ends_at || '—'}` },
])
const lessonActions = computed(() => [
  { label: 'Открыть журнал', to: props.lesson?.schedule_entry_id ? { path: '/journal', query: { lesson: props.lesson.schedule_entry_id } } : { path: '/journal', query: { legacyLesson: props.lesson?.id } }, disabled: props.lesson?.status === 'cancelled' },
  ...(auth.can('groups.view') ? [{ label: 'Группа', to: { path: '/groups', query: { selected: props.lesson?.group_id } }, disabled: !props.lesson?.group_id }] : []),
  ...(auth.can('teachers.view') ? [{ label: 'Преподаватель', to: { path: '/teachers', query: { selected: props.lesson?.teacher_id } }, disabled: !props.lesson?.teacher_id }] : []),
  ...(auth.can('classrooms.view') ? [{ label: 'Аудитория', to: { path: '/classrooms', query: { selected: props.lesson?.classroom_id } }, disabled: !props.lesson?.classroom_id }] : []),
])
</script>

<template>
  <AppEmptyState v-if="!lesson" title="Занятие не выбрано" description="Выберите занятие в расписании, чтобы открыть подробности." />

  <WorkspacePanel v-else class="schedule-details-card" :title="subjectText" :subtitle="[lesson.topic || 'Тема занятия не указана', lesson.lesson_date || 'Дата не указана']" :metrics="lessonMetrics" :actions="lessonActions">
    <template #status><AppStatusBadge :label="lessonTypeText" :tone="lessonTypeTone" /></template>

    <div class="schedule-details">
      <q-banner v-if="conflicts.length" rounded class="schedule-details__warning">
        <template #avatar><AlertTriangle :size="18" /></template>
        <strong>Возможный конфликт</strong>
        <ul><li v-for="conflict in conflicts" :key="conflict">{{ conflict }}</li></ul>
      </q-banner>

      <section class="schedule-details__section">
        <h3>Занятие</h3>
        <dl class="schedule-details__list">
          <div><dt>Дисциплина</dt><dd>{{ subjectText }}</dd></div>
          <div><dt>Преподаватель</dt><dd>{{ teacherText }}</dd></div>
          <div><dt>Группа</dt><dd>{{ groupText }}</dd></div>
          <div><dt>Аудитория</dt><dd>{{ classroomText }}</dd></div>
          <div><dt>Дата</dt><dd>{{ lesson.lesson_date || '—' }}</dd></div>
          <div><dt>Время</dt><dd>{{ lesson.starts_at || '—' }}–{{ lesson.ends_at || '—' }}</dd></div>
        </dl>
      </section>
    </div>
  </WorkspacePanel>
</template>
