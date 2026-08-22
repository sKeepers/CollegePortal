<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import { useQuasar } from "quasar";
import AppCard from "../../components/ui/AppCard.vue";
import WorkspaceBackBar from '../../components/workspace/WorkspaceBackBar.vue'
import WorkspacePanel from "../../components/workspace/WorkspacePanel.vue";
import { useAuthStore } from "../../stores/auth";
import { useHrStore } from "../../stores/hr";
import { api } from "../../services/api";

const $q = useQuasar();
const auth = useAuthStore();
const store = useHrStore();
const mode = ref("month");
const selectedPeriod = ref(null);
const selectedLesson = ref(null);
const createDialog = ref(false);
const preview = ref(null);
const replacementTeacherId = ref(null);

const filters = reactive({
  date_from: monthStart(),
  date_to: monthEnd(),
  status: "",
  period_status: "",
  department_id: "",
});
const periodForm = reactive({
  employee_id: null,
  status: "vacation",
  date_from: new Date().toISOString().slice(0, 10),
  date_to: "",
  reason: "",
  comment: "",
});

const statusOptions = [
  { label: "Отпуск", value: "vacation", color: "orange" },
  { label: "Больничный", value: "sick_leave", color: "deep-orange" },
  { label: "Командировка", value: "business_trip", color: "teal" },
  { label: "Декрет", value: "maternity_leave", color: "purple" },
  { label: "Приостановка", value: "suspended", color: "negative" },
  { label: "Увольнение", value: "dismissed", color: "dark" },
];
const periodStatusOptions = [
  { label: "Запланирован", value: "planned", color: "blue" },
  { label: "Активен", value: "active", color: "positive" },
  { label: "Завершен", value: "completed", color: "grey" },
  { label: "Отменен", value: "cancelled", color: "negative" },
];
const modeOptions = [
  { label: "День", value: "day" },
  { label: "Неделя", value: "week" },
  { label: "Месяц", value: "month" },
  { label: "Список", value: "list" },
];

const rows = computed(() => store.calendar.periods || []);
const summary = computed(() => store.calendar.summary || {});
const employeeOptions = computed(() =>
  store.employees.map((item) => ({
    label: item.full_name || item.employee_number,
    value: item.id,
  })),
);
const canManage = computed(() => auth.can("hr.absences.manage"));
const canExportAbsences = computed(() => auth.can("hr.reports.view"));
const exporting = ref(false);

/** Выгрузка идёт за тот же период, который сейчас показан на экране. */
async function exportAbsences() {
  exporting.value = true;
  try {
    const params = new URLSearchParams({
      date_from: filters.date_from,
      date_to: filters.date_to,
    });
    const blob = await api.download(`/hr/reports/absences.csv?${params.toString()}`);
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `hr-absences-${filters.date_from}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (err) {
    $q.notify({ type: "negative", message: err.message || "Файл не удалось скачать" });
  } finally {
    exporting.value = false;
  }
}
const canReplace = computed(() => auth.can("hr.replacements.manage"));
const metrics = computed(() =>
  selectedPeriod.value
    ? [
        { label: "Тип", value: statusLabel(selectedPeriod.value.status) },
        {
          label: "Период",
          value: `${formatDate(selectedPeriod.value.date_from)} — ${formatDate(selectedPeriod.value.date_to)}`,
        },
        {
          label: "Занятий",
          value: selectedPeriod.value.affected_lessons_count || 0,
        },
      ]
    : [],
);

const columns = [
  {
    name: "employee",
    label: "Сотрудник",
    field: "employee_name",
    align: "left",
  },
  { name: "status", label: "Тип", field: "status", align: "left" },
  {
    name: "period_status",
    label: "Состояние",
    field: "period_status",
    align: "left",
  },
  { name: "dates", label: "Период", field: "date_from", align: "left" },
  {
    name: "department",
    label: "Подразделение",
    field: "department",
    align: "left",
  },
  {
    name: "lessons",
    label: "Занятия",
    field: "affected_lessons_count",
    align: "right",
  },
];
const lessonColumns = [
  { name: "date", label: "Дата", field: "date", align: "left" },
  { name: "time", label: "Время", field: "starts_at", align: "left" },
  { name: "subject", label: "Дисциплина", field: "subject", align: "left" },
  { name: "group", label: "Группа", field: "group", align: "left" },
  { name: "actions", label: "", field: "actions", align: "right" },
];

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}
function monthEnd() {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth() + 1, 0)
    .toISOString()
    .slice(0, 10);
}
function statusLabel(value) {
  return (
    statusOptions.find((item) => item.value === value)?.label || value || "—"
  );
}
function statusColor(value) {
  return statusOptions.find((item) => item.value === value)?.color || "grey";
}
function periodStatusLabel(value) {
  return (
    periodStatusOptions.find((item) => item.value === value)?.label ||
    value ||
    "—"
  );
}
function periodStatusColor(value) {
  return (
    periodStatusOptions.find((item) => item.value === value)?.color || "grey"
  );
}
function formatDate(value) {
  if (!value) return "—";
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString("ru-RU");
}
function employeeName(id) {
  return (
    store.employees.find((item) => Number(item.id) === Number(id))?.full_name ||
    "Сотрудник"
  );
}

async function load() {
  await store.loadDictionaries();
  if (!store.employees.length) await store.loadEmployees({ per_page: 200 });
  await store.loadCalendar(filters);
}

function periodRowClass(row) {
  return Number(row.id) === Number(selectedPeriod.value?.id) ? "workspace-row--selected" : "";
}

async function openPeriod(row) {
  selectedPeriod.value = row;
  selectedLesson.value = null;
  replacementTeacherId.value = null;
  store.candidates = [];
  await store.loadAffectedLessons(row.id);
}

async function makePreview() {
  if (!periodForm.employee_id) return;
  preview.value = await store.previewPeriod(periodForm.employee_id, periodForm);
}

async function applyPeriod() {
  await makePreview();
  if (!preview.value?.can_apply) {
    $q.notify({
      type: "warning",
      message: "Есть блокирующие конфликты. Проверьте preview.",
    });
    return;
  }
  await store.applyPeriod(periodForm.employee_id, periodForm);
  createDialog.value = false;
  preview.value = null;
  await load();
  $q.notify({ type: "positive", message: "Кадровый период создан" });
}

function cancelPeriod(row) {
  $q.dialog({
    title: "Отменить период?",
    message: row.employee_name,
    prompt: { model: "", type: "text", label: "Причина" },
    cancel: true,
  }).onOk(async (reason) => {
    await store.cancelPeriod(row.id, reason);
    await load();
  });
}

async function openCandidates(lesson) {
  selectedLesson.value = lesson;
  replacementTeacherId.value = null;
  await store.loadReplacementCandidates(
    lesson.id,
    selectedPeriod.value.employee_id,
  );
}

async function previewReplacement() {
  if (!selectedLesson.value || !replacementTeacherId.value) return;
  await store.previewReplacements([
    {
      schedule_entry_id: selectedLesson.value.id,
      teacher_id: replacementTeacherId.value,
    },
  ]);
}

async function applyReplacement() {
  await previewReplacement();
  if (!store.replacementPreview?.can_apply) {
    $q.notify({ type: "warning", message: "Замена содержит конфликты" });
    return;
  }
  await store.applyReplacements([
    {
      schedule_entry_id: selectedLesson.value.id,
      teacher_id: replacementTeacherId.value,
    },
  ]);
  await store.loadAffectedLessons(selectedPeriod.value.id);
  $q.notify({ type: "positive", message: "Замена назначена" });
}

function openCreate(status = "vacation") {
  Object.assign(periodForm, {
    employee_id: null,
    status,
    date_from: new Date().toISOString().slice(0, 10),
    date_to: "",
    reason: "",
    comment: "",
  });
  preview.value = null;
  createDialog.value = true;
}

onMounted(load);
</script>

<template>
  <div class="hr-calendar-page">
    <div class="hr-calendar-header">
      <div>
        <p class="text-overline text-primary q-mb-xs">Отдел кадров</p>
        <h1>Календарь отсутствий и замены</h1>
        <p>
          Кадровые периоды связаны с расписанием. Замены выполняются только
          после preview и подтверждения.
        </p>
      </div>
      <div class="q-gutter-sm">
        <q-btn-toggle
          v-model="mode"
          :options="modeOptions"
          no-caps
          unelevated
          toggle-color="primary"
        />
        <!-- Выгрузка отсутствий работала на бэкенде с rc2, а вызвать её из
             интерфейса было неоткуда: право `hr.reports.view` не вело никуда. -->
        <q-btn v-if="canExportAbsences" flat no-caps :loading="exporting" @click="exportAbsences"
          >Выгрузить CSV</q-btn
        >
        <q-btn v-if="canManage" color="primary" no-caps @click="openCreate()"
          >Оформить период</q-btn
        >
      </div>
    </div>

    <div class="hr-calendar-summary">
      <AppCard
        ><strong>{{ summary.total || 0 }}</strong
        ><span>Всего периодов</span></AppCard
      >
      <AppCard
        ><strong>{{ summary.vacation || 0 }}</strong
        ><span>Отпуска</span></AppCard
      >
      <AppCard
        ><strong>{{ summary.sick_leave || 0 }}</strong
        ><span>Больничные</span></AppCard
      >
      <AppCard
        ><strong>{{ summary.business_trip || 0 }}</strong
        ><span>Командировки</span></AppCard
      >
    </div>

    <AppCard>
      <div class="hr-calendar-filters">
        <q-input
          v-model="filters.date_from"
          outlined
          dense
          type="date"
          label="С даты"
        />
        <q-input
          v-model="filters.date_to"
          outlined
          dense
          type="date"
          label="По дату"
        />
        <q-select
          v-model="filters.status"
          outlined
          dense
          label="Тип"
          :options="[{ label: 'Все', value: '' }, ...statusOptions]"
          emit-value
          map-options
          clearable
        />
        <q-select
          v-model="filters.period_status"
          outlined
          dense
          label="Состояние"
          :options="[{ label: 'Все', value: '' }, ...periodStatusOptions]"
          emit-value
          map-options
          clearable
        />
        <q-select
          v-model="filters.department_id"
          outlined
          dense
          label="Подразделение"
          :options="store.departmentOptions"
          emit-value
          map-options
          clearable
        />
        <q-btn color="primary" no-caps :loading="store.loading" @click="load"
          >Применить</q-btn
        >
      </div>
    </AppCard>

    <div
      class="hr-calendar-layout workspace-page"
      :class="{ 'workspace-page--card': Boolean(selectedPeriod) }"
    >
      <div class="hr-calendar-main workspace-page__list">
        <AppCard>
          <q-table
            flat
            :dense="mode !== 'month'"
            :rows="rows"
            :columns="columns"
            row-key="id"
            :loading="store.loading"
            :rows-per-page-options="[20, 50, 100]"
            :table-row-class-fn="periodRowClass"
            @row-click="(_, row) => openPeriod(row)"
          >
            <template #body-cell-status="props"
              ><q-td :props="props"
                ><q-chip
                  dense
                  :color="statusColor(props.row.status)"
                  text-color="white"
                  >{{ statusLabel(props.row.status) }}</q-chip
                ></q-td
              ></template
            >
            <template #body-cell-period_status="props"
              ><q-td :props="props"
                ><q-chip
                  dense
                  :color="periodStatusColor(props.row.period_status)"
                  text-color="white"
                  >{{ periodStatusLabel(props.row.period_status) }}</q-chip
                ></q-td
              ></template
            >
            <template #body-cell-dates="props"
              ><q-td :props="props"
                >{{ formatDate(props.row.date_from) }} —
                {{ formatDate(props.row.date_to) }}</q-td
              ></template
            >
          </q-table>
        </AppCard>
      </div>

      <div class="workspace-page__card">
        <WorkspaceBackBar @back="selectedPeriod = null" />
        <WorkspacePanel
          v-if="selectedPeriod"
          class="hr-calendar-workspace"
          :title="selectedPeriod.employee_name"
          :subtitle="[selectedPeriod.department, selectedPeriod.position]"
          :metrics="metrics"
          :actions="[
            {
              label: 'Сотрудник',
              to: `/hr/employees?employee=${selectedPeriod.employee_id}`,
            },
            {
              label: 'Расписание',
              to: `/schedule?teacher_id=${selectedPeriod.teacher_id}`,
            },
          ]"
        >
          <template #status
            ><q-chip
              dense
              :color="statusColor(selectedPeriod.status)"
              text-color="white"
              >{{ statusLabel(selectedPeriod.status) }}</q-chip
            ></template
          >
          <div class="q-gutter-sm q-mb-md">
            <q-btn
              v-if="canManage"
              outline
              no-caps
              color="negative"
              @click="cancelPeriod(selectedPeriod)"
              >Отменить период</q-btn
            >
          </div>
          <q-table
            flat
            dense
            title="Затронутые занятия"
            :rows="store.affectedLessons"
            :columns="lessonColumns"
            row-key="id"
            :rows-per-page-options="[5, 10, 20]"
          >
            <template #body-cell-time="props"
              ><q-td :props="props"
                >{{ props.row.starts_at }}–{{ props.row.ends_at }}</q-td
              ></template
            >
            <template #body-cell-actions="props"
              ><q-td :props="props"
                ><q-btn
                  v-if="canReplace"
                  flat
                  dense
                  no-caps
                  color="primary"
                  @click="openCandidates(props.row)"
                  >Подобрать замену</q-btn
                ></q-td
              ></template
            >
          </q-table>
          <q-separator class="q-my-md" />
          <div v-if="selectedLesson">
            <h3 class="text-subtitle1 q-mb-sm">Кандидаты на замену</h3>
            <q-list bordered separator class="rounded-borders">
              <q-item
                v-for="candidate in store.candidates"
                :key="candidate.teacher_id"
                clickable
                @click="replacementTeacherId = candidate.teacher_id"
              >
                <q-item-section>
                  <q-item-label>{{ candidate.full_name }}</q-item-label>
                  <q-item-label caption
                    >Оценка {{ candidate.score }} ·
                    {{ candidate.result }}</q-item-label
                  >
                </q-item-section>
                <q-item-section side
                  ><q-radio
                    v-model="replacementTeacherId"
                    :val="candidate.teacher_id"
                /></q-item-section>
              </q-item>
            </q-list>
            <div class="q-gutter-sm q-mt-md">
              <q-btn outline no-caps color="primary" @click="previewReplacement"
                >Preview</q-btn
              >
              <q-btn
                color="primary"
                no-caps
                :disable="!replacementTeacherId"
                @click="applyReplacement"
                >Назначить замену</q-btn
              >
            </div>
            <q-banner
              v-if="store.replacementPreview"
              rounded
              class="q-mt-md"
              :class="
                store.replacementPreview.can_apply
                  ? 'bg-green-1 text-positive'
                  : 'bg-orange-1 text-warning'
              "
            >
              {{
                store.replacementPreview.can_apply
                  ? "Замену можно применить"
                  : "Есть конфликты замены"
              }}
            </q-banner>
          </div>
        </WorkspacePanel>
      </div>
    </div>

    <q-dialog v-model="createDialog" persistent>
      <q-card class="hr-calendar-dialog">
        <q-card-section
          ><div class="text-h6">Новый кадровый период</div></q-card-section
        >
        <q-card-section class="hr-calendar-form">
          <q-select
            v-model="periodForm.employee_id"
            outlined
            dense
            label="Сотрудник"
            :options="employeeOptions"
            emit-value
            map-options
          />
          <q-select
            v-model="periodForm.status"
            outlined
            dense
            label="Тип периода"
            :options="statusOptions"
            emit-value
            map-options
          />
          <q-input
            v-model="periodForm.date_from"
            outlined
            dense
            type="date"
            label="Дата начала"
          />
          <q-input
            v-model="periodForm.date_to"
            outlined
            dense
            type="date"
            label="Дата окончания"
          />
          <q-input v-model="periodForm.reason" outlined dense label="Причина" />
          <q-input
            v-model="periodForm.comment"
            outlined
            dense
            type="textarea"
            label="Комментарий"
            class="wide"
          />
        </q-card-section>
        <q-card-section v-if="preview">
          <q-banner
            rounded
            :class="
              preview.can_apply
                ? 'bg-green-1 text-positive'
                : 'bg-red-1 text-negative'
            "
          >
            Затронуто занятий: {{ preview.affected_lessons_count }}. Blocking:
            {{ preview.blocking_count }}. Warning: {{ preview.warning_count }}.
          </q-banner>
        </q-card-section>
        <q-card-actions align="right"
          ><q-btn flat no-caps label="Отмена" v-close-popup /><q-btn
            outline
            no-caps
            label="Preview"
            @click="makePreview" /><q-btn
            color="primary"
            no-caps
            label="Применить"
            @click="applyPeriod"
        /></q-card-actions>
      </q-card>
    </q-dialog>
  </div>
</template>

<style scoped>
/* Тот же потолок ширины, что и у страниц на общем контейнере. */
.hr-calendar-page {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: var(--cp-page-max-width);
}
.hr-calendar-header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
}
.hr-calendar-header h1 {
  margin: 0;
  font-size: 28px;
  line-height: 1.2;
}
.hr-calendar-header p {
  margin: 6px 0 0;
  color: #64748b;
}
.hr-calendar-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}
.hr-calendar-summary strong {
  display: block;
  font-size: 26px;
  color: #0f172a;
}
.hr-calendar-summary span {
  color: #64748b;
}
.hr-calendar-filters {
  display: grid;
  grid-template-columns: repeat(5, minmax(150px, 1fr)) auto;
  gap: 10px;
  align-items: end;
}
.hr-calendar-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 420px;
  gap: 16px;
  align-items: start;
}
.hr-calendar-workspace {
  position: sticky;
  top: 82px;
  max-height: calc(100vh - 104px);
  overflow: auto;
}
.hr-calendar-dialog {
  width: min(760px, calc(100vw - 32px));
}
.hr-calendar-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.hr-calendar-form .wide {
  grid-column: 1 / -1;
}
@media (max-width: 1366px) {
  .hr-calendar-layout {
    grid-template-columns: minmax(0, 1fr) 360px;
  }
  .hr-calendar-filters {
    grid-template-columns: repeat(3, minmax(150px, 1fr));
  }
}
@media (max-width: 1023px) {
  .hr-calendar-header,
  .hr-calendar-layout {
    display: block;
  }
  .hr-calendar-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .hr-calendar-workspace {
    position: static;
    margin-top: 16px;
    max-height: none;
  }
  .hr-calendar-filters,
  .hr-calendar-form {
    grid-template-columns: 1fr;
  }
}
</style>
