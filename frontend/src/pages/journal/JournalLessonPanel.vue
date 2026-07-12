<script setup>
import { computed, reactive, watch } from 'vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import { lessonTypeLabels, lessonTypeTones, teacherName } from '../../stores/schedule'

const props = defineProps({ lesson: { type: Object, default: null }, student: { type: Object, default: null } })
const emit = defineEmits(['save', 'complete', 'sign', 'mark-all-present'])
const form = reactive({ topic: '', homework: '', teacher_comment: '' })
watch(() => props.lesson, (lesson) => {
  form.topic = lesson?.topic || ''
  form.homework = lesson?.homework || ''
  form.teacher_comment = lesson?.teacher_comment || ''
}, { immediate: true })

const lessonTypeText = computed(() => props.lesson?.lesson_type?.name || lessonTypeLabels[props.lesson?.lesson_type] || props.lesson?.lesson_type || 'Тип не указан')
const lessonTypeTone = computed(() => props.lesson?.status === 'signed' ? 'success' : (lessonTypeTones[props.lesson?.lesson_type] || 'neutral'))
const teacherText = computed(() => teacherName(props.lesson?.teacher) || 'Преподаватель не указан')
const groupText = computed(() => props.lesson?.group?.name || 'Группа не указана')
const classroomText = computed(() => props.lesson?.classroom?.number || 'Аудитория не указана')
const homeworkText = computed(() => props.lesson?.homework || props.lesson?.homework_text || 'Домашнее задание не указано')
const isSigned = computed(() => props.lesson?.status === 'signed')
function save() { emit('save', { ...form }) }
const lessonMetrics = computed(() => [
  { label: 'Группа', value: groupText.value, to: props.lesson?.group_id ? { path: '/groups', query: { selected: props.lesson.group_id } } : null },
  { label: 'Преподаватель', value: teacherText.value },
  { label: 'Аудитория', value: classroomText.value },
  { label: 'Время', value: `${props.lesson?.starts_at || '—'}–${props.lesson?.ends_at || '—'}` },
])
const lessonActions = computed(() => [
  { label: props.student?.id ? 'Студент' : 'Студенты группы', to: props.student?.id ? { path: '/students', query: { group: props.lesson?.group_id, selected: props.student.id } } : { path: '/students', query: { group: props.lesson?.group_id } }, disabled: !props.lesson?.group_id },
  { label: 'Группа', to: { path: '/groups', query: { selected: props.lesson?.group_id } }, disabled: !props.lesson?.group_id },
  { label: 'Расписание', to: { path: '/schedule', query: { selected: props.lesson?.id, date: props.lesson?.lesson_date } } },
])
</script>

<template>
  <AppEmptyState v-if="!lesson" title="Занятие не выбрано" description="Выберите колонку занятия в журнале, чтобы открыть подробности." />

  <WorkspacePanel v-else class="journal-lesson-card" :title="lesson.subject?.name || 'Дисциплина не указана'" :subtitle="[lesson.topic || 'Тема занятия не указана', lesson.lesson_date || 'Дата не указана']" :metrics="lessonMetrics" :actions="lessonActions">
    <template #status><AppStatusBadge :label="lesson.status === 'signed' ? 'Подписано' : lessonTypeText" :tone="lessonTypeTone" /></template>

<style scoped>
.journal-lesson__form {
  display: grid;
  gap: 10px;
}

.journal-lesson__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>

    <div class="journal-lesson">
      <section class="journal-lesson__section">
        <h3>Заполнение</h3>
        <div class="journal-lesson__form">
          <q-input v-model="form.topic" dense outlined autogrow label="Тема занятия" :readonly="isSigned" />
          <q-input v-model="form.homework" dense outlined autogrow label="Домашнее задание" :readonly="isSigned" />
          <q-input v-model="form.teacher_comment" dense outlined autogrow label="Комментарий преподавателя" :readonly="isSigned" />
          <div class="journal-lesson__actions">
            <q-btn color="primary" label="Сохранить" :disable="isSigned" @click="save" />
            <q-btn outline color="positive" label="Все присутствуют" :disable="isSigned" @click="emit('mark-all-present')" />
            <q-btn outline color="primary" label="Завершить" :disable="isSigned" @click="emit('complete')" />
            <q-btn color="positive" label="Подписать" :disable="isSigned" @click="emit('sign')" />
          </div>
        </div>
      </section>

      <section class="journal-lesson__section">
        <h3>Занятие</h3>
        <dl class="journal-lesson__list">
          <div><dt>Дисциплина</dt><dd>{{ lesson.subject?.name || '—' }}</dd></div>
          <div><dt>Преподаватель</dt><dd>{{ teacherText }}</dd></div>
          <div><dt>Группа</dt><dd>{{ groupText }}</dd></div>
          <div><dt>Аудитория</dt><dd>{{ classroomText }}</dd></div>
          <div><dt>Дата</dt><dd>{{ lesson.lesson_date || '—' }}</dd></div>
          <div><dt>Тема</dt><dd>{{ lesson.topic || '—' }}</dd></div>
          <div><dt>Домашнее задание</dt><dd>{{ homeworkText }}</dd></div>
        </dl>
      </section>
    </div>
  </WorkspacePanel>
</template>

<style scoped>
.journal-lesson__form {
  display: grid;
  gap: 10px;
}

.journal-lesson__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
