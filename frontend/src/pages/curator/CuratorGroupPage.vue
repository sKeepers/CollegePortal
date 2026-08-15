<script setup>
import { computed, onMounted } from 'vue'
import { ClipboardList, ExternalLink, Mail, Phone } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import { useCuratorGroupStore } from '../../stores/curatorGroup'

const store = useCuratorGroupStore()

const studentColumns = [
  { name: 'name', label: 'Студент', field: 'name', align: 'left', sortable: true },
  { name: 'grades_count', label: 'Оценок', field: 'grades_count', align: 'right', sortable: true },
  { name: 'average_grade', label: 'Средний балл', field: 'average_grade', align: 'right', sortable: true },
  { name: 'failing_count', label: 'Двоек', field: 'failing_count', align: 'right', sortable: true },
  { name: 'recent', label: 'Последние оценки', field: 'recent', align: 'left' },
]

const subjectColumns = [
  { name: 'name', label: 'Дисциплина', field: 'name', align: 'left', sortable: true },
  { name: 'grades_count', label: 'Оценок', field: 'grades_count', align: 'right', sortable: true },
  { name: 'average_grade', label: 'Средний балл', field: 'average_grade', align: 'right', sortable: true },
  { name: 'failing_count', label: 'Двоек', field: 'failing_count', align: 'right', sortable: true },
]

const journalLink = computed(() => ({ path: '/journal', query: { group: store.groupId } }))

async function start() {
  await store.loadGroups()
  if (store.groupId) await store.open(store.groupId)
}

onMounted(start)
</script>

<template>
  <AppPage>
    <PageHeader
      title="Моя группа"
      subtitle="Успеваемость и состав закреплённой группы. Занятия открываются в журнале — на просмотр: править их может тот, кто ведёт."
    />

    <AppErrorBanner :message="store.error" />

    <AppEmptyState
      v-if="!store.groupsLoading && !store.hasGroups"
      title="Групп нет"
      :description="store.message || 'За вами не закреплено ни одной группы. Куратора назначают в карточке группы.'"
    />

    <template v-else>
      <AppCard class="curator-block">
        <div class="curator-filters">
          <q-select
            v-if="store.groups.length > 1"
            v-model="store.groupId"
            dense
            outlined
            emit-value
            map-options
            label="Группа"
            :options="store.groupOptions"
            @update:model-value="store.open"
          />
          <q-input v-model="store.filters.date_from" dense outlined type="date" label="Оценки с" />
          <q-input v-model="store.filters.date_to" dense outlined type="date" label="Оценки по" />
          <q-btn color="primary" :loading="store.performanceLoading" @click="store.loadPerformance()">Показать</q-btn>
          <q-btn flat :to="journalLink">
            <ExternalLink :size="16" class="q-mr-xs" /> Журнал группы
          </q-btn>
        </div>

        <p v-if="store.summary" class="curator-summary">
          {{ store.currentGroup?.name }} · студентов: {{ store.summary.students_count }} ·
          занятий за период: {{ store.summary.lessons_count }} ·
          оценок: {{ store.summary.grades_count }} ·
          средний балл: {{ store.summary.average_grade ?? '—' }} ·
          с двойками: {{ store.summary.with_failing }} ·
          без оценок: {{ store.summary.without_grades }}
        </p>
      </AppCard>

      <AppCard class="curator-block">
        <h3>Успеваемость</h3>
        <AppTable :rows="store.rows" :columns="studentColumns" :loading="store.performanceLoading" :pagination="{ rowsPerPage: 30 }">
          <template #body-cell-average_grade="props">
            <q-td :props="props">{{ props.row.average_grade ?? '—' }}</q-td>
          </template>
          <template #body-cell-failing_count="props">
            <q-td :props="props">
              <span :class="{ 'curator-failing': props.row.failing_count > 0 }">{{ props.row.failing_count }}</span>
            </q-td>
          </template>
          <template #body-cell-recent="props">
            <q-td :props="props">
              <template v-if="props.row.recent.length">
                <span v-for="(item, index) in props.row.recent" :key="index" class="curator-mark">
                  {{ item.value }}<small v-if="item.subject"> · {{ item.subject }}</small>
                </span>
              </template>
              <span v-else>Оценок нет</span>
            </q-td>
          </template>
        </AppTable>
      </AppCard>

      <AppCard v-if="store.subjects.length" class="curator-block">
        <h3>По дисциплинам</h3>
        <AppTable :rows="store.subjects" :columns="subjectColumns" :pagination="{ rowsPerPage: 20 }">
          <template #body-cell-average_grade="props">
            <q-td :props="props">{{ props.row.average_grade ?? '—' }}</q-td>
          </template>
        </AppTable>
      </AppCard>

      <AppCard class="curator-block">
        <h3><ClipboardList :size="18" /> Состав группы</h3>
        <ul class="curator-roster">
          <li v-for="student in store.students" :key="student.id">
            <strong>{{ student.name }}</strong>
            <span class="curator-contacts">
              <a v-if="student.phone" :href="`tel:${student.phone}`"><Phone :size="14" /> {{ student.phone }}</a>
              <a v-if="student.email" :href="`mailto:${student.email}`"><Mail :size="14" /> {{ student.email }}</a>
              <em v-if="!student.phone && !student.email">контактов нет</em>
            </span>
          </li>
        </ul>
        <p v-if="!store.students.length && !store.studentsLoading">В группе нет действующих студентов.</p>
      </AppCard>
    </template>
  </AppPage>
</template>

<style scoped>
.curator-block { margin-bottom: 16px; }
.curator-filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 12px; }
.curator-filters :deep(.q-field) { min-width: 180px; }
.curator-summary { margin: 0; color: var(--cp-text-muted, #5b6472); }
.curator-failing { color: var(--cp-negative, #c1121f); font-weight: 600; }
.curator-mark { display: inline-flex; gap: 4px; align-items: baseline; margin-right: 10px; }
.curator-mark small { opacity: 0.7; }
.curator-roster { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.curator-roster li { display: flex; flex-wrap: wrap; gap: 8px 16px; justify-content: space-between; border-bottom: 1px solid var(--cp-border, #e4e7ec); padding-bottom: 6px; }
.curator-contacts { display: flex; gap: 12px; flex-wrap: wrap; }
.curator-contacts a { display: inline-flex; align-items: center; gap: 4px; }
</style>
