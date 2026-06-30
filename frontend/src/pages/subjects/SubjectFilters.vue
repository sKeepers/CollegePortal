<script setup>
import { computed, reactive, watch } from 'vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  departmentOptions: {
    type: Array,
    default: () => [],
  },
  teacherOptions: {
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
  department: '',
  teacher_id: '',
})

const activeChips = computed(() => {
  const chips = []
  const selectedDepartment = props.departmentOptions.find((department) => department.value === localFilters.department)
  const selectedTeacher = props.teacherOptions.find((teacher) => Number(teacher.value) === Number(localFilters.teacher_id))

  if (localFilters.search) {
    chips.push({ key: 'search', label: `Поиск: ${localFilters.search}` })
  }

  if (localFilters.department) {
    chips.push({ key: 'department', label: `Отделение: ${selectedDepartment?.label || localFilters.department}` })
  }

  if (localFilters.teacher_id) {
    chips.push({ key: 'teacher_id', label: `Преподаватель: ${selectedTeacher?.label || localFilters.teacher_id}` })
  }

  return chips
})

watch(
  () => props.modelValue,
  (value) => {
    Object.assign(localFilters, {
      search: value.search || '',
      department: value.department || '',
      teacher_id: value.teacher_id || '',
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
    department: '',
    teacher_id: '',
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
  <AppFilterBar class="subject-filters">
    <q-input
      v-model="localFilters.search"
      dense
      outlined
      clearable
      label="Поиск"
      placeholder="Название, код, описание"
      @keyup.enter="applyFilters"
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
    <q-select
      v-model="localFilters.teacher_id"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Преподаватель"
      :options="teacherOptions"
    />
    <template #actions>
      <q-btn flat label="Сбросить" :disable="loading" @click="resetFilters" />
      <q-btn color="primary" label="Применить" :loading="loading" @click="applyFilters" />
    </template>

    <template v-if="activeChips.length" #footer>
      <div class="subject-filters__chips">
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
