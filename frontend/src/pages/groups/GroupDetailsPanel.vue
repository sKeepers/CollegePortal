<script setup>
import { computed } from 'vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'

const props = defineProps({ group: { type: Object, default: null } })

function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}
function programLabel(program) {
  return [program?.name, program?.specialty?.code, program?.year_start, program?.study_form].filter(Boolean).join(' · ')
}

const curatorText = computed(() => teacherName(props.group?.curator) || '—')
const specialtyText = computed(() => props.group?.specialty || 'Специальность не указана')
const studyFormText = computed(() => props.group?.education_program?.study_form || 'Форма не указана')
const programText = computed(() => programLabel(props.group?.education_program) || 'Образовательная программа не указана')
const groupMetrics = computed(() => [
  { label: 'Студентов', value: props.group?.students_count ?? '—', to: props.group?.id ? { path: '/students', query: { group: props.group.id } } : null },
  { label: 'Курс', value: props.group?.course || '—' },
  { label: 'Год набора', value: props.group?.year_start || '—' },
  { label: 'Специальность', value: specialtyText.value },
])
const groupActions = computed(() => [
  { label: 'Студенты', to: { path: '/students', query: { group: props.group?.id } } },
  { label: 'Расписание', to: { path: '/schedule', query: { group: props.group?.id } } },
  { label: 'Журнал', to: { path: '/journal', query: { group: props.group?.id } } },
  { label: 'Учебный план', to: '/curricula' },
])
</script>

<template>
  <AppEmptyState v-if="!group" title="Группа не выбрана" description="Выберите строку в таблице, чтобы открыть карточку группы." />

  <WorkspacePanel v-else class="group-details-card" :title="group.name" :subtitle="[specialtyText, programText]" :metrics="groupMetrics" :actions="groupActions">
    <template #status>
      <AppStatusBadge :label="`${group.course || '—'} курс`" tone="info" />
      <AppStatusBadge :label="studyFormText" tone="neutral" />
    </template>

    <div class="group-details">
      <section class="group-details__section">
        <h3>Основное</h3>
        <dl class="group-details__list">
          <div><dt>Название</dt><dd>{{ group.name }}</dd></div>
          <div><dt>Специальность</dt><dd>{{ specialtyText }}</dd></div>
          <div><dt>Образовательная программа</dt><dd>{{ programText }}</dd></div>
          <div><dt>Форма обучения</dt><dd>{{ studyFormText }}</dd></div>
          <div><dt>Год набора</dt><dd>{{ group.year_start || '—' }}</dd></div>
          <div><dt>Курс</dt><dd>{{ group.course || '—' }}</dd></div>
        </dl>
      </section>

      <section class="group-details__section">
        <h3>Связанные данные</h3>
        <dl class="group-details__list">
          <div><dt>Куратор</dt><dd>{{ curatorText }}</dd></div>
          <div><dt>Количество студентов</dt><dd>{{ group.students_count ?? '—' }}</dd></div>
        </dl>
      </section>
    </div>
  </WorkspacePanel>
</template>
