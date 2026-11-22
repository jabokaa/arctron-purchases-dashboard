import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

const apiTarget = process.env.API_PROXY_TARGET ?? 'http://localhost:8088'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    // Em volume do Docker (macOS/Windows) os eventos de arquivo não chegam ao container; o polling garante o hot reload
    watch: { usePolling: true },
    // O front chama /api/... e o Vite repassa para o backend (sem precisar de CORS).
    // No Docker o backend é http://backend:8000 (ver docker-compose.yml); fora dele, localhost:8088.
    proxy: {
      '/api': apiTarget,
      // Imagens enviadas para os produtos
      '/uploads': apiTarget,
    },
  },
})
