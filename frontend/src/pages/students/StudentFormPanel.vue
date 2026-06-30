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
  status: 'active',
  enrollment_date: '',
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
      status: student?.status || 'active',
      enrollment_date: student?.enrollment_date || '',
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
        </div>
      </AppFormSection>

      <AppFormSection title="Контакты">
        <div class="student-form__grid">
          <q-input v-model="form.phone" dense outlined label="Телефон" />
          <q-input v-model="form.email" dense outlined type="email" label="Email" />
        </div>
      </AppFormSection>

      <div class="student-form__actions">
        <q-btn flat label="Отмена" :disable="saving" @click="emit('cancel')" />
        <q-btn color="primary" type="submit" label="Сохранить" :loading="saving" />
      </div>
    </form>
  </AppCard>
</template>
