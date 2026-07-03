<script setup>
import { computed, ref } from 'vue'
import { Camera, Trash2, Upload } from '@lucide/vue'
import { api } from '../../services/api'

const props = defineProps({
  type: { type: String, required: true },
  person: { type: Object, default: null },
  compact: { type: Boolean, default: false },
})
const emit = defineEmits(['updated', 'removed', 'error'])
const file = ref(null)
const loading = ref(false)
const photoUrl = computed(() => props.person?.photo_url || '')
const initials = computed(() => [props.person?.last_name, props.person?.first_name].filter(Boolean).map((part) => part[0]).join('').toUpperCase() || 'CP')

async function uploadPhoto(nextFile) {
  if (!nextFile || !props.person?.id) return
  loading.value = true
  try {
    const formData = new FormData()
    formData.append('photo', nextFile)
    const payload = await api.upload(`/person-photos/${props.type}/${props.person.id}`, formData)
    emit('updated', payload?.data || {})
    file.value = null
  } catch (err) {
    emit('error', err)
  } finally {
    loading.value = false
  }
}

async function removePhoto() {
  if (!props.person?.id || !photoUrl.value) return
  loading.value = true
  try {
    await api.delete(`person-photos/${props.type}`, props.person.id)
    emit('removed')
  } catch (err) {
    emit('error', err)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div :class="['person-photo-manager', { 'person-photo-manager--compact': compact }]">
    <q-avatar :size="compact ? '64px' : '92px'" class="person-photo-manager__avatar">
      <img v-if="photoUrl" :src="photoUrl" alt="Фото" />
      <span v-else>{{ initials }}</span>
    </q-avatar>
    <div class="person-photo-manager__actions">
      <q-file v-model="file" dense outlined accept="image/png,image/jpeg,image/webp" label="Фото" :loading="loading" @update:model-value="uploadPhoto">
        <template #prepend><Upload :size="15" /></template>
      </q-file>
      <q-btn v-if="photoUrl" flat dense no-caps color="negative" :loading="loading" @click="removePhoto">
        <Trash2 :size="15" class="q-mr-xs" /> Удалить
      </q-btn>
      <div v-else class="person-photo-manager__hint"><Camera :size="14" /> Фото не загружено</div>
    </div>
  </div>
</template>
