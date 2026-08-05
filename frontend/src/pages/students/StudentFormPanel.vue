<script setup>
import { reactive, watch } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppFormSection from '../../components/ui/AppFormSection.vue'

const props = defineProps({
  student: {
    type: Object,
    default: null,
  },
  groupOptions: {
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
  user_id: '',
  group_id: '',
  last_name: '',
  first_name: '',
  middle_name: '',
  birth_date: '',
  phone: '',
  email: '',
  snils: '',
  address: '',
  passport_series: '',
  passport_number: '',
  passport_issue_date: '',
  passport_issued_by: '',
  status: 'active',
  course: '',
  education_form: '',
  enrollment_date: '',
  enrollment_order_number: '',
  enrollment_order_date: '',
})

watch(
  () => props.student,
  (student) => {
    Object.assign(form, {
      user_id: student?.user_id || '',
      group_id: student?.group_id || '',
      last_name: student?.last_name || '',
      first_name: student?.first_name || '',
      middle_name: student?.middle_name || '',
      birth_date: student?.birth_date || '',
      phone: student?.phone || '',
      email: student?.email || '',
      snils: student?.snils || '',
      address: student?.address || '',
      passport_series: student?.passport_series || '',
      passport_number: student?.passport_number || '',
      passport_issue_date: student?.passport_issue_date || '',
      passport_issued_by: student?.passport_issued_by || '',
      status: student?.status || 'active',
      course: student?.course || student?.group?.course || '',
      education_form: student?.education_form || '',
      enrollment_date: student?.enrollment_date || '',
      enrollment_order_number: student?.enrollment_order_number || '',
      enrollment_order_date: student?.enrollment_order_date || '',
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
    :title="student?.id ? 'Редактирование студента' : 'Новый студент'"
    subtitle="Основные данные, группа, контакты и статус обучения."
  >
    <form class="student-form" @submit.prevent="submitForm">
      <AppFormSection title="Персональные данные">
        <div class="student-form__grid">
          <q-input v-model="form.last_name" dense outlined label="Фамилия" required />
          <q-input v-model="form.first_name" dense outlined label="Имя" required />
          <q-input v-model="form.middle_name" dense outlined label="Отчество" />
          <q-input v-model="form.birth_date" dense outlined type="date" label="Дата рождения" stack-label />
        </div>
      </AppFormSection>

      <AppFormSection title="Обучение">
        <div class="student-form__grid">
          <q-select
            v-model="form.group_id"
            dense
            outlined
            emit-value
            map-options
            label="Группа"
            :options="groupOptions"
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
          <q-input v-model="form.enrollment_date" dense outlined type="date" label="Дата зачисления" stack-label />
          <q-input v-model="form.user_id" dense outlined type="number" label="ID пользователя" />
          <q-input v-model="form.course" dense outlined type="number" min="1" max="6" label="Курс" />
          <q-select v-model="form.education_form" dense outlined clearable emit-value map-options label="Форма обучения" :options="[{ label: 'Очная', value: 'Очная' }, { label: 'Заочная', value: 'Заочная' }]" />
          <q-input v-model="form.enrollment_order_number" dense outlined label="Приказ о зачислении" />
          <q-input v-model="form.enrollment_order_date" dense outlined type="date" label="Дата приказа" stack-label />
        </div>
      </AppFormSection>

      <AppFormSection title="Контакты">
        <div class="student-form__grid">
          <q-input v-model="form.phone" dense outlined label="Телефон" />
          <q-input v-model="form.email" dense outlined type="email" label="Email" />
          <q-input v-model="form.snils" dense outlined label="СНИЛС (необязательно)" />
          <q-input v-model="form.address" dense outlined class="student-form__wide" label="Адрес" />
        </div>
      </AppFormSection>

      <AppFormSection title="Паспорт">
        <div class="student-form__grid">
          <q-input v-model="form.passport_series" dense outlined label="Серия" />
          <q-input v-model="form.passport_number" dense outlined label="Номер" />
          <q-input v-model="form.passport_issue_date" dense outlined type="date" label="Дата выдачи" stack-label />
          <q-input v-model="form.passport_issued_by" dense outlined label="Кем выдан" />
        </div>
      </AppFormSection>

      <div class="student-form__actions">
        <q-btn flat label="Отмена" :disable="saving" @click="emit('cancel')" />
        <q-btn color="primary" type="submit" label="Сохранить" :loading="saving" />
      </div>
    </form>
  </AppCard>
</template>
