import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { completeFilter, parseFilterTokens, pendingFilter, removeFilterToken } from '../src/filters';
import { GlobalSearch } from '../src/global-search';
import { readRecentRefs, rememberRecent, rememberRecentRef, writeRecentRefs } from '../src/recent';
import type { SearchConfig } from '../src/types';
import { config, markup, q, root } from './support';

const KEY = 'test.recent';
const DEBOUNCE = 150;
const FILTERS = { keys: ['is', 'assignee', 'type'], valuesUrl: '/search/filters', remove: 'Remove filter', values: 'Suggestions' };

describe('filter helpers', () => {
  it('parses only configured, complete tokens (quoted, negated)', () => {
    expect(parseFilterTokens('bug is:open -assignee:"Ana Gómez" foo:bar at 10:30 type:', ['is', 'assignee', 'type'])).toEqual([
      { key: 'is', value: 'open', negated: false, raw: 'is:open' },
      { key: 'assignee', value: 'Ana Gómez', negated: true, raw: '-assignee:"Ana Gómez"' },
    ]);
    expect(parseFilterTokens('IS:Open', ['is'])).toEqual([{ key: 'is', value: 'Open', negated: false, raw: 'IS:Open' }]);
  });

  it('removes a token and collapses the whitespace', () => {
    expect(removeFilterToken('bug  is:open   login', 'is:open')).toBe('bug login');
    expect(removeFilterToken('bug', 'is:open')).toBe('bug');
  });

  it('finds the filter being typed at the end and completes it', () => {
    expect(pendingFilter('bug assignee:al', ['assignee'])).toEqual({ key: 'assignee', prefix: 'al', negated: false, start: 4 });
    expect(pendingFilter('-is:', ['is'])).toEqual({ key: 'is', prefix: '', negated: true, start: 0 });
    expect(pendingFilter('bug foo:al', ['assignee'])).toBeNull();
    expect(pendingFilter('assignee:al bug', ['assignee'])).toBeNull();
    const pending = pendingFilter('bug -assignee:"An', ['assignee']);
    expect(pending).not.toBeNull();
    expect(completeFilter('bug -assignee:"An', pending!, 'Ana "G" Gómez')).toBe('bug -assignee:"Ana G Gómez" ');
    expect(completeFilter('assignee:a', pendingFilter('assignee:a', ['assignee'])!, 'ana')).toBe('assignee:ana ');
  });
});

describe('recent references', () => {
  afterEach(() => window.localStorage.clear());

  it('stores URL and kind only, reads 1.7 rows as references, and skips off-site URLs', () => {
    rememberRecent(KEY, { title: 'Secret title', subtitle: 'x', url: '/old', kind: 'issues' }, 8);
    rememberRecentRef(KEY, { title: 'Hidden', subtitle: 'y', url: '/new', kind: 'bad kind!' }, 8);
    rememberRecentRef(KEY, { title: 'Off', subtitle: '', url: 'https://evil.test/x' }, 8);
    expect(window.localStorage.getItem(KEY)).toBe('[{"url":"/new","kind":"page"},{"url":"/old","kind":"issues"}]');
    expect(readRecentRefs(KEY, 1)).toEqual([{ url: '/new', kind: 'page' }]);
    rememberRecentRef(KEY, { title: 'T', subtitle: '', url: '/new', kind: 'pages' }, 8);
    expect(readRecentRefs(KEY, 8)).toEqual([
      { url: '/new', kind: 'pages' },
      { url: '/old', kind: 'issues' },
    ]);
    rememberRecentRef(KEY, { title: 'T', subtitle: '', url: '/k' }, 8);
    expect(readRecentRefs(KEY, 8)[0]).toEqual({ url: '/k', kind: 'page' });
    writeRecentRefs(KEY, [], 8);
    expect(window.localStorage.getItem(KEY)).toBeNull();
  });

  it('survives foreign or broken storage values', () => {
    window.localStorage.setItem(KEY, '{"not":"a list"}');
    expect(readRecentRefs(KEY, 8)).toEqual([]);
    window.localStorage.setItem(KEY, '[1, null, {"url": "//evil"}, {"url": "/ok", "kind": 3}]');
    expect(readRecentRefs(KEY, 8)).toEqual([{ url: '/ok', kind: 'page' }]);
    window.localStorage.setItem(KEY, 'not json');
    expect(readRecentRefs(KEY, 8)).toEqual([]);
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota');
    });
    expect(() => writeRecentRefs(KEY, [{ url: '/a', kind: 'page' }], 8)).not.toThrow();
    setItem.mockRestore();
  });
});

describe('GlobalSearch 1.8 palette', () => {
  let search: GlobalSearch;
  let fetchMock: ReturnType<typeof vi.fn>;
  let assign: ReturnType<typeof vi.fn>;

  const start = (cfg: SearchConfig): GlobalSearch => {
    document.body.innerHTML = markup(cfg);
    search = new GlobalSearch(root(), cfg);
    search.start();
    return search;
  };
  const input = (): HTMLInputElement => q<HTMLInputElement>('[data-part="input"]');
  const toggle = (): HTMLButtonElement => q<HTMLButtonElement>('[data-part="toggle"]');
  const options = (): HTMLElement[] => [...document.querySelectorAll<HTMLElement>('[role="option"]')];
  const status = (): string => q('[data-part="status"]').textContent ?? '';
  const chips = (): HTMLButtonElement[] => [...document.querySelectorAll<HTMLButtonElement>('.nowo-search__chip')];
  const type = async (value: string): Promise<void> => {
    input().value = value;
    input().dispatchEvent(new Event('input', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(DEBOUNCE);
    await vi.runAllTimersAsync();
  };
  const reply = (body: unknown, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => body });
  const urlOf = (call: number): URL => new URL(String(fetchMock.mock.calls[call]?.[0]));

  beforeEach(() => {
    vi.useFakeTimers();
    fetchMock = vi.fn().mockResolvedValue(reply({ groups: [] }));
    vi.stubGlobal('fetch', fetchMock);
    assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign, origin: 'https://app.test', pathname: '/projects/acme' });
    Element.prototype.scrollIntoView = vi.fn();
  });

  afterEach(() => {
    search?.destroy();
    window.localStorage.clear();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('sends a ">" query at once (no minimum), with the page path, and shows the actions-only status and shortcuts', async () => {
    fetchMock.mockResolvedValue(
      reply({
        actionsOnly: true,
        groups: [{ key: 'actions', label: 'Actions', total: 1, items: [{ title: 'New project', subtitle: 'Acme', url: '/projects/new', kind: 'action', shortcut: 'g n' }] }],
      }),
    );
    start(config({ actionPrefix: '>', labels: { ...config().labels, actionsOnly: 'Actions only' }, results: { url: '/search/results', label: 'See all results' } }));
    toggle().click();
    await type('>');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(urlOf(0).searchParams.get('q')).toBe('>');
    expect(urlOf(0).searchParams.get('from')).toBe('/projects/acme');
    expect(options().map((o) => o.dataset.title)).toEqual(['New project']);
    expect(options()[0]?.querySelector('.nowo-search__option-shortcut')?.textContent).toBe('g n');
    expect(status()).toBe('Actions only');
    expect(document.querySelector('[data-group="see-all"]')).toBeNull();
  });

  it('ends the results with "See all results" (query and scope), never remembered', async () => {
    fetchMock.mockResolvedValue(reply({ groups: [{ key: 'issues', label: 'Issues', total: 1, items: [{ title: 'Bug', subtitle: '', url: '/i/1' }] }] }));
    start(config({ scope: { id: 'acme', label: 'Acme' }, results: { url: '/search/results', label: 'See all results' } }));
    toggle().click();
    await type('bug');
    expect(urlOf(0).searchParams.has('from')).toBe(false);
    const seeAll = options().at(-1) as HTMLAnchorElement;
    expect(seeAll.dataset.title).toBe('See all results');
    expect(seeAll.getAttribute('href')).toBe('/search/results?q=bug&scope=acme');
    seeAll.click();
    expect(assign).toHaveBeenCalledWith('/search/results?q=bug&scope=acme');
    expect(window.localStorage.getItem(KEY)).toBeNull();
  });

  it('skips an unsafe results URL', async () => {
    fetchMock.mockResolvedValue(reply({ groups: [{ key: 'issues', label: 'Issues', total: 1, items: [{ title: 'Bug', subtitle: '', url: '/i/1' }] }] }));
    start(config({ results: { url: 'javascript:alert(1)', label: 'x' } }));
    toggle().click();
    await type('bug');
    expect(options()).toHaveLength(1);
  });

  it('shows typed filters as chips, removes one on click, and sends a filters-only query', async () => {
    start(config({ filters: FILTERS }));
    toggle().click();
    await type('is:open');
    expect(chips().map((c) => c.textContent)).toEqual(['is:open']);
    // The token is still being typed: the values are asked first; with none, the text is searched as typed.
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(urlOf(0).pathname).toBe('/search/filters');
    expect(urlOf(1).searchParams.get('q')).toBe('is:open');
    await type('bug is:open -assignee:me');
    expect(chips().map((c) => c.dataset.token)).toEqual(['is:open', '-assignee:me']);
    expect(chips()[0]?.getAttribute('aria-label')).toBe('Remove filter: is:open');
    chips()[0]?.click();
    await vi.runAllTimersAsync();
    expect(input().value).toBe('bug -assignee:me');
    expect(chips()).toHaveLength(1);
    q('[data-part="chips"]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(input().value).toBe('bug -assignee:me');
  });

  it('suggests the visible values of a filter being typed and completes it', async () => {
    fetchMock.mockImplementation(async (url: string) =>
      String(url).includes('/search/filters')
        ? reply({ values: [{ value: 'Ana Gómez', label: 'Ana Gómez (you)' }, { value: 'bob', label: '' }, { value: '' }] })
        : reply({ groups: [] }),
    );
    start(config({ filters: FILTERS, scope: { id: 'acme', label: 'Acme' } }));
    toggle().click();
    await type('bug assignee:an');
    const call = urlOf(0);
    expect(call.pathname).toBe('/search/filters');
    expect(call.searchParams.get('key')).toBe('assignee');
    expect(call.searchParams.get('q')).toBe('an');
    expect(call.searchParams.get('scope')).toBe('acme');
    expect(options().map((o) => o.dataset.title)).toEqual(['Ana Gómez (you)', 'bob']);
    expect(options()[0]?.hasAttribute('href')).toBe(false);
    expect(q('[data-group="filter-values"] .nowo-search__group-label').textContent).toBe('Suggestions');
    input().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    await vi.runAllTimersAsync();
    expect(input().value).toBe('bug assignee:"Ana Gómez" ');
    expect(assign).not.toHaveBeenCalled();
    expect(chips().map((c) => c.textContent)).toEqual(['assignee:"Ana Gómez"']);
  });

  it('searches the text as typed when there is no suggestion (failing, empty or aborted answer)', async () => {
    start(config({ filters: FILTERS }));
    toggle().click();
    fetchMock.mockResolvedValue(reply({}, 429));
    await type('is:');
    expect(options()).toHaveLength(0);
    expect(status()).toBe('Slow down');
    fetchMock.mockRejectedValueOnce(new Error('offline')).mockResolvedValue(reply({ groups: [{ key: 'issues', label: 'Issues', total: 1, items: [{ title: 'Open bug', subtitle: '', url: '/i/1' }] }] }));
    await type('is:o');
    expect(options().map((o) => o.dataset.title)).toEqual(['Open bug']);
    fetchMock.mockResolvedValue(reply({ values: 'nope', groups: [] }));
    await type('is:op');
    expect(options()).toHaveLength(0);
    const calls = fetchMock.mock.calls.length;
    fetchMock.mockRejectedValue(new DOMException('aborted', 'AbortError'));
    await type('is:ope');
    expect(fetchMock.mock.calls.length).toBe(calls + 1);
  });

  describe('revalidated recents', () => {
    const revalidating = (): SearchConfig =>
      config({ actions: [], recent: { enabled: true, max: 8, storageKey: KEY, revalidateUrl: '/search/recent', csrfToken: 'tok' } });

    it('shows nothing until the server answers, then only the rows it kept; storage keeps only those references', async () => {
      writeRecentRefs(
        KEY,
        [
          { url: '/allowed', kind: 'pages' },
          { url: '/revoked', kind: 'issues' },
        ],
        8,
      );
      let answer: (value: unknown) => void = () => undefined;
      fetchMock.mockReturnValue(new Promise((resolve) => (answer = resolve)));
      start(revalidating());
      toggle().click();
      expect(options()).toHaveLength(0);
      const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
      expect(url).toBe('https://app.test/search/recent');
      expect(init.method).toBe('POST');
      expect((init.headers as Record<string, string>)['X-CSRF-Token']).toBe('tok');
      expect(JSON.parse(String(init.body))).toEqual({
        refs: [
          { url: '/allowed', kind: 'pages' },
          { url: '/revoked', kind: 'issues' },
        ],
      });
      answer(reply({ items: [{ title: 'Allowed page', subtitle: 'Settings', url: '/allowed', kind: 'pages' }, { title: 'Bad', url: 'javascript:x' }, { url: '/x' }] }));
      await vi.runAllTimersAsync();
      expect(options().map((o) => o.dataset.title)).toEqual(['Allowed page']);
      expect(readRecentRefs(KEY, 8)).toEqual([{ url: '/allowed', kind: 'pages' }]);

      options()[0]?.click();
      expect(window.localStorage.getItem(KEY)).toBe('[{"url":"/allowed","kind":"pages"}]');
    });

    it('a refused or failing check shows nothing; only a real answer prunes the storage', async () => {
      writeRecentRefs(KEY, [{ url: '/a', kind: 'page' }], 8);
      fetchMock.mockResolvedValue(reply({ error: 'rate_limited' }, 429));
      start(revalidating());
      toggle().click();
      await vi.runAllTimersAsync();
      expect(options()).toHaveLength(0);
      expect(readRecentRefs(KEY, 8)).toHaveLength(1);
      search.close();
      fetchMock.mockRejectedValue(new Error('offline'));
      toggle().click();
      await vi.runAllTimersAsync();
      expect(options()).toHaveLength(0);
      expect(readRecentRefs(KEY, 8)).toHaveLength(1);
    });

    it('skips the request without references, and clears the shown rows', async () => {
      start(revalidating());
      toggle().click();
      await vi.runAllTimersAsync();
      expect(fetchMock).not.toHaveBeenCalled();

      search.close();
      writeRecentRefs(KEY, [{ url: '/a', kind: 'page' }], 8);
      fetchMock.mockResolvedValue(reply({ items: [{ title: 'A', url: '/a' }] }));
      toggle().click();
      await vi.runAllTimersAsync();
      expect(options().map((o) => o.dataset.kind)).toEqual(['page']);
      q('[data-nowo-search-clear-recent]').click();
      expect(options()).toHaveLength(0);
      expect(window.localStorage.getItem(KEY)).toBeNull();
    });

    it('a new opening aborts the previous check, and destroy aborts the pending one', async () => {
      writeRecentRefs(KEY, [{ url: '/a', kind: 'page' }], 8);
      fetchMock.mockReturnValue(new Promise(() => undefined));
      start(revalidating());
      toggle().click();
      const first = (fetchMock.mock.calls[0]?.[1] as RequestInit).signal as AbortSignal;
      search.close();
      toggle().click();
      expect(first.aborted).toBe(true);
      const second = (fetchMock.mock.calls[1]?.[1] as RequestInit).signal as AbortSignal;
      search.destroy();
      expect(second.aborted).toBe(true);
    });
  });
});
