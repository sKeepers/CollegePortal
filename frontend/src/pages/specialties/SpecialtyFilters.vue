<script setup>
import { computed, reactive, watch } from 'vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  educationLevelOptions: {
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
  education_level: '',
})

const activeChips = computed(() => {
  const chips = []

  if (localFilters.search) {
    chips.push({ key: 'search', label: `Поиск: ${localFilters.search}` })
  }

  if (localFilters.education_level) {
    chips.push({ key: 'education_level', label: `Уровень: ${localFilters.education_level}` })
  }

  return chips
})

watch(
  () => props.modelValue,
  (value) => {
    Object.assign(localFilters, {
      search: value.search || '',
      education_level: value.education_level || '',
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
  Object.assign(localFilters, { search: '', education_level: '' })
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
  <AppFilterBar class="specialty-filters">
    <q-input
      v-model="localFilters.search"
      dense
      outlined
      clearable
      label="Поиск по коду, названию или квалификации"
      :disable="loading"
      @keyup.enter="applyFilters"
      @clear="applyFilters"
    />
    <q-select
      v-model="localFilters.education_level"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Уровень образования"
      :options="educationLevelOptions"
      :disable="loading"
      @update:model-value="applyFilters"
    />

    <template #actions>
      <q-btn color="primary" :disable="loading" label="Применить" @click="applyFilters" />
      <q-btn flat :disable="loading" label="Сбросить" @click="resetFilters" />
    </template>

    <template v-if="activeChips.length" #footer>
      <q-chip
        v-for="chip in activeChips"
        :key="chip.key"
        removable
        dense
        @remove="removeChip(chip.key)"
      >
        {{ chip.label }}
      </q-chip>
    </template>
  </AppFilterBar>
</template>
