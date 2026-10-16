/** Shapes shared by the search box and the endpoint (`GET {prefix}?q=&scope=`). */

/** Optional pill at the end of a row. */
export interface SearchBadge {
  label: string;
  tone: string;
}

/** One row: plain text (escaped and highlighted through DOM nodes), target URL, optional trusted SVG icon. */
export interface SearchItem {
  title: string;
  subtitle: string;
  url: string;
  kind?: string;
  icon?: string | null;
  badge?: SearchBadge | null;
}

/** A titled group of rows with an optional "See all" link. */
export interface SearchGroup {
  key: string;
  label: string;
  total: number;
  items: SearchItem[];
  more?: { url: string; label: string } | null;
}

/** UI strings (`__QUERY__` / `__COUNT__` placeholders are filled here). */
export interface SearchLabels {
  empty: string;
  minChars: string;
  error: string;
  rateLimited: string;
  loading: string;
  results: string;
  recent: string;
  actions: string;
  clearRecent: string;
}

/** JSON of `data-nowo-search-config` (built by `nowo_search_kit_config()`). */
export interface SearchConfig {
  url: string;
  minLength: number;
  maxLength: number;
  debounceMs: number;
  shortcuts: boolean;
  scope: { id: string; label: string } | null;
  actions: SearchItem[];
  recent: { enabled: boolean; max: number; storageKey: string };
  popoverCss: string | null;
  labels: SearchLabels;
}

/** Options of a mounted search box (JavaScript only; the Twig configuration covers everything else). */
export interface SearchOptions {
  /**
   * Loads the dropdown styles on the first hover / focus instead of injecting `popoverCss` — e.g.
   * `() => import('./search-popover.css')` for a bundler chunk. Called once per box.
   */
  loadPopoverStyles?: () => unknown;
}

/** State of the toggle (also `data-state` on the root and the `nowo-search:state` event detail). */
export type SearchState = 'idle' | 'open' | 'loading';
