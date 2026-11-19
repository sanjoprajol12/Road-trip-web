import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import vueDevTools from 'vite-plugin-vue-devtools'

// Public website and rider platform. Dev :5180, preview :4180, build ./dist.
// /api and /sanctum are proxied to the Laravel API (API_TARGET, default the local `composer dev` on :8010), so the
// session cookie stays first-party. In production the host does the same with rewrites (vercel.json).
export default defineConfig(({ mode }) => {
  const env = { ...loadEnv(mode, process.cwd(), ''), ...process.env }
  const api = { target: env.API_TARGET || 'http://127.0.0.1:8010', changeOrigin: false }
  const proxy = { '/api': api, '/sanctum': api }
  return {
    plugins: [vue(), tailwindcss(), ...(mode === 'development' ? [vueDevTools()] : [])],
    resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
    server: { port: 5180, strictPort: true, proxy },
    preview: { port: 4180, strictPort: true, proxy },
  }
})
