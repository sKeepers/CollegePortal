<script setup>
import { computed, reactive, ref, watch } from 'vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import { api } from '../../services/api'
import { useAuthStore } from '../../stores/auth'
import { lessonTypeLabels, lessonTypeTones, teacherName } from '../../stores/schedule'

const props = defineProps({
  lesson: { type: Object, default: null },
  student: { type: Object, default: null },
  files: { type: Array, default: () => [] },
  readOnly: { type: Boolean, default: false },
  canEdit: { type: Boolean, default: true },
  canFiles: { type: Boolean, default: true },
  canReopen: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'complete', 'sign', 'reopen', 'mark-all-present', 'upload-file', 'delete-file'])
const auth = useAuthStore()
const form = reactive({ topic: '', homework: '', homework_due_at: '', teacher_comment: '' })
const reopenReason = ref('')
const fileInput = ref(null)

watch(() => props.lesson, (lesson) => {
  form.topic = lesson?.topic || ''
  form.homework = lesson?.homework || ''
  form.homework_due_at = lesson?.homework_due_at ? String(lesson.homework_due_at).slice(0, 16) : ''
  form.teacher_comment = lesson?.teacher_comment || ''
  reopenReason.value = ''
}, { immediate: true })

const lessonTypeText = computed(() => props.lesson?.lesson_type?.name || lessonTypeLabels[props.lesson?.lesson_type] || props.lesson?.lesson_type || 'Тип не указан')
const lessonTypeTone = computed(() => props.lesson?.status === 'signed' ? 'success' : (lessonTypeTones[props.lesson?.lesson_type] || 'neutral'))
const teacherText = computed(() => teacherName(props.lesson?.teacher) || 'Преподаватель не указан')
const groupText = computed(() => props.lesson?.group?.name || 'Группа не указана')
const classroomText = computed(() => props.lesson?.classroom?.number || props.lesson?.schedule_entry?.classroom?.number || 'Аудитория не указана')
const homeworkText = computed(() => props.lesson?.homework || props.lesson?.homework_text || 'Домашнее задание не указано')
const metrics = computed(() => props.lesson?.metrics || {})
const signedText = computed(() => props.lesson?.signed_at ? `${props.lesson.signed_at}${props.lesson.signed_by ? ` · ${props.lesson.signed_by}` : ''}` : 'Не подписано')

const lessonMetrics = computed(() => [
  { label: 'Студентов', value: metrics.value.students ?? '—' },
  { label: 'Присутствовали', value: metrics.value.present ?? '—' },
  { label: 'Отсутствовали', value: metrics.value.absent ?? '—' },
  { label: 'Оценок', value: metrics.value.grades ?? '—' },
])

const lessonActions = computed(() => [
  ...(auth.can('students.view') ? [{ label: props.student?.id ? 'Открыть студента' : 'Студенты группы', to: props.student?.id ? { path: '/students', query: { group: props.lesson?.group_id, selected: props.student.id } } : { path: '/students', query: { group: props.lesson?.group_id } }, disabled: !props.lesson?.group_id }] : []),
  ...(auth.can('groups.view') ? [{ label: 'Группа', to: { path: '/groups', query: { selected: props.lesson?.group_id } }, disabled: !props.lesson?.group_id }] : []),
  ...(auth.can('schedule.view') ? [{ label: 'Расписание', to: { path: '/schedule', query: { date: props.lesson?.lesson_date } } }] : []),
])

function statusLabel(status) {
  return { draft: 'Черновик', in_progress: 'В работе', planned: 'Запланировано', opened: 'Открыто', completed: 'Завершено', signed: 'Подписано', reopened: 'Переоткрыто', cancelled: 'Отменено' }[status] || 'Статус не указан'
}
function statusTone(status) {
  return { draft: 'neutral', in_progress: 'info', planned: 'neutral', opened: 'info', completed: 'warning', signed: 'success', reopened: 'warning', cancelled: 'danger' }[status] || 'neutral'
}
function save() { emit('save', { ...form, homework_due_at: form.homework_due_at || null }) }
function upload(event) {
  const file = event.target.files?.[0]
  if (file) emit('upload-file', file)
  event.target.value = ''
}
function fileUrl(file) { return `${api.baseUrl}/journal/lessons/${props.lesson.id}/files/${file.id}/download` }
function reopen() {
  if (reopenReason.value.trim()) emit('reopen', reopenReason.value.trim())
}
</script>

<template>
  <AppEmptyState v-if="!lesson" title="Занятие не выбрано" description="Выберите занятие, чтобы открыть подробности и заполнить журнал." />

  <WorkspacePanel v-else class="journal-lesson-card" :title="lesson.subject?.name || 'Дисциплина не указана'" :subtitle="[lesson.topic || 'Тема занятия не указана', lesson.lesson_date || 'Дата не указана']" :metrics="lessonMetrics" :actions="lessonActions">
    <template #status>
      <div class="journal-statuses">
        <AppStatusBadge :label="statusLabel(lesson.status)" :tone="statusTone(lesson.status)" />
        <AppStatusBadge :label="lessonTypeText" :tone="lessonTypeTone" />
      </div>
    </template>

    <div class="journal-lesson">
      <section class="journal-lesson__section">
        <h3>Заполнение занятия</h3>
        <div class="journal-lesson__form">
          <q-input v-model="form.topic" dense outlined autogrow label="Тема занятия" :readonly="readOnly || !canEdit" />
          <q-input v-model="form.homework" dense outlined autogrow label="Домашнее задание" :readonly="readOnly || !canEdit" />
          <q-input v-model="form.homework_due_at" dense outlined type="datetime-local" label="Срок выполнения" :readonly="readOnly || !canEdit" />
          <q-input v-model="form.teacher_comment" dense outlined autogrow label="Комментарий преподавателя" :readonly="readOnly || !canEdit" />
          <div class="journal-lesson__actions">
            <q-btn color="primary" label="Сохранить черновик" :disable="readOnly || !canEdit" @click="save" />
            <q-btn outline color="positive" label="Все присутствуют" :disable="readOnly" @click="emit('mark-all-present')" />
            <q-btn outline color="primary" label="Завершить занятие" :disable="readOnly" @click="emit('complete')" />
            <q-btn color="positive" label="Подписать журнал" :disable="readOnly" @click="emit('sign')" />
          </div>
        </div>
      </section>

      <section class="journal-lesson__section">
        <h3>Занятие</h3>
        <dl class="journal-lesson__list">
          <div><dt>Преподаватель</dt><dd>{{ teacherText }}</dd></div>
          <div><dt>Группа</dt><dd>{{ groupText }}</dd></div>
          <div><dt>Аудитория</dt><dd>{{ classroomText }}</dd></div>
          <div><dt>Дата и время</dt><dd>{{ lesson.lesson_date || '—' }} · {{ lesson.starts_at || '—' }}–{{ lesson.ends_at || '—' }}</dd></div>
          <div><dt>Домашнее задание</dt><dd>{{ homeworkText }}</dd></div>
          <div><dt>Подпись</dt><dd>{{ signedText }}</dd></div>
        </dl>
      </section>

      <section class="journal-lesson__section">
        <h3>Материалы</h3>
        <div class="journal-files">
          <div v-for="file in files" :key="file.id" class="journal-file-row">
            <a :href="fileUrl(file)" target="_blank" rel="noopener">{{ file.original_name }}</a>
            <q-btn flat dense color="negative" label="Удалить" :disable="!canFiles" @click="emit('delete-file', file.id)" />
          </div>
          <span v-if="!files.length" class="journal-muted">Файлы не загружены.</span>
          <input ref="fileInput" class="journal-file-input" type="file" accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.pptx" @change="upload">
          <q-btn outline color="primary" label="Загрузить материал" :disable="!canFiles" @click="fileInput?.click()" />
        </div>
      </section>

      <section v-if="canReopen" class="journal-lesson__section journal-reopen">
        <h3>Переоткрытие</h3>
        <q-input v-model="reopenReason" dense outlined autogrow label="Причина исправления подписанного журнала" />
        <q-btn color="warning" label="Переоткрыть" :disable="!reopenReason.trim()" @click="reopen" />
      </section>
    </div>
  </WorkspacePanel>
</template>

<style scoped>
.journal-statuses { display: flex; flex-wrap: wrap; gap: 6px; }
.journal-lesson { display: grid; gap: 14px; }
.journal-lesson__section { display: grid; gap: 10px; }
.journal-lesson__section h3 { margin: 0; font-size: 15px; }
.journal-lesson__form { display: grid; gap: 10px; }
.journal-lesson__actions { display: flex; flex-wrap: wrap; gap: 8px; }
.journal-lesson__list { display: grid; gap: 8px; margin: 0; }
.journal-lesson__list div { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 8px; }
.journal-lesson__list dt { color: #64748b; }
.journal-lesson__list dd { margin: 0; }
.journal-files { display: grid; gap: 8px; }
.journal-file-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; }
.journal-file-input { display: none; }
.journal-muted { color: #64748b; font-size: 13px; }
.journal-reopen { border: 1px dashed #f59e0b; border-radius: 8px; padding: 10px; background: #fffbeb; }
</style>
