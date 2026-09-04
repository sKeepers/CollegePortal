<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useQuasar } from "quasar";
import { useRoute, useRouter } from "vue-router";
import {
  Archive,
  CheckCircle2,
  Download,
  ExternalLink,
  FileJson,
  Plus,
  RefreshCw,
  ShieldCheck,
} from "@lucide/vue";
import AppPage from "../../components/ui/AppPage.vue";
import PageHeader from "../../components/ui/PageHeader.vue";
import AppToolbar from "../../components/ui/AppToolbar.vue";
import AppFilterBar from "../../components/ui/AppFilterBar.vue";
import AppTable from "../../components/ui/AppTable.vue";
import AppEmptyState from "../../components/ui/AppEmptyState.vue";
import AppLoading from "../../components/ui/AppLoading.vue";
import AppErrorBanner from "../../components/ui/AppErrorBanner.vue";
import AppStatusBadge from "../../components/ui/AppStatusBadge.vue";
import WorkspaceBackBar from '../../components/workspace/WorkspaceBackBar.vue'
import WorkspacePanel from "../../components/workspace/WorkspacePanel.vue";
import { usePermissions } from "../../composables/usePermissions";
import {
  TABLE_ROWS_PER_PAGE_OPTIONS,
  createTablePagination,
  persistTablePagination,
} from "../../services/tableSettings";
import {
  FRDO_RECORD_STATUS_OPTIONS,
  FRDO_STATUS_OPTIONS,
  formatRuDateTime,
  statusLabel,
  statusTone,
  useFrdoStore,
} from "../../stores/frdo";

const store = useFrdoStore();
const permissions = usePermissions();
const canManage = computed(() => permissions.hasPermission("frdo.export"));
const $q = useQuasar(),
  route = useRoute(),
  router = useRouter();
const rowsKey = "collegePortal.frdo.rowsPerPage";
const syncingQuery = ref(false),
  createVisible = ref(false);
const tablePagination = ref(
  createTablePagination(rowsKey, {
    sortBy: "created_at",
    descending: true,
    rowsPerPage: 20,
  }),
);
const form = reactive({
  name: "",
  graduation_year: new Date().getFullYear(),
  education_program_id: "",
  note: "",
});
const columns = [
  {
    name: "name",
    label: "Пакет",
    field: "name",
    align: "left",
    sortable: true,
  },
  {
    name: "year",
    label: "Год",
    field: "graduation_year",
    align: "left",
    sortable: true,
  },
  {
    name: "program",
    label: "Программа",
    field: "education_program",
    align: "left",
  },
  { name: "records", label: "Записей", field: "records_count", align: "left" },
  {
    name: "errors",
    label: "Ошибок",
    field: "validation_errors_count",
    align: "left",
  },
  { name: "status", label: "Статус", field: "status", align: "left" },
];
const recordColumns = [
  { name: "student", label: "Выпускник", field: "student", align: "left" },
  { name: "diploma", label: "Диплом", field: "diploma", align: "left" },
  {
    name: "program",
    label: "Программа",
    field: "education_program",
    align: "left",
  },
  { name: "status", label: "Статус", field: "status", align: "left" },
  { name: "actions", label: "", field: "actions", align: "right" },
];
const tableSubtitle = computed(
  () =>
    store.error
      ? "Найдено пакетов: неизвестно, ответ не получен"
      : `Найдено пакетов: ${store.filteredPackages.length}`,
);
const selected = computed(() => store.selectedPackage);
const recordCount = computed(
  () => selected.value?.records_count ?? store.records.length,
);
const errorCount = computed(
  () => selected.value?.validation_errors_count ?? store.errors.length,
);

const frdoMetrics = computed(() => [
  { label: "Записей", value: recordCount.value },
  { label: "Ошибок", value: errorCount.value },
  { label: "Год", value: selected.value?.graduation_year || "—" },
  { label: "Выгрузка", value: selected.value?.exported_at ? "Да" : "Нет" },
]);
function rowClass(row) {
  return Number(row.id) === Number(store.selectedId)
    ? "frdo-row--selected"
    : "";
}
function notify(message) {
  $q.notify({
    type: "positive",
    message,
    position: "top-right",
    timeout: 1800,
  });
}
function packageProgram(pkg) {
  return pkg?.education_program?.name || "Все программы";
}
function payload(record, key) {
  return record?.payload?.[key] || "—";
}
function diplomaText(record) {
  return (
    [record?.payload?.diploma_series, record?.payload?.diploma_number]
      .filter(Boolean)
      .join(" ") || "—"
  );
}
function updatePagination(p) {
  tablePagination.value = p;
  persistTablePagination(rowsKey, p);
}
function routeSelectedId() {
  return route.params.id ? String(route.params.id) : "";
}
async function syncQuery(selectedId = routeSelectedId()) {
  const query = { ...route.query };
  syncingQuery.value = true;
  await router.replace({ path: selectedId ? `/frdo/${selectedId}` : "/frdo", query });
  syncingQuery.value = false;
}
async function selectPackage(pkg) {
  store.select(pkg);
  await syncQuery(pkg?.id || "");
}
function openCreate() {
  if (!canManage.value) return;
  Object.assign(form, {
    name: "",
    graduation_year:
      store.graduationYearOptions[0]?.value || new Date().getFullYear(),
    education_program_id: "",
    note: "",
  });
  createVisible.value = true;
}
async function createPackage() {
  if (!canManage.value) return;
  await store.createPackage(form);
  createVisible.value = false;
  notify("Пакет ФРДО создан");
}
async function validatePackage() {
  if (!canManage.value) return;
  await store.validatePackage();
  notify("Пакет проверен");
}
async function markExported() {
  if (!canManage.value) return;
  await store.markExported();
  notify("Пакет отмечен как выгруженный");
}
async function archivePackage() {
  if (!canManage.value) return;
  await store.archive();
  notify("Пакет архивирован");
}
async function exportCsv() {
  if (!canManage.value) return;
  await store.exportCsv();
  notify("CSV выгружен");
}
async function exportJson() {
  if (!canManage.value) return;
  await store.exportJson();
  notify("JSON выгружен");
}
async function applyFilters() {
  store.setFilters({ ...store.filters });
  await syncQuery("");
}
async function resetFilters() {
  store.resetFilters();
  await syncQuery("");
}
watch(
  () => route.params.id,
  () => {
    if (!syncingQuery.value) store.selectById(routeSelectedId());
  },
);
onMounted(async () => {
  store.selectById(routeSelectedId());
  await store.load();
  if (!store.selectedPackage && store.filteredPackages[0])
    await selectPackage(store.filteredPackages[0]);
});
</script>

<template>
  <AppPage>
    <PageHeader
      title="ФРДО"
      subtitle="Подготовка, проверка и выгрузка данных выпускников без реальной отправки во ФРДО."
      ><template #actions
        ><q-btn v-if="canManage" color="primary" @click="openCreate"
          ><Plus :size="16" class="q-mr-xs" /> Новый пакет</q-btn
        ></template
      ></PageHeader
    >
    <AppToolbar
      ><span>{{ tableSubtitle }}</span
      ><template #actions
        ><AppLoading v-if="store.loading" label="Загрузка ФРДО..." /><q-btn
          flat
          :disable="store.loading"
          @click="store.load"
          ><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn
        ></template
      ></AppToolbar
    >
    <AppErrorBanner :message="store.error" />
    <AppFilterBar
      ><q-select
        v-model="store.filters.graduation_year"
        dense
        outlined
        clearable
        emit-value
        map-options
        label="Год выпуска"
        :options="store.graduationYearOptions"
      /><q-select
        v-model="store.filters.status"
        dense
        outlined
        clearable
        emit-value
        map-options
        label="Статус"
        :options="FRDO_STATUS_OPTIONS"
      /><q-select
        v-model="store.filters.education_program_id"
        dense
        outlined
        clearable
        emit-value
        map-options
        label="Программа"
        :options="store.programOptions"
      /><template #actions
        ><q-btn color="primary" @click="applyFilters">Применить</q-btn
        ><q-btn flat @click="resetFilters">Сбросить</q-btn></template
      ></AppFilterBar
    >
    <div class="frdo-workspace workspace-page" :class="{ 'workspace-page--card': Boolean(route.params.id) }">
      <div class="frdo-main workspace-page__list">
        <AppTable
          v-if="store.filteredPackages.length || store.loading"
          :rows="store.filteredPackages"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          :table-row-class-fn="rowClass"
          @update:pagination="updatePagination"
          :row-link="(row) => `/frdo/${row.id}`"
          @row-click="(_, row) => selectPackage(row)"
          ><template #body-cell-name="props"
            ><q-td :props="props"
              ><button
                class="frdo-row-link"
                type="button"
                @click.stop="selectPackage(props.row)"
              >
                {{ props.row.name }}
              </button>
              <div class="frdo-secondary-cell">
                Проверка:
                {{ formatRuDateTime(props.row.validation_checked_at) }}
              </div></q-td
            ></template
          ><template #body-cell-year="props"
            ><q-td :props="props">{{
              props.row.graduation_year
            }}</q-td></template
          ><template #body-cell-program="props"
            ><q-td :props="props">{{
              packageProgram(props.row)
            }}</q-td></template
          ><template #body-cell-records="props"
            ><q-td :props="props">{{
              props.row.records_count ?? props.row.records?.length ?? 0
            }}</q-td></template
          ><template #body-cell-errors="props"
            ><q-td :props="props"
              ><strong
                :class="
                  Number(props.row.validation_errors_count || 0) > 0
                    ? 'text-negative'
                    : 'text-positive'
                "
                >{{
                  props.row.validation_errors_count ??
                  props.row.validation_errors?.length ??
                  0
                }}</strong
              ></q-td
            ></template
          ><template #body-cell-status="props"
            ><q-td :props="props"
              ><AppStatusBadge
                :label="statusLabel(FRDO_STATUS_OPTIONS, props.row.status)"
                :tone="
                  statusTone(FRDO_STATUS_OPTIONS, props.row.status)
                " /></q-td></template></AppTable
        ><AppEmptyState
          v-else-if="store.error"
          title="Список пакетов прочитать не удалось"
          description="Это не значит, что пакетов нет: ответ не получен. Сообщение об ошибке — выше."
        /><AppEmptyState
          v-else
          title="Пакеты ФРДО не найдены"
          description="Создайте пакет из выпускников за выбранный год."
        />
      </div>
      <aside class="frdo-side workspace-page__card">
        <WorkspaceBackBar />
        <AppEmptyState
          v-if="!selected"
          title="Пакет не выбран"
          description="Выберите пакет в таблице, чтобы открыть карточку."
        /><WorkspacePanel
          v-else
          class="frdo-card"
          :title="selected.name"
          :subtitle="`${selected.graduation_year} · ${packageProgram(selected)}`"
          :metrics="frdoMetrics"
          ><template #status
            ><AppStatusBadge
              :label="statusLabel(FRDO_STATUS_OPTIONS, selected.status)"
              :tone="statusTone(FRDO_STATUS_OPTIONS, selected.status)"
          /></template>
          <div class="frdo-details">
            <section>
              <h3>Действия</h3>
              <div class="frdo-actions">
                <q-btn
                  color="primary"
                  dense
                  :loading="store.saving"
                  @click="validatePackage"
                  ><ShieldCheck :size="15" class="q-mr-xs" /> Проверить</q-btn
                ><q-btn dense outline @click="exportCsv"
                  ><Download :size="15" class="q-mr-xs" /> CSV</q-btn
                ><q-btn dense outline @click="exportJson"
                  ><FileJson :size="15" class="q-mr-xs" /> JSON</q-btn
                ><q-btn
                  dense
                  outline
                  color="positive"
                  :loading="store.saving"
                  @click="markExported"
                  ><CheckCircle2 :size="15" class="q-mr-xs" /> Exported</q-btn
                ><q-btn
                  dense
                  outline
                  color="warning"
                  :loading="store.saving"
                  @click="archivePackage"
                  ><Archive :size="15" class="q-mr-xs" /> Архив</q-btn
                >
              </div>
            </section>
            <section v-if="store.errors.length">
              <h3>Ошибки проверки</h3>
              <div class="frdo-errors">
                <div v-for="error in store.errors" :key="error.id">
                  <strong>{{ error.field || "Поле" }}</strong
                  ><span>{{ error.message }}</span>
                </div>
              </div>
            </section>
            <section>
              <h3>Записи пакета</h3>
              <div v-if="store.records.length" class="frdo-record-list">
                <article
                  v-for="record in store.records"
                  :key="record.id"
                  class="frdo-record-item"
                >
                  <div>
                    <RouterLink
                      :to="{
                        path: `/graduation/${record.graduate_id}`,
                      }"
                      class="entity-link-action frdo-record-title"
                      >{{ payload(record, "student") }}</RouterLink
                    ><span>{{ payload(record, "birth_date") }}</span>
                  </div>
                  <div>
                    <strong>{{ diplomaText(record) }}</strong
                    ><span>{{ payload(record, "registration_number") }}</span>
                  </div>
                  <div>
                    <strong>{{ payload(record, "education_program") }}</strong
                    ><span>{{ payload(record, "specialty") }}</span>
                  </div>
                  <div class="frdo-record-footer">
                    <AppStatusBadge
                      :label="
                        statusLabel(FRDO_RECORD_STATUS_OPTIONS, record.status)
                      "
                      :tone="
                        statusTone(FRDO_RECORD_STATUS_OPTIONS, record.status)
                      "
                    /><q-btn
                      flat
                      round
                      dense
                      title="Открыть студента"
                      :to="{
                        path: `/students/${record.graduate?.student_id}`,
                      }"
                      ><ExternalLink :size="15"
                    /></q-btn>
                  </div>
                </article>
              </div>
              <p v-else class="frdo-muted">В пакете пока нет записей.</p>
            </section>
          </div></WorkspacePanel
        >
      </aside>
    </div>
    <q-dialog v-model="createVisible"
      ><q-card class="frdo-dialog"
        ><q-card-section
          ><div class="text-h6">Новый пакет ФРДО</div></q-card-section
        ><q-card-section class="frdo-dialog__body"
          ><q-input
            v-model="form.name"
            outlined
            dense
            label="Название" /><q-input
            v-model="form.graduation_year"
            outlined
            dense
            type="number"
            label="Год выпуска" /><q-select
            v-model="form.education_program_id"
            outlined
            dense
            clearable
            emit-value
            map-options
            label="Программа"
            :options="store.programOptions" /><q-input
            v-model="form.note"
            outlined
            dense
            type="textarea"
            label="Комментарий" /></q-card-section
        ><q-card-actions align="right"
          ><q-btn flat label="Отмена" @click="createVisible = false" /><q-btn
            color="primary"
            :loading="store.saving"
            :disable="!form.graduation_year"
            @click="createPackage"
            >Создать</q-btn
          ></q-card-actions
        ></q-card
      ></q-dialog
    >
  </AppPage>
</template>
