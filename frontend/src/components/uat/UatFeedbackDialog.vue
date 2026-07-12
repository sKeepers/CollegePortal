<script setup>
import { reactive, ref, watch } from 'vue'
import { api } from '../../services/api'
import { useAuthStore } from '../../stores/auth'
import { getVersionInfo, getRuntimeEnvironmentInfo } from '../../services/versionService'

const props = defineProps({ modelValue: { type: Boolean, default: false } })
const emit = defineEmits(['update:modelValue'])
const auth = useAuthStore()
const saving = ref(false)
const error = ref('')
const screenshot = ref(null)
const form = reactive({
  title: '', category: 'error', severity: 'medium', description: '', expected_result: '', actual_result: '', page_url: '', app_version: '', build_hash: '', environment: '', role_code: '',
})
const categoryOptions = [
  { label: 'Ошибка', value: 'error' }, { label: 'Неудобство', value: 'ux' }, { label: 'Предложение', value: 'suggestion' }, { label: 'Данные', value: 'data' }, { label: 'Права доступа', value: 'access' },
]
const severityOptions = [
  { label: 'Critical', value: 'critical' }, { label: 'High', value: 'high' }, { label: 'Medium', value: 'medium' }, { label: 'Low', value: 'low' }, { label: 'UX', value: 'ux' },
]

watch(() => props.modelValue, async (open) => {
  if (!open) return
  const version = await getVersionInfo()
  const env = getRuntimeEnvironmentInfo()
  form.page_url = window.location.pathname + window.location.search
  form.app_version = version.version
  form.build_hash = version.build
  form.environment = env.key
  form.role_code = auth.user?.role?.code || auth.roleCodes?.[0] || ''
}, { immediate: true })

function close() { emit('update:modelValue', false) }
async function submit() {
  saving.value = true
  error.value = ''
  try {
    const data = new FormData()
    Object.entries(form).forEach(([key, value]) => { if (value) data.append(key, value) })
    if (screenshot.value) data.append('screenshot', screenshot.value)
    await api.upload('/uat/feedback', data)
    form.title = ''; form.description = ''; form.expected_result = ''; form.actual_result = ''; screenshot.value = null
    close()
  } catch (err) {
    error.value = err.message || 'Не удалось отправить замечание'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <q-dialog :model-value="modelValue" @update:model-value="emit('update:modelValue', $event)">
    <q-card class="uat-feedback-dialog">
      <q-card-section><h3>Сообщить о проблеме</h3><p>Замечание попадет в private UAT registry. Скриншот не публикуется прямой ссылкой.</p></q-card-section>
      <q-card-section class="uat-feedback-form">
        <q-banner v-if="error" rounded class="bg-red-1 text-negative">{{ error }}</q-banner>
        <q-input v-model="form.page_url" outlined readonly label="Страница" />
        <q-input v-model="form.role_code" outlined readonly label="Роль" />
        <q-select v-model="form.category" outlined emit-value map-options label="Категория" :options="categoryOptions" />
        <q-select v-model="form.severity" outlined emit-value map-options label="Важность" :options="severityOptions" />
        <q-input v-model="form.title" outlined label="Краткий заголовок" />
        <q-input v-model="form.description" outlined autogrow label="Описание" />
        <q-input v-model="form.expected_result" outlined autogrow label="Ожидаемое поведение" />
        <q-input v-model="form.actual_result" outlined autogrow label="Фактическое поведение" />
        <q-file v-model="screenshot" outlined clearable accept=".jpg,.jpeg,.png,.webp" label="Скриншот" />
        <div class="uat-feedback-meta"><span>Version: {{ form.app_version }}</span><span>Build: {{ form.build_hash }}</span><span>{{ form.environment }}</span></div>
      </q-card-section>
      <q-card-actions align="right"><q-btn flat :disable="saving" @click="close">Отмена</q-btn><q-btn color="primary" :loading="saving" :disable="!form.title || !form.description" @click="submit">Отправить</q-btn></q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.uat-feedback-dialog { min-width: 560px; max-width: 720px; }
.uat-feedback-dialog h3 { margin: 0; }
.uat-feedback-dialog p { margin: 6px 0 0; color: #64748b; }
.uat-feedback-form { display: grid; gap: 10px; }
.uat-feedback-meta { display: flex; flex-wrap: wrap; gap: 8px; color: #64748b; font-size: 12px; }
@media (max-width: 640px) { .uat-feedback-dialog { min-width: auto; width: 96vw; } }
</style>
