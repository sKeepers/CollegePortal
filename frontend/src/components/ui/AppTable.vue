<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

const props = defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
  columns: {
    type: Array,
    default: () => [],
  },
  rowKey: {
    type: String,
    default: 'id',
  },
  loading: {
    type: Boolean,
    default: false,
  },
  pagination: {
    type: Object,
    default: null,
  },
  rowsPerPageOptions: {
    type: Array,
    default: () => [10, 20, 50, 0],
  },
  rowsPerPageLabel: {
    type: String,
    default: 'Записей на странице:',
  },
  tableRowClassFn: {
    type: Function,
    default: null,
  },

  /**
   * Адрес карточки строки: `(row) => '/students/955'`.
   *
   * Нужен ровно для одного — `Ctrl`+клик должен открывать карточку в новой
   * вкладке. Строка таблицы не ссылка, поэтому браузер сам этого не умеет, а
   * карточка с собственным адресом (решение владельца 22.08.2026) без такого
   * жеста наполовину бесполезна: адрес есть, а воспользоваться им нечем.
   */
  rowLink: {
    type: Function,
    default: null,
  },
})

const emit = defineEmits(['update:pagination', 'request', 'row-click'])

const router = useRouter()

/**
 * `Ctrl` и `Cmd` — открыть в новой вкладке; всё остальное идёт странице как
 * прежде. `Shift` намеренно не трогаем: в таблицах с выбором он выделяет
 * диапазон, и перехват сломал бы привычное поведение.
 */
function onRowClick(event, row, index) {
  const target = props.rowLink?.(row)

  if (target && (event?.ctrlKey || event?.metaKey)) {
    event.preventDefault?.()
    window.open(router.resolve(target).href, '_blank', 'noopener')

    return
  }

  emit('row-click', event, row, index)
}

const safeTableRowClassFn = computed(() => props.tableRowClassFn || (() => ''))

/**
 * Размер страницы живёт внутри таблицы, а не только в свойстве родителя.
 *
 * Раньше `tablePagination` читал `props.pagination` напрямую. Страницы, которые
 * передают литерал — `:pagination="{ rowsPerPage: 25 }"`, а таких в портале
 * два десятка, — не слушают `update:pagination`, поэтому свойство не менялось
 * никогда: человек выбирал «50», таблица отдавала событие в пустоту и тут же
 * возвращалась к 25. Владелец увидел это 29.08.2026 на реестре карт: выбор
 * «Записей на странице» не реагировал вовсе.
 *
 * Теперь состояние своё, а свойство родителя — начальное значение и способ
 * прислать новое. Слежение сравнивает **содержимое** и срабатывает, только
 * когда родитель вправду что-то изменил: литерал пересоздаётся на каждом
 * рендере, и сравнение по ссылке сбрасывало бы выбор человека каждый раз.
 */
const innerPagination = ref({ ...(props.pagination || {}) })

watch(
  () => JSON.stringify(props.pagination || {}),
  (now, before) => {
    if (now !== before && props.pagination) {
      innerPagination.value = { ...props.pagination }
    }
  },
)

const tablePagination = computed({
  get: () => innerPagination.value,
  set: (value) => {
    innerPagination.value = value
    emit('update:pagination', value)
  },
})

/**
 * Предлагаемые размеры страницы вместе с текущим.
 *
 * Если страница просит размер, которого нет в списке, Quasar подставляет его
 * первым — отсюда порядок «25, 10, 20, 50, Все», по которому владелец и решил,
 * что список сломан. Досыпаем значение в набор и сортируем; «Все» (ноль)
 * остаётся последним, потому что это не число строк, а их отсутствие предела.
 */
const offeredRowsPerPage = computed(() => {
  const current = Number(innerPagination.value?.rowsPerPage ?? 0)
  const all = new Set(props.rowsPerPageOptions.map(Number))

  if (!Number.isNaN(current)) {
    all.add(current)
  }

  return Array.from(all).sort((a, b) => {
    if (a === 0) return 1
    if (b === 0) return -1

    return a - b
  })
})
</script>

<template>
  <q-table
    flat
    bordered
    dense
    class="app-table"
    :rows="rows"
    :columns="columns"
    :row-key="rowKey"
    :loading="loading"
    :pagination="tablePagination"
    :rows-per-page-options="offeredRowsPerPage"
    :rows-per-page-label="rowsPerPageLabel"
    :table-row-class-fn="safeTableRowClassFn"
    binary-state-sort
    @update:pagination="tablePagination = $event"
    @request="emit('request', $event)"
    @row-click="onRowClick"
  >
    <template v-for="(_, name) in $slots" #[name]="slotProps">
      <slot :name="name" v-bind="slotProps || {}" />
    </template>
  </q-table>
</template>
