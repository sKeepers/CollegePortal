<script setup>
import { reactive, watch } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppFormSection from '../../components/ui/AppFormSection.vue'

const props = defineProps({
  classroom: {
    type: Object,
    default: null,
  },
  saving: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['save', 'cancel'])

const form = reactive({
  number: '',
  building: '',
  floor: '',
  capacity: '',
  type: '',
  description: '',
})

watch(
  () => props.classroom,
  (classroom) => {
    Object.assign(form, {
      number: classroom?.number || '',
      building: classroom?.building || '',
      floor: classroom?.floor ?? '',
      capacity: classroom?.capacity ?? '',
      type: classroom?.type || '',
      description: classroom?.description || '',
    })
  },
  { immediate: true },
)

function submitForm() {
  emit('save', { ...form })
}
</script>

<template>
  <AppCard
    :title="classroom?.id ? 'Редактирование аудитории' : 'Новая аудитория'"
    subtitle="Номер, корпус, этаж, вместимость, тип и описание."
  >
    <form class="classroom-form" @submit.prevent="submitForm">
      <AppFormSection title="Основные данные">
        <div class="classroom-form__grid">
          <q-input v-model="form.number" dense outlined label="Номер / название" required />
          <q-input v-model="form.building" dense outlined label="Корпус" />
          <q-input v-model.number="form.floor" dense outlined type="number" min="0" max="50" label="Этаж" />
          <q-input v-model.number="form.capacity" dense outlined type="number" min="1" max="1000" label="Вместимость" />
          <q-input v-model="form.type" dense outlined label="Тип аудитории" />
        </div>
      </AppFormSection>

      <AppFormSection title="Описание">
        <q-input
          v-model="form.description"
          dense
          outlined
          type="textarea"
          autogrow
          label="Краткое описание"
        />
      </AppFormSection>

      <div class="classroom-form__actions">
        <q-btn flat label="Отмена" :disable="saving" @click="emit('cancel')" />
        <q-btn color="primary" type="submit" label="Сохранить" :loading="saving" />
      </div>
    </form>
  </AppCard>
</template>
