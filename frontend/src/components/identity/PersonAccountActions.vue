<script setup>
import { computed, ref } from 'vue'
import { KeyRound, UserPlus } from '@lucide/vue'
import { api } from '../../services/api'

/**
 * Создание и сброс учетной записи прямо в карточке человека. Раньше за этим
 * надо было идти в раздел «Пользователи» и искать человека второй раз.
 *
 * Пароль приходит один раз и здесь же показывается: восстановить его нельзя,
 * поэтому окно предупреждает об этом до нажатия, а не после.
 */
const props = defineProps({
  profileType: { type: String, required: true },
  profileId: { type: [Number, String], default: null },
  hasAccount: { type: Boolean, default: false },
  login: { type: String, default: '' },
})

const busy = ref(false)
const error = ref('')
const confirmReset = ref(false)
const credentials = ref(null)
// Учетную запись только что создали в этой же карточке: кнопка должна сразу
// стать «Сбросить пароль», не дожидаясь перезагрузки списка.
const justCreated = ref(false)

const canAct = computed(() => Boolean(props.profileId))
const accountExists = computed(() => props.hasAccount || justCreated.value)

async function call(path) {
  busy.value = true
  error.value = ''
  try {
    const payload = await api.post(path, { profile_type: props.profileType, profile_id: props.profileId })
    credentials.value = payload?.data || null
  } catch (err) {
    error.value = err.message || 'Не удалось выполнить действие с учетной записью'
  } finally {
    busy.value = false
  }
}

async function createAccount() {
  await call('admin/users/provision')
  if (!error.value) justCreated.value = true
}

function resetPassword() {
  confirmReset.value = false
  call('admin/users/reset-password')
}
</script>

<template>
  <div class="person-account">
    <q-btn
      v-if="!accountExists"
      outline
      no-caps
      color="primary"
      :disable="!canAct"
      :loading="busy"
      @click="createAccount"
    >
      <UserPlus :size="16" class="q-mr-xs" />Создать учетную запись
    </q-btn>

    <q-btn
      v-else
      outline
      no-caps
      :disable="!canAct"
      :loading="busy"
      @click="confirmReset = true"
    >
      <KeyRound :size="16" class="q-mr-xs" />Сбросить пароль
    </q-btn>

    <div v-if="error" class="person-account__error">{{ error }}</div>

    <q-dialog v-model="confirmReset">
      <q-card class="person-account__dialog">
        <q-card-section class="text-h6">Сбросить пароль?</q-card-section>
        <q-card-section>
          Текущий пароль сразу перестанет работать, а новый будет показан один раз и не сохранится нигде.
          Если человек его не запишет, останется только сбросить пароль еще раз.
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Отмена" v-close-popup />
          <q-btn color="primary" label="Сбросить" @click="resetPassword" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog :model-value="Boolean(credentials)" persistent>
      <q-card class="person-account__dialog">
        <q-card-section class="text-h6">Учетная запись: {{ credentials?.name }}</q-card-section>
        <q-card-section>
          <dl class="person-account__creds">
            <dt>Логин</dt>
            <dd>{{ credentials?.login }}</dd>
            <dt>Пароль</dt>
            <dd>{{ credentials?.password }}</dd>
          </dl>
          <q-banner rounded class="bg-orange-1 text-orange-10">
            Пароль показан один раз и нигде не сохранен. Запишите или передайте его сейчас —
            восстановить его нельзя, можно только сбросить заново.
          </q-banner>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn color="primary" label="Я записал, закрыть" @click="credentials = null" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </div>
</template>

<style scoped>
.person-account { display: flex; flex-direction: column; gap: 6px; }
.person-account__error { font-size: 12px; color: var(--q-negative, #c10015); }
.person-account__dialog { min-width: 360px; }
.person-account__creds { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; margin: 0 0 12px; }
.person-account__creds dt { font-size: 12px; opacity: 0.7; }
.person-account__creds dd { margin: 0; font-family: monospace; font-size: 16px; font-weight: 600; }
</style>
