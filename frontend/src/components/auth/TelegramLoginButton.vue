<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Кнопка Telegram Login Widget.
 *
 * Виджет нельзя нарисовать самим: Telegram отдаёт его скриптом, который вставляет
 * свой iframe и сам ведёт окно подтверждения. Наше дело — вставить скрипт с нужными
 * атрибутами и принять ответ.
 *
 * **Виджет появляется только на домене, записанном у бота через `/setdomain`, и только
 * по публично доверенному HTTPS.** На стенде с самоподписанным сертификатом кнопки не
 * будет вовсе — это ограничение Telegram, а не наша ошибка, поэтому рядом показывается
 * пояснение, а не пустое место.
 */
const props = defineProps({
  botUsername: { type: String, required: true },
  size: { type: String, default: 'large' },
})

const emit = defineEmits(['authorized'])

const container = ref(null)
const failed = ref(false)
let callbackName = ''

onMounted(() => {
  if (!props.botUsername || !container.value) {
    failed.value = true
    return
  }

  // Виджет зовёт функцию по имени из глобальной области — своего способа передать
  // обработчик у него нет. Имя уникальное: на странице может быть две кнопки.
  callbackName = `onTelegramAuth_${Math.random().toString(36).slice(2)}`
  window[callbackName] = (user) => emit('authorized', user)

  const script = document.createElement('script')
  script.src = 'https://telegram.org/js/telegram-widget.js?22'
  script.async = true
  script.setAttribute('data-telegram-login', props.botUsername)
  script.setAttribute('data-size', props.size)
  script.setAttribute('data-userpic', 'false')
  script.setAttribute('data-request-access', 'write')
  script.setAttribute('data-onauth', `${callbackName}(user)`)
  script.onerror = () => { failed.value = true }
  container.value.appendChild(script)
})

onBeforeUnmount(() => {
  if (callbackName) delete window[callbackName]
})
</script>

<template>
  <div class="telegram-login">
    <div ref="container" />
    <p v-if="failed" class="telegram-login__note">
      Кнопка Telegram не загрузилась. Она работает только на публичном адресе портала с доверенным сертификатом —
      на стенде её не будет.
    </p>
  </div>
</template>

<style scoped>
.telegram-login { display: grid; gap: 6px; justify-items: start; }
.telegram-login__note { margin: 0; font-size: 12px; color: var(--q-color-grey-7, #666); }
</style>
