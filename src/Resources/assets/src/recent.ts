import { isSameSitePath } from './url';
import type { SearchItem } from './types';

/** Kind names a recent row may carry (anything else becomes `page`). */
const KIND = /^[a-z0-9_-]{1,40}$/i;

/**
 * Recent picks of this viewer (per-browser convenience; empty when storage is unavailable or the value is not ours).
 * Only same-site paths are kept, and only text fields — never HTML.
 *
 * @param key localStorage key
 * @param max Maximum rows
 * @returns The recent rows, newest first
 */
export function readRecent(key: string, max: number): SearchItem[] {
  try {
    const parsed: unknown = JSON.parse(window.localStorage.getItem(key) ?? '[]');
    if (!Array.isArray(parsed)) {
      return [];
    }
    return parsed
      .filter(
        (row): row is SearchItem =>
          typeof row === 'object' &&
          row !== null &&
          typeof (row as SearchItem).title === 'string' &&
          typeof (row as SearchItem).url === 'string' &&
          isSameSitePath((row as SearchItem).url),
      )
      .map((row) => ({
        title: row.title,
        subtitle: typeof row.subtitle === 'string' ? row.subtitle : '',
        url: row.url,
        kind: typeof row.kind === 'string' && KIND.test(row.kind) ? row.kind : 'page',
      }))
      .slice(0, max);
  } catch {
    return [];
  }
}

/**
 * Stores the recent rows (removes the key when empty). Failures (private mode, quota) are ignored.
 *
 * @param key localStorage key
 * @param items Rows to store
 * @param max Maximum rows
 */
export function writeRecent(key: string, items: SearchItem[], max: number): void {
  try {
    if (items.length === 0) {
      window.localStorage.removeItem(key);
    } else {
      window.localStorage.setItem(key, JSON.stringify(items.slice(0, max)));
    }
  } catch {
    // Blocked storage: recents are a convenience only.
  }
}

/**
 * Puts a pick on top of the recents (same URL listed once; no icon HTML stored). Off-site URLs are not remembered.
 *
 * @param key localStorage key
 * @param item The picked row
 * @param max Maximum rows
 */
export function rememberRecent(key: string, item: SearchItem, max: number): void {
  if (!isSameSitePath(item.url)) {
    return;
  }
  const entry: SearchItem = { title: item.title, subtitle: item.subtitle, url: item.url, kind: item.kind ?? 'page' };
  writeRecent(key, [entry, ...readRecent(key, max).filter((row) => row.url !== item.url)], max);
}
