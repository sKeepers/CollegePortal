<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { Bell, KeyRound, LogIn, Mail, Phone, UserRound } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import TelegramLoginButton from '../../components/auth/TelegramLoginButton.vue'
import { formatPhone } from '../../utils/phone'
import { useAccountStore } from '../../stores/account'
import { formatDateTime as formatCollegeDateTime } from '../../utils/datetime'

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

// Показываем кнопку привязки, только если Telegram подключён и ещё не привязан.
const telegramToLink = computed(() => {
  const linked = store.identities.some((identity) => identity.provider === 'telegram')
  return linked ? null : store.availableProviders.find((provider) => provider.code === 'telegram')
})

watch(account, (value) => {
  contacts.email = value?.email || ''
  contacts.phone = value?.phone || ''
}, { immediate: true })

onMounted(async () => {
  await store.load()
  await store.loadIdentities()
  // Отказ здесь не должен закрывать раздел: уведомления могут быть не подключены,
  // а почта и пароль от этого не зависят.
  await store.loadNotifications().catch(() => {})
})

// Уведомления. Канал один — MAX; галочка без начатого диалога это обещание,
// которое портал не выполнит, поэтому состояния показываются раздельно.
const notifyChannel = computed(() => store.notifications?.channels?.[0] || null)
const watchers = computed(() => store.notifications?.watchers || [])
const linkCode = ref(null)

function isSubscribed(event) {
  return (store.notifications?.subscribed || []).includes(`${event.code}|${notifyChannel.value?.code}`)
}

async function toggleNotification(event, enabled) {
  await store.setNotification(event.code, notifyChannel.value.code, enabled)
}

async function showLinkCode() {
  linkCode.value = await store.requestLinkCode()
}

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

// Привязка подтверждается текущим паролем: перехваченной сессии мало, чтобы прицепить
// свой мессенджер к чужой учётной записи и получить постоянный вход.
function askLink(telegramUser) {
  $q.dialog({
    title: 'Привязать Telegram?',
    message: 'Этот аккаунт станет открывать вход в портал. Подтвердите текущим паролем.',
    prompt: { model: '', type: 'password', label: 'Текущий пароль' },
    cancel: true,
    persistent: true,
  }).onOk(async (currentPassword) => {
    await store.linkIdentity('telegram', telegramUser, currentPassword)
    $q.notify({ type: 'positive', message: 'Telegram привязан' })
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
  return formatCollegeDateTime(value, {}, 'нет данных')
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
          <!--
            Карта показывается списком, а не одним полем: у четверых людей их
            две-три (замер 29.08.2026), и «номер карты» соврал бы на первой же
            такой карточке. Состояние стоит рядом с номером — у кого карту
            забрали, не должен видеть её действующей.

            Поля нет вовсе, если у смотрящего нет права `rfid.cards.view`:
            отсутствие поля честнее прочерка, который читался бы как «карты
            нет».
          -->
          <div v-if="account.rfid_cards"><dt>Карта СКУД</dt><dd>
            <template v-if="account.rfid_cards.length">
              <!--
                Номер и состояние — разными строками, и между картами отступ.
                В одну строку они не влезают: колонка значения узкая, и
                «9799887766 (основная) — На руках» рвалось на три строки, а
                три карты подряд читались кашей. Замечено глазами 29.08.2026;
                в разметке этого не видно.
              -->
              <div v-for="card in account.rfid_cards" :key="card.id" class="q-mb-xs">
                <div style="white-space: nowrap">{{ card.uid }}</div>
                <div class="text-caption text-grey-7">
                  {{ card.status_label || card.status }}<template v-if="card.label"> · {{ card.label }}</template>
                </div>
              </div>
            </template>
            <template v-else>карта не выдана</template>
          </dd></div>
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
            Вход через мессенджер на этом портале не подключён.
          </template>
        </p>

        <!-- Привязать можно только то, что ещё не привязано: у человека один Telegram,
             а не пять, и это же ограничение стоит в базе. -->
        <div v-if="telegramToLink" class="account-link-provider">
          <p class="account-note">
            Привяжите Telegram, чтобы входить без пароля. Портал сохранит только идентификатор аккаунта
            и то, как вас показать, — ни телефона, ни фотографии.
          </p>
          <TelegramLoginButton :bot-username="telegramToLink.config.bot_username" @authorized="askLink" />
        </div>
      </AppCard>

      <AppCard v-if="notifyChannel">
        <template #header><div class="account-card-title"><Bell :size="18" /> Уведомления в {{ notifyChannel.name }}</div></template>

        <template v-if="!notifyChannel.chat_ready">
          <p class="account-note">
            Бот не может написать первым — сначала нужно с ним поздороваться. Возьмите код, откройте бота
            в {{ notifyChannel.name }}, нажмите «Старт» и отправьте код сообщением.
          </p>
          <div class="account-actions">
            <q-btn outline no-caps :loading="store.saving" @click="showLinkCode">Получить код привязки</q-btn>
          </div>
          <div v-if="linkCode" class="account-link-code">
            <strong>{{ linkCode.code }}</strong>
            <span>отправьте боту {{ linkCode.bot_username ? '@' + linkCode.bot_username : '' }} — код действует 15 минут</span>
          </div>
        </template>

        <template v-else>
          <p class="account-note">
            Приходит только то, что отмечено. Снятая галочка прекращает отправку сразу.
          </p>
          <q-list dense>
            <q-item v-for="event in store.notifications.events" :key="event.code">
              <q-item-section>
                <q-item-label>{{ event.name }}</q-item-label>
                <q-item-label caption>{{ event.hint }}<template v-if="!event.ready"> · пока не отправляется</template></q-item-label>
              </q-item-section>
              <q-item-section side>
                <q-toggle
                  :model-value="isSubscribed(event)"
                  :disable="!event.ready || store.saving"
                  @update:model-value="(value) => toggleNotification(event, value)"
                />
              </q-item-section>
            </q-item>
          </q-list>
        </template>

        <!-- Отключить эти уведомления человек не может — так решил владелец, — но
             узнавать о них случайно не должен: скрытая рассылка о себе обнаруживается
             в худший момент. Показываем независимо от того, начат ли его собственный
             диалог с ботом: пишут-то не ему. -->
        <q-banner v-if="watchers.length" rounded class="bg-blue-1 text-blue-10 account-watchers">
          Уведомления о вас получают: {{ watchers.map((watcher) => watcher.name || 'без имени').join(', ') }}.
          Отключить их можно только распоряжением директора — обратитесь в учебную часть.
        </q-banner>
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
.account-link-provider { display: grid; gap: 8px; margin-top: 12px; }
.account-link-code { display: grid; gap: 4px; margin-top: 12px; }
.account-link-code strong { font-size: 24px; letter-spacing: 4px; font-family: monospace; }
.account-link-code span { color: #64748b; font-size: 13px; }
.account-watchers { margin-top: 12px; }
.account-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
@media (max-width: 640px) {
  .account-details div { grid-template-columns: 1fr; gap: 2px; }
}
</style>
