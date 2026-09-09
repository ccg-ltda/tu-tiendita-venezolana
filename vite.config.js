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

      '/storage': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },

      '/api/admin/products': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },

      // El resto de las rutas API se resuelven en Laravel.
      '/api/products': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },

      '/api/admin/orders': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },

      '/api/orders': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },

      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
});
