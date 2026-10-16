import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { GlobalSearch } from '../src/global-search';
import { readRecent, rememberRecent, writeRecent } from '../src/recent';
import type { SearchConfig } from '../src/types';
import { config, markup, q, root } from './support';

const KEY = 'test.recent';
const DEBOUNCE = 150;

const GROUPS = {
  groups: [
    {
      key: 'issues',
      label: 'Issues',
      total: 7,
      items: [
        { title: 'Timeout in checkout', subtitle: 'Acme · timeout', url: '/i/1', kind: 'issues', icon: '<svg class="server-icon"></svg>', badge: { label: 'error', tone: 'error' } },
        { title: 'Other', subtitle: '', url: '/i/2', kind: 'issues', icon: null, badge: null },
        { title: 'Evil', subtitle: '', url: 'javascript:alert(1)' },
      ],
      more: { url: '/projects/p/issues?q=timeout', label: 'See all 7 issues' },
    },
    { key: 'pa ges', label: 'Pages', total: 1, items: [{ title: 'No icon', subtitle: 'x', url: '/w' }], more: { url: 'javascript:x', label: 'bad' } },
  ],
};

describe('GlobalSearch', () => {
  let search: GlobalSearch;
  let fetchMock: ReturnType<typeof vi.fn>;
  let assign: ReturnType<typeof vi.fn>;
  let openWindow: ReturnType<typeof vi.fn>;
  let states: string[];

  const start = (cfg: SearchConfig = config(), id = 'nowo-search'): GlobalSearch => {
    document.body.innerHTML = markup(cfg, id);
    states = [];
    root().addEventListener('nowo-search:state', (event) => states.push((event as CustomEvent<{ state: string }>).detail.state));
    search = new GlobalSearch(root(), cfg);
    search.start();
    return search;
  };
  const input = (): HTMLInputElement => q<HTMLInputElement>('[data-part="input"]');
  const toggle = (): HTMLButtonElement => q<HTMLButtonElement>('[data-part="toggle"]');
  const popover = (): HTMLElement => q('[data-part="popover"]');
  const options = (): HTMLAnchorElement[] => [...document.querySelectorAll<HTMLAnchorElement>('[role="option"]')];
  const status = (): string => q('[data-part="status"]').textContent ?? '';
  const isOpen = (): boolean => root().classList.contains('is-open');
  const key = (k: string, init: KeyboardEventInit = {}, target: EventTarget = input()): KeyboardEvent => {
    const event = new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...init });
    target.dispatchEvent(event);
    return event;
  };
  const type = async (value: string): Promise<void> => {
    input().value = value;
    input().dispatchEvent(new Event('input', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(DEBOUNCE);
    await vi.runAllTimersAsync();
  };

  beforeEach(() => {
    vi.useFakeTimers();
    fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => GROUPS });
    vi.stubGlobal('fetch', fetchMock);
    assign = vi.fn();
    openWindow = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign, origin: 'https://app.test' });
    vi.stubGlobal('open', openWindow);
    Element.prototype.scrollIntoView = vi.fn();
  });

  afterEach(() => {
    search?.destroy();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('fails fast on a root without the required parts', () => {
    document.body.innerHTML = '<div class="nowo-search"></div>';
    expect(() => new GlobalSearch(root(), config())).toThrow('missing [data-part="toggle"]');
  });

  it('opens from the toggle with recents and safe quick actions, and folds back', () => {
    rememberRecent(KEY, { title: 'Seen before', subtitle: 'Acme', url: '/i/9', kind: 'page' }, 8);
    start();
    toggle().click();
    expect(isOpen()).toBe(true);
    expect(root().dataset.state).toBe('open');
    expect(toggle().getAttribute('aria-expanded')).toBe('true');
    expect(document.activeElement).toBe(input());
    expect(input().tabIndex).toBe(0);
    expect(popover().hidden).toBe(false);
    expect(options().map((o) => o.textContent)).toEqual(['Seen beforeAcme↵', 'Dashboard↵']);
    expect(options()[0]?.querySelector('svg.kind-page')).not.toBeNull();
    expect(options()[1]?.querySelector('svg.kind-action')).not.toBeNull();
    expect(options()[0]?.getAttribute('aria-selected')).toBe('true');
    expect(fetchMock).not.toHaveBeenCalled();

    toggle().click();
    expect(isOpen()).toBe(false);
    expect(popover().hidden).toBe(true);
    expect(document.activeElement).toBe(toggle());
    expect(input().tabIndex).toBe(-1);
    expect(states, 'the initial state is announced once after mount').toEqual(['idle', 'open', 'idle']);
  });

  it('clears the recents from their group header, and hides recents when disabled', () => {
    rememberRecent(KEY, { title: 'Seen', subtitle: '', url: '/s' }, 8);
    start();
    toggle().click();
    q<HTMLButtonElement>('[data-nowo-search-clear-recent]').click();
    expect(readRecent(KEY, 8)).toEqual([]);
    expect(options().map((o) => o.dataset.title)).toEqual(['Dashboard']);
    search.destroy();

    rememberRecent(KEY, { title: 'Seen', subtitle: '', url: '/s' }, 8);
    start(config({ recent: { enabled: false, max: 8, storageKey: KEY }, actions: [] }));
    toggle().click();
    expect(options()).toEqual([]);
    expect(popover().hidden).toBe(true);
  });

  it('opens with Ctrl/⌘ K anywhere and "/" outside fields, never twice', () => {
    start();
    const typing = key('/', {}, q('#elsewhere'));
    expect(typing.defaultPrevented).toBe(false);
    expect(isOpen()).toBe(false);
    const prevented = new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, cancelable: true, bubbles: true });
    prevented.preventDefault();
    document.dispatchEvent(prevented);
    expect(isOpen()).toBe(false);
    key('k', { altKey: true, ctrlKey: true }, document.body);
    expect(isOpen()).toBe(false);

    expect(key('/', {}, document.body).defaultPrevented).toBe(true);
    expect(isOpen()).toBe(true);
    key('Escape');
    expect(isOpen()).toBe(false);
    expect(key('K', { metaKey: true }, document.body).defaultPrevented).toBe(true);
    expect(isOpen()).toBe(true);
    key('k', { ctrlKey: true }, document.body);
    expect(isOpen()).toBe(true);
  });

  it('ignores the shortcuts when they are turned off', () => {
    start(config({ shortcuts: false }));
    expect(key('k', { ctrlKey: true }, document.body).defaultPrevented).toBe(false);
    expect(isOpen()).toBe(false);
  });

  it('closes when the phone backdrop (the root itself) is tapped', () => {
    start();
    toggle().click();
    root().dispatchEvent(new Event('pointerdown', { bubbles: true }));
    expect(isOpen()).toBe(false);
  });

  it('lets the host load the dropdown styles (bundler chunk) instead of the link', async () => {
    document.body.innerHTML = markup(config());
    const loader = vi.fn(() => Promise.reject(new Error('chunk failed')));
    search = new GlobalSearch(root(), config(), { loadPopoverStyles: loader });
    search.start();
    root().dispatchEvent(new Event('pointerenter'));
    root().dispatchEvent(new Event('focusin'));
    await vi.runAllTimersAsync();
    expect(loader).toHaveBeenCalledTimes(1);
    expect(document.querySelectorAll('link[data-nowo-search-popover]')).toHaveLength(0);
  });

  it('announces the open state on mount when the root is already open', () => {
    document.body.innerHTML = markup(config());
    root().classList.add('is-open');
    const seen: string[] = [];
    root().addEventListener('nowo-search:state', (event) => seen.push((event as CustomEvent<{ state: string }>).detail.state));
    search = new GlobalSearch(root(), config());
    search.start();
    expect(seen).toEqual(['open']);
  });

  it('closes on a click outside, not inside', () => {
    start();
    toggle().click();
    input().dispatchEvent(new Event('pointerdown', { bubbles: true }));
    expect(isOpen()).toBe(true);
    q('#outside').dispatchEvent(new Event('pointerdown', { bubbles: true }));
    expect(isOpen()).toBe(false);
    expect(document.activeElement).not.toBe(toggle());
    q('#outside').dispatchEvent(new Event('pointerdown', { bubbles: true }));
    search.close();
    expect(isOpen()).toBe(false);
  });

  it('asks for the minimum length, then fetches grouped results with highlights, badges and a see-all row', async () => {
    start();
    toggle().click();
    await type('t');
    expect(status()).toBe('Type 2');
    expect(fetchMock).not.toHaveBeenCalled();

    await type('timeout');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(String(fetchMock.mock.calls[0]?.[0])).toBe('https://app.test/search?q=timeout');
    expect(options().map((o) => o.dataset.title)).toEqual(['Timeout in checkout', 'Other', 'See all 7 issues', 'No icon']);
    const first = options()[0] as HTMLAnchorElement;
    expect(first.querySelector('mark')?.textContent).toBe('Timeout');
    expect(first.querySelector('.server-icon')).not.toBeNull();
    expect(first.querySelector('.nowo-search__badge--error')?.textContent).toBe('error');
    expect(options()[1]?.querySelector('svg.kind-item')).not.toBeNull();
    expect(options()[2]?.classList.contains('nowo-search__option--more')).toBe(true);
    expect(options()[3]?.closest('[data-group]')?.getAttribute('data-group')).toBe('pages');
    expect(options()[3]?.id).toBe('nowo-search-option-3');
    expect(status()).toBe('3 results');
    expect(input().getAttribute('aria-expanded')).toBe('true');
    expect(input().getAttribute('aria-activedescendant')).toBe(first.id);
    expect(root().dataset.state).toBe('open');
    expect(states).toContain('loading');
  });

  it('moves with the arrows (wrapping), follows the pointer and opens with Enter', async () => {
    start();
    toggle().click();
    await type('timeout');
    key('ArrowUp');
    expect(options()[3]?.getAttribute('aria-selected')).toBe('true');
    key('ArrowDown');
    expect(options()[0]?.getAttribute('aria-selected')).toBe('true');
    key('ArrowDown');
    options()[2]?.querySelector('span')?.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
    expect(options()[2]?.getAttribute('aria-selected')).toBe('true');
    options()[2]?.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
    q('[data-part="results"]').dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
    key('ArrowUp');
    key('Enter');
    expect(assign).toHaveBeenCalledWith('/i/2');
    expect(readRecent(KEY, 8)[0]).toEqual({ title: 'Other', subtitle: '', url: '/i/2', kind: 'issues' });
    expect(isOpen()).toBe(false);
  });

  it('opens a new tab with Ctrl/⌘, does not remember the see-all row, and clicks pick options', async () => {
    start();
    toggle().click();
    await type('timeout');
    key('ArrowDown');
    key('ArrowDown');
    key('Enter', { metaKey: true });
    expect(openWindow).toHaveBeenCalledWith('/projects/p/issues?q=timeout', '_blank', 'noopener');
    expect(readRecent(KEY, 8)).toEqual([]);
    expect(isOpen()).toBe(true);

    q('.nowo-search__option-title').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }));
    expect(openWindow).toHaveBeenLastCalledWith('/i/1', '_blank', 'noopener');
    q('[data-part="results"]').click();
    options()[1]?.click();
    expect(assign).toHaveBeenCalledWith('/i/2');
  });

  it('lets the host take over navigation (nowo-search:navigate)', async () => {
    start(config({ recent: { enabled: false, max: 8, storageKey: KEY } }));
    root().addEventListener('nowo-search:navigate', (event) => event.preventDefault());
    toggle().click();
    await type('timeout');
    key('Enter');
    expect(assign).not.toHaveBeenCalled();
    expect(isOpen()).toBe(false);
    expect(readRecent(KEY, 8)).toEqual([]);
  });

  it('ignores Enter / arrows without options and options without a safe URL', async () => {
    fetchMock.mockResolvedValue({ ok: true, status: 200, json: async () => ({}) });
    start(config({ actions: [] }));
    toggle().click();
    writeRecent(KEY, [], 8);
    await type('zz');
    expect(status()).toBe('No results for “zz”');
    expect(q('.nowo-search__empty').textContent).toBe('No results for “zz”');
    expect(popover().hidden).toBe(false);
    key('ArrowDown');
    key('Enter');
    expect(assign).not.toHaveBeenCalled();
    key('a');
    await type('z$&');
    expect(status()).toBe('No results for “z$&”');

    const ghost = document.createElement('a');
    ghost.setAttribute('role', 'option');
    q('[data-part="results"]').append(ghost);
    ghost.click();
    expect(assign).not.toHaveBeenCalled();
  });

  it('shows an error when the request fails, a rate-limit message on 429, and aborts stale requests', async () => {
    fetchMock.mockResolvedValueOnce({ ok: false, status: 500, json: async () => ({}) });
    start();
    toggle().click();
    await type('timeout');
    expect(status()).toBe('Unavailable');

    fetchMock.mockResolvedValueOnce({ ok: false, status: 429, json: async () => ({ error: 'rate_limited' }) });
    await type('timeouts');
    expect(status()).toBe('Slow down');

    fetchMock.mockRejectedValueOnce(new Error('offline'));
    await type('timeoutss');
    expect(status()).toBe('Unavailable');

    fetchMock.mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ groups: 'nope' }) });
    await type('timeoutsss');
    expect(status()).toBe('No results for “timeoutsss”');

    let release: (value: unknown) => void = () => undefined;
    fetchMock.mockImplementationOnce(
      (_url: string, init: RequestInit) =>
        new Promise((resolve, reject) => {
          release = resolve;
          init.signal?.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
        }),
    );
    input().value = 'slow';
    input().dispatchEvent(new Event('input', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(DEBOUNCE);
    expect(root().dataset.state).toBe('loading');
    expect(q('[data-part="results"]').getAttribute('aria-busy')).toBe('true');
    toggle().click();
    await vi.runAllTimersAsync();
    expect(root().dataset.state).toBe('idle');
    release({ ok: true, status: 200, json: async () => GROUPS });
    expect(popover().hidden).toBe(true);
  });

  it('a result arriving after closing never reopens the dropdown', async () => {
    let release: (value: unknown) => void = () => undefined;
    fetchMock.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
    start();
    toggle().click();
    input().value = 'late';
    input().dispatchEvent(new Event('input', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(DEBOUNCE);
    root().classList.remove('is-open');
    release({ ok: true, status: 200, json: async () => GROUPS });
    await vi.runAllTimersAsync();
    expect(popover().hidden).toBe(true);
  });

  it('scopes the search until the chip is removed (button or Backspace)', async () => {
    const scoped = config({ scope: { id: 'p1', label: 'Shop' } });
    start(scoped);
    toggle().click();
    await type('timeout');
    expect(String(fetchMock.mock.calls[0]?.[0])).toBe('https://app.test/search?q=timeout&scope=p1');
    q<HTMLButtonElement>('[data-part="scope-remove"]').click();
    await vi.runAllTimersAsync();
    expect(q('[data-part="scope"]').hidden).toBe(true);
    expect(String(fetchMock.mock.calls.at(-1)?.[0])).toBe('https://app.test/search?q=timeout');
    search.destroy();

    start(scoped);
    toggle().click();
    input().value = '';
    expect(key('Backspace').defaultPrevented).toBe(true);
    expect(q('[data-part="scope"]').hidden).toBe(true);
    expect(key('Backspace').defaultPrevented).toBe(false);
    search.destroy();

    // A scope without its chip markup still works.
    document.body.innerHTML = markup(config());
    search = new GlobalSearch(root(), scoped);
    search.start();
    search.removeScope();
    expect(document.activeElement).toBe(input());
  });

  it('shows ⌘ on Apple keyboards, injects the dropdown stylesheet once and stops listening when destroyed', () => {
    vi.spyOn(navigator, 'platform', 'get').mockReturnValue('MacIntel');
    start();
    expect(q('[data-part="shortcut"]').textContent).toBe('⌘ K');
    expect(q('[data-part="mod-key"]').textContent).toBe('⌘');
    root().dispatchEvent(new Event('pointerenter'));
    root().dispatchEvent(new Event('focusin'));
    expect(document.querySelectorAll('link[data-nowo-search-popover]')).toHaveLength(1);
    expect(q<HTMLLinkElement>('link[data-nowo-search-popover]').getAttribute('href')).toBe('/bundles/nowosearchkit/css/nowo-search-popover.css');
    search.destroy();
    key('k', { ctrlKey: true }, document.body);
    expect(isOpen()).toBe(false);

    // A second box on the page does not inject the stylesheet again; no stylesheet configured injects nothing.
    start(config(), '');
    search.preload();
    expect(document.querySelectorAll('link[data-nowo-search-popover]')).toHaveLength(1);
    document.head.innerHTML = '';
    start(config({ popoverCss: null }));
    search.preload();
    expect(document.querySelectorAll('link[data-nowo-search-popover]')).toHaveLength(0);
  });

  it('uses ids from the root and works when the browser cannot scroll', async () => {
    start(config(), 'header-search');
    Element.prototype.scrollIntoView = undefined as unknown as Element['scrollIntoView'];
    toggle().click();
    await type('timeout');
    key('ArrowDown');
    expect(options()[0]?.id).toBe('header-search-option-0');
    expect(q('[data-group="issues"] .nowo-search__group-label').id).toBe('header-search-group-issues');
  });
});
