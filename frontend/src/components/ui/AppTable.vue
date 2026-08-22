<script setup>
import { computed } from 'vue'
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

const tablePagination = computed({
  get: () => props.pagination,
  set: (value) => emit('update:pagination', value),
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
    :rows-per-page-options="rowsPerPageOptions"
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
