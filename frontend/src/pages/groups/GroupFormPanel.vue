<script setup>
import { reactive, watch } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppFormSection from '../../components/ui/AppFormSection.vue'

const props = defineProps({
  group: {
    type: Object,
    default: null,
  },
  educationProgramOptions: {
    type: Array,
    default: () => [],
  },
  teacherOptions: {
    type: Array,
    default: () => [],
  },
  saving: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['save', 'cancel'])

const form = reactive({
  name: '',
  specialty: '',
  education_program_id: '',
  course: '',
  year_start: '',
  curator_id: '',
})

watch(
  () => props.group,
  (group) => {
    Object.assign(form, {
      name: group?.name || '',
      specialty: group?.specialty || '',
      education_program_id: group?.education_program_id || '',
      course: group?.course || '',
      year_start: group?.year_start || '',
      curator_id: group?.curator_id || '',
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
    :title="group?.id ? 'Редактирование группы' : 'Новая группа'"
    subtitle="Учебная группа, программа, курс и куратор."
  >
    <form class="group-form" @submit.prevent="submitForm">
      <AppFormSection title="Основные данные">
        <div class="group-form__grid">
          <q-input v-model="form.name" dense outlined label="Название группы" required />
          <q-input v-model="form.specialty" dense outlined label="Специальность" required />
          <q-input v-model="form.course" dense outlined type="number" min="1" max="6" label="Курс" required />
          <q-input v-model="form.year_start" dense outlined type="number" min="2000" max="2100" label="Год набора" required />
        </div>
      </AppFormSection>

      <AppFormSection title="Связи">
        <div class="group-form__grid">
          <q-select
            v-model="form.education_program_id"
            dense
            outlined
            clearable
            emit-value
            map-options
            label="Образовательная программа"
            :options="educationProgramOptions"
          />
          <q-select
            v-model="form.curator_id"
            dense
            outlined
            clearable
            emit-value
            map-options
            label="Куратор"
            :options="teacherOptions"
          />
        </div>
      </AppFormSection>

      <div class="group-form__actions">
        <q-btn flat label="Отмена" :disable="saving" @click="emit('cancel')" />
        <q-btn color="primary" type="submit" label="Сохранить" :loading="saving" />
      </div>
    </form>
  </AppCard>
</template>
