<script setup>
import { computed, onMounted, ref } from 'vue'
import { useDocumentsStore } from '../../stores/documents'
import AppPage from '../../ui/AppPage.vue'
import AppCard from '../../ui/AppCard.vue'
import AppErrorBanner from '../../ui/AppErrorBanner.vue'

const store = useDocumentsStore()
const selectedStudentId = ref('')
const selectedType = ref('student_enrollment_certificate')

const studentOptions = computed(() => store.students.map((student) => ({
  label: `${student.last_name || ''} ${student.first_name || ''} ${student.middle_name || ''}`.trim() || `Студент #${student.id}`,
  value: student.id,
})))

async function preview() {
  await store.previewDocument({ document_type_code: selectedType.value, student_id: selectedStudentId.value })
}

async function generate() {
  await store.generate({ document_type_code: selectedType.value, student_id: selectedStudentId.value })
}

onMounted(() => store.load())
</script>

<template>
  <AppPage title="Документы" subtitle="Печатные формы, справки, шаблоны и журнал выданных документов">
    <AppErrorBanner v-if="store.error" :message="store.error" />

    <div class="documents-grid">
      <AppCard title="Сформировать справку об обучении">
        <div class="form-stack">
          <q-select v-model="selectedType" :options="store.types.map((type) => ({ label: type.name, value: type.code }))" emit-value map-options label="Тип документа" dense outlined />
          <q-select v-model="selectedStudentId" :options="studentOptions" emit-value map-options label="Студент" dense outlined use-input />
          <div class="row q-gutter-sm">
            <q-btn color="primary" :loading="store.saving" :disable="!selectedStudentId" label="Preview" @click="preview" />
            <q-btn color="positive" :loading="store.saving" :disable="!store.preview?.can_generate" label="Сформировать DOCX" @click="generate" />
          </div>
        </div>
      </AppCard>

      <AppCard title="Preview">
        <div v-if="!store.preview" class="text-grey-7">Выберите студента и выполните preview.</div>
        <template v-else>
          <q-banner v-if="!store.preview.can_generate" class="bg-orange-1 text-orange-10 rounded-borders">
            Генерация заблокирована. Проверьте недостающие данные.
          </q-banner>
          <div v-if="store.preview.missing?.length" class="q-mt-md">
            <div class="text-weight-medium">Недостающие поля</div>
            <q-list dense>
              <q-item v-for="item in store.preview.missing" :key="item.key">
                <q-item-section>{{ item.label }}</q-item-section>
                <q-item-section side>{{ item.key }}</q-item-section>
              </q-item>
            </q-list>
          </div>
          <div class="preview-box q-mt-md" v-html="store.preview.preview_html" />
        </template>
      </AppCard>
    </div>

    <AppCard class="q-mt-lg" title="Журнал документов">
      <q-table
        flat
        :rows="store.documents"
        :loading="store.loading"
        row-key="id"
        :columns="[
          { name: 'number', label: 'Рег. номер', field: 'registration_number', align: 'left' },
          { name: 'type', label: 'Тип', field: row => row.type?.name, align: 'left' },
          { name: 'date', label: 'Дата', field: 'issue_date', align: 'left' },
          { name: 'status', label: 'Статус', field: 'status', align: 'left' },
          { name: 'actions', label: 'Действия', field: 'actions', align: 'right' },
        ]"
      >
        <template #body-cell-actions="props">
          <q-td :props="props">
            <q-btn flat dense color="primary" label="DOCX" :disable="!props.row.has_docx" @click="store.download(props.row, 'docx')" />
            <q-btn flat dense color="primary" label="PDF" :disable="!props.row.has_pdf" @click="store.download(props.row, 'pdf')" />
            <q-btn flat dense label="Выдать" :disable="props.row.status === 'issued'" @click="store.action(props.row, 'issue')" />
          </q-td>
        </template>
      </q-table>
    </AppCard>
  </AppPage>
</template>

<style scoped>
.documents-grid {
  display: grid;
  grid-template-columns: minmax(320px, 420px) minmax(0, 1fr);
  gap: 16px;
}
.form-stack {
  display: grid;
  gap: 12px;
}
.preview-box {
  border: 1px solid #d8dee9;
  border-radius: 8px;
  padding: 16px;
  background: #fff;
}
@media (max-width: 900px) {
  .documents-grid {
    grid-template-columns: 1fr;
  }
}
</style>
