import { defineConfig } from 'vite'
import { resolve } from 'node:path'

// Контент-скрипт плашки: единый iife-бандл без импортов (классический скрипт MV3)
export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: false,
    rollupOptions: {
      input: resolve(import.meta.dirname, 'src/content/badge.js'),
      output: {
        format: 'iife',
        entryFileNames: 'badge.js',
      },
    },
  },
})
