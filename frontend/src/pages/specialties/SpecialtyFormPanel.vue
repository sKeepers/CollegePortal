<script setup>
import { reactive, watch } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppFormSection from '../../components/ui/AppFormSection.vue'

const props = defineProps({
  specialty: {
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
  code: '',
  name: '',
  education_level: '',
  qualification: '',
  normative_study_years: '',
  description: '',
})

watch(
  () => props.specialty,
  (specialty) => {
    Object.assign(form, {
      code: specialty?.code || '',
      name: specialty?.name || '',
      education_level: specialty?.education_level || '',
      qualification: specialty?.qualification || '',
      normative_study_years: specialty?.normative_study_years ?? '',
      description: specialty?.description || '',
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
    :title="specialty?.id ? 'Редактирование специальности' : 'Новая специальность'"
    subtitle="Код по классификатору, наименование, уровень образования и квалификация."
  >
    <form class="specialty-form" @submit.prevent="submitForm">
      <AppFormSection title="Основные данные">
        <div class="specialty-form__grid">
          <q-input
            v-model="form.code"
            dense
            outlined
            label="Код"
            hint="Можно оставить пустым — система подставит код сама"
          />
          <q-input v-model="form.name" dense outlined label="Наименование" required />
          <q-input v-model="form.education_level" dense outlined label="Уровень образования" required />
          <q-input v-model="form.qualification" dense outlined label="Квалификация" />
          <q-input
            v-model="form.normative_study_years"
            dense
            outlined
            type="number"
            step="0.5"
            min="0.5"
            max="10"
            label="Нормативный срок обучения, лет"
          />
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

      <div class="specialty-form__actions">
        <q-btn flat label="Отмена" :disable="saving" @click="emit('cancel')" />
        <q-btn color="primary" type="submit" label="Сохранить" :loading="saving" />
      </div>
    </form>
  </AppCard>
</template>

<style scoped>
.specialty-form { display: flex; flex-direction: column; gap: 16px; }
.specialty-form__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.specialty-form__actions { display: flex; justify-content: flex-end; gap: 8px; }
</style>
