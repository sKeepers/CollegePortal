<script setup>
import { computed, ref, watch } from 'vue'
import { useTrashStore } from '../../stores/trash'

/**
 * Пометить карточку на удаление.
 *
 * Причина обязательна: администратор проверяет заявку, а проверять нечего,
 * если не сказано, что не так с карточкой. Ограничение в пять символов
 * совпадает с серверным — отказ должен приходить до отправки, а не после.
 */
const model = defineModel({ type: Boolean, default: false })

const props = defineProps({
  subjectType: { type: String, required: true },
  subjectId: { type: [Number, String], default: null },
  subjectLabel: { type: String, default: '' },
})

const emit = defineEmits(['requested'])

const trash = useTrashStore()
const reason = ref('')
const sending = ref(false)
const failure = ref('')

const MIN_REASON = 5
const tooShort = computed(() => reason.value.trim().length < MIN_REASON)

watch(model, (open) => {
  if (open) {
    reason.value = ''
    failure.value = ''
  }
})

async function submit() {
  if (tooShort.value || props.subjectId === null) return

  sending.value = true
  failure.value = ''
  try {
    const created = await trash.requestDeletion(props.subjectType, props.subjectId, reason.value.trim())
    model.value = false
    emit('requested', created)
  } catch (err) {
    failure.value = err.message || 'Не удалось отправить заявку'
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <q-dialog v-model="model">
    <q-card class="deletion-request-dialog">
      <q-card-section>
        <div class="text-h6">Пометить на удаление</div>
        <p v-if="subjectLabel" class="deletion-request-dialog__subject">{{ subjectLabel }}</p>
        <p class="deletion-request-dialog__hint">
          Карточка не удалится сразу. Заявку проверит администратор — он удалит карточку или отклонит заявку.
        </p>
      </q-card-section>

      <q-card-section>
        <q-input
          v-model="reason"
          type="textarea"
          autogrow
          outlined
          autofocus
          label="Причина"
          hint="Что не так с карточкой: дубль, ошибка при заведении, лишняя запись"
          :error="reason.length > 0 && tooShort"
          error-message="Опишите причину — не короче пяти символов"
          counter
          maxlength="1000"
        />
        <q-banner v-if="failure" dense class="deletion-request-dialog__error">{{ failure }}</q-banner>
      </q-card-section>

      <q-card-actions align="right">
        <q-btn flat no-caps label="Отмена" :disable="sending" v-close-popup />
        <q-btn
          color="negative"
          no-caps
          label="Отправить заявку"
          :loading="sending"
          :disable="tooShort"
          @click="submit"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.deletion-request-dialog {
  min-width: min(520px, 92vw);
}

.deletion-request-dialog__subject {
  margin: 0.25rem 0 0;
  font-weight: 600;
}

.deletion-request-dialog__hint {
  margin: 0.5rem 0 0;
  color: var(--cp-text-muted, #6b7280);
  font-size: 0.875rem;
}

.deletion-request-dialog__error {
  margin-top: 0.75rem;
  background: var(--cp-negative-soft, #fee2e2);
  color: var(--cp-negative, #b91c1c);
  border-radius: 8px;
}
</style>
