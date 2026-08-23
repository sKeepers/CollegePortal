<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useQuasar } from 'quasar'
import { HeartHandshake, Plus, RefreshCw } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { useDormUpbringingStore } from '../../stores/dormUpbringing'
import { usePermissions } from '../../composables/usePermissions'

/**
 * Воспитательная работа: провинности и социальный паспорт.
 *
 * Отдельный раздел, а не вкладка общежития, — намеренно. У этого контура своё
 * право, выданное ровно одной роли: комендант сюда не попадает вовсе, и
 * смешивать два разных доступа на одной странице нельзя.
 *
 * Три правила владельца видны прямо на экране, а не спрятаны в коде:
 * запись гаснет через год, студент своих записей не видит, а исправляется
 * запись дополнением — после суток переписать её нельзя.
 */
const store = useDormUpbringingStore()
const permissions = usePermissions()
const $q = useQuasar()

const canManageConduct = computed(() => permissions.hasPermission('dorm.conduct.manage'))
const canSeeSocial = computed(() => permissions.hasPermission('dorm.social.view'))
const canManageSocial = computed(() => permissions.hasPermission('dorm.social.manage'))

const tab = ref('conduct')

const conductDialog = ref(false)
const conductForm = reactive({ id: null, mode: 'create', student_id: null, student_name: '', happened_on: today(), summary: '', description: '' })

const socialDialog = ref(false)
const socialForm = reactive({ id: null, student_id: null, student_name: '', category: 'difficult', details: '', opened_on: today(), closed_on: '' })

const conductColumns = [
  { name: 'happened_on', label: 'Когда', field: 'happened_on', align: 'left', sortable: true },
  { name: 'student', label: 'Студент', field: 'student', align: 'left' },
  { name: 'group', label: 'Группа', field: 'group', align: 'left' },
  { name: 'summary', label: 'Что произошло', field: 'summary', align: 'left' },
  { name: 'expires_on', label: 'Учитывается до', field: 'expires_on', align: 'left', sortable: true },
  { name: 'created_by', label: 'Записал', field: 'created_by', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const socialColumns = [
  { name: 'student', label: 'Студент', field: 'student', align: 'left' },
  { name: 'group', label: 'Группа', field: 'group', align: 'left' },
  { name: 'category', label: 'Категория', field: 'category', align: 'left', sortable: true },
  { name: 'opened_on', label: 'С', field: 'opened_on', align: 'left', sortable: true },
  { name: 'closed_on', label: 'По', field: 'closed_on', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

function today() {
  return new Date().toISOString().slice(0, 10)
}

function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)

  return Number.isNaN(date.valueOf()) ? String(value) : date.toLocaleDateString('ru-RU')
}

function notify(message, type = 'positive') {
  $q.notify({ type, message, position: 'top-right', timeout: 2500 })
}

function openConduct(mode, record = null) {
  Object.assign(conductForm, {
    id: record?.id ?? null,
    mode,
    student_id: record?.student_id ?? null,
    student_name: record?.student?.full_name ?? '',
    happened_on: record?.happened_on || today(),
    summary: mode === 'edit' ? record?.summary || '' : '',
    description: mode === 'edit' ? record?.description || '' : '',
  })
  conductDialog.value = true
}

async function submitConduct() {
  let done = false

  if (conductForm.mode === 'create') {
    done = await store.recordConduct({
      student_id: conductForm.student_id,
      happened_on: conductForm.happened_on,
      summary: conductForm.summary.trim(),
      description: conductForm.description || null,
    })
  } else if (conductForm.mode === 'edit') {
    done = await store.updateConduct({ id: conductForm.id }, {
      summary: conductForm.summary.trim(),
      description: conductForm.description || null,
    })
  } else {
    done = await store.amendConduct({ id: conductForm.id }, {
      summary: conductForm.summary.trim(),
      description: conductForm.description || null,
    })
  }

  if (done) {
    conductDialog.value = false
    notify(conductForm.mode === 'amend' ? 'Дополнение записано' : 'Запись сохранена')
  }
}

function openSocial(record = null) {
  Object.assign(socialForm, {
    id: record?.id ?? null,
    student_id: record?.student_id ?? null,
    student_name: record?.student?.full_name ?? '',
    category: record?.category || 'difficult',
    details: record?.details || '',
    opened_on: record?.opened_on || today(),
    closed_on: record?.closed_on || '',
  })
  socialDialog.value = true
}

async function submitSocial() {
  const done = socialForm.id
    ? await store.updateSocial({ id: socialForm.id }, {
      details: socialForm.details || null,
      closed_on: socialForm.closed_on || null,
    })
    : await store.recordSocial({
      student_id: socialForm.student_id,
      category: socialForm.category,
      details: socialForm.details || null,
      opened_on: socialForm.opened_on,
      closed_on: socialForm.closed_on || null,
    })

  if (done) {
    socialDialog.value = false
    notify('Сведения сохранены')
  }
}

async function openTab(name) {
  tab.value = name
  if (name === 'conduct') await store.loadConduct()
  if (name === 'social') await store.loadSocial()
}

onMounted(() => store.loadConduct())
</script>

<template>
  <AppPage>
    <PageHeader title="Воспитательная работа" subtitle="Провинности и социальный паспорт">
      <template #icon><HeartHandshake :size="22" /></template>
    </PageHeader>

    <AppErrorBanner v-if="store.error" :message="store.error" />

    <q-tabs :model-value="tab" dense no-caps align="left" class="upb-tabs" @update:model-value="openTab">
      <q-tab name="conduct" label="Провинности" />
      <q-tab v-if="canSeeSocial" name="social" label="Социальный паспорт" />
    </q-tabs>

    <q-tab-panels :model-value="tab" animated class="upb-panels">
      <q-tab-panel name="conduct" class="q-pa-none">
        <AppToolbar>
          <q-toggle v-model="store.conductFilters.active" label="Только действующие" @update:model-value="store.loadConduct" />
          <q-space />
          <q-btn flat no-caps :disable="store.loading" @click="store.loadConduct">
            <RefreshCw :size="16" class="q-mr-xs" /> Обновить
          </q-btn>
          <q-btn v-if="canManageConduct" color="primary" unelevated no-caps @click="openConduct('create')">
            <Plus :size="16" class="q-mr-xs" /> Записать
          </q-btn>
        </AppToolbar>

        <div class="upb-hint">
          Запись <b>не удаляется, а гаснет через год</b> — дальше она остаётся в истории, но не учитывается.
          Студент своих записей не видит. Исправить свою запись можно в течение суток, дальше — только дополнением:
          история не переписывается задним числом.
        </div>

        <AppLoading v-if="store.loading" />
        <AppEmptyState v-else-if="!store.conduct.length" title="Записей нет" description="По выбранному отбору записей о провинностях нет." />
        <AppTable v-else :rows="store.conduct" :columns="conductColumns" row-key="id" :pagination="{ rowsPerPage: 50 }">
          <template #body-cell-happened_on="props">
            <q-td :props="props">{{ formatDate(props.row.happened_on) }}</q-td>
          </template>
          <template #body-cell-student="props">
            <q-td :props="props">{{ props.row.student?.full_name || '—' }}</q-td>
          </template>
          <template #body-cell-group="props">
            <q-td :props="props">{{ props.row.student?.group || '—' }}</q-td>
          </template>
          <template #body-cell-summary="props">
            <q-td :props="props">
              <div>{{ props.row.summary }}</div>
              <div v-for="item in props.row.amendments || []" :key="item.id" class="upb-amendment">
                дополнение: {{ item.summary }} <span v-if="item.created_by">— {{ item.created_by }}</span>
              </div>
            </q-td>
          </template>
          <template #body-cell-expires_on="props">
            <q-td :props="props">
              <AppStatusBadge
                :label="props.row.is_active ? `до ${formatDate(props.row.expires_on)}` : 'погасла'"
                :tone="props.row.is_active ? 'warning' : 'neutral'"
              />
            </q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props" class="upb-actions">
              <template v-if="canManageConduct">
                <q-btn flat dense no-caps color="primary" @click="openConduct('edit', props.row)">Исправить</q-btn>
                <q-btn flat dense no-caps @click="openConduct('amend', props.row)">Дополнить</q-btn>
              </template>
            </q-td>
          </template>
        </AppTable>
      </q-tab-panel>

      <q-tab-panel name="social" class="q-pa-none">
        <AppToolbar>
          <q-select
            v-model="store.socialFilters.category"
            dense outlined clearable emit-value map-options
            label="Категория" style="min-width: 240px"
            :options="store.categoryOptions"
            @update:model-value="store.loadSocial"
          />
          <q-toggle v-model="store.socialFilters.open" label="Только действующие" @update:model-value="store.loadSocial" />
          <q-space />
          <q-btn flat no-caps :disable="store.loading" @click="store.loadSocial">
            <RefreshCw :size="16" class="q-mr-xs" /> Обновить
          </q-btn>
          <q-btn v-if="canManageSocial" color="primary" unelevated no-caps @click="openSocial()">
            <Plus :size="16" class="q-mr-xs" /> Добавить сведения
          </q-btn>
        </AppToolbar>

        <div class="upb-hint">
          Самые чувствительные данные портала: право на них выдано одной роли, и <b>каждый просмотр этого списка пишется в аудит</b> —
          не только правка. Спросят «кто это смотрел» — ответ будет.
        </div>

        <AppLoading v-if="store.loading" />
        <AppEmptyState v-else-if="!store.social.length" title="Сведений нет" description="По выбранному отбору записей нет." />
        <AppTable v-else :rows="store.social" :columns="socialColumns" row-key="id" :pagination="{ rowsPerPage: 50 }">
          <template #body-cell-student="props">
            <q-td :props="props">{{ props.row.student?.full_name || '—' }}</q-td>
          </template>
          <template #body-cell-group="props">
            <q-td :props="props">{{ props.row.student?.group || '—' }}</q-td>
          </template>
          <template #body-cell-category="props">
            <q-td :props="props">{{ props.row.category_label }}</q-td>
          </template>
          <template #body-cell-opened_on="props">
            <q-td :props="props">{{ formatDate(props.row.opened_on) }}</q-td>
          </template>
          <template #body-cell-closed_on="props">
            <q-td :props="props">
              <span v-if="props.row.is_open">действует</span>
              <span v-else>{{ formatDate(props.row.closed_on) }}</span>
            </q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props">
              <q-btn v-if="canManageSocial" flat dense no-caps color="primary" @click="openSocial(props.row)">Изменить</q-btn>
            </q-td>
          </template>
        </AppTable>
      </q-tab-panel>
    </q-tab-panels>

    <q-dialog v-model="conductDialog">
      <q-card class="upb-dialog">
        <q-card-section class="text-h6">
          {{ conductForm.mode === 'create' ? 'Записать' : conductForm.mode === 'edit' ? 'Исправить запись' : 'Дополнить запись' }}
        </q-card-section>
        <q-card-section class="column q-gutter-sm">
          <div v-if="conductForm.mode !== 'create'">{{ conductForm.student_name }}</div>
          <template v-if="conductForm.mode === 'create'">
            <q-select
              v-model="conductForm.student_id"
              dense outlined use-input emit-value map-options
              input-debounce="350" label="Студент"
              :options="store.studentOptions" :loading="store.searching"
              @filter="(value, update) => { store.searchStudents(value); update(() => {}) }"
            />
            <q-input v-model="conductForm.happened_on" dense outlined type="date" label="Когда произошло" />
          </template>
          <q-input v-model="conductForm.summary" dense outlined label="В одну строку" autofocus />
          <q-input v-model="conductForm.description" dense outlined type="textarea" autogrow label="Подробно" />
          <div v-if="conductForm.mode === 'amend'" class="upb-hint">
            Дополнение встанет рядом с исходной записью и не перепишет сказанного.
          </div>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps label="Сохранить" :loading="store.saving" :disable="!conductForm.summary.trim() || (conductForm.mode === 'create' && !conductForm.student_id)" @click="submitConduct" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="socialDialog">
      <q-card class="upb-dialog">
        <q-card-section class="text-h6">{{ socialForm.id ? 'Изменить сведения' : 'Добавить сведения' }}</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <div v-if="socialForm.id">{{ socialForm.student_name }} — {{ store.categories[socialForm.category] }}</div>
          <template v-else>
            <q-select
              v-model="socialForm.student_id"
              dense outlined use-input emit-value map-options
              input-debounce="350" label="Студент"
              :options="store.studentOptions" :loading="store.searching"
              @filter="(value, update) => { store.searchStudents(value); update(() => {}) }"
            />
            <q-select v-model="socialForm.category" dense outlined emit-value map-options label="Категория" :options="store.categoryOptions" />
            <q-input v-model="socialForm.opened_on" dense outlined type="date" label="Действует с" />
          </template>
          <q-input v-model="socialForm.details" dense outlined type="textarea" autogrow label="Подробности" />
          <q-input v-model="socialForm.closed_on" dense outlined type="date" label="Закрыто" hint="Пусто — сведения действуют" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps label="Сохранить" :loading="store.saving" :disable="!socialForm.id && !socialForm.student_id" @click="submitSocial" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </AppPage>
</template>

<style scoped>
.upb-tabs { border-bottom: 1px solid #e2e8f0; }
.upb-panels { background: transparent; }
.upb-hint { margin: 12px 0; font-size: 13px; color: #475569; }
.upb-amendment { font-size: 12px; color: #64748b; margin-top: 4px; }
.upb-actions { white-space: nowrap; }
.upb-dialog { min-width: min(520px, 92vw); }

/* Поля ввода блочные: без этого фильтры встают столбиком. */
:deep(.app-toolbar__content) {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  width: 100%;
}

:deep(.app-toolbar) { padding: 8px 10px; }
</style>
