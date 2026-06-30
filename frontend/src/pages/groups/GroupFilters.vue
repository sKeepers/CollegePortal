<script setup>
import { computed, reactive, watch } from 'vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  courseOptions: {
    type: Array,
    default: () => [],
  },
  educationProgramOptions: {
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
  course: '',
  education_program_id: '',
})

const activeChips = computed(() => {
  const chips = []
  const selectedCourse = props.courseOptions.find((course) => Number(course.value) === Number(localFilters.course))
  const selectedProgram = props.educationProgramOptions.find((program) => (
    Number(program.value) === Number(localFilters.education_program_id)
  ))

  if (localFilters.search) {
    chips.push({ key: 'search', label: `Поиск: ${localFilters.search}` })
  }

  if (localFilters.course) {
    chips.push({ key: 'course', label: selectedCourse?.label || `${localFilters.course} курс` })
  }

  if (localFilters.education_program_id) {
    chips.push({ key: 'education_program_id', label: `Программа: ${selectedProgram?.label || localFilters.education_program_id}` })
  }

  return chips
})

watch(
  () => props.modelValue,
  (value) => {
    Object.assign(localFilters, {
      search: value.search || '',
      course: value.course || '',
      education_program_id: value.education_program_id || '',
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
    course: '',
    education_program_id: '',
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
  <AppFilterBar class="group-filters">
    <q-input
      v-model="localFilters.search"
      dense
      outlined
      clearable
      label="Поиск"
      placeholder="Название, специальность, программа или куратор"
      @keyup.enter="applyFilters"
    />
    <q-select
      v-model="localFilters.course"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Курс"
      :options="courseOptions"
    />
    <q-select
      v-model="localFilters.education_program_id"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Образовательная программа"
      :options="educationProgramOptions"
    />
    <template #actions>
      <q-btn flat label="Сбросить" :disable="loading" @click="resetFilters" />
      <q-btn color="primary" label="Применить" :loading="loading" @click="applyFilters" />
    </template>

    <template v-if="activeChips.length" #footer>
      <div class="group-filters__chips">
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
