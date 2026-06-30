<script setup>
import { computed, ref, watch } from 'vue'
import { X } from '@lucide/vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  academicYearOptions: {
    type: Array,
    default: () => [],
  },
  groupOptions: {
    type: Array,
    default: () => [],
  },
  subjectOptions: {
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

const emit = defineEmits(['apply', 'reset', 'update:model-value'])

const localFilters = ref({ ...props.modelValue })

const semesterOptions = [
  { label: '1 семестр', value: '1' },
  { label: '2 семестр', value: '2' },
]

const activeChips = computed(() => [
  chipForOption('academic_year', 'Учебный год', props.academicYearOptions),
  chipForOption('semester', 'Семестр', semesterOptions),
  chipForOption('group_id', 'Группа', props.groupOptions),
  chipForOption('subject_id', 'Дисциплина', props.subjectOptions),
  chipForOption('teacher_id', 'Преподаватель', props.teacherOptions),
  localFilters.value.date ? { key: 'date', label: `Дата: ${localFilters.value.date}` } : null,
].filter(Boolean))

watch(
  () => props.modelValue,
  (value) => {
    localFilters.value = { ...value }
  },
  { deep: true },
)

function chipForOption(key, label, options) {
  const value = localFilters.value[key]

  if (!value) {
    return null
  }

  const option = options.find((item) => String(item.value) === String(value))

  return {
    key,
    label: `${label}: ${option?.label || value}`,
  }
}

function updateFilters(nextFilters) {
  localFilters.value = {
    ...localFilters.value,
    ...nextFilters,
  }
  emit('update:model-value', localFilters.value)
}

function apply() {
  emit('apply', { ...localFilters.value })
}

function reset() {
  emit('reset')
}

function removeChip(key) {
  const nextFilters = {
    ...localFilters.value,
    [key]: '',
  }

  localFilters.value = nextFilters
  emit('update:model-value', nextFilters)
  emit('apply', nextFilters)
}
</script>

<template>
  <AppFilterBar class="journal-filters">
    <q-select
      :model-value="localFilters.academic_year"
      dense
      outlined
      emit-value
      map-options
      clearable
      label="Учебный год"
      :options="academicYearOptions"
      :disable="loading"
      @update:model-value="updateFilters({ academic_year: $event || '' })"
    />
    <q-select
      :model-value="localFilters.semester"
      dense
      outlined
      emit-value
      map-options
      clearable
      label="Семестр"
      :options="semesterOptions"
      :disable="loading"
      @update:model-value="updateFilters({ semester: $event || '' })"
    />
    <q-select
      :model-value="localFilters.group_id"
      dense
      outlined
      emit-value
      map-options
      clearable
      use-input
      input-debounce="0"
      label="Группа"
      :options="groupOptions"
      :disable="loading"
      @update:model-value="updateFilters({ group_id: $event || '' })"
    />
    <q-select
      :model-value="localFilters.subject_id"
      dense
      outlined
      emit-value
      map-options
      clearable
      label="Дисциплина"
      :options="subjectOptions"
      :disable="loading"
      @update:model-value="updateFilters({ subject_id: $event || '' })"
    />
    <q-select
      :model-value="localFilters.teacher_id"
      dense
      outlined
      emit-value
      map-options
      clearable
      use-input
      input-debounce="0"
      label="Преподаватель"
      :options="teacherOptions"
      :disable="loading"
      @update:model-value="updateFilters({ teacher_id: $event || '' })"
    />
    <q-input
      :model-value="localFilters.date"
      dense
      outlined
      type="date"
      label="Дата"
      :disable="loading"
      @update:model-value="updateFilters({ date: $event || '' })"
    />

    <template #actions>
      <q-btn color="primary" :disable="loading" @click="apply">Применить</q-btn>
      <q-btn flat :disable="loading" @click="reset">Сбросить</q-btn>
    </template>

    <template v-if="activeChips.length" #footer>
      <div class="journal-filters__chips">
        <q-chip
          v-for="chip in activeChips"
          :key="chip.key"
          dense
          removable
          @remove="removeChip(chip.key)"
        >
          <span>{{ chip.label }}</span>
          <template #remove>
            <X :size="13" />
          </template>
        </q-chip>
      </div>
    </template>
  </AppFilterBar>
</template>
