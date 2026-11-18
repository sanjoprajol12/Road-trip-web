import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import vueDevTools from 'vite-plugin-vue-devtools'

// The public site / rider platform SPA lives in resources/app. Laravel serves the built files
// (public/app) and the API from one origin. In development Vite (:5180) forwards /api and
// /sanctum to `php artisan serve --port=8010`, so cookies stay first-party there too.
const api = { target: 'http://127.0.0.1:8010', changeOrigin: false }

export default defineConfig(({ command }) => ({
  root: fileURLToPath(new URL('./resources/app', import.meta.url)),
  base: command === 'build' ? '/app/' : '/',
  publicDir: fileURLToPath(new URL('./public', import.meta.url)),
  plugins: [vue(), tailwindcss(), vueDevTools()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/app/src', import.meta.url)),
    },
  },
  build: {
    outDir: fileURLToPath(new URL('./public/app', import.meta.url)),
    emptyOutDir: true,
    // Laravel's public/ is served as-is; copying it into the build would duplicate everything.
    copyPublicDir: false,
  },
  // Built JS/CSS load from /app/; files from Laravel's public/ (photos, favicon) stay at the site root.
  experimental: { renderBuiltUrl: (filename: string, { type }: { type: 'public' | 'asset' }) => (type === 'public' ? `/${filename}` : undefined) },
  server: { port: 5180, strictPort: true, proxy: { '/api': api, '/sanctum': api } },
}))
