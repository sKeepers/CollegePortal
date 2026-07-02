<script setup>
import { computed } from 'vue'

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
})

const emit = defineEmits(['update:pagination'])

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
  >
    <template v-for="(_, name) in $slots" #[name]="slotProps">
      <slot :name="name" v-bind="slotProps || {}" />
    </template>
  </q-table>
</template>
