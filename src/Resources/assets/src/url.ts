/**
 * Whether a URL is a same-site path (`/…`, not `//host` or `/\host`).
 *
 * @param url The URL
 * @returns True for a same-site path
 */
export function isSameSitePath(url: string): boolean {
  return url.startsWith('/') && !url.startsWith('//') && !url.startsWith('/\\');
}

/**
 * Whether a URL may be a row target: a same-site path or an absolute http(s) URL (never `javascript:` / `data:`).
 *
 * @param url The URL
 * @returns True when safe to navigate to
 */
export function isSafeUrl(url: string): boolean {
  return isSameSitePath(url) || /^https?:\/\/[^\s/]+/i.test(url);
}
