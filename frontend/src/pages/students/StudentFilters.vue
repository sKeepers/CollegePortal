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
  courseOptions: {
    type: Array,
    default: () => [],
  },
  specialtyOptions: {
    type: Array,
    default: () => [],
  },
  // Почему список статусов пуст: «нет прав» и «справочник не заполнен» —
  // разные вещи, и молчащий список не должен выдавать их за одно и то же.
  statusHint: {
    type: String,
    default: '',
  },
  loading: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'apply', 'reset'])

const completenessOptions = [
  { label: 'Все карточки', value: '' },
  { label: 'Только неполные', value: 'incomplete' },
]

const localFilters = reactive({
  search: '',
  group_id: '',
  course: '',
  specialty_id: '',
  status: '',
  completeness: '',
})

const activeChips = computed(() => {
  const chips = []
  const selectedGroup = props.groupOptions.find((group) => Number(group.value) === Number(localFilters.group_id))
  const selectedStatus = props.statusOptions.find((status) => status.value === localFilters.status)
  const selectedSpecialty = props.specialtyOptions.find((item) => Number(item.value) === Number(localFilters.specialty_id))

  if (localFilters.search) {
    chips.push({ key: 'search', label: `Поиск: ${localFilters.search}` })
  }

  if (localFilters.group_id) {
    chips.push({ key: 'group_id', label: `Группа: ${selectedGroup?.label || localFilters.group_id}` })
  }

  if (localFilters.course) {
    chips.push({ key: 'course', label: `Курс: ${localFilters.course}` })
  }

  if (localFilters.specialty_id) {
    chips.push({ key: 'specialty_id', label: `Специальность: ${selectedSpecialty?.label || localFilters.specialty_id}` })
  }

  if (localFilters.status) {
    chips.push({ key: 'status', label: `Статус: ${selectedStatus?.label || localFilters.status}` })
  }

  if (localFilters.completeness) {
    chips.push({ key: 'completeness', label: 'Только неполные карточки' })
  }

  return chips
})

watch(
  () => props.modelValue,
  (value) => {
    Object.assign(localFilters, {
      search: value.search || '',
      group_id: value.group_id || '',
      course: value.course || '',
      specialty_id: value.specialty_id || '',
      status: value.status || '',
      completeness: value.completeness || '',
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
    course: '',
    specialty_id: '',
    status: '',
    completeness: '',
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
      v-model="localFilters.specialty_id"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Специальность"
      :options="specialtyOptions"
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
      :hint="statusOptions.length ? '' : statusHint"
      :disable="!statusOptions.length && !!statusHint"
    />
    <q-select
      v-model="localFilters.completeness"
      dense
      outlined
      emit-value
      map-options
      label="Полнота карточки"
      :options="completenessOptions"
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
