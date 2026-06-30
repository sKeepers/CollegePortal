<script setup>
defineProps({
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
    binary-state-sort
  >
    <template v-for="(_, name) in $slots" #[name]="slotProps">
      <slot :name="name" v-bind="slotProps || {}" />
    </template>
  </q-table>
</template>
