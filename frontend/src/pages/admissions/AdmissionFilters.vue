<script setup>
import { computed, reactive, watch } from 'vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'
import { COMPLETENESS_OPTIONS, STATUS_OPTIONS } from '../../stores/admissions'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  specialtyOptions: {
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
  status: '',
  specialtyId: '',
  educationProgramId: '',
  completeness: '',
  submittedDate: '',
})

const activeChips = computed(() => {
  const chips = []
  const selectedStatus = STATUS_OPTIONS.find((status) => status.value === localFilters.status)
  const selectedSpecialty = props.specialtyOptions.find((specialty) => Number(specialty.value) === Number(localFilters.specialtyId))
  const selectedProgram = props.educationProgramOptions.find((program) => Number(program.value) === Number(localFilters.educationProgramId))
  const selectedCompleteness = COMPLETENESS_OPTIONS.find((option) => option.value === localFilters.completeness)

  if (localFilters.search) {
    chips.push({ key: 'search', label: `Поиск: ${localFilters.search}` })
  }

  if (localFilters.status) {
    chips.push({ key: 'status', label: `Статус: ${selectedStatus?.label || localFilters.status}` })
  }

  if (localFilters.specialtyId) {
    chips.push({ key: 'specialtyId', label: `Специальность: ${selectedSpecialty?.label || localFilters.specialtyId}` })
  }

  if (localFilters.educationProgramId) {
    chips.push({ key: 'educationProgramId', label: `Программа: ${selectedProgram?.label || localFilters.educationProgramId}` })
  }

  if (localFilters.completeness) {
    chips.push({ key: 'completeness', label: `Документы: ${selectedCompleteness?.label || localFilters.completeness}` })
  }

  if (localFilters.submittedDate) {
    chips.push({ key: 'submittedDate', label: `Дата подачи: ${localFilters.submittedDate}` })
  }

  return chips
})

watch(
  () => props.modelValue,
  (value) => {
    Object.assign(localFilters, {
      search: value.search || '',
      status: value.status || '',
      specialtyId: value.specialtyId || '',
      educationProgramId: value.educationProgramId || '',
      completeness: value.completeness || '',
      submittedDate: value.submittedDate || '',
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
    specialtyId: '',
    educationProgramId: '',
    completeness: '',
    submittedDate: '',
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
  <AppFilterBar class="admission-filters">
    <q-input
      v-model="localFilters.search"
      dense
      outlined
      clearable
      label="Поиск"
      placeholder="ФИО, телефон, email"
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
      :options="STATUS_OPTIONS"
    />
    <q-select
      v-model="localFilters.specialtyId"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Специальность"
      :options="specialtyOptions"
    />
    <q-select
      v-model="localFilters.educationProgramId"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Программа"
      :options="educationProgramOptions"
    />
    <q-select
      v-model="localFilters.completeness"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Документы"
      :options="COMPLETENESS_OPTIONS"
    />
    <q-input v-model="localFilters.submittedDate" dense outlined clearable type="date" label="Дата подачи" />

    <template #actions>
      <q-btn flat label="Сбросить" :disable="loading" @click="resetFilters" />
      <q-btn color="primary" label="Применить" :loading="loading" @click="applyFilters" />
    </template>

    <template v-if="activeChips.length" #footer>
      <div class="admission-filters__chips">
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
