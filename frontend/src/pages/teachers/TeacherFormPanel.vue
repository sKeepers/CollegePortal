<script setup>
import { reactive, watch } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppFormSection from '../../components/ui/AppFormSection.vue'

const props = defineProps({
  teacher: {
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
  last_name: '',
  first_name: '',
  middle_name: '',
  phone: '',
  email: '',
  position: '',
  department: '',
  is_active: true,
})

watch(
  () => props.teacher,
  (teacher) => {
    Object.assign(form, {
      last_name: teacher?.last_name || '',
      first_name: teacher?.first_name || '',
      middle_name: teacher?.middle_name || '',
      phone: teacher?.phone || '',
      email: teacher?.email || '',
      position: teacher?.position || '',
      department: teacher?.department || '',
      is_active: teacher?.id ? Boolean(teacher.is_active) : true,
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
    :title="teacher?.id ? 'Редактирование преподавателя' : 'Новый преподаватель'"
    subtitle="ФИО, контакты, должность и отделение."
  >
    <form class="teacher-form" @submit.prevent="submitForm">
      <AppFormSection title="Основные данные">
        <div class="teacher-form__grid">
          <q-input v-model="form.last_name" dense outlined label="Фамилия" required />
          <q-input v-model="form.first_name" dense outlined label="Имя" required />
          <q-input v-model="form.middle_name" dense outlined label="Отчество" />
          <q-toggle v-model="form.is_active" label="Активный преподаватель" />
        </div>
      </AppFormSection>

      <AppFormSection title="Контакты и работа">
        <div class="teacher-form__grid">
          <q-input v-model="form.phone" dense outlined label="Телефон" />
          <q-input v-model="form.email" dense outlined type="email" label="Email" />
          <q-input v-model="form.position" dense outlined label="Должность" />
          <q-input v-model="form.department" dense outlined label="Отделение / кафедра" />
        </div>
      </AppFormSection>

      <div class="teacher-form__actions">
        <q-btn flat label="Отмена" :disable="saving" @click="emit('cancel')" />
        <q-btn color="primary" type="submit" label="Сохранить" :loading="saving" />
      </div>
    </form>
  </AppCard>
</template>
