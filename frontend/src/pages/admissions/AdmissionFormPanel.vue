<script setup>
import { reactive, watch } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppFormSection from '../../components/ui/AppFormSection.vue'
import { EDUCATION_BASE_OPTIONS } from '../../stores/admissions'

const props = defineProps({
  application: {
    type: Object,
    default: null,
  },
  educationProgramOptions: {
    type: Array,
    default: () => [],
  },
  statusOptions: {
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
  education_program_id: '',
  last_name: '',
  first_name: '',
  middle_name: '',
  birth_date: '',
  phone: '',
  email: '',
  education_base: 'after_9',
  status: 'new',
  submitted_at: new Date().toISOString().slice(0, 10),
  comment: '',
})

watch(
  () => props.application,
  (application) => {
    Object.assign(form, {
      education_program_id: application?.education_program_id || '',
      last_name: application?.last_name || '',
      first_name: application?.first_name || '',
      middle_name: application?.middle_name || '',
      birth_date: application?.birth_date || '',
      phone: application?.phone || '',
      email: application?.email || '',
      education_base: application?.education_base || 'after_9',
      status: application?.status || 'new',
      submitted_at: application?.submitted_at || new Date().toISOString().slice(0, 10),
      comment: application?.comment || '',
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
    :title="application?.id ? 'Редактирование заявления' : 'Новое заявление'"
    subtitle="Данные абитуриента, программа обучения, статус и комментарий приёмной комиссии."
  >
    <form class="admission-form" @submit.prevent="submitForm">
      <AppFormSection title="Абитуриент">
        <div class="admission-form__grid">
          <q-input v-model="form.last_name" dense outlined label="Фамилия" required />
          <q-input v-model="form.first_name" dense outlined label="Имя" required />
          <q-input v-model="form.middle_name" dense outlined label="Отчество" />
          <q-input v-model="form.birth_date" dense outlined type="date" label="Дата рождения" />
          <q-input v-model="form.phone" dense outlined label="Телефон" />
          <q-input v-model="form.email" dense outlined type="email" label="Email" />
        </div>
      </AppFormSection>

      <AppFormSection title="Поступление">
        <div class="admission-form__grid">
          <q-select
            v-model="form.education_program_id"
            dense
            outlined
            emit-value
            map-options
            label="Образовательная программа"
            :options="educationProgramOptions"
            required
          />
          <q-select
            v-model="form.education_base"
            dense
            outlined
            emit-value
            map-options
            label="База поступления"
            :options="EDUCATION_BASE_OPTIONS"
            required
          />
          <q-select
            v-model="form.status"
            dense
            outlined
            emit-value
            map-options
            label="Статус"
            :options="statusOptions"
            required
          />
          <q-input v-model="form.submitted_at" dense outlined type="date" label="Дата подачи" required />
        </div>
      </AppFormSection>

      <AppFormSection title="Комментарий">
        <q-input
          v-model="form.comment"
          dense
          outlined
          type="textarea"
          autogrow
          label="Комментарий приёмной комиссии"
        />
      </AppFormSection>

      <div class="admission-form__actions">
        <q-btn flat label="Отмена" :disable="saving" @click="emit('cancel')" />
        <q-btn color="primary" type="submit" label="Сохранить" :loading="saving" />
      </div>
    </form>
  </AppCard>
</template>
