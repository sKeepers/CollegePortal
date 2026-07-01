<script setup>
import { computed, reactive, watch } from 'vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  buildingOptions: {
    type: Array,
    default: () => [],
  },
  typeOptions: {
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
  building: '',
  type: '',
})

const activeChips = computed(() => {
  const chips = []
  const selectedBuilding = props.buildingOptions.find((building) => building.value === localFilters.building)
  const selectedType = props.typeOptions.find((type) => type.value === localFilters.type)

  if (localFilters.search) {
    chips.push({ key: 'search', label: `Поиск: ${localFilters.search}` })
  }

  if (localFilters.building) {
    chips.push({ key: 'building', label: `Корпус: ${selectedBuilding?.label || localFilters.building}` })
  }

  if (localFilters.type) {
    chips.push({ key: 'type', label: `Тип: ${selectedType?.label || localFilters.type}` })
  }

  return chips
})

watch(
  () => props.modelValue,
  (value) => {
    Object.assign(localFilters, {
      search: value.search || '',
      building: value.building || '',
      type: value.type || '',
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
    building: '',
    type: '',
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
  <AppFilterBar class="classroom-filters">
    <q-input
      v-model="localFilters.search"
      dense
      outlined
      clearable
      label="Поиск"
      placeholder="Номер, корпус, тип, описание"
      @keyup.enter="applyFilters"
    />
    <q-select
      v-model="localFilters.building"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Корпус"
      :options="buildingOptions"
    />
    <q-select
      v-model="localFilters.type"
      dense
      outlined
      clearable
      emit-value
      map-options
      label="Тип"
      :options="typeOptions"
    />
    <template #actions>
      <q-btn flat label="Сбросить" :disable="loading" @click="resetFilters" />
      <q-btn color="primary" label="Применить" :loading="loading" @click="applyFilters" />
    </template>

    <template v-if="activeChips.length" #footer>
      <div class="classroom-filters__chips">
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
