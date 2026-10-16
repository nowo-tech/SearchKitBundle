/**
 * Nowo Search Kit — browser entry point. The IIFE build (`nowo-search.js`) mounts the search box on every
 * `[data-nowo-search-config]` element rendered by `@NowoSearchKitBundle/search.html.twig`, and again after Turbo
 * visits. Applications with their own bundler can import `mount` / `GlobalSearch` from the sources, or register the
 * Stimulus wrapper from `./stimulus`.
 */
import { GlobalSearch } from './global-search';
import type { SearchConfig } from './types';

export { GlobalSearch } from './global-search';
export type { NavigateDetail } from './global-search';
export { appendHighlighted, isMac } from './highlight';
export { readRecent, rememberRecent, writeRecent } from './recent';
export { isSafeUrl, isSameSitePath } from './url';
export type { SearchBadge, SearchConfig, SearchGroup, SearchItem, SearchLabels, SearchState } from './types';

/** Package version of the browser bundle. */
export const VERSION = '1.0.1';

/** Prefix of every CSS custom property (theme token) of the search box. */
export const CSS_TOKEN_PREFIX = '--nowo-search-';

const mounted = new WeakMap<HTMLElement, GlobalSearch>();

/**
 * Mounts the search box on one root (idempotent).
 *
 * @param root Element carrying the JSON `data-nowo-search-config`
 * @returns The search box of that root, or null when the configuration or a required part is missing
 */
export function mount(root: HTMLElement): GlobalSearch | null {
  const existing = mounted.get(root);
  if (existing !== undefined) {
    return existing;
  }
  let search: GlobalSearch;
  try {
    search = new GlobalSearch(root, JSON.parse(root.dataset.nowoSearchConfig ?? '') as SearchConfig);
  } catch {
    return null;
  }
  mounted.set(root, search);
  search.start();
  return search;
}

/**
 * Stops the search box of a root (listeners, pending request).
 *
 * @param root Root element previously passed to mount()
 */
export function unmount(root: HTMLElement): void {
  mounted.get(root)?.destroy();
  mounted.delete(root);
}

/**
 * Mounts every search root in a scope.
 *
 * @param scope Where to look for `[data-nowo-search-config]` roots
 */
export function mountAll(scope: ParentNode = document): void {
  scope.querySelectorAll<HTMLElement>('[data-nowo-search-config]').forEach((root) => mount(root));
}

/** Auto start for the IIFE build: document ready, Turbo visits, and cleanup before Turbo swaps the body. */
export function autoStart(): void {
  /** Mounts the roots of the current document. */
  const start = (): void => mountAll();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
  document.addEventListener('turbo:load', start);
  document.addEventListener('turbo:before-render', () => document.querySelectorAll<HTMLElement>('[data-nowo-search-config]').forEach(unmount));
}
