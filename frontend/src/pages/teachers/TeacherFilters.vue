<script setup>
import { computed, reactive, watch } from 'vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  statusOptions: {
    type: Array,
    default: () => [],
  },
  departmentOptions: {
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
  status: '',
  department: '',
})

const activeChips = computed(() => {
  const chips = []
  const selectedStatus = props.statusOptions.find((status) => status.value === localFilters.status)
  const selectedDepartment = props.departmentOptions.find((department) => department.value === localFilters.department)

  if (localFilters.search) {
    chips.push({ key: 'search', label: `Поиск: ${localFilters.search}` })
  }

  if (localFilters.status) {
    chips.push({ key: 'status', label: selectedStatus?.label || localFilters.status })
  }

  if (localFilters.department) {
    chips.push({ key: 'department', label: `Отделение: ${selectedDepartment?.label || localFilters.department}` })
  }

  return chips
})

watch(
  () => props.modelValue,
  (value) => {
    Object.assign(localFilters, {
      search: value.search || '',
      status: value.status || '',
      department: value.department || '',
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
    status: '',
    department: '',
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
  <AppFilterBar class="teacher-filters">
    <q-input
      v-model="localFilters.search"
      dense
      outlined
      clearable
      label="Поиск"
      placeholder="ФИО, телефон, email, должность"
      @keyup.enter="applyFilters"
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
    <q-select
      v-model="localFilters.department"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Отделение"
      :options="departmentOptions"
    />
    <template #actions>
      <q-btn flat label="Сбросить" :disable="loading" @click="resetFilters" />
      <q-btn color="primary" label="Применить" :loading="loading" @click="applyFilters" />
    </template>

    <template v-if="activeChips.length" #footer>
      <div class="teacher-filters__chips">
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
