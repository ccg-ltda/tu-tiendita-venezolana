import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  base: './',
  server: {
    proxy: {
      // La nueva autenticación administrativa se procesa en Laravel.
      '/api/auth': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },

      // El resto de la tienda continúa temporalmente usando Express.
      '/api/products': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },

      '/api': {
        target: 'http://127.0.0.1:3001',
        changeOrigin: true,
      },
    },
  },
});
