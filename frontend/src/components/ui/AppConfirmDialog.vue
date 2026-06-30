<script setup>
const model = defineModel({ type: Boolean, default: false })

defineProps({
  title: {
    type: String,
    default: 'Подтверждение',
  },
  message: {
    type: String,
    default: 'Подтвердите действие.',
  },
  confirmLabel: {
    type: String,
    default: 'Подтвердить',
  },
  cancelLabel: {
    type: String,
    default: 'Отмена',
  },
  tone: {
    type: String,
    default: 'negative',
  },
})

const emit = defineEmits(['confirm'])
</script>

<template>
  <q-dialog v-model="model">
    <q-card class="app-confirm-dialog">
      <q-card-section>
        <div class="text-h6">{{ title }}</div>
        <p>{{ message }}</p>
      </q-card-section>
      <q-card-actions align="right">
        <q-btn flat :label="cancelLabel" v-close-popup />
        <q-btn :color="tone" :label="confirmLabel" v-close-popup @click="emit('confirm')" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>
