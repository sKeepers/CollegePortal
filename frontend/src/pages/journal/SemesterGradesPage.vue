<script setup>
import { computed, onMounted, watch } from 'vue'
import { ClipboardCheck } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import { useSemesterGradesStore } from '../../stores/semesterGrades'

const store = useSemesterGradesStore()

const CONTROL_LABELS = {
  exam: 'экзамен',
  credit: 'зачёт',
  differentiated_credit: 'дифференцированный зачёт',
}

const controlLabel = computed(() => (store.controlType ? CONTROL_LABELS[store.controlType] || store.controlType : null))

onMounted(async () => {
  await store.loadReferences()
})

// Ведомость перезагружается сама при смене группы, дисциплины или семестра: нажимать
// «показать» после каждого выбора — лишний шаг там, где выбор и есть запрос.
watch(
  () => [store.filters.group_id, store.filters.subject_id, store.filters.academic_year, store.filters.semester],
  () => store.loadSheet(),
)
</script>

<template>
  <AppPage>
    <PageHeader
      title="Итоговые оценки"
      subtitle="Итог дисциплины за семестр — то, из чего собирается приложение к диплому, ведомость и справка об обучении. Это не средний балл: итоговую оценку ставит преподаватель."
    />

    <AppErrorBanner :message="store.error" />

    <AppCard>
      <div class="semester-filters">
        <q-select
          v-model="store.filters.group_id"
          dense
          outlined
          emit-value
          map-options
          label="Группа"
          :options="store.groupOptions"
          :loading="store.referencesLoading"
        />
        <q-select
          v-model="store.filters.subject_id"
          dense
          outlined
          emit-value
          map-options
          label="Дисциплина"
          :options="store.subjectOptions"
          :loading="store.referencesLoading"
        />
        <q-input v-model="store.filters.academic_year" dense outlined label="Учебный год" hint="Например, 2026/2027" />
        <q-select
          v-model="store.filters.semester"
          dense
          outlined
          emit-value
          map-options
          label="Семестр"
          :options="store.semesterOptions"
        />
      </div>

      <p v-if="controlLabel" class="semester-control">
        Форма контроля по учебному плану: <strong>{{ controlLabel }}</strong>
      </p>
    </AppCard>

    <AppEmptyState
      v-if="!store.ready"
      title="Выберите группу и дисциплину"
      description="Ведомость строится на группу, дисциплину и семестр — без них показывать нечего."
    />

    <AppEmptyState
      v-else-if="!store.loading && store.students.length === 0"
      title="В группе нет студентов"
      description="Ведомость строится от состава группы. Если студенты есть, а список пуст, проверьте, та ли выбрана группа."
    />

    <AppCard v-else>
      <div class="semester-toolbar">
        <span class="semester-counter">
          Студентов: {{ store.students.length }} · с оценкой: {{ store.filled }}
        </span>
        <span v-if="store.notice" class="semester-notice">{{ store.notice }}</span>
        <q-btn color="primary" :loading="store.saving" :disable="store.loading" @click="store.save()">
          <ClipboardCheck :size="16" class="q-mr-xs" /> Сохранить ведомость
        </q-btn>
      </div>

      <q-markup-table flat dense class="semester-table">
        <thead>
          <tr>
            <th class="text-left">Студент</th>
            <th class="text-left semester-table__value">Итог</th>
            <th class="text-left">Примечание</th>
            <th class="text-left">Поставлена</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in store.students" :key="row.student_id">
            <td class="text-left">{{ row.name }}</td>
            <td class="text-left">
              <!-- Значение строкой, а не числом: «зачтено» и «не аттестован» — такие же
                   законные итоги, как «5». Пустое поле снимает оценку. -->
              <q-input
                v-model="row.value"
                dense
                outlined
                maxlength="32"
                placeholder="5, зачтено, …"
              />
            </td>
            <td class="text-left">
              <q-input v-model="row.comment" dense outlined maxlength="500" placeholder="необязательно" />
            </td>
            <td class="text-left semester-table__set-at">{{ row.set_at ? new Date(row.set_at).toLocaleDateString('ru-RU') : '—' }}</td>
          </tr>
        </tbody>
      </q-markup-table>
    </AppCard>
  </AppPage>
</template>

<style scoped>
.semester-filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
}

.semester-control {
  margin: 12px 0 0;
  color: var(--text-secondary, #5f6368);
}

.semester-toolbar {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.semester-counter {
  font-weight: 600;
}

.semester-notice {
  color: var(--text-secondary, #5f6368);
}

.semester-table__value {
  width: 200px;
}

.semester-table__set-at {
  white-space: nowrap;
}
</style>
