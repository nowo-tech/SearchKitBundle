import { resolve } from 'node:path';
import { defineConfig } from 'vite';

/**
 * Library IIFE for `assets:install` (REQ-ASSETS-004).
 * Output: `src/Resources/public/js/nowo-search.js`, served through the `nowo_search_kit`
 * asset package (`asset('js/nowo-search.js', 'nowo_search_kit')`).
 */
export default defineConfig({
  build: {
    outDir: 'src/Resources/public/js',
    emptyOutDir: false,
    lib: {
      entry: resolve(__dirname, 'src/Resources/assets/src/iife.ts'),
      name: 'NowoSearch',
      formats: ['iife'],
      fileName: () => 'nowo-search.js',
    },
    rollupOptions: {
      output: {
        inlineDynamicImports: true,
      },
    },
    minify: true,
    sourcemap: false,
    target: 'es2022',
  },
});
