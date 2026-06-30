<script setup>
import { computed, reactive, watch } from 'vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  groupOptions: {
    type: Array,
    default: () => [],
  },
  statusOptions: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'apply', 'reset'])

const localFilters = reactive({
  search: '',
  group_id: '',
  status: '',
})

const activeChips = computed(() => {
  const chips = []
  const selectedGroup = props.groupOptions.find((group) => Number(group.value) === Number(localFilters.group_id))
  const selectedStatus = props.statusOptions.find((status) => status.value === localFilters.status)

  if (localFilters.search) {
    chips.push({ key: 'search', label: `Поиск: ${localFilters.search}` })
  }

  if (localFilters.group_id) {
    chips.push({ key: 'group_id', label: `Группа: ${selectedGroup?.label || localFilters.group_id}` })
  }

  if (localFilters.status) {
    chips.push({ key: 'status', label: `Статус: ${selectedStatus?.label || localFilters.status}` })
  }

  return chips
})

watch(
  () => props.modelValue,
  (value) => {
    Object.assign(localFilters, {
      search: value.search || '',
      group_id: value.group_id || '',
      status: value.status || '',
    })
  },
  { immediate: true, deep: true },
)

function applyFilters() {
  emit('update:modelValue', { ...localFilters })
  emit('apply', { ...localFilters })
}

function resetFilters() {
  Object.assign(localFilters, {
    search: '',
    group_id: '',
    status: '',
  })
  emit('update:modelValue', { ...localFilters })
  emit('reset')
}

function removeChip(key) {
  localFilters[key] = ''
  applyFilters()
}
</script>

<template>
  <AppFilterBar class="student-filters">
    <q-input
      v-model="localFilters.search"
      dense
      outlined
      clearable
      label="Поиск по ФИО"
      placeholder="Фамилия, имя или отчество"
      @keyup.enter="applyFilters"
    />
    <q-select
      v-model="localFilters.group_id"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Группа"
      :options="groupOptions"
    />
    <q-select
      v-model="localFilters.status"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Статус"
      :options="statusOptions"
    />
    <template #actions>
      <q-btn flat label="Сбросить" :disable="loading" @click="resetFilters" />
      <q-btn color="primary" label="Применить" :loading="loading" @click="applyFilters" />
    </template>

    <template v-if="activeChips.length" #footer>
      <div class="student-filters__chips">
        <q-chip
          v-for="chip in activeChips"
          :key="chip.key"
          dense
          removable
          color="primary"
          text-color="white"
          @remove="removeChip(chip.key)"
        >
          {{ chip.label }}
        </q-chip>
      </div>
    </template>
  </AppFilterBar>
</template>
