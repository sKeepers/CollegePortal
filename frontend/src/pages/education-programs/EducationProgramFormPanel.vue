<script setup>
import { reactive, watch } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppFormSection from '../../components/ui/AppFormSection.vue'
import { STUDY_FORM_OPTIONS } from '../../stores/educationPrograms'

const props = defineProps({
  program: { type: Object, default: null },
  specialtyOptions: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'cancel'])

const form = reactive({
  specialty_id: '',
  name: '',
  year_start: new Date().getFullYear(),
  study_form: 'Очная',
  study_years: '',
  is_active: true,
  description: '',
})

watch(
  () => props.program,
  (program) => {
    Object.assign(form, {
      specialty_id: program?.specialty_id ?? '',
      name: program?.name || '',
      year_start: program?.year_start ?? new Date().getFullYear(),
      study_form: program?.study_form || 'Очная',
      study_years: program?.study_years ?? '',
      is_active: program ? Boolean(program.is_active) : true,
      description: program?.description || '',
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
    :title="program?.id ? 'Редактирование программы' : 'Новая образовательная программа'"
    subtitle="Специальность, год набора и форма обучения вместе задают программу — они же и различают её."
  >
    <form class="program-form" @submit.prevent="submitForm">
      <AppFormSection title="Основные данные">
        <div class="program-form__grid">
          <q-select
            v-model="form.specialty_id"
            dense
            outlined
            emit-value
            map-options
            label="Специальность"
            :options="specialtyOptions"
            required
          />
          <q-input v-model="form.name" dense outlined label="Наименование программы" required />
          <q-input
            v-model.number="form.year_start"
            dense
            outlined
            type="number"
            min="2000"
            max="2100"
            label="Год набора"
            required
          />
          <q-select
            v-model="form.study_form"
            dense
            outlined
            use-input
            fill-input
            hide-selected
            new-value-mode="add-unique"
            label="Форма обучения"
            :options="STUDY_FORM_OPTIONS"
          />
          <q-input
            v-model="form.study_years"
            dense
            outlined
            type="number"
            step="0.5"
            min="0.5"
            max="10"
            label="Срок обучения, лет"
          />
        </div>
        <q-toggle v-model="form.is_active" label="Программа действует" />
      </AppFormSection>

      <AppFormSection title="Описание">
        <q-input v-model="form.description" dense outlined type="textarea" autogrow label="Краткое описание" />
      </AppFormSection>

      <div class="program-form__actions">
        <q-btn flat label="Отмена" :disable="saving" @click="emit('cancel')" />
        <q-btn color="primary" type="submit" label="Сохранить" :loading="saving" />
      </div>
    </form>
  </AppCard>
</template>

<style scoped>
.program-form { display: flex; flex-direction: column; gap: 16px; }
.program-form__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.program-form__actions { display: flex; justify-content: flex-end; gap: 8px; }
</style>
