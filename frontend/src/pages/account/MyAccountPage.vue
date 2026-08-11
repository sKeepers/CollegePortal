<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { KeyRound, LogIn, Mail, Phone, UserRound } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import { formatPhone } from '../../utils/phone'
import { useAccountStore } from '../../stores/account'

const store = useAccountStore()
const $q = useQuasar()

const contacts = reactive({ email: '', phone: '' })
const password = reactive({ current_password: '', password: '', password_confirmation: '' })
const showPassword = ref(false)

const account = computed(() => store.account)

// Требования владельца от 11.08.2026. Проверка здесь — подсказка, а не защита:
// решает всё равно сервер, `App\Rules\SelfChosenPassword`.
const PASSWORD_MIN = 6
const passwordProblem = computed(() => {
  if (!password.password) return ''
  if (password.password.length < PASSWORD_MIN) return `Не короче ${PASSWORD_MIN} символов`
  if (!/^[\x21-\x7E]+$/.test(password.password)) return 'Только латиница, без пробелов'
  if (!/[A-Z]/.test(password.password)) return 'Нужна хотя бы одна заглавная латинская буква'
  return ''
})
const passwordValid = computed(() =>
  Boolean(password.current_password && password.password && !passwordProblem.value && password.password === password.password_confirmation),
)

// Пароль выдан порталом: предложение видно, пока человек не заведёт свой.
const issuedPassword = computed(() => Boolean(account.value?.must_change_password))

watch(account, (value) => {
  contacts.email = value?.email || ''
  contacts.phone = value?.phone || ''
}, { immediate: true })

onMounted(async () => {
  await store.load()
  await store.loadIdentities()
})

// Отвязка требует текущего пароля: перехваченной сессии мало, чтобы снять чужой
// способ входа. Тем же паролем подтверждается и привязка.
function askUnlink(identity) {
  $q.dialog({
    title: 'Отвязать способ входа?',
    message: `${identity.provider_name} перестанет открывать вход в портал. Подтвердите текущим паролем.`,
    prompt: { model: '', type: 'password', label: 'Текущий пароль' },
    cancel: true,
    persistent: true,
  }).onOk(async (currentPassword) => {
    await store.unlinkIdentity(identity.id, currentPassword)
    $q.notify({ type: 'positive', message: 'Способ входа отвязан' })
  })
}

async function saveContacts() {
  // Пустое поле здесь значит «очистить»: человек видит, что стирает.
  await store.saveContacts({ email: contacts.email || null, phone: contacts.phone || null })
  $q.notify({ type: 'positive', message: 'Контакты сохранены' })
}

async function savePassword() {
  await store.changePassword({ ...password })
  Object.assign(password, { current_password: '', password: '', password_confirmation: '' })
  showPassword.value = false
  $q.notify({ type: 'positive', message: 'Пароль изменён' })
}

function formatDateTime(value) {
  if (!value) return 'нет данных'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ru-RU')
}
</script>

<template>
  <AppPage>
    <PageHeader title="Моя учётная запись" subtitle="Здесь вы меняете свои контакты и пароль. ФИО и документы правит кадровая или учебная часть." />
    <AppErrorBanner :message="store.error" />
    <AppLoading v-if="store.loading" label="Загрузка учётной записи..." />

    <div v-else-if="account" class="account-grid">
      <q-banner v-if="issuedPassword" rounded class="bg-orange-1 text-orange-10 account-issued-banner">
        <template #avatar><KeyRound :size="20" /></template>
        Вы входите с паролем, который выдал портал: его знаете не только вы — он был напечатан на карточке
        или передан вам администратором. Заведите свой в блоке «Пароль» ниже. Прямо сейчас это не обязательно,
        но пока вы этого не сделали, напоминание будет появляться при каждом входе.
      </q-banner>

      <AppCard>
        <template #header><div class="account-card-title"><UserRound :size="18" /> Кто вы в портале</div></template>
        <dl class="account-details">
          <div><dt>Имя</dt><dd>{{ account.name || '—' }}</dd></div>
          <div><dt>Вход</dt><dd>{{ account.login || '—' }}</dd></div>
          <div><dt>Роль</dt><dd>{{ account.role || '—' }}</dd></div>
          <div><dt>Последний вход</dt><dd>{{ formatDateTime(account.last_login_at) }}</dd></div>
        </dl>
      </AppCard>

      <AppCard>
        <template #header><div class="account-card-title"><Mail :size="18" /> Контакты</div></template>
        <p v-if="!account.has_person" class="account-note">
          К учётной записи не привязана личная карточка, поэтому контакты хранить негде. Обратитесь к администратору.
        </p>
        <template v-else>
          <div class="account-form">
            <q-input v-model="contacts.email" outlined dense type="email" label="Email" />
            <q-input v-model="contacts.phone" outlined dense label="Телефон">
              <template #prepend><Phone :size="16" /></template>
              <template #hint>Сейчас: {{ formatPhone(account.phone, 'не указан') }}</template>
            </q-input>
          </div>
          <p class="account-note">
            Это общие данные человека: исправление здесь появится и в вашей карточке преподавателя, студента или сотрудника.
          </p>
          <div class="account-actions">
            <q-btn color="primary" no-caps :loading="store.saving" @click="saveContacts">Сохранить контакты</q-btn>
          </div>
        </template>
      </AppCard>

      <AppCard>
        <template #header><div class="account-card-title"><LogIn :size="18" /> Способы входа</div></template>
        <q-list v-if="store.identities.length" dense separator>
          <q-item v-for="identity in store.identities" :key="identity.id">
            <q-item-section>
              <q-item-label>{{ identity.provider_name }}</q-item-label>
              <q-item-label caption>{{ identity.display_name || 'аккаунт привязан' }}</q-item-label>
            </q-item-section>
            <q-item-section side>
              <q-btn flat dense no-caps color="negative" :disable="store.saving" @click="askUnlink(identity)">Отвязать</q-btn>
            </q-item-section>
          </q-item>
        </q-list>
        <p v-else class="account-note">
          Кроме пароля, других способов входа пока нет.
          <template v-if="!store.availableProviders.length">
            Вход через Telegram появится отдельной задачей — тогда его можно будет привязать здесь.
          </template>
        </p>
      </AppCard>

      <AppCard>
        <template #header><div class="account-card-title"><KeyRound :size="18" /> Пароль</div></template>
        <template v-if="!showPassword">
          <p class="account-note">Пароль меняется только вами и только с подтверждением текущего.</p>
          <div class="account-actions">
            <q-btn :outline="!issuedPassword" :color="issuedPassword ? 'primary' : undefined" no-caps @click="showPassword = true">
              {{ issuedPassword ? 'Создать свой пароль' : 'Изменить пароль' }}
            </q-btn>
          </div>
        </template>
        <template v-else>
          <div class="account-form">
            <q-input v-model="password.current_password" outlined dense type="password" label="Текущий пароль" autocomplete="current-password"
              :hint="issuedPassword ? 'Тот, что выдал портал' : ''" />
            <q-input
              v-model="password.password"
              outlined dense type="password" label="Новый пароль" autocomplete="new-password"
              :error="Boolean(passwordProblem)"
              :error-message="passwordProblem"
              hint="Не короче 6 символов, латиница, есть заглавная буква"
            />
            <q-input v-model="password.password_confirmation" outlined dense type="password" label="Новый пароль ещё раз" autocomplete="new-password" />
          </div>
          <div class="account-actions">
            <q-btn flat no-caps @click="showPassword = false">Отмена</q-btn>
            <q-btn color="primary" no-caps :disable="!passwordValid" :loading="store.saving" @click="savePassword">Сохранить пароль</q-btn>
          </div>
        </template>
      </AppCard>
    </div>
  </AppPage>
</template>

<style scoped>
.account-grid { display: grid; gap: 16px; max-width: 720px; }
.account-card-title { display: flex; align-items: center; gap: 8px; font-weight: 600; }
.account-details { display: grid; gap: 10px; margin: 0; }
.account-details div { display: grid; grid-template-columns: 180px 1fr; gap: 8px; }
.account-details dt { color: #64748b; font-size: 13px; }
.account-details dd { margin: 0; color: #0f172a; overflow-wrap: anywhere; }
.account-form { display: grid; gap: 12px; }
.account-note { color: #64748b; font-size: 13px; margin: 12px 0 0; }
.account-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
@media (max-width: 640px) {
  .account-details div { grid-template-columns: 1fr; gap: 2px; }
}
</style>
