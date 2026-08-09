<script setup>
import { computed } from 'vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'

const props = defineProps({
  specialty: { type: Object, default: null },
  programs: { type: Array, default: () => [] },
})

const title = computed(() => [props.specialty?.code, props.specialty?.name].filter(Boolean).join(' · '))

const metrics = computed(() => [
  { label: 'Уровень', value: props.specialty?.education_level || '—' },
  { label: 'Квалификация', value: props.specialty?.qualification || '—' },
  { label: 'Срок обучения', value: props.specialty?.normative_study_years ?? '—' },
  { label: 'Программ', value: props.programs.length },
])

const actions = computed(() => [
  { label: 'Программы специальности', to: { path: '/education-programs', query: { specialty: props.specialty?.id } } },
  { label: 'Учебные планы', to: { path: '/curricula', query: { specialty: props.specialty?.id } } },
])

const events = computed(() => props.programs.slice(0, 3).map((program) => ({
  id: program.id,
  title: program.name,
  description: [program.year_start, program.study_form].filter(Boolean).join(' · ') || 'Параметры программы не указаны',
})))
</script>

<template>
  <AppEmptyState
    v-if="!specialty"
    title="Специальность не выбрана"
    description="Выберите строку в таблице, чтобы открыть карточку специальности."
  />

  <WorkspacePanel
    v-else
    class="specialty-details-card"
    :title="title"
    :subtitle="specialty.description || 'Описание не указано'"
    :metrics="metrics"
    :events="events"
    :actions="actions"
  >
    <template #status>
      <AppStatusBadge v-if="specialty.education_level" :label="specialty.education_level" tone="info" />
      <AppStatusBadge
        :label="programs.length ? `Программ: ${programs.length}` : 'Программ нет'"
        :tone="programs.length ? 'success' : 'neutral'"
      />
    </template>

    <div class="specialty-details">
      <section class="specialty-details__section">
        <h3>Основное</h3>
        <dl class="specialty-details__list">
          <div><dt>Код</dt><dd>{{ specialty.code || '—' }}</dd></div>
          <div><dt>Наименование</dt><dd>{{ specialty.name }}</dd></div>
          <div><dt>Уровень образования</dt><dd>{{ specialty.education_level || '—' }}</dd></div>
          <div><dt>Квалификация</dt><dd>{{ specialty.qualification || '—' }}</dd></div>
          <div><dt>Нормативный срок</dt><dd>{{ specialty.normative_study_years ?? '—' }}</dd></div>
        </dl>
      </section>

      <section class="specialty-details__section">
        <h3>Образовательные программы</h3>
        <div v-if="programs.length" class="specialty-details__program-list">
          <div v-for="program in programs.slice(0, 8)" :key="program.id">
            <strong>{{ program.name }}</strong>
            <span>
              {{ program.year_start || 'год не указан' }}
              <template v-if="program.study_form"> · {{ program.study_form }}</template>
              <template v-if="!program.is_active"> · не действует</template>
            </span>
          </div>
        </div>
        <p v-else class="specialty-details__muted">
          На специальности пока нет программ. Заведите программу, иначе по ней не создать группу.
        </p>
      </section>
    </div>
  </WorkspacePanel>
</template>

<style scoped>
.specialty-details { display: flex; flex-direction: column; gap: 16px; }
.specialty-details__section h3 { margin: 0 0 8px; font-size: 14px; }
.specialty-details__list { display: grid; gap: 6px; margin: 0; }
.specialty-details__list div { display: flex; justify-content: space-between; gap: 12px; }
.specialty-details__list dt { color: var(--cp-text-muted, #64748b); }
.specialty-details__list dd { margin: 0; text-align: right; }
.specialty-details__program-list { display: flex; flex-direction: column; gap: 8px; }
.specialty-details__program-list div { display: flex; flex-direction: column; }
.specialty-details__program-list span { color: var(--cp-text-muted, #64748b); font-size: 12px; }
.specialty-details__muted { color: var(--cp-text-muted, #64748b); margin: 0; }
</style>
