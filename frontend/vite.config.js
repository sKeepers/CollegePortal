import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { quasar, transformAssetUrls } from '@quasar/vite-plugin'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  server: {
    proxy: {
      '/api': {
        target: 'http://nginx',
        changeOrigin: true,
      },
      '/storage': {
        target: 'http://nginx',
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
