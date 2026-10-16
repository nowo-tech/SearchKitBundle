# Browser assets

TypeScript sources for the Nowo Search Kit header search box (`index.ts`, `global-search.ts`, the Stimulus wrapper `stimulus.ts`). The built IIFE lives in `src/Resources/public/js/nowo-search.js` and is published by `assets:install` under the `nowo_search_kit` asset package.

This is **not** a standalone npm package. From the repository root:

```bash
pnpm install
pnpm test
pnpm build
```
