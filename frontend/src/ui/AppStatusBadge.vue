<script setup>
const props = defineProps({
  label: {
    type: String,
    required: true,
  },
  tone: {
    type: String,
    default: 'neutral',
    validator: (value) => ['neutral', 'info', 'success', 'warning', 'danger'].includes(value),
  },
})

const toneClasses = {
  neutral: 'app-status-badge--neutral',
  info: 'app-status-badge--info',
  success: 'app-status-badge--success',
  warning: 'app-status-badge--warning',
  danger: 'app-status-badge--danger',
}
</script>

<template>
  <q-badge rounded :class="['app-status-badge', toneClasses[props.tone]]">
    {{ label }}
  </q-badge>
</template>
