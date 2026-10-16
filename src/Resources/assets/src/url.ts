/** C0 controls, space and DEL: browsers strip TAB / CR / LF inside URLs, so "/\t/host" would become "//host". */
// eslint-disable-next-line no-control-regex -- matching control characters is the point of this guard
const CONTROL_OR_SPACE = /[\u0000-\u0020\u007f]/;

/**
 * Whether a URL is a same-site path (`/…`, not `//host` or `/\host`).
 *
 * @param url The URL
 * @returns True for a same-site path
 */
export function isSameSitePath(url: string): boolean {
  if (CONTROL_OR_SPACE.test(url)) {
    return false;
  }
  return url.startsWith('/') && !url.startsWith('//') && !url.startsWith('/\\');
}

/**
 * Whether a URL may be a row target: a same-site path or an absolute http(s) URL (never `javascript:` / `data:`).
 *
 * @param url The URL
 * @returns True when safe to navigate to
 */
export function isSafeUrl(url: string): boolean {
  return isSameSitePath(url) || (/^https?:\/\/[^\s/]+/i.test(url) && !CONTROL_OR_SPACE.test(url));
}
