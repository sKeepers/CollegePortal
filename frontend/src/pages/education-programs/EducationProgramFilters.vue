<script setup>
import { computed, reactive, watch } from 'vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'

const props = defineProps({
  modelValue: { type: Object, required: true },
  specialtyOptions: { type: Array, default: () => [] },
  yearOptions: { type: Array, default: () => [] },
  studyFormOptions: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'apply', 'reset'])

const localFilters = reactive({
  search: '',
  specialty_id: '',
  year_start: '',
  study_form: '',
  active_only: false,
})

const activeChips = computed(() => {
  const chips = []
  const specialty = props.specialtyOptions.find((option) => option.value === localFilters.specialty_id)

  if (localFilters.search) {
    chips.push({ key: 'search', label: `Поиск: ${localFilters.search}` })
  }
  if (localFilters.specialty_id) {
    chips.push({ key: 'specialty_id', label: `Специальность: ${specialty?.label || localFilters.specialty_id}` })
  }
  if (localFilters.year_start) {
    chips.push({ key: 'year_start', label: `Год набора: ${localFilters.year_start}` })
  }
  if (localFilters.study_form) {
    chips.push({ key: 'study_form', label: `Форма: ${localFilters.study_form}` })
  }

  return chips
})

watch(
  () => props.modelValue,
  (value) => {
    Object.assign(localFilters, {
      search: value.search || '',
      specialty_id: value.specialty_id || '',
      year_start: value.year_start || '',
      study_form: value.study_form || '',
      active_only: Boolean(value.active_only),
    })
  },
  { immediate: true, deep: true },
)

function emitUpdate() {
  emit('update:modelValue', { ...localFilters })
}

function applyFilters() {
  emitUpdate()
  emit('apply', { ...localFilters })
}

function resetFilters() {
  Object.assign(localFilters, { search: '', specialty_id: '', year_start: '', study_form: '', active_only: false })
  emitUpdate()
  emit('reset')
}

function removeChip(key) {
  localFilters[key] = ''
  emitUpdate()
  emit('apply', { ...localFilters })
}
</script>

<template>
  <AppFilterBar class="program-filters">
    <q-input
      v-model="localFilters.search"
      dense
      outlined
      clearable
      label="Поиск по названию, форме или специальности"
      :disable="loading"
      @keyup.enter="applyFilters"
      @clear="applyFilters"
    />
    <q-select
      v-model="localFilters.specialty_id"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Специальность"
      :options="specialtyOptions"
      :disable="loading"
      @update:model-value="applyFilters"
    />
    <q-select
      v-model="localFilters.year_start"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Год набора"
      :options="yearOptions"
      :disable="loading"
      @update:model-value="applyFilters"
    />
    <q-select
      v-model="localFilters.study_form"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Форма обучения"
      :options="studyFormOptions"
      :disable="loading"
      @update:model-value="applyFilters"
    />
    <q-toggle
      v-model="localFilters.active_only"
      dense
      label="Только действующие"
      :disable="loading"
      @update:model-value="applyFilters"
    />

    <template #actions>
      <q-btn color="primary" :disable="loading" label="Применить" @click="applyFilters" />
      <q-btn flat :disable="loading" label="Сбросить" @click="resetFilters" />
    </template>

    <template v-if="activeChips.length" #footer>
      <q-chip v-for="chip in activeChips" :key="chip.key" removable dense @remove="removeChip(chip.key)">
        {{ chip.label }}
      </q-chip>
    </template>
  </AppFilterBar>
</template>
