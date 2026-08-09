<script setup>
import { computed } from 'vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'

const props = defineProps({
  program: { type: Object, default: null },
  groups: { type: Array, default: () => [] },
})

const specialtyTitle = computed(() => (
  [props.program?.specialty?.code, props.program?.specialty?.name].filter(Boolean).join(' · ') || '—'
))

const metrics = computed(() => [
  { label: 'Год набора', value: props.program?.year_start || '—' },
  { label: 'Форма обучения', value: props.program?.study_form || '—' },
  { label: 'Срок обучения', value: props.program?.study_years ?? '—' },
  { label: 'Групп', value: props.groups.length },
])

const actions = computed(() => [
  { label: 'Учебные планы программы', to: { path: '/curricula', query: { program: props.program?.id } } },
  { label: 'Группы программы', to: { path: '/groups', query: { program: props.program?.id } } },
])

const events = computed(() => props.groups.slice(0, 3).map((group) => ({
  id: group.id,
  title: group.name,
  description: [group.course ? `${group.course} курс` : null, group.year_start].filter(Boolean).join(' · ') || 'Параметры группы не указаны',
})))
</script>

<template>
  <AppEmptyState
    v-if="!program"
    title="Программа не выбрана"
    description="Выберите строку в таблице, чтобы открыть карточку образовательной программы."
  />

  <WorkspacePanel
    v-else
    class="program-details-card"
    :title="program.name"
    :subtitle="program.description || 'Описание не указано'"
    :metrics="metrics"
    :events="events"
    :actions="actions"
  >
    <template #status>
      <AppStatusBadge
        :label="program.is_active ? 'Действует' : 'Не действует'"
        :tone="program.is_active ? 'success' : 'neutral'"
      />
      <AppStatusBadge v-if="program.study_form" :label="program.study_form" tone="info" />
    </template>

    <div class="program-details">
      <section class="program-details__section">
        <h3>Основное</h3>
        <dl class="program-details__list">
          <div><dt>Специальность</dt><dd>{{ specialtyTitle }}</dd></div>
          <div><dt>Год набора</dt><dd>{{ program.year_start || '—' }}</dd></div>
          <div><dt>Форма обучения</dt><dd>{{ program.study_form || '—' }}</dd></div>
          <div><dt>Срок обучения</dt><dd>{{ program.study_years ?? '—' }}</dd></div>
        </dl>
      </section>

      <section class="program-details__section">
        <h3>Группы</h3>
        <div v-if="groups.length" class="program-details__group-list">
          <div v-for="group in groups.slice(0, 8)" :key="group.id">
            <strong>{{ group.name }}</strong>
            <span>
              <template v-if="group.course">{{ group.course }} курс</template>
              <template v-if="group.year_start"> · набор {{ group.year_start }}</template>
            </span>
          </div>
        </div>
        <p v-else class="program-details__muted">По программе пока нет групп.</p>
      </section>
    </div>
  </WorkspacePanel>
</template>

<style scoped>
.program-details { display: flex; flex-direction: column; gap: 16px; }
.program-details__section h3 { margin: 0 0 8px; font-size: 14px; }
.program-details__list { display: grid; gap: 6px; margin: 0; }
.program-details__list div { display: flex; justify-content: space-between; gap: 12px; }
.program-details__list dt { color: var(--cp-text-muted, #64748b); }
.program-details__list dd { margin: 0; text-align: right; }
.program-details__group-list { display: flex; flex-direction: column; gap: 8px; }
.program-details__group-list div { display: flex; flex-direction: column; }
.program-details__group-list span { color: var(--cp-text-muted, #64748b); font-size: 12px; }
.program-details__muted { color: var(--cp-text-muted, #64748b); margin: 0; }
</style>
