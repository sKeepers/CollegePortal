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
const cascade = ref([])
const blockers = ref([])
const checking = ref(false)

const MIN_REASON = 5
const tooShort = computed(() => reason.value.trim().length < MIN_REASON)
const blocked = computed(() => blockers.value.length > 0)

watch(model, async (open) => {
  if (!open) return

  reason.value = ''
  failure.value = ''
  cascade.value = []
  blockers.value = []

  if (props.subjectId === null) return

  // Список связанных записей спрашивается при открытии: решение «удалять или
  // нет» принимается, глядя на то, что уйдёт вместе, а не после отказа сервера.
  checking.value = true
  try {
    const dependents = await trash.loadDependents(props.subjectType, props.subjectId)
    cascade.value = dependents.cascade || []
    blockers.value = dependents.blockers || []
  } catch (err) {
    failure.value = err.message || 'Не удалось узнать, что связано с карточкой'
  } finally {
    checking.value = false
  }
})

async function submit() {
  if (tooShort.value || blocked.value || props.subjectId === null) return

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

      <q-card-section v-if="checking || cascade.length || blockers.length" class="deletion-request-dialog__related">
        <div v-if="checking" class="deletion-request-dialog__hint">Смотрим, что связано с карточкой…</div>

        <template v-if="blockers.length">
          <div class="deletion-request-dialog__title">Удалению мешает</div>
          <ul class="deletion-request-dialog__list">
            <li v-for="row in blockers" :key="`blocker-${row.relation}`">{{ row.label }} — {{ row.count }}</li>
          </ul>
          <p class="deletion-request-dialog__hint">
            Эти записи удаляются отдельно, в своём разделе: за ними стоят поданные документы и выданные дипломы.
            Пока они есть, карточку пометить нельзя.
          </p>
        </template>

        <template v-if="cascade.length">
          <div class="deletion-request-dialog__title">Будет удалено вместе с карточкой</div>
          <ul class="deletion-request-dialog__list">
            <li v-for="row in cascade" :key="`cascade-${row.relation}`">{{ row.label }} — {{ row.count }}</li>
          </ul>
          <p class="deletion-request-dialog__hint">
            Пока карточка в корзине, всё это возвращается вместе с ней: карточки восстанавливаются, вход
            включается обратно, пропуск снова действует.
          </p>
        </template>
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
          :disable="tooShort || blocked || checking"
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

.deletion-request-dialog__related {
  padding-top: 0;
}

.deletion-request-dialog__title {
  margin-top: 0.5rem;
  font-weight: 600;
  font-size: 0.875rem;
}

.deletion-request-dialog__list {
  margin: 0.25rem 0 0;
  padding-left: 1.25rem;
  font-size: 0.875rem;
}

.deletion-request-dialog__error {
  margin-top: 0.75rem;
  background: var(--cp-negative-soft, #fee2e2);
  color: var(--cp-negative, #b91c1c);
  border-radius: 8px;
}
</style>
