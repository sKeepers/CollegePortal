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
  teacherOptions: {
    type: Array,
    default: () => [],
  },
  classroomOptions: {
    type: Array,
    default: () => [],
  },
  subjectOptions: {
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

const weekTypeOptions = [
  { label: 'Каждая неделя', value: 'all' },
  { label: 'Четная неделя', value: 'even' },
  { label: 'Нечетная неделя', value: 'odd' },
]

const statusOptions = [
  { label: 'Запланировано', value: 'scheduled' },
  { label: 'Перенесено', value: 'moved' },
  { label: 'Отменено', value: 'canceled' },
  { label: 'Черновик', value: 'draft' },
]

const activeChips = computed(() => [
  chipForOption('academic_year', 'Учебный год', props.academicYearOptions),
  chipForOption('semester', 'Семестр', semesterOptions),
  chipForOption('group_id', 'Группа', props.groupOptions),
  chipForOption('teacher_id', 'Преподаватель', props.teacherOptions),
  chipForOption('classroom_id', 'Аудитория', props.classroomOptions),
  chipForOption('subject_id', 'Дисциплина', props.subjectOptions),
  chipForOption('week_type', 'Неделя', weekTypeOptions),
  chipForOption('status', 'Статус', statusOptions),
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
    // Вне списка короткой строки мало: под заголовком специальности «2 курс» понятно,
  // а в фишке фильтра заголовка нет. Полное имя группы лежит рядом, в `fullLabel`.
    label: `${label}: ${option?.fullLabel || option?.label || value}`,
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
  updateFilters({ [key]: '' })
  emit('apply', { ...localFilters.value, [key]: '' })
}
</script>

<template>
  <AppFilterBar class="schedule-filters">
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
    <q-select
      :model-value="localFilters.classroom_id"
      dense
      outlined
      emit-value
      map-options
      clearable
      label="Аудитория"
      :options="classroomOptions"
      :disable="loading"
      @update:model-value="updateFilters({ classroom_id: $event || '' })"
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
      :model-value="localFilters.week_type"
      dense
      outlined
      emit-value
      map-options
      clearable
      label="Четность"
      :options="weekTypeOptions"
      :disable="loading"
      @update:model-value="updateFilters({ week_type: $event || '' })"
    />
    <q-select
      :model-value="localFilters.status"
      dense
      outlined
      emit-value
      map-options
      clearable
      label="Статус"
      :options="statusOptions"
      :disable="loading"
      @update:model-value="updateFilters({ status: $event || '' })"
    />
    <q-checkbox
      :model-value="localFilters.conflicts_only"
      dense
      label="Только конфликты"
      :disable="loading"
      @update:model-value="updateFilters({ conflicts_only: $event })"
    />

    <template #actions>
      <q-btn color="primary" :disable="loading" @click="apply">Применить</q-btn>
      <q-btn flat :disable="loading" @click="reset">Сбросить</q-btn>
    </template>

    <template v-if="activeChips.length" #footer>
      <div class="schedule-filters__chips">
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
