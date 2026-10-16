import { afterEach, describe, expect, it, vi } from 'vitest';
import { appendHighlighted, isMac } from '../src/highlight';
import { readRecent, rememberRecent, writeRecent } from '../src/recent';
import { isSafeUrl, isSameSitePath } from '../src/url';

const KEY = 'test.recent';

describe('url helpers', () => {
  it('accepts same-site paths and http(s) URLs only', () => {
    expect(isSameSitePath('/a')).toBe(true);
    expect(isSameSitePath('//evil')).toBe(false);
    expect(isSameSitePath('/\\evil')).toBe(false);
    expect(isSameSitePath('a')).toBe(false);
    expect(isSafeUrl('https://example.com/x')).toBe(true);
    expect(isSafeUrl('HTTP://example.com')).toBe(true);
    expect(isSafeUrl('javascript:alert(1)')).toBe(false);
    expect(isSafeUrl('data:text/html,x')).toBe(false);
    expect(isSafeUrl('https:///x')).toBe(false);
  });
});

describe('recents', () => {
  afterEach(() => vi.restoreAllMocks());

  it('reads only well-formed same-site recents and caps them', () => {
    expect(readRecent(KEY, 8)).toEqual([]);
    window.localStorage.setItem(KEY, '{"not":"a list"}');
    expect(readRecent(KEY, 8)).toEqual([]);
    window.localStorage.setItem(KEY, 'not json');
    expect(readRecent(KEY, 8)).toEqual([]);
    window.localStorage.setItem(
      KEY,
      JSON.stringify([
        { title: 'A', subtitle: 'a', url: '/a', kind: 'issue' },
        { title: 'B', url: '/b', kind: 'evil kind!' },
        { title: 'C', url: 'https://evil.example/c' },
        { title: 'D', url: '//evil.example/d' },
        null,
        'x',
        { title: 4, url: '/d' },
        ...Array.from({ length: 10 }, (_, i) => ({ title: `T${i}`, url: `/t${i}` })),
      ]),
    );
    const rows = readRecent(KEY, 8);
    expect(rows).toHaveLength(8);
    expect(rows[0]).toEqual({ title: 'A', subtitle: 'a', url: '/a', kind: 'issue' });
    expect(rows[1]).toEqual({ title: 'B', subtitle: '', url: '/b', kind: 'page' });
  });

  it('remembers picks on top, once per URL, never off-site, and clears', () => {
    rememberRecent(KEY, { title: 'One', subtitle: '', url: '/1', kind: 'issue' }, 8);
    rememberRecent(KEY, { title: 'Two', subtitle: '', url: '/2' }, 8);
    rememberRecent(KEY, { title: 'Out', subtitle: '', url: 'https://example.com' }, 8);
    rememberRecent(KEY, { title: 'One again', subtitle: 's', url: '/1', kind: 'issue', icon: '<svg>' }, 8);
    expect(readRecent(KEY, 8).map((row) => row.title)).toEqual(['One again', 'Two']);
    expect(window.localStorage.getItem(KEY)).not.toContain('svg');
    expect(readRecent(KEY, 8)[1]?.kind).toBe('page');
    writeRecent(KEY, [], 8);
    expect(window.localStorage.getItem(KEY)).toBeNull();
  });

  it('survives blocked storage', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    expect(() => writeRecent(KEY, [{ title: 'x', subtitle: '', url: '/x' }], 8)).not.toThrow();
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    expect(readRecent(KEY, 8)).toEqual([]);
  });
});

describe('highlight and platform', () => {
  afterEach(() => vi.restoreAllMocks());

  it('marks every case-insensitive match through DOM nodes', () => {
    const el = document.createElement('span');
    appendHighlighted(el, 'Error: error in ERRORS', 'error');
    expect(el.innerHTML).toBe('<mark>Error</mark>: <mark>error</mark> in <mark>ERROR</mark>S');
    const plain = document.createElement('span');
    appendHighlighted(plain, '<b>x</b>', ' ');
    expect(plain.innerHTML).toBe('&lt;b&gt;x&lt;/b&gt;');
    const html = document.createElement('span');
    appendHighlighted(html, '<img src=x onerror=alert(1)>', 'img');
    expect(html.querySelector('img')).toBeNull();
  });

  it('detects Apple platforms', () => {
    vi.spyOn(navigator, 'platform', 'get').mockReturnValue('MacIntel');
    expect(isMac()).toBe(true);
    vi.spyOn(navigator, 'platform', 'get').mockReturnValue('');
    vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue('Linux');
    expect(isMac()).toBe(false);
  });
});
