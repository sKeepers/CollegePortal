<script setup>
/**
 * Справки студентам и реестр выданных.
 *
 * Кнопки «удалить» здесь нет намеренно: реестр, из которого можно убрать
 * строку, реестром не является. Номер уже на бумаге у студента, и пропуск в
 * нумерации виден сразу, а спрятанная строка нет.
 */
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { useStudentCertificatesStore } from '../../stores/studentCertificates'
import { useSettingsStore } from '../../stores/settings'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import { escapeHtml, printHtmlDocument, printPage } from '../../utils/print'
import { buildCertificateSheet } from '../../utils/certificateSheet'

const store = useStudentCertificatesStore()
const settings = useSettingsStore()
const $q = useQuasar()

const issueOpen = ref(false)
const issueForm = ref({ student_id: null, copies: 2 })
const selected = ref([])

const columns = [
  { name: 'number', label: '№ справки', field: 'number', align: 'left', sortable: true },
  { name: 'full_name', label: 'Фамилия, имя, отчество', field: 'full_name', align: 'left' },
  { name: 'course', label: 'Курс', field: 'course', align: 'left' },
  { name: 'specialty', label: 'Специальность', field: 'specialty', align: 'left' },
  { name: 'issued_on', label: 'Дата выдачи', field: 'issued_on', align: 'left' },
  { name: 'source', label: 'Откуда', field: 'source', align: 'left' },
  { name: 'received_on', label: 'Получена', field: 'received_on', align: 'left' },
]

const total = computed(() => store.certificates.length)

function ru(date) {
  if (!date) return '—'
  const [year, month, day] = String(date).slice(0, 10).split('-')
  return `${day}.${month}.${year}`
}

onMounted(async () => {
  await Promise.all([store.load(), settings.loadPublic()])
})

async function submitIssue() {
  if (!issueForm.value.student_id) return

  try {
    const issued = await store.issue(issueForm.value.student_id, Number(issueForm.value.copies) || 2)
    issueOpen.value = false
    $q.notify({
      type: 'positive',
      message: `Выдано справок: ${issued.length}, номера ${issued.map((row) => row.number).join(', ')}`,
    })
    // Печатаем сразу то, что выдали: номер уже занят, и лист к нему обязан
    // появиться, а не ждать, пока оператор найдёт строки в реестре.
    printCertificates(issued)
  } catch {
    $q.notify({ type: 'negative', message: store.error || 'Не удалось выдать справку' })
  }
}

function askReceived(row) {
  $q.dialog({
    title: 'Отметить получение',
    message: `Справка № ${row.number}. Дата получения в виде ГГГГ-ММ-ДД, пустая строка снимает отметку.`,
    prompt: { model: row.received_on || '', type: 'date' },
    cancel: true,
  }).onOk((value) => store.markReceived(row, value))
}

/** Печать самих бланков — отдельным документом, две справки на лист. */
function printCertificates(rows) {
  const list = rows && rows.length ? rows : selected.value

  if (!list.length) {
    $q.notify({ type: 'warning', message: 'Выберите справки в реестре или выдайте новую.' })
    return
  }

  printHtmlDocument(buildCertificateSheet(list, letterhead.value))
}

/** Печать реестра — та же таблица, что колледж ведёт на бумаге. */
function printRegistry() {
  const rows = store.certificates.map((row, index) => `<tr>
        <td>${index + 1}</td>
        <td>${escapeHtml(row.number)}</td>
        <td>${escapeHtml(row.full_name)}</td>
        <td>${ru(row.birth_date)}</td>
        <td>${escapeHtml(row.specialty || '')}</td>
        <td>${escapeHtml(row.enrollment_order_number || '')}</td>
        <td>${ru(row.enrollment_order_date)}</td>
        <td>${ru(row.issued_on)}</td>
        <td>${row.received_on ? ru(row.received_on) : ''}</td>
        <td class="sign"></td>
      </tr>`).join('')

  printHtmlDocument(printPage({
    title: 'Реестр справок для студентов',
    subtitle: store.filters.year ? `Выдано в ${store.filters.year} году` : 'Все годы выдачи',
    body: `<table>
<thead>
<tr><th>№</th><th>№ справки</th><th>Фамилия, имя, отчество</th><th>Дата рождения</th><th>Специальность</th><th>Приказ</th><th>Дата приказа</th><th>Дата выдачи</th><th>Дата получения</th><th class="sign">Подпись</th></tr>
</thead>
<tbody>${rows}
</tbody>
</table>`,
    footer: `Всего записей: ${total.value}. Напечатано ${new Date().toLocaleString('ru-RU')}.`,
  }))
}

/**
 * Шапка бланка. Название, адрес и контакты берутся из настроек колледжа —
 * второго места для них заводить нельзя, они уже есть. Учредитель, реквизиты и
 * фамилия директора лежат в настройках справок: директор меняется, и его имя
 * в коде однажды станет неправдой.
 */
const letterhead = computed(() => ({
  founder: settings.publicValue('certificates', 'founder', 'Министерство культуры Ставропольского края'),
  fullName: settings.publicValue('general', 'college_full_name', ''),
  shortName: settings.publicValue('certificates', 'short_name_line', ''),
  contacts: [
    settings.publicValue('general', 'college_address', ''),
    settings.publicValue('general', 'college_phone', ''),
    settings.publicValue('general', 'college_email', ''),
  ].filter(Boolean).join('  '),
  requisites: settings.publicValue('certificates', 'requisites', ''),
  genitiveName: settings.publicValue('certificates', 'name_genitive', ''),
  director: settings.publicValue('certificates', 'director_name', ''),
}))
</script>

<template>
  <q-page padding>
    <PageHeader
      title="Справки студентам"
      subtitle="Выдача справок, реестр выданных и печать. Ничего из этого не удаляется.">
      <template #actions>
        <q-btn color="primary" icon="add" label="Выдать справку" @click="issueOpen = true" />
      </template>
    </PageHeader>

    <!--
      Ошибка показывается раньше пустоты и вместо неё.

      29.08.2026 владелец увидел на этом экране «Выдано справок: 0» и «Справок
      пока не выдавали» — а таблицы в базе не было вовсе, миграция не была
      накатана. Экран не мог работать и сказал об этом **словами о колледже**;
      владелец прочитал подпись как факт и написал, что журнал нужен завести.

      Пустое состояние обязано отличать «данных нет» от «спросить не удалось».
    -->
    <q-banner v-if="store.error" class="bg-red-1 text-red-9 q-mb-md" rounded>{{ store.error }}</q-banner>

    <q-card flat bordered class="q-mb-sm">
      <q-card-section class="row items-center q-gutter-sm">
        <!--
          Поиск по номеру стоит первым: ради него владелец и просил реестр —
          «найти по номеру, кому и когда выдавалась эта справка». Номер
          единственен, поэтому он отменяет остальные отборы.
        -->
        <q-input
          :model-value="store.filters.number" dense outlined clearable type="number"
          style="min-width: 160px" label="Номер справки"
          @update:model-value="(value) => store.setFilters({ number: value || null })" />
        <q-select
          :model-value="store.filters.year" dense outlined clearable emit-value map-options
          style="min-width: 160px" label="Год выдачи" :options="store.yearOptions"
          @update:model-value="(value) => store.setFilters({ year: value })" />
        <q-select
          :model-value="store.filters.group_id" dense outlined clearable emit-value map-options
          style="min-width: 260px" label="Группа" :options="store.groupOptions"
          @update:model-value="(value) => store.setFilters({ group_id: value })" />
        <q-space />
        <q-btn flat icon="refresh" label="Обновить" @click="store.load" />
        <q-btn
          flat icon="print" label="Печать выбранных"
          :disable="!selected.length" @click="printCertificates(null)" />
        <q-btn
          color="primary" icon="print" label="Печать реестра"
          :disable="!total" @click="printRegistry" />
      </q-card-section>
    </q-card>

    <q-card flat bordered>
      <q-card-section>
        <!--
          Число называется только когда его посчитали. При неполученном ответе
          «Выдано справок: 0» — то же утверждение о колледже, что и пустая
          таблица: владелец видел именно эту строку и поверил ей.
        -->
        <div class="text-subtitle2 q-mb-sm">
          Выдано справок: {{ store.error ? 'неизвестно, ответ не получен' : total }}
        </div>

        <q-table
          v-model:selected="selected"
          flat dense
          row-key="id"
          selection="multiple"
          :rows="store.certificates"
          :columns="columns"
          :loading="store.loading"
          :rows-per-page-options="[0]">
          <template #body-cell-issued_on="props">
            <q-td :props="props">{{ ru(props.row.issued_on) }}</q-td>
          </template>
          <!--
            Откуда строка. У перенесённой с бумаги нет ни даты выдачи, ни курса,
            ни сроков обучения — в книге колледжа их не было вовсе. Показывать
            её как выданную порталом значило бы обещать документ, который портал
            воспроизвести не может.
          -->
          <template #body-cell-source="props">
            <q-td :props="props">
              <span v-if="props.row.source === 'paper'" class="text-caption text-grey-7">бумажный реестр</span>
              <span v-else class="text-caption">портал</span>
            </q-td>
          </template>
          <template #body-cell-received_on="props">
            <q-td :props="props">
              <q-btn flat dense size="sm" :label="props.row.received_on ? ru(props.row.received_on) : 'отметить'"
                @click="askReceived(props.row)" />
            </q-td>
          </template>
          <template #no-data>
            <AppEmptyState
              v-if="!store.error"
              title="Справок пока не выдавали"
              description="Нумерация продолжает бумажный реестр колледжа." />
            <AppEmptyState
              v-else
              title="Реестр прочитать не удалось"
              description="Это не значит, что справок нет: ответ не получен. Сообщение об ошибке — выше." />
          </template>
        </q-table>
      </q-card-section>
    </q-card>

    <q-dialog v-model="issueOpen">
      <q-card style="min-width: 460px">
        <q-card-section class="text-subtitle1">Выдать справку</q-card-section>
        <q-card-section class="q-gutter-sm">
          <q-select
            v-model="issueForm.student_id" dense outlined emit-value map-options use-input
            label="Студент" :options="store.studentOptions"
            hint="Курс, приказ и специальность берутся из карточки студента" />
          <q-input
            v-model.number="issueForm.copies" dense outlined type="number" min="1" max="5"
            label="Сколько справок"
            hint="Обычно две: у каждой свой номер, оба расходуются" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Отмена" v-close-popup />
          <q-btn color="primary" label="Выдать и печатать" :loading="store.saving" @click="submitIssue" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>
