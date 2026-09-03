<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute } from 'vue-router'
import { CreditCard, Download, Plus, Printer, RefreshCw, Search, Upload, X } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { escapeHtml, printHtmlDocument, printPage } from '../../utils/print'
import { findGroupOption } from '../../utils/groupOptions'
import { useRfidCardsStore } from '../../stores/rfidCards'
import { usePermissions } from '../../composables/usePermissions'

/**
 * Кабинет коменданта и отдела кадров: RFID-карты.
 *
 * Работа идёт от двух вещей, и обе на виду сразу: поле считывателя и поиск по
 * фамилии. Пришёл за картой — нашли человека, поднесли карту, записали. Пришёл
 * сдать — поднесли карту, портал сам открыл владельца. Потерял — отметили и
 * привязали новую.
 *
 * Отдельного шага «сначала завести карту» на главном пути нет: номер приходит
 * со считывателя, и незнакомая карта заводится сама. Заведение партии осталось
 * отдельной кнопкой в реестре — это про коробку новых карт, а не про выдачу.
 */
const store = useRfidCardsStore()
const route = useRoute()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('rfid.cards.manage'))
const $q = useQuasar()

const tab = ref('desk')
const scanRef = ref(null)
const scan = ref('')
const searchQuery = ref('')
const pendingUid = ref('')
const waitingForCard = ref(false)
const createVisible = ref(false)
const createForm = reactive({ uid: '', label: '', note: '' })
const releaseVisible = ref(false)
const releaseForm = reactive({ card: null, reason: 'left', note: '' })
const printedAt = ref('')
const importFileRef = ref(null)
const importVisible = ref(false)
const importJob = ref(null)

const columns = [
  { name: 'uid', label: 'Номер карты', field: 'uid', align: 'left', sortable: true },
  { name: 'label', label: 'Подпись', field: 'label', align: 'left' },
  { name: 'status', label: 'Состояние', field: 'status', align: 'left', sortable: true },
  { name: 'person', label: 'У кого', field: 'person', align: 'left' },
  { name: 'issued_at', label: 'Выдана', field: 'issued_at', align: 'left', sortable: true },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const tone = (status) => ({
  issued: 'success',
  stock: 'neutral',
  lost: 'danger',
  blocked: 'warning',
  written_off: 'neutral',
}[status] || 'neutral')

const openOptions = [
  { value: null, label: 'Все' },
  { value: true, label: 'Только на руках' },
  { value: false, label: 'Только закрытые' },
]

/**
 * Заголовок печатной формы: ведомость на группу или журнал за период.
 *
 * Читается **применённый** отбор, а не то, что сейчас в полях. Живой отбор
 * подписывал бы строки, отобранные по другому: выбрал группу, «Показать» не
 * нажал, нажал «Печать» — и лист выходил ведомостью на группу со всеми
 * картами колледжа. Замер 29.08.2026: 245 строк под заголовком одной группы.
 */
const printTitle = computed(() => {
  const group = findGroupOption(store.groupOptions, store.journalApplied?.group_id ?? null)

  // Шапка печатной ведомости: здесь нужно полное имя группы, а не строка списка —
  // «2 курс» на бумаге не говорит ни о чём.
  //
  // Отбора нет — нет и хвоста: без группы это журнал за период, а не ведомость на
  // группу, и подписывать его как ведомость нельзя. Всё, чем этот поиск отличается от
  // обычного `find`, живёт в `findGroupOption` и объяснено там.
  return group ? `Ведомость выдачи RFID-карт — ${group.fullLabel || group.label}` : 'Журнал выдачи RFID-карт'
})

const printPeriod = computed(() => {
  // Тот же снимок, что и у заголовка: период на листе обязан описывать строки
  // на листе, а не поле ввода.
  const { from = '', to = '' } = store.journalApplied ?? {}
  if (!from && !to) return 'за всё время'

  return `за период ${from ? formatDate(from) : '…'} — ${to ? formatDate(to) : '…'}`
})

function formatDate(value) {
  if (!value) return ''
  const date = new Date(value)

  return Number.isNaN(date.valueOf()) ? String(value) : date.toLocaleDateString('ru-RU')
}

function formatDateTime(value) {
  if (!value) return '—'
  const date = new Date(value)

  return Number.isNaN(date.valueOf())
    ? String(value)
    : date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function notify(message, type = 'positive') {
  $q.notify({ type, message, position: 'top-right', timeout: 2500 })
}

function focusScan() {
  nextTick(() => scanRef.value?.focus?.())
}

/**
 * Считыватель работает как клавиатура: набирает номер и жмёт Enter.
 *
 * Что происходит дальше, зависит от номера: знакомая карта открывает своего
 * владельца, незнакомая ждёт, кому её выдать.
 */
async function handleScan() {
  const value = scan.value.trim()
  if (!value) return

  scan.value = ''
  waitingForCard.value = false
  const result = await store.lookup(value)
  focusScan()

  if (!result) return

  if (result.found && result.person) {
    pendingUid.value = ''
    notify(`Карта ${result.card.uid} — ${result.person.full_name}`)
    return
  }

  // Свободная или незнакомая карта: её сейчас кому-то выдадут.
  pendingUid.value = result.uid

  if (!store.person) {
    notify('Карта свободна. Найдите человека, которому её выдать.', 'info')
  }
}

async function bindPending() {
  if (!store.person || !pendingUid.value) return

  const uid = pendingUid.value
  if (await store.bind(store.person.id, uid)) {
    pendingUid.value = ''

    // Вторая карта разрешена — но сказать о первой обязаны. Иначе оператор не
    // узнает, что у человека **две живые карты**, и старая продолжает открывать
    // турникет. Замер 01.09.2026: до этой правки экран говорил только «выдана».
    const stillOut = store.cardsStillOut
    if (stillOut.length) {
      notify(
        `Карта ${uid} выдана. На руках остаётся ещё ${stillOut.length === 1 ? 'карта' : 'карт'}: `
        + `${stillOut.join(', ')} — примите её, если она больше не нужна.`,
        'warning',
      )
    } else {
      notify(`Карта ${uid} выдана`)
    }
  }
  focusScan()
}

function awaitCard() {
  waitingForCard.value = true
  focusScan()
}

async function onSearch(value) {
  searchQuery.value = value || ''
  await store.searchPeople(searchQuery.value)
}

function choosePerson(found) {
  store.selectPerson(found)
  searchQuery.value = ''
  focusScan()
}

async function acceptCurrent() {
  const card = store.person?.card
  if (!card) return

  if (await store.accept(card)) notify(`Карта ${card.uid} принята`)
  focusScan()
}

async function markLost() {
  const card = store.person?.card
  if (!card) return

  if (await store.changeStatus(card, 'lost')) {
    notify(`Карта ${card.uid} отмечена утерянной`, 'warning')
    awaitCard()
  }
}

async function changeStatus(card, status) {
  if (await store.changeStatus(card, status)) notify(`Карта ${card.uid}: ${store.statusLabels[status]}`)
}

async function accept(card) {
  if (await store.accept(card)) notify(`Карта ${card.uid} принята`)
}

/**
 * Отвязка — это не приём: карту не принесли.
 *
 * Человек уволился или отчислился, карта осталась у него. Причину спрашиваем,
 * потому что именно она попадёт в журнал.
 */
function openRelease(card) {
  releaseForm.card = card
  releaseForm.reason = 'left'
  releaseForm.note = ''
  releaseVisible.value = true
}

async function submitRelease() {
  const card = releaseForm.card
  if (!card) return

  if (await store.release(card, releaseForm.reason, releaseForm.note)) {
    releaseVisible.value = false
    notify(`Карта ${card.uid} отвязана`)
  }
  focusScan()
}

async function removeCard(card) {
  $q.dialog({
    title: 'Удалить карту',
    message: `Карта ${card.uid} будет удалена насовсем, вместе с её строками в журнале выдач. Так убирают запись, заведённую по ошибке — например, с опечаткой в номере. Настоящую карту, вышедшую из оборота, лучше списать: она останется в реестре.`,
    cancel: { flat: true, noCaps: true, label: 'Отмена' },
    ok: { color: 'negative', unelevated: true, noCaps: true, label: 'Удалить' },
    persistent: true,
  }).onOk(async () => {
    if (await store.remove(card)) notify(`Карта ${card.uid} удалена`)
  })
}

function openCreate() {
  createForm.uid = ''
  createForm.label = ''
  createForm.note = ''
  createVisible.value = true
}

async function submitCreate() {
  if (!createForm.uid.trim()) return
  if (await store.create({ uid: createForm.uid.trim(), label: createForm.label || null, note: createForm.note || null })) {
    createVisible.value = false
    notify('Карта заведена')
  }
}

async function openJournal() {
  await store.loadGroups()
  await store.loadJournal()
}

/**
 * Загрузка журнала из файла: сначала показываем, что получится, и только
 * потом пишем. Тот же порядок, что в разделе «Импорт данных».
 */
function pickJournalFile() {
  importFileRef.value?.click?.()
}

async function onJournalFile(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return

  importJob.value = await store.previewJournalImport(file)
  if (importJob.value) importVisible.value = true
}

async function confirmJournalImport() {
  const job = importJob.value
  if (!job) return

  const done = await store.confirmJournalImport(job.id)
  if (!done) return

  importVisible.value = false
  const summary = done.summary || {}
  notify(`Загружено строк: ${summary.created || 0}, пропущено: ${summary.skipped || 0}, с ошибками: ${summary.failed || 0}`)
}

async function exportJournal() {
  const blob = await store.exportJournalFile()
  if (!blob) return

  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `Журнал выдачи RFID-карт ${new Date().toISOString().slice(0, 10)}.xlsx`
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
  notify('Журнал выгружен в Excel')
}

/**
 * Ведомость выдачи на бумагу.
 *
 * Колонка «Возвращена» здесь не для полноты. Лист перечисляет **события
 * выдачи**, а не то, что на руках сейчас: карту выдали, приняли и выдали
 * снова — и человек стоит в ведомости дважды с одним и тем же номером. Пока
 * возврат не показан, это читается как «у него две карты», а по такому листу
 * сверяют фактическое наличие, и разбираться потом будут с человеком.
 *
 * Замечено 24.08.2026, когда ведомость впервые посмотрели глазами — до этого
 * форма проверялась запросами, и данные в ней сходились.
 */
function printJournal() {
  if (!store.journal.length) {
    notify('Печатать нечего: в журнале нет записей за выбранный период.', 'warning')
    return
  }

  printedAt.value = new Date().toLocaleString('ru-RU')

  const rows = store.journal.map((row, index) => `
      <tr>
        <td>${index + 1}</td>
        <td>${escapeHtml(formatDateTime(row.issued_at))}</td>
        <td>${escapeHtml(row.person?.full_name || '—')}</td>
        <td>${escapeHtml(row.person?.unit || '—')}</td>
        <td>${escapeHtml(row.card?.uid || '')}</td>
        <td>${escapeHtml(row.issued_by || '—')}</td>
        <td>${row.returned_at ? escapeHtml(formatDateTime(row.returned_at)) : 'на руках'}</td>
        <td class="sign"></td>
      </tr>`).join('')

  printHtmlDocument(printPage({
    title: printTitle.value,
    subtitle: printPeriod.value,
    body: `<table>
<thead>
<tr><th>№</th><th>Выдана</th><th>Фамилия, имя, отчество</th><th>Группа / подразделение</th><th>Номер карты</th><th>Выдал</th><th>Возвращена</th><th class="sign">Подпись</th></tr>
</thead>
<tbody>${rows}
</tbody>
</table>`,
    // Число честное ровно потому, что журнал читается целиком (`listAll` в
    // хранилище). Вернётся страница на 500 — вернётся и подпись, называющая
    // размер страницы журналом.
    footer: `Всего записей: ${store.journal.length}. Напечатано ${printedAt.value}.`,
  }))
}

/**
 * Страховка от потерянного фокуса.
 *
 * Считыватель работает как клавиатура и «печатает» туда, где стоит курсор.
 * Комендант щёлкнул мышью мимо поля — и номер уходит в никуда, а выглядит это
 * как «считыватель не работает». Поэтому цифры ловим на уровне страницы, но
 * только когда курсор **не** в поле ввода: иначе перебили бы набор фамилии и
 * ввод в окнах.
 */
let wedgeBuffer = ''

function isTypingTarget(target) {
  if (!target) return false

  return target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable === true
}

function onGlobalKey(event) {
  if (isTypingTarget(event.target) || event.ctrlKey || event.altKey || event.metaKey) return

  if (event.key === 'Enter') {
    if (!wedgeBuffer) return

    scan.value = wedgeBuffer
    wedgeBuffer = ''
    handleScan()

    return
  }

  if (event.key.length === 1 && /[0-9A-Za-z]/.test(event.key)) wedgeBuffer += event.key
}

/**
 * Адрес может назвать карту: `/identity/rfid-cards?card=0005082083`.
 *
 * Из карточки человека приходят именно так. Раньше ссылка вела в общий список
 * из 244 строк, и владелец искал свою карту заново — кнопка называла карту, а
 * приводила к чужим. Теперь отбор уже стоит, и открыт реестр, а не выдача.
 */
const routeCard = String(route.query.card || '').trim()

if (routeCard) {
  store.filters.search = routeCard
  tab.value = 'registry'
}

onMounted(async () => {
  document.addEventListener('keydown', onGlobalKey)
  await store.load()
  focusScan()
})

onBeforeUnmount(() => document.removeEventListener('keydown', onGlobalKey))
</script>

<template>
  <AppPage>
    <PageHeader title="RFID-карты" subtitle="Выдача, приём и журнал">
      <template #icon><CreditCard :size="22" /></template>
    </PageHeader>

    <AppErrorBanner v-if="store.error" :message="store.error" />

    <q-tabs v-model="tab" dense no-caps align="left" class="rfid-tabs">
      <q-tab name="desk" label="Выдача" />
      <q-tab name="registry" label="Реестр карт" />
      <q-tab name="journal" label="Журнал" @click="openJournal" />
    </q-tabs>

    <!--
      Без `animated`: переход между вкладками фиксирует высоту панели, и если
      содержимое выросло после него, панель остаётся прежней. Замер 29.08.2026:
      панель 275 px при содержимом 287 — и при окне 900, и при 1400, то есть от
      размера окна это не зависело вовсе. Владелец видел блок «Найти человека»
      обрезанным по полю «Фамилия», со своей полосой прокрутки и пустым местом
      под ней.
    -->
    <q-tab-panels v-model="tab" class="rfid-panels">
      <!-- Рабочее место: считыватель слева, человек справа. -->
      <q-tab-panel name="desk" class="q-pa-none">
        <div class="rfid-desk">
          <div class="rfid-desk__column">
            <div class="rfid-block" :class="{ 'rfid-block--waiting': waitingForCard }">
              <div class="rfid-block__title">Поднесите карту к считывателю</div>
              <q-input
                ref="scanRef"
                v-model="scan"
                outlined
                dense
                autofocus
                :disable="store.saving"
                placeholder="Ожидание карты…"
                input-class="rfid-scan-input"
                @keyup.enter="handleScan"
              />
              <div class="rfid-block__hint">
                Считыватель работает как клавиатура: номер набирается сам и заканчивается Enter. Номер можно ввести и руками.
              </div>
            </div>

            <div class="rfid-block">
              <div class="rfid-block__title">Найти человека</div>
              <q-input
                :model-value="searchQuery"
                outlined
                dense
                clearable
                debounce="350"
                placeholder="Фамилия"
                :loading="store.searching"
                @update:model-value="onSearch"
              >
                <template #prepend><Search :size="16" /></template>
              </q-input>

              <q-list v-if="store.foundPeople.length" bordered separator class="rfid-found">
                <q-item
                  v-for="found in store.foundPeople"
                  :key="found.id"
                  clickable
                  @click="choosePerson(found)"
                >
                  <q-item-section>
                    <q-item-label>{{ found.full_name }}</q-item-label>
                    <q-item-label caption>{{ found.kind }}<span v-if="found.unit"> · {{ found.unit }}</span></q-item-label>
                  </q-item-section>
                  <q-item-section side>
                    <AppStatusBadge
                      v-if="found.card"
                      :label="found.card.status_label"
                      :tone="tone(found.card.status)"
                    />
                    <span v-else class="rfid-found__none">без карты</span>
                  </q-item-section>
                </q-item>
              </q-list>
            </div>
          </div>

          <div class="rfid-desk__column">
            <div v-if="store.person" class="rfid-block rfid-person">
              <div class="rfid-person__head">
                <div>
                  <div class="rfid-person__name">{{ store.person.full_name }}</div>
                  <div class="rfid-person__unit">
                    {{ store.person.kind }}<span v-if="store.person.unit"> · {{ store.person.unit }}</span>
                  </div>
                </div>
                <q-btn flat dense round :disable="store.saving" @click="store.clearPerson()">
                  <X :size="16" />
                </q-btn>
              </div>

              <div v-if="store.person.card" class="rfid-person__card">
                <div class="rfid-person__card-uid">{{ store.person.card.uid }}</div>
                <AppStatusBadge :label="store.person.card.status_label" :tone="tone(store.person.card.status)" />
                <div class="rfid-person__card-date">выдана {{ formatDate(store.person.card.issued_at) }}</div>
              </div>
              <div v-else class="rfid-person__empty">Карты на руках нет</div>

              <div v-if="canManage" class="rfid-person__actions">
                <template v-if="pendingUid">
                  <q-btn color="primary" unelevated no-caps :loading="store.saving" @click="bindPending">
                    Записать карту {{ pendingUid }}
                  </q-btn>
                  <q-btn flat no-caps :disable="store.saving" @click="pendingUid = ''">Отмена</q-btn>
                </template>
                <template v-else>
                  <q-btn
                    v-if="!store.person.card"
                    color="primary"
                    unelevated
                    no-caps
                    :disable="store.saving"
                    @click="awaitCard"
                  >
                    Привязать карту
                  </q-btn>
                  <!--
                    Подписи отвечают на вопрос «что случилось с картой», а не
                    «что сделает система»: комендант думает о человеке перед
                    собой, а не о состояниях в базе.
                  -->
                  <q-btn v-if="store.person.card" flat no-caps color="primary" :disable="store.saving" @click="acceptCurrent">
                    Принять — карту вернули
                    <q-tooltip>Человек принёс карту. Она вернётся на склад, и её можно будет выдать другому.</q-tooltip>
                  </q-btn>
                  <q-btn v-if="store.person.card" flat no-caps color="negative" :disable="store.saving" @click="markLost">
                    Утеряна — выдать новую
                    <q-tooltip>Карта потеряна: проход по ней закроется, а человек освободится под новую. Экран сразу будет ждать новую карту.</q-tooltip>
                  </q-btn>
                  <q-btn v-if="store.person.card" flat no-caps :disable="store.saving" @click="openRelease(store.person.card)">
                    Отвязать — карта не вернулась
                    <q-tooltip>Карта осталась у человека: уволился, отчислился. Числиться за ним перестанет, причина запишется в журнал.</q-tooltip>
                  </q-btn>
                </template>
              </div>

              <div v-if="waitingForCard && !pendingUid" class="rfid-person__waiting">
                Поднесите новую карту к считывателю.
              </div>
            </div>

            <div v-else-if="pendingUid" class="rfid-block rfid-pending">
              <div class="rfid-person__card-uid">{{ pendingUid }}</div>
              <div class="rfid-block__hint">Карта свободна. Найдите человека слева, чтобы её выдать.</div>
            </div>

            <AppEmptyState
              v-else
              title="Никто не выбран"
              description="Поднесите карту к считывателю или найдите человека по фамилии."
            />
          </div>
        </div>
      </q-tab-panel>

      <!-- Реестр: что вообще есть и в каком состоянии. -->
      <q-tab-panel name="registry" class="q-pa-none">
        <AppToolbar>
          <q-input
            v-model="store.filters.search"
            dense
            outlined
            clearable
            debounce="400"
            label="Номер карты или фамилия"
            style="min-width: 260px"
            @update:model-value="store.load"
          />
          <q-select
            v-model="store.filters.status"
            dense
            outlined
            clearable
            emit-value
            map-options
            label="Состояние"
            style="min-width: 200px"
            :options="store.statusOptions"
            @update:model-value="store.load"
          />
          <q-space />
          <q-btn flat :disable="store.loading" @click="store.load">
            <RefreshCw :size="16" class="q-mr-xs" /> Обновить
          </q-btn>
          <q-btn v-if="canManage" color="primary" unelevated no-caps @click="openCreate">
            <Plus :size="16" class="q-mr-xs" /> Добавить карту
          </q-btn>
        </AppToolbar>

        <div class="rfid-counters">
          <div v-for="option in store.statusOptions" :key="option.value" class="rfid-counter">
            <span class="rfid-counter__value">{{ store.counts[option.value] || 0 }}</span>
            <span class="rfid-counter__label">{{ option.label }}</span>
          </div>
        </div>

        <AppLoading v-if="store.loading" />

        <AppEmptyState
          v-else-if="!store.cardsLoaded"
          title="Реестр не получен"
          description="Портал не ответил на запрос. Сколько карт заведено, экран сейчас не знает."
        />

        <AppEmptyState
          v-else-if="!store.cards.length"
          title="Карт нет"
          description="Карта заводится сама, когда её впервые подносят к считывателю на вкладке «Выдача»."
        />

        <AppTable v-else :rows="store.cards" :columns="columns" row-key="id" :pagination="{ rowsPerPage: 25 }">
          <template #body-cell-status="props">
            <q-td :props="props">
              <AppStatusBadge :label="props.row.status_label" :tone="tone(props.row.status)" />
            </q-td>
          </template>
          <template #body-cell-person="props">
            <q-td :props="props">{{ props.row.person?.full_name || '—' }}</q-td>
          </template>
          <template #body-cell-issued_at="props">
            <q-td :props="props">{{ formatDateTime(props.row.issued_at) }}</q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props" class="rfid-actions">
              <template v-if="canManage">
                <q-btn v-if="props.row.status === 'issued'" flat dense no-caps color="primary" :disable="store.saving" @click="accept(props.row)">
                  Принять
                  <q-tooltip>Карту вернули: она уходит на склад и её можно выдать другому.</q-tooltip>
                </q-btn>
                <q-btn v-if="props.row.person_id" flat dense no-caps :disable="store.saving" @click="openRelease(props.row)">
                  Отвязать
                  <q-tooltip>Карта не вернулась — человек выбыл. Перестанет за ним числиться, причина уйдёт в журнал.</q-tooltip>
                </q-btn>
                <q-btn flat dense no-caps color="negative" :disable="store.saving" @click="changeStatus(props.row, 'lost')">Утеряна</q-btn>
                <q-btn flat dense no-caps :disable="store.saving" @click="changeStatus(props.row, 'blocked')">Заблокировать</q-btn>
                <q-btn v-if="props.row.status !== 'stock'" flat dense no-caps :disable="store.saving" @click="changeStatus(props.row, 'stock')">В оборот</q-btn>
                <q-btn v-if="props.row.status !== 'written_off'" flat dense no-caps :disable="store.saving" @click="changeStatus(props.row, 'written_off')">Списать</q-btn>
                <q-btn v-if="props.row.can_delete" flat dense no-caps color="negative" :disable="store.saving" @click="removeCard(props.row)">Удалить</q-btn>
              </template>
            </q-td>
          </template>
        </AppTable>
      </q-tab-panel>

      <!-- Журнал: что происходило. Он же печатная форма. -->
      <q-tab-panel name="journal" class="q-pa-none">
        <AppToolbar>
          <q-input v-model="store.journalFilters.from" dense outlined type="date" label="С" style="min-width: 150px" />
          <q-input v-model="store.journalFilters.to" dense outlined type="date" label="По" style="min-width: 150px" />
          <q-select
            v-model="store.journalFilters.group_id"
            dense
            outlined
            clearable
            emit-value
            map-options
            label="Группа"
            style="min-width: 200px"
            :options="store.groupOptions"
          />
          <q-select
            v-model="store.journalFilters.open"
            dense
            outlined
            emit-value
            map-options
            label="Состояние"
            style="min-width: 170px"
            :options="openOptions"
          />
          <q-space />
          <q-btn flat no-caps :disable="store.loading" @click="store.loadJournal">
            <RefreshCw :size="16" class="q-mr-xs" /> Показать
          </q-btn>
          <q-btn flat no-caps :disable="!store.journal.length || store.saving" @click="exportJournal">
            <Download :size="16" class="q-mr-xs" /> Выгрузить в Excel
          </q-btn>
          <q-btn v-if="canManage" flat no-caps :disable="store.saving" @click="pickJournalFile">
            <Upload :size="16" class="q-mr-xs" /> Загрузить журнал из Excel
          </q-btn>
          <input
            ref="importFileRef"
            type="file"
            accept=".xlsx,.csv,.txt"
            class="rfid-file-input"
            @change="onJournalFile"
          >
          <q-btn color="primary" unelevated no-caps :disable="!store.journal.length" @click="printJournal">
            <Printer :size="16" class="q-mr-xs" /> Печать
          </q-btn>
        </AppToolbar>

        <AppLoading v-if="store.loading" />

        <AppEmptyState
          v-else-if="!store.journalLoaded"
          title="Журнал не получен"
          description="Портал не ответил на запрос. Это не значит, что за период ничего не выдавали."
        />

        <AppEmptyState
          v-else-if="!store.journal.length"
          title="Записей нет"
          description="Выберите период и нажмите «Показать». Журнал заполняется сам при выдаче и приёме карт."
        />

        <div v-else class="rfid-journal">
          <table class="rfid-journal__table">
            <thead>
              <tr>
                <th>№</th>
                <th>Выдана</th>
                <th>Фамилия, имя, отчество</th>
                <th>Группа / подразделение</th>
                <th>Номер карты</th>
                <th>Выдал</th>
                <th>Закрыта</th>
                <th>Причина</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in store.journal" :key="row.id">
                <td>{{ index + 1 }}</td>
                <td>{{ formatDateTime(row.issued_at) }}</td>
                <td>{{ row.person?.full_name || '—' }}</td>
                <td>{{ row.person?.unit || '—' }}</td>
                <td>{{ row.card?.uid }}</td>
                <td>{{ row.issued_by || '—' }}</td>
                <td>{{ row.is_open ? 'на руках' : formatDateTime(row.returned_at) }}</td>
                <td>{{ row.close_reason_label || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </q-tab-panel>
    </q-tab-panels>


    <q-dialog v-model="createVisible">
      <q-card class="rfid-dialog">
        <q-card-section class="text-h6">Добавить карту</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <div class="rfid-block__hint">
            Обычно карта заводится сама, когда её подносят к считывателю на вкладке «Выдача».
            Здесь — если номер приходится вводить руками: считывателя нет под рукой или карту вносят по списку.
          </div>
          <q-input v-model="createForm.uid" dense outlined autofocus label="Номер карты" hint="Десять цифр, как их отдаёт считыватель" />
          <q-input v-model="createForm.label" dense outlined label="Подпись" hint="Что написано на самой карте" />
          <q-input v-model="createForm.note" dense outlined type="textarea" autogrow label="Примечание" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps label="Добавить" :loading="store.saving" :disable="!createForm.uid.trim()" @click="submitCreate" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="importVisible">
      <q-card class="rfid-dialog">
        <q-card-section class="text-h6">Загрузка журнала</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <div class="rfid-block__hint">
            Файл разобран, но ничего ещё не записано. Проверьте, что портал понял колонки, и подтвердите.
            Строки, которые уже есть в журнале, будут пропущены.
          </div>
          <div><b>Файл:</b> {{ importJob?.original_filename || '—' }}</div>
          <div><b>Строк в файле:</b> {{ importJob?.summary?.total ?? importJob?.preview_rows?.length ?? '—' }}</div>
          <div v-if="importJob?.validation_errors?.length" class="rfid-import-errors">
            <b>Строки с ошибками — они не загрузятся:</b>
            <ul>
              <li v-for="(row, index) in importJob.validation_errors.slice(0, 10)" :key="index">
                строка {{ row.row || index + 1 }}: {{ (row.errors || []).join('; ') }}
              </li>
            </ul>
            <div v-if="importJob.validation_errors.length > 10">
              …и ещё {{ importJob.validation_errors.length - 10 }}
            </div>
          </div>
          <div v-else>Ошибок в разборе нет.</div>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps label="Записать в журнал" :loading="store.saving" @click="confirmJournalImport" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="releaseVisible">
      <q-card class="rfid-dialog">
        <q-card-section class="text-h6">Отвязать карту {{ releaseForm.card?.uid }}</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <div class="rfid-block__hint">
            Карта перестанет числиться за человеком, и её можно будет выдать другому.
            Если человек принёс карту, пользуйтесь кнопкой «Принять» — это не отвязка.
          </div>
          <q-select
            v-model="releaseForm.reason"
            dense
            outlined
            emit-value
            map-options
            label="Причина"
            :options="store.reasonOptions"
          />
          <q-input v-model="releaseForm.note" dense outlined type="textarea" autogrow label="Примечание" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps label="Отвязать" :loading="store.saving" @click="submitRelease" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </AppPage>
</template>

<style scoped>
.rfid-tabs { border-bottom: 1px solid #e2e8f0; }
/*
  Панель вкладок берёт высоту от содержимого, а не наоборот.

  Quasar меряет высоту панели один раз и ставит её родителю; выросшее позже
  содержимое в неё уже не влезает. Замер 29.08.2026: панель 275 px при
  содержимом 287 — одинаково при окне 900 и 1400, то есть с размером окна это
  не связано. Владелец видел «Найти человека» обрезанным по полю «Фамилия».
*/
.rfid-panels { background: transparent; height: auto; }
.rfid-panels :deep(.q-panel-parent) { height: auto; }
.rfid-panels :deep(.q-panel) { height: auto; overflow: visible; }
.rfid-desk { display: grid; grid-template-columns: minmax(280px, 1fr) minmax(280px, 1fr); gap: 16px; margin-top: 12px; }
.rfid-desk__column { display: grid; gap: 16px; align-content: start; }
.rfid-block { border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; background: #fff; }
.rfid-block--waiting { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12); }
.rfid-block__title { font-size: 13px; font-weight: 600; color: #0f172a; margin-bottom: 8px; }
.rfid-block__hint { font-size: 12px; color: #64748b; margin-top: 8px; }
.rfid-found { margin-top: 10px; border-radius: 10px; max-height: 320px; overflow: auto; }
.rfid-found__none { font-size: 12px; color: #94a3b8; }
.rfid-person__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.rfid-person__name { font-size: 18px; font-weight: 600; color: #0f172a; }
.rfid-person__unit { font-size: 13px; color: #64748b; margin-top: 2px; }
.rfid-person__card { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 14px; padding: 10px 12px; border-radius: 10px; background: #f8fafc; }
.rfid-person__card-uid { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 18px; font-weight: 600; color: #0f172a; }
.rfid-person__card-date { font-size: 12px; color: #64748b; }
.rfid-person__empty { margin-top: 14px; font-size: 13px; color: #64748b; }
.rfid-person__actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
.rfid-person__waiting { margin-top: 12px; font-size: 13px; color: #2563eb; }
.rfid-pending { display: grid; gap: 6px; }
.rfid-counters { display: flex; gap: 12px; flex-wrap: wrap; margin: 12px 0; }
.rfid-counter { display: grid; gap: 2px; padding: 8px 14px; border: 1px solid #e2e8f0; border-radius: 10px; min-width: 120px; }
.rfid-counter__value { font-size: 20px; font-weight: 600; color: #0f172a; }
.rfid-counter__label { font-size: 12px; color: #64748b; }
.rfid-actions { white-space: nowrap; }
.rfid-dialog { min-width: min(520px, 92vw); }
.rfid-file-input { display: none; }
.rfid-import-errors { font-size: 13px; color: #b91c1c; }
.rfid-import-errors ul { margin: 6px 0 0; padding-left: 18px; }
.rfid-journal { margin-top: 12px; overflow-x: auto; }
.rfid-journal__table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rfid-journal__table th,
.rfid-journal__table td { border: 1px solid #e2e8f0; padding: 6px 10px; text-align: left; white-space: nowrap; }
.rfid-journal__table th { background: #f8fafc; font-weight: 600; }

@media (max-width: 900px) {
  .rfid-desk { grid-template-columns: 1fr; }
}

:deep(.rfid-scan-input) { font-size: 20px; font-family: ui-monospace, "SF Mono", Menlo, monospace; letter-spacing: 1px; }

:deep(.app-toolbar) { padding: 8px 10px; }
</style>

