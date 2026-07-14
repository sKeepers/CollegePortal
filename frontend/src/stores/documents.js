import { ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const rows = (payload) => (Array.isArray(payload?.data) ? payload.data : [])

export const useDocumentsStore = defineStore('documents', () => {
  const types = ref([])
  const templates = ref([])
  const documents = ref([])
  const students = ref([])
  const preview = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const [typesPayload, templatesPayload, docsPayload, studentsPayload] = await Promise.all([
        api.list('document-types'),
        api.list('document-templates'),
        api.list('documents'),
        api.list('students'),
      ])
      types.value = rows(typesPayload)
      templates.value = rows(templatesPayload)
      documents.value = rows(docsPayload)
      students.value = rows(studentsPayload)
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить документы'
    } finally {
      loading.value = false
    }
  }

  async function previewDocument(payload) {
    saving.value = true
    error.value = ''
    try {
      preview.value = await api.create('documents/preview', payload)
      return preview.value
    } catch (err) {
      error.value = err.message || 'Не удалось подготовить preview'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function generate(payload) {
    saving.value = true
    error.value = ''
    try {
      const result = await api.create('documents/generate', payload)
      await load()
      return result?.data
    } catch (err) {
      error.value = err.message || 'Не удалось сформировать документ'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function action(document, actionName, payload = {}) {
    saving.value = true
    error.value = ''
    try {
      await api.create(`documents/${document.id}/${actionName}`, payload)
      await load()
    } finally {
      saving.value = false
    }
  }

  async function download(generatedDocument, format) {
    const blob = await api.download(`/documents/${generatedDocument.id}/download/${format}`)
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${generatedDocument.registration_number}.${format}`
    link.click()
    window.URL.revokeObjectURL(url)
  }

  return { types, templates, documents, students, preview, loading, saving, error, load, previewDocument, generate, action, download }
})
