<script setup>
import { onMounted } from 'vue'
import { Download, FileText } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import { useReportsStore } from '../../stores/reports'

const store = useReportsStore()

const attendanceColumns = [
  { name: 'name', label: 'Студент', field: 'name', align: 'left', sortable: true },
  { name: 'present', label: 'Присутствовал', field: 'present', align: 'right', sortable: true },
  { name: 'late', label: 'Опоздал', field: 'late', align: 'right', sortable: true },
  { name: 'absent', label: 'Отсутствовал', field: 'absent', align: 'right', sortable: true },
  { name: 'excused', label: 'По уважительной', field: 'excused', align: 'right', sortable: true },
  { name: 'unmarked', label: 'Не отмечен', field: 'unmarked', align: 'right', sortable: true },
]

const gradesColumns = [
  { name: 'name', label: 'Студент', field: 'name', align: 'left', sortable: true },
  { name: 'grades', label: 'Оценки', field: 'grades', align: 'left' },
  { name: 'numeric_grades_count', label: 'Числовых', field: 'numeric_grades_count', align: 'right', sortable: true },
  { name: 'average_grade', label: 'Средний балл', field: 'average_grade', align: 'right', sortable: true },
]

onMounted(() => store.loadDictionaries())
</script>

<template>
  <AppPage>
    <PageHeader
      title="Отчеты"
      subtitle="Посещаемость и успеваемость по группе, выгрузки журнала и кадровых отсутствий."
    />

    <AppErrorBanner :message="store.error" />

    <AppCard class="reports-block">
      <h3>Посещаемость по группе</h3>
      <div class="reports-filters">
        <q-select v-model="store.attendanceFilters.group_id" dense outlined emit-value map-options label="Группа" :options="store.groupOptions" />
        <q-input v-model="store.attendanceFilters.date_from" dense outlined type="date" label="Дата с" />
        <q-input v-model="store.attendanceFilters.date_to" dense outlined type="date" label="Дата по" />
        <q-btn color="primary" :loading="store.loading" @click="store.loadAttendance">Построить</q-btn>
        <q-btn
          flat
          :disable="!store.attendanceFilters.group_id"
          :loading="store.exporting === 'attendance'"
          @click="store.exportAttendance"
        >
          <Download :size="16" class="q-mr-xs" /> CSV
        </q-btn>
      </div>

      <template v-if="store.attendanceReport">
        <p class="reports-summary">
          Занятий за период: {{ store.attendanceReport.summary.total_lessons }} ·
          студентов: {{ store.attendanceReport.summary.students_count }} ·
          опозданий: {{ store.attendanceReport.summary.late }} ·
          пропусков: {{ store.attendanceReport.summary.absent }} ·
          не отмечено: {{ store.attendanceReport.summary.unmarked }}
        </p>
        <AppTable :rows="store.attendanceReport.students" :columns="attendanceColumns" :pagination="{ rowsPerPage: 20 }" />
      </template>
      <AppEmptyState v-else title="Отчёт не построен" description="Выберите группу и период, затем нажмите «Построить»." />
    </AppCard>

    <AppCard class="reports-block">
      <h3>Оценки по группе и дисциплине</h3>
      <div class="reports-filters">
        <q-select v-model="store.gradesFilters.group_id" dense outlined emit-value map-options label="Группа" :options="store.groupOptions" />
        <!-- Дисциплины видны не каждой роли: без права список пуст, и выбор
             прятать честнее, чем показывать пустой. -->
        <q-select v-if="store.canReadSubjects" v-model="store.gradesFilters.subject_id" dense outlined emit-value map-options label="Дисциплина" :options="store.subjectOptions" />
        <q-input v-model="store.gradesFilters.date_from" dense outlined type="date" label="Дата с" />
        <q-input v-model="store.gradesFilters.date_to" dense outlined type="date" label="Дата по" />
        <q-btn color="primary" :loading="store.loading" @click="store.loadGrades">Построить</q-btn>
        <q-btn
          flat
          :disable="!store.gradesFilters.group_id || !store.gradesFilters.subject_id"
          :loading="store.exporting === 'grades'"
          @click="store.exportGrades"
        >
          <Download :size="16" class="q-mr-xs" /> CSV
        </q-btn>
      </div>

      <template v-if="store.gradesReport">
        <p class="reports-summary">
          {{ store.gradesReport.subject.name }} · занятий: {{ store.gradesReport.summary.lessons_count }} ·
          оценок: {{ store.gradesReport.summary.grades_count }} ·
          средний балл: {{ store.gradesReport.summary.average_grade ?? '—' }}
        </p>
        <AppTable :rows="store.gradesReport.students" :columns="gradesColumns" :pagination="{ rowsPerPage: 20 }">
          <template #body-cell-grades="props">
            <q-td :props="props">{{ props.row.grades.join(', ') || '—' }}</q-td>
          </template>
          <template #body-cell-average_grade="props">
            <q-td :props="props">{{ props.row.average_grade ?? '—' }}</q-td>
          </template>
        </AppTable>
      </template>
      <AppEmptyState v-else title="Отчёт не построен" description="Выберите группу, дисциплину и период, затем нажмите «Построить»." />
    </AppCard>

    <!-- Выгрузки открыты тем, у кого есть право. Раньше право «Журнал: экспорт»
         было выдано пяти ролям и не вело никуда. -->
    <AppCard v-if="store.canExportJournal" class="reports-block">
      <h3>Выгрузки журнала</h3>
      <div class="reports-filters">
        <q-select v-model="store.journalFilters.group_id" dense outlined clearable emit-value map-options label="Группа" :options="store.groupOptions" />
        <q-select v-model="store.journalFilters.teacher_id" dense outlined clearable emit-value map-options label="Преподаватель" :options="store.teacherOptions" />
        <q-input v-model="store.journalFilters.date_from" dense outlined type="date" label="Дата с" />
        <q-input v-model="store.journalFilters.date_to" dense outlined type="date" label="Дата по" />
        <q-btn
          color="primary"
          :disable="!store.journalFilters.group_id"
          :loading="store.exporting === 'journal-group'"
          @click="store.exportJournalGroup"
        >
          <FileText :size="16" class="q-mr-xs" /> По группе
        </q-btn>
        <q-btn
          color="secondary"
          :loading="store.exporting === 'journal-teacher'"
          @click="store.exportJournalTeacher"
        >
          <FileText :size="16" class="q-mr-xs" /> По преподавателю
        </q-btn>
      </div>
      <p class="reports-hint">Выгрузка по преподавателю без выбора отдаёт занятия всех преподавателей за период.</p>
    </AppCard>

    <AppCard v-if="store.canExportAbsences" class="reports-block">
      <h3>Кадровые отсутствия</h3>
      <div class="reports-filters">
        <q-input v-model="store.absenceFilters.date_from" dense outlined type="date" label="Дата с" />
        <q-input v-model="store.absenceFilters.date_to" dense outlined type="date" label="Дата по" />
        <q-btn color="primary" :loading="store.exporting === 'absences'" @click="store.exportAbsences">
          <Download :size="16" class="q-mr-xs" /> CSV
        </q-btn>
      </div>
      <p class="reports-hint">Отпуска, больничные, командировки и отстранения сотрудников за период.</p>
    </AppCard>
  </AppPage>
</template>

<style scoped>
.reports-block { margin-bottom: 16px; }
.reports-block h3 { margin: 0 0 12px; font-size: 16px; font-weight: 600; }
.reports-filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 12px; }
.reports-filters > .q-select, .reports-filters > .q-input { min-width: 180px; }
.reports-summary { margin: 0 0 12px; font-size: 13px; }
.reports-hint { margin: 8px 0 0; font-size: 12px; opacity: 0.75; }
</style>
