import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    // O front chama /api/... e o Vite repassa para o backend (sem precisar de CORS).
    // No Docker o backend é http://backend:8000 (ver docker-compose.yml); fora dele, localhost:8088.
    proxy: {
      '/api': process.env.API_PROXY_TARGET ?? 'http://localhost:8088',
    },
  },
})
