<script setup>
/**
 * Бланки строгой отчётности и книга регистрации выданных дипломов.
 *
 * Кнопки «удалить» на этом экране нет и не должно быть. Испорченный бланк
 * отмечается испорченным и списывается актом: он остаётся в книге с номером и
 * причиной, потому что по нему отчитываются. «Его тут не было» — не ответ
 * проверке.
 */
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import {
  BLANK_KIND_OPTIONS,
  BLANK_STATUS_OPTIONS,
  formatRuDate,
  kindLabel,
  statusLabel,
  statusTone,
  useDiplomaBlanksStore,
} from '../../stores/diplomaBlanks'
import { useGraduationStore } from '../../stores/graduation'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import { escapeHtml, printHtmlDocument, printPage } from '../../utils/print'

const store = useDiplomaBlanksStore()
const graduation = useGraduationStore()
const $q = useQuasar()

const tab = ref('blanks')
const receiveOpen = ref(false)
const registryYear = ref(null)
const receiveForm = ref(emptyBatch())

const toneColour = { neutral: 'grey-7', info: 'blue-7', success: 'green-7', warning: 'orange-8', danger: 'red-7' }

function emptyBatch() {
  return {
    kind: 'diploma',
    series: '',
    number_from: '',
    number_to: '',
    received_at: '',
    supplier: '',
    invoice_number: '',
    note: '',
  }
}

const rangeSize = computed(() => {
  const from = Number(receiveForm.value.number_from)
  const to = Number(receiveForm.value.number_to)
  if (!Number.isFinite(from) || !Number.isFinite(to) || to < from) return null
  return to - from + 1
})

const graduateOptions = computed(() => graduation.graduates.map((item) => ({
  label: [item.student?.last_name, item.student?.first_name, item.student?.middle_name].filter(Boolean).join(' ') || `Выпускник ${item.id}`,
  value: item.id,
})))

onMounted(async () => {
  await store.load()
  await graduation.load()
})

async function submitBatch() {
  try {
    await store.receive(receiveForm.value)
    receiveOpen.value = false
    receiveForm.value = emptyBatch()
    $q.notify({ type: 'positive', message: 'Партия принята' })
  } catch {
    $q.notify({ type: 'negative', message: store.error })
  }
}

async function run(action, blank, ...args) {
  try {
    await store[action](blank, ...args)
    $q.notify({ type: 'positive', message: 'Записано' })
  } catch {
    $q.notify({ type: 'negative', message: store.error })
  }
}

function askAndSpoil(blank) {
  $q.dialog({
    title: 'Бланк испорчен',
    message: `Бланк ${blank.label}. Укажите, чем именно испорчен: по этой записи потом отчитываются.`,
    prompt: { model: '', type: 'text', isValid: (value) => String(value).trim().length >= 3 },
    cancel: true,
  }).onOk((reason) => run('spoil', blank, reason))
}

function askAndWriteOff(blank) {
  $q.dialog({
    title: 'Списание',
    message: `Бланк ${blank.label}. Номер акта списания:`,
    prompt: { model: '', type: 'text', isValid: (value) => String(value).trim().length > 0 },
    cancel: true,
  }).onOk((actNumber) => run('writeOff', blank, actNumber))
}

function askAndAssign(blank) {
  $q.dialog({
    title: 'Закрепить за выпускником',
    message: `Бланк ${blank.label}.`,
    options: { type: 'radio', model: null, items: graduateOptions.value },
    cancel: true,
  }).onOk((graduateId) => graduateId && run('assign', blank, graduateId))
}

async function openRegistry() {
  await store.loadRegistry(registryYear.value)
}

/**
 * Печать книги — отдельным документом, а не текущей страницей.
 *
 * Тот же приём, что у ведомости выдачи карт: каскад приложения до печатного
 * листа не достаёт, и что собрано, то и печатается.
 */
function printRegistry() {
  const rows = store.registry.map((row, index) => `
      <tr>
        <td>${index + 1}</td>
        <td>${escapeHtml(row.registration_number || '—')}</td>
        <td>${escapeHtml(row.full_name)}</td>
        <td>${escapeHtml(row.specialty || '—')}</td>
        <td>${escapeHtml(row.qualification || '—')}</td>
        <td>${escapeHtml(row.diploma_blank || '—')}</td>
        <td>${escapeHtml(row.supplement_blank || '—')}</td>
        <td>${escapeHtml(formatRuDate(row.issue_date))}</td>
        <td class="sign"></td>
      </tr>`).join('')

  printHtmlDocument(printPage({
    title: 'Книга регистрации выданных дипломов',
    subtitle: registryYear.value ? `Выпуск ${registryYear.value} года` : 'Все годы выпуска',
    body: `<table>
<thead>
<tr><th>№</th><th>Рег. №</th><th>Фамилия, имя, отчество</th><th>Специальность</th><th>Квалификация</th><th>Бланк диплома</th><th>Бланк приложения</th><th>Дата выдачи</th><th class="sign">Подпись получателя</th></tr>
</thead>
<tbody>${rows}
</tbody>
</table>`,
    footer: `Всего записей: ${store.registry.length}. Напечатано ${new Date().toLocaleString('ru-RU')}.`,
  }))
}
</script>

<template>
  <q-page padding>
<!--
      Заголовок — общей опорой `PageHeader`, как во всех прочих разделах.
      Раньше здесь стоял свой `text-h6`: на экране он выглядел заголовком, но
      был не тем же самым, и раздел отличался от соседних. Увидели это только
      глазами, когда на DEV появился браузер: счётчик проверок такого не ловит.
    -->
    <PageHeader
      title="Бланки строгой отчётности"
      subtitle="Приход партии, закрепление за выпускником, выдача, порча и списание. Ничего из этого не удаляется."
    >
      <template #actions>
        <q-chip outline color="grey-8">В наличии: {{ store.inStock }}</q-chip>
        <q-chip v-if="store.spoiled" outline color="orange-8">Испорчено: {{ store.spoiled }}</q-chip>
        <q-btn color="primary" icon="add" label="Принять партию" @click="receiveOpen = true" />
      </template>
    </PageHeader>

    <q-banner v-if="store.error" class="bg-red-1 text-red-9 q-mb-md" rounded>{{ store.error }}</q-banner>

    <q-tabs v-model="tab" align="left" class="q-mb-sm" dense>
      <q-tab name="blanks" label="Бланки" />
      <q-tab name="balance" label="Остаток" />
      <q-tab name="batches" label="Партии" />
      <q-tab name="registry" label="Книга регистрации" />
    </q-tabs>

    <q-tab-panels v-model="tab" animated>
      <q-tab-panel name="blanks">
        <div class="row q-gutter-sm q-mb-sm">
          <q-select
            :model-value="store.filters.kind" dense outlined clearable emit-value map-options style="min-width: 200px"
            label="Вид" :options="BLANK_KIND_OPTIONS"
            @update:model-value="store.setFilters({ kind: $event || '' }); store.load()"
          />
          <q-select
            :model-value="store.filters.status" dense outlined clearable emit-value map-options style="min-width: 180px"
            label="Состояние" :options="BLANK_STATUS_OPTIONS"
            @update:model-value="store.setFilters({ status: $event || '' }); store.load()"
          />
          <q-input
            :model-value="store.filters.number" dense outlined clearable label="Номер" style="min-width: 160px"
            @update:model-value="store.setFilters({ number: $event || '' })" @keyup.enter="store.load()"
          />
        </div>

        <q-markup-table flat bordered dense>
          <thead>
            <tr>
              <th class="text-left">Вид</th>
              <th class="text-left">Серия и номер</th>
              <th class="text-left">Состояние</th>
              <th class="text-left">За кем</th>
              <th class="text-left">Причина / акт</th>
              <th class="text-right">Что сделать</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="blank in store.blanks" :key="blank.id">
              <td>{{ kindLabel(blank.kind) }}</td>
              <td class="text-weight-medium">{{ blank.label }}</td>
              <td><q-badge :color="toneColour[statusTone(blank.status)]">{{ statusLabel(blank.status) }}</q-badge></td>
              <td>{{ blank.graduate_name || '—' }}</td>
              <td class="text-caption">
                <div v-if="blank.reason">{{ blank.reason }}</div>
                <div v-if="blank.write_off_act">Акт: {{ blank.write_off_act }}</div>
                <div v-if="blank.issued_at">Выдан {{ formatRuDate(blank.issued_at) }}</div>
              </td>
              <td class="text-right">
                <q-btn v-if="blank.status === 'stock'" flat dense size="sm" label="Закрепить" @click="askAndAssign(blank)" />
                <q-btn v-if="blank.status === 'assigned'" flat dense size="sm" label="Выдать" @click="run('issue', blank, null)" />
                <q-btn v-if="blank.status === 'assigned'" flat dense size="sm" label="Снять" @click="run('release', blank, '')" />
                <q-btn v-if="['stock', 'assigned'].includes(blank.status)" flat dense size="sm" color="orange-9" label="Испорчен" @click="askAndSpoil(blank)" />
                <q-btn v-if="blank.status === 'spoiled'" flat dense size="sm" color="red-8" label="Списать" @click="askAndWriteOff(blank)" />
                <span v-if="['issued', 'written_off'].includes(blank.status)" class="text-caption text-grey-7">действий нет</span>
              </td>
            </tr>
            <tr v-if="!store.blanks.length && !store.loading">
              <td colspan="6" class="text-center text-grey-7">Бланков нет. Начните с прихода партии.</td>
            </tr>
          </tbody>
        </q-markup-table>
      </q-tab-panel>

      <q-tab-panel name="balance">
        <!--
          Пустое место обязано сказать, что оно пустое. `q-markup-table` своего
          пустого состояния не имеет, и без этой ветки человек видел голую шапку
          таблицы и читал её как сломанный экран.
        -->
        <AppEmptyState
          v-if="!store.balance.length && !store.loading"
          title="Остатка пока нет"
          description="Остаток считается по принятым бланкам. Примите партию — и он появится сам."
        />
        <q-markup-table v-else flat bordered dense>
          <thead>
            <tr>
              <th class="text-left">Вид</th>
              <th class="text-left">Серия</th>
              <th class="text-right" v-for="option in BLANK_STATUS_OPTIONS" :key="option.value">{{ option.label }}</th>
              <th class="text-right">Всего</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in store.balance" :key="`${row.kind}-${row.series}`">
              <td>{{ kindLabel(row.kind) }}</td>
              <td>{{ row.series }}</td>
              <td class="text-right" v-for="option in BLANK_STATUS_OPTIONS" :key="option.value">{{ row[option.value] || 0 }}</td>
              <td class="text-right text-weight-medium">{{ row.total }}</td>
            </tr>
          </tbody>
        </q-markup-table>
      </q-tab-panel>

      <q-tab-panel name="batches">
        <AppEmptyState
          v-if="!store.batches.length && !store.loading"
          title="Партий пока не принимали"
          description="Приход партии — первое действие в учёте: бланки заводятся диапазоном номеров."
        />
        <q-markup-table v-else flat bordered dense>
          <thead>
            <tr>
              <th class="text-left">Принята</th>
              <th class="text-left">Вид</th>
              <th class="text-left">Серия</th>
              <th class="text-left">Диапазон</th>
              <th class="text-right">Бланков</th>
              <th class="text-left">Поставщик, накладная</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="batch in store.batches" :key="batch.id">
              <td>{{ formatRuDate(batch.received_at) }}</td>
              <td>{{ kindLabel(batch.kind) }}</td>
              <td>{{ batch.series }}</td>
              <td>{{ batch.number_from }} — {{ batch.number_to }}</td>
              <td class="text-right">{{ batch.quantity }}</td>
              <td class="text-caption">{{ [batch.supplier, batch.invoice_number].filter(Boolean).join(', ') || '—' }}</td>
            </tr>
          </tbody>
        </q-markup-table>
      </q-tab-panel>

      <q-tab-panel name="registry">
        <div class="row q-gutter-sm q-mb-sm items-center">
          <q-select
            v-model="registryYear" dense outlined clearable emit-value map-options style="min-width: 200px"
            label="Год выпуска" :options="store.registryYears.map((year) => ({ label: String(year), value: year }))"
            @update:model-value="openRegistry"
          />
          <q-btn flat icon="refresh" label="Обновить" @click="openRegistry" />
          <q-space />
          <q-btn color="primary" icon="print" label="Печать книги" :disable="!store.registry.length" @click="printRegistry" />
        </div>

        <q-markup-table flat bordered dense>
          <thead>
            <tr>
              <th class="text-left">Рег. №</th>
              <th class="text-left">Фамилия, имя, отчество</th>
              <th class="text-left">Специальность</th>
              <th class="text-left">Бланк диплома</th>
              <th class="text-left">Бланк приложения</th>
              <th class="text-left">Дата выдачи</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in store.registry" :key="row.diploma_id">
              <td>{{ row.registration_number || '—' }}</td>
              <td>{{ row.full_name }}</td>
              <td>{{ row.specialty || '—' }}</td>
              <td>{{ row.diploma_blank || '—' }}</td>
              <td>{{ row.supplement_blank || '—' }}</td>
              <td>{{ formatRuDate(row.issue_date) }}</td>
            </tr>
            <tr v-if="!store.registry.length && !store.loading">
              <td colspan="6" class="text-center text-grey-7">Выданных дипломов пока нет. Нажмите «Обновить».</td>
            </tr>
          </tbody>
        </q-markup-table>
      </q-tab-panel>
    </q-tab-panels>

    <q-dialog v-model="receiveOpen">
      <q-card style="min-width: 460px">
        <q-card-section class="text-h6">Приход партии бланков</q-card-section>
        <q-card-section class="q-gutter-sm">
          <q-select v-model="receiveForm.kind" dense outlined emit-value map-options label="Вид бланка" :options="BLANK_KIND_OPTIONS" />
          <q-input v-model="receiveForm.series" dense outlined label="Серия" />
          <div class="row q-gutter-sm">
            <q-input v-model="receiveForm.number_from" dense outlined class="col" label="Номер с" hint="Ведущие нули сохраняются" />
            <q-input v-model="receiveForm.number_to" dense outlined class="col" label="Номер по" />
          </div>
          <div v-if="rangeSize" class="text-caption text-grey-8">В диапазоне {{ rangeSize }} бланков.</div>
          <q-input v-model="receiveForm.received_at" dense outlined type="date" stack-label label="Дата прихода" />
          <q-input v-model="receiveForm.supplier" dense outlined label="Поставщик" />
          <q-input v-model="receiveForm.invoice_number" dense outlined label="Накладная" />
          <q-input v-model="receiveForm.note" dense outlined type="textarea" autogrow label="Примечание" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Отмена" v-close-popup />
          <q-btn color="primary" label="Принять" :loading="store.saving" @click="submitBatch" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>
