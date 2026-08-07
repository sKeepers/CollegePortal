<script setup>
import { computed } from 'vue'
import { Clock } from '@lucide/vue'

/*
 * Нативный <input type="time"> показывает время в формате браузера, а не портала:
 * при английской локали браузера получается «09:00 AM». Здесь поле всегда
 * 24-часовое и хранит значение как ЧЧ:ММ, то есть в том же виде, что и раньше.
 */

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  label: {
    type: String,
    default: '',
  },
  dense: {
    type: Boolean,
    default: true,
  },
  outlined: {
    type: Boolean,
    default: true,
  },
  disable: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue'])

const value = computed({
  get: () => props.modelValue || '',
  set: (next) => emit('update:modelValue', next || ''),
})

function isValidTime(input) {
  return !input || /^([01]\d|2[0-3]):[0-5]\d$/.test(input) || 'Время в формате ЧЧ:ММ'
}
</script>

<template>
  <q-input
    v-model="value"
    :dense="dense"
    :outlined="outlined"
    :label="label"
    :disable="disable"
    :rules="[isValidTime]"
    mask="##:##"
    placeholder="ЧЧ:ММ"
    inputmode="numeric"
    hide-bottom-space
  >
    <template #append>
      <span class="app-time-field__picker" role="button" tabindex="0" title="Выбрать время">
        <Clock :size="16" />
        <q-popup-proxy cover transition-show="scale" transition-hide="scale">
          <q-time v-model="value" format24h mask="HH:mm" :disable="disable">
            <div class="row items-center justify-end">
              <q-btn v-close-popup flat color="primary" label="Готово" />
            </div>
          </q-time>
        </q-popup-proxy>
      </span>
    </template>
  </q-input>
</template>

<style scoped>
.app-time-field__picker {
  display: inline-flex;
  align-items: center;
  color: #64748b;
  cursor: pointer;
}

.app-time-field__picker:hover {
  color: #2563eb;
}
</style>
