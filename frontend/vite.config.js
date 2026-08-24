import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { quasar, transformAssetUrls } from '@quasar/vite-plugin'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
/**
 * Куда сервер разработки отправляет `/api` и `/storage`.
 *
 * По умолчанию — на nginx стенда, как было всегда. Переменной `CP_API_TARGET`
 * цель переводится на свой бэкенд: так смотрят экран, которого на стенде нет,
 * не трогая общие данные. Идти через прокси, а не через
 * `VITE_API_BASE_URL`, важно — тогда страница и API остаются одним источником,
 * cookie входа своя, и ничего не упирается в межсайтовые правила.
 *
 * Этот файл читается **только при старте**: поправили — перезапустите Vite,
 * иначе смотреть будете на прежнюю цель, ничего об этом не зная.
 */
const apiTarget = process.env.CP_API_TARGET || 'http://nginx'

export default defineConfig({
  server: {
    proxy: {
      '/api': {
        target: apiTarget,
        changeOrigin: true,
      },
      '/storage': {
        target: apiTarget,
        changeOrigin: true,
      },
    },
  },
  plugins: [
    vue({
      template: { transformAssetUrls },
    }),
    quasar(),
    tailwindcss(),
  ],
})
