<script setup>
import { reactive, ref, watch } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import { Edit3 } from '@lucide/vue'
import AppFormSection from '../../components/ui/AppFormSection.vue'

const props = defineProps({
  subject: {
    type: Object,
    default: null,
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
const codeEditable = ref(false)

const form = reactive({
  name: '',
  code: '',
  department: '',
  description: '',
  teacher_ids: [],
})

watch(
  () => props.subject,
  (subject) => {
    Object.assign(form, {
      name: subject?.name || '',
      code: subject?.code || '',
      department: subject?.department || '',
      description: subject?.description || '',
      teacher_ids: Array.isArray(subject?.teachers) ? subject.teachers.map((teacher) => teacher.id) : [],
    })
    codeEditable.value = false
  },
  { immediate: true },
)

function submitForm() {
  emit('save', { ...form })
}
</script>

<template>
  <AppCard
    :title="subject?.id ? 'Редактирование дисциплины' : 'Новая дисциплина'"
    subtitle="Название, код, отделение, описание и связанные преподаватели."
  >
    <form class="subject-form" @submit.prevent="submitForm">
      <AppFormSection title="Основные данные">
        <div class="subject-form__grid">
          <q-input v-model="form.name" dense outlined label="Название дисциплины" required />
          <q-input v-model="form.code" dense outlined label="Код" placeholder="Будет создан автоматически" :readonly="!codeEditable"><template #append><q-btn flat round dense title="Разрешить ручное редактирование" @click="codeEditable = true"><Edit3 :size="15" /></q-btn></template></q-input>
          <q-input v-model="form.department" dense outlined label="Отделение / кафедра" />
        </div>
      </AppFormSection>

      <AppFormSection title="Преподаватели">
        <q-select
          v-model="form.teacher_ids"
          dense
          outlined
          multiple
          use-chips
          emit-value
          map-options
          label="Связанные преподаватели"
          :options="teacherOptions"
        />
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

      <div class="subject-form__actions">
        <q-btn flat label="Отмена" :disable="saving" @click="emit('cancel')" />
        <q-btn color="primary" type="submit" label="Сохранить" :loading="saving" />
      </div>
    </form>
  </AppCard>
</template>
