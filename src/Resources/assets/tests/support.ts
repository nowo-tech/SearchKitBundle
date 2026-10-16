import type { SearchConfig } from '../src/types';

export const LABELS = {
  empty: 'No results for “__QUERY__”',
  minChars: 'Type 2',
  error: 'Unavailable',
  rateLimited: 'Slow down',
  loading: 'Searching',
  results: '__COUNT__ results',
  recent: 'Recent',
  actions: 'Quick actions',
  clearRecent: 'Clear',
};

export function config(overrides: Partial<SearchConfig> = {}): SearchConfig {
  return {
    url: '/search',
    minLength: 2,
    maxLength: 200,
    debounceMs: 150,
    shortcuts: true,
    scope: null,
    actions: [
      { title: 'Dashboard', subtitle: '', url: '/dashboard', kind: 'action' },
      { title: 'Evil', subtitle: '', url: 'javascript:alert(1)', kind: 'action' },
    ],
    recent: { enabled: true, max: 8, storageKey: 'test.recent' },
    popoverCss: '/bundles/nowosearchkit/css/nowo-search-popover.css',
    labels: LABELS,
    ...overrides,
  };
}

/** Markup of the header partial (same data-part contract as search.html.twig). */
export function markup(cfg: SearchConfig = config(), id = 'nowo-search'): string {
  const json = JSON.stringify(cfg).replace(/'/g, '&#39;');
  return `
    <button id="outside">outside</button>
    <input id="elsewhere">
    <div class="nowo-search" ${id ? `id="${id}"` : ''} data-state="idle" data-nowo-search-config='${json}'>
      <div class="nowo-search__box" data-part="box">
        <button type="button" data-part="toggle" aria-expanded="false">t</button>
        <div data-part="field">
          ${cfg.scope ? '<span data-part="scope"><button type="button" data-part="scope-remove" tabindex="-1">x</button></span>' : ''}
          <input type="search" data-part="input" tabindex="-1">
          <kbd data-part="shortcut">Ctrl K</kbd>
        </div>
      </div>
      <div data-part="popover" hidden>
        <div data-part="results"></div>
        <kbd data-part="mod-key">Ctrl</kbd>
      </div>
      <p data-part="status"></p>
      <template data-nowo-search-icon="action"><svg class="kind-action"></svg></template>
      <template data-nowo-search-icon="page"><svg class="kind-page"></svg></template>
      <template data-nowo-search-icon="item"><svg class="kind-item"></svg></template>
    </div>`;
}

export function root(): HTMLElement {
  const el = document.querySelector<HTMLElement>('.nowo-search');
  if (!el) {
    throw new Error('no root');
  }
  return el;
}

export function q<T extends Element = HTMLElement>(selector: string): T {
  const el = document.querySelector<T>(selector);
  if (!el) {
    throw new Error(`missing ${selector}`);
  }
  return el;
}
