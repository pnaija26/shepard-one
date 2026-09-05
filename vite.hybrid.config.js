import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'node:path';

/**
 * Story 12.1: Capacitor webDir build (mobile/www).
 * Usage: npm run hybrid:build
 */
export default defineConfig({
  root: resolve(__dirname, 'resources/hybrid'),
  publicDir: false,
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'resources/js'),
    },
  },
  build: {
    outDir: resolve(__dirname, 'mobile/www'),
    emptyOutDir: true,
    sourcemap: true,
  },
});
