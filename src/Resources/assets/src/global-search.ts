import { appendHighlighted, isMac } from './highlight';
import { readRecent, rememberRecent, writeRecent } from './recent';
import { isSafeUrl } from './url';
import type { SearchConfig, SearchGroup, SearchItem, SearchState } from './types';

/** Group keys rendered from the config (not from the endpoint): their rows use the kind `<template>` icons. */
const LOCAL_GROUPS = new Set(['recent', 'actions']);

/** Detail of the cancelable `nowo-search:navigate` event (cancel it to navigate yourself, e.g. with Turbo). */
export interface NavigateDetail {
  url: string;
  newTab: boolean;
}

/**
 * The header search box on one root (`@NowoSearchKitBundle/search.html.twig`).
 *
 * A magnifier that stretches into a field (toggle, Ctrl/⌘ K, "/" outside fields) with the results in a dropdown
 * under it. Debounced, aborted fetches to the endpoint; grouped rows navigated with the arrow keys (ARIA combobox +
 * listbox); Enter opens, Ctrl/⌘ Enter opens a new tab; Esc or a click outside folds it back. With a short query it
 * lists the viewer's recent picks and the quick actions. The dropdown stylesheet is injected on first hover / focus.
 *
 * Events on the root: `nowo-search:state` (`{state}`: idle / open / loading — hook a morphing icon on it) and the
 * cancelable `nowo-search:navigate` (`{url, newTab}`).
 */
export class GlobalSearch {
  private readonly toggle: HTMLButtonElement;
  private readonly field: HTMLElement;
  private readonly input: HTMLInputElement;
  private readonly popover: HTMLElement;
  private readonly results: HTMLElement;
  private readonly status: HTMLElement;
  private readonly scope: HTMLElement | null;
  private readonly idPrefix: string;
  private scopeId: string;
  private timer: number | undefined;
  private request: AbortController | null = null;
  private active = -1;
  private stylesLoaded = false;
  private readonly listeners: Array<[EventTarget, string, EventListener]> = [];

  /**
   * @param root Element rendered by the header partial (carries `data-nowo-search-config`)
   * @param config Parsed configuration
   */
  constructor(
    private readonly root: HTMLElement,
    private readonly config: SearchConfig,
  ) {
    this.toggle = this.part<HTMLButtonElement>('toggle');
    this.field = this.part('field');
    this.input = this.part<HTMLInputElement>('input');
    this.popover = this.part('popover');
    this.results = this.part('results');
    this.status = this.part('status');
    this.scope = root.querySelector<HTMLElement>('[data-part="scope"]');
    this.idPrefix = root.id !== '' ? root.id : 'nowo-search';
    this.scopeId = config.scope?.id ?? '';
  }

  /** Whether the field is stretched open. */
  get isOpen(): boolean {
    return this.root.classList.contains('is-open');
  }

  /** Wires the listeners and the platform key hints. */
  start(): void {
    this.listen(document, 'keydown', (event) => this.globalKey(event as KeyboardEvent));
    this.listen(document, 'pointerdown', (event) => this.outside(event));
    this.listen(this.root, 'pointerenter', () => this.preload());
    this.listen(this.root, 'focusin', () => this.preload());
    this.listen(this.toggle, 'click', () => (this.isOpen ? this.close() : this.open()));
    this.listen(this.input, 'input', () => this.debounce());
    this.listen(this.input, 'keydown', (event) => this.keydown(event as KeyboardEvent));
    this.listen(this.results, 'click', (event) => this.choose(event as MouseEvent));
    this.listen(this.results, 'mousemove', (event) => this.hover(event));
    const remove = this.root.querySelector('[data-part="scope-remove"]');
    if (remove) {
      this.listen(remove, 'click', () => this.removeScope());
    }
    if (isMac()) {
      this.root.querySelectorAll('[data-part="shortcut"]').forEach((el) => (el.textContent = '⌘ K'));
      this.root.querySelectorAll('[data-part="mod-key"]').forEach((el) => (el.textContent = '⌘'));
    }
    this.setState('idle');
  }

  /** Removes every listener and aborts a pending request. */
  destroy(): void {
    this.cancel();
    this.listeners.forEach(([target, type, listener]) => target.removeEventListener(type, listener));
    this.listeners.length = 0;
  }

  /** Stretches the field, focuses it and shows recents / actions (or the results of the current text). */
  open(): void {
    this.preload();
    if (!this.isOpen) {
      this.root.classList.add('is-open');
      this.toggle.setAttribute('aria-expanded', 'true');
      this.setState('open');
      this.setFieldTabbable(true);
      this.refresh();
    }
    this.input.focus();
    this.input.select();
  }

  /**
   * Folds back to the magnifier.
   *
   * @param restoreFocus Move the focus back to the toggle (false when the user clicked elsewhere)
   */
  close(restoreFocus = true): void {
    if (!this.isOpen) {
      return;
    }
    this.cancel();
    this.root.classList.remove('is-open');
    this.toggle.setAttribute('aria-expanded', 'false');
    this.setState('idle');
    this.setFieldTabbable(false);
    this.popover.hidden = true;
    this.input.setAttribute('aria-expanded', 'false');
    if (restoreFocus) {
      this.toggle.focus();
    }
  }

  /** Drops the scope chip: the next searches cover everything. */
  removeScope(): void {
    this.scopeId = '';
    if (this.scope) {
      this.scope.hidden = true;
    }
    this.input.focus();
    this.refresh();
  }

  /** Injects the lazy dropdown stylesheet once. */
  preload(): void {
    if (this.stylesLoaded) {
      return;
    }
    this.stylesLoaded = true;
    const href = this.config.popoverCss;
    if (href && !document.querySelector(`link[data-nowo-search-popover]`)) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.dataset.nowoSearchPopover = '';
      document.head.append(link);
    }
  }

  /** Re-renders for the current text: recents + actions when short, otherwise fetch. */
  refresh(): void {
    window.clearTimeout(this.timer);
    const query = this.input.value.trim();
    if (query.length < this.config.minLength) {
      this.cancel();
      this.renderIdle(query);
      return;
    }
    void this.fetchResults(query);
  }

  /** Ctrl/⌘ K anywhere, "/" when the focus is not in a field. */
  private globalKey(event: KeyboardEvent): void {
    if (!this.config.shortcuts || event.defaultPrevented) {
      return;
    }
    const shortcut = (event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k';
    const target = event.target as HTMLElement | null;
    const typing = target !== null && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
    const slash = event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !typing;
    if (shortcut || slash) {
      event.preventDefault();
      this.open();
    }
  }

  private outside(event: Event): void {
    if (this.isOpen && !this.root.contains(event.target as Node | null)) {
      this.close(false);
    }
  }

  private debounce(): void {
    window.clearTimeout(this.timer);
    this.timer = window.setTimeout(() => this.refresh(), this.config.debounceMs);
  }

  private keydown(event: KeyboardEvent): void {
    const options = this.options();
    if (event.key === 'Escape') {
      event.preventDefault();
      this.close();
      return;
    }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (options.length > 0) {
        const step = event.key === 'ArrowDown' ? 1 : -1;
        this.highlight((this.active + step + options.length) % options.length);
      }
      return;
    }
    if (event.key === 'Enter') {
      event.preventDefault();
      const option = options[this.active] ?? options[0];
      if (option) {
        this.go(option, event.ctrlKey || event.metaKey);
      }
      return;
    }
    if (event.key === 'Backspace' && this.input.value === '' && this.scopeId !== '') {
      event.preventDefault();
      this.removeScope();
    }
  }

  private choose(event: MouseEvent): void {
    const target = event.target as Element | null;
    if (target?.closest('[data-nowo-search-clear-recent]')) {
      event.preventDefault();
      writeRecent(this.config.recent.storageKey, [], this.config.recent.max);
      this.refresh();
      this.input.focus();
      return;
    }
    const option = target?.closest<HTMLAnchorElement>('[role="option"]');
    if (option) {
      event.preventDefault();
      this.go(option, event.ctrlKey || event.metaKey);
    }
  }

  private hover(event: Event): void {
    const option = (event.target as Element | null)?.closest<HTMLElement>('[role="option"]');
    const index = option ? this.options().indexOf(option) : -1;
    if (index !== -1 && index !== this.active) {
      this.highlight(index, false);
    }
  }

  private async fetchResults(query: string): Promise<void> {
    this.cancel();
    const request = new AbortController();
    this.request = request;
    this.setLoading(true);
    const url = new URL(this.config.url, window.location.origin);
    url.searchParams.set('q', query);
    if (this.scopeId !== '') {
      url.searchParams.set('scope', this.scopeId);
    }
    try {
      const response = await fetch(url.toString(), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: request.signal,
      });
      if (response.status === 429) {
        this.renderMessage(this.config.labels.rateLimited);
        return;
      }
      if (!response.ok) {
        throw new Error(String(response.status));
      }
      const data = (await response.json()) as { groups?: SearchGroup[] };
      this.renderGroups(Array.isArray(data.groups) ? data.groups : [], query);
    } catch (error) {
      if ((error as Error).name === 'AbortError') {
        return;
      }
      this.renderMessage(this.config.labels.error);
    } finally {
      if (this.request === request) {
        this.request = null;
        this.setLoading(false);
      }
    }
  }

  private cancel(): void {
    window.clearTimeout(this.timer);
    if (this.request) {
      this.request.abort();
      this.request = null;
      this.setLoading(false);
    }
  }

  private setLoading(loading: boolean): void {
    this.setState(loading ? 'loading' : this.isOpen ? 'open' : 'idle');
    this.results.setAttribute('aria-busy', loading ? 'true' : 'false');
  }

  private setState(state: SearchState): void {
    if (this.root.dataset.state === state) {
      return;
    }
    this.root.dataset.state = state;
    this.root.dispatchEvent(new CustomEvent('nowo-search:state', { bubbles: true, detail: { state } }));
  }

  private setFieldTabbable(on: boolean): void {
    this.input.tabIndex = on ? 0 : -1;
    this.field.querySelectorAll<HTMLElement>('button').forEach((button) => (button.tabIndex = on ? 0 : -1));
  }

  private renderIdle(query: string): void {
    const groups: SearchGroup[] = [];
    const recent = this.config.recent.enabled ? readRecent(this.config.recent.storageKey, this.config.recent.max) : [];
    if (recent.length > 0) {
      groups.push({ key: 'recent', label: this.config.labels.recent, total: recent.length, items: recent });
    }
    const actions = this.config.actions.filter((action) => isSafeUrl(action.url));
    if (actions.length > 0) {
      groups.push({ key: 'actions', label: this.config.labels.actions, total: actions.length, items: actions });
    }
    this.renderList(groups, '');
    this.status.textContent = query.length > 0 ? this.config.labels.minChars : '';
    this.syncPopover();
  }

  /** The dropdown shows only while open and with something to show. */
  private syncPopover(): void {
    this.popover.hidden = !this.isOpen || this.results.childElementCount === 0;
  }

  private renderGroups(groups: SearchGroup[], query: string): void {
    if (groups.length === 0) {
      this.renderMessage(this.config.labels.empty.replace('__QUERY__', () => query));
      return;
    }
    this.renderList(groups, query);
    const count = groups.reduce((sum, group) => sum + group.items.filter((row) => isSafeUrl(row.url)).length, 0);
    this.status.textContent = this.config.labels.results.replace('__COUNT__', () => String(count));
    this.syncPopover();
  }

  private renderMessage(message: string): void {
    this.renderList([], '');
    const empty = document.createElement('p');
    empty.className = 'nowo-search__empty';
    empty.textContent = message;
    this.results.append(empty);
    this.status.textContent = message;
    this.syncPopover();
  }

  private renderList(groups: SearchGroup[], query: string): void {
    this.results.replaceChildren();
    this.active = -1;
    let index = 0;
    for (const group of groups) {
      const key = group.key.replace(/[^a-z0-9_-]/gi, '');
      const section = document.createElement('div');
      section.className = 'nowo-search__group';
      section.setAttribute('role', 'group');
      section.dataset.group = key;
      const heading = document.createElement('div');
      heading.className = 'nowo-search__group-label';
      heading.id = `${this.idPrefix}-group-${key}`;
      heading.textContent = group.label;
      section.setAttribute('aria-labelledby', heading.id);
      if (key === 'recent') {
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'nowo-search__group-action';
        clear.dataset.nowoSearchClearRecent = '';
        clear.textContent = this.config.labels.clearRecent;
        heading.append(clear);
      }
      section.append(heading);
      for (const item of group.items.filter((row) => isSafeUrl(row.url))) {
        section.append(this.option(item, key, query, index++));
      }
      if (group.more && isSafeUrl(group.more.url)) {
        section.append(this.option({ title: group.more.label, subtitle: '', url: group.more.url, kind: 'action' }, key, '', index++, true));
      }
      this.results.append(section);
    }
    const hasOptions = index > 0;
    this.input.setAttribute('aria-expanded', hasOptions ? 'true' : 'false');
    this.input.removeAttribute('aria-activedescendant');
    if (hasOptions) {
      this.highlight(0, false);
    }
  }

  private option(item: SearchItem, groupKey: string, query: string, index: number, more = false): HTMLAnchorElement {
    const kind = (item.kind ?? groupKey).replace(/[^a-z0-9_-]/gi, '');
    const link = document.createElement('a');
    link.className = more ? 'nowo-search__option nowo-search__option--more' : 'nowo-search__option';
    link.href = item.url;
    link.id = `${this.idPrefix}-option-${index}`;
    link.tabIndex = -1;
    link.setAttribute('role', 'option');
    link.setAttribute('aria-selected', 'false');
    link.dataset.title = item.title;
    link.dataset.subtitle = item.subtitle;
    link.dataset.kind = kind;
    if (more) {
      link.dataset.more = '';
    }

    const icon = document.createElement('span');
    icon.className = 'nowo-search__option-icon';
    icon.setAttribute('aria-hidden', 'true');
    if (item.icon && !LOCAL_GROUPS.has(groupKey)) {
      // Server-rendered SVG from the application's own icon set (UX Icons), never user text.
      // eslint-disable-next-line no-restricted-syntax
      icon.innerHTML = item.icon;
    } else {
      const template =
        this.root.querySelector<HTMLTemplateElement>(`template[data-nowo-search-icon="${kind}"]`) ??
        this.root.querySelector<HTMLTemplateElement>('template[data-nowo-search-icon="item"]');
      if (template) {
        icon.append(template.content.cloneNode(true));
      }
    }
    link.append(icon);

    const text = document.createElement('span');
    text.className = 'nowo-search__option-text';
    const title = document.createElement('span');
    title.className = 'nowo-search__option-title';
    appendHighlighted(title, item.title, query);
    text.append(title);
    if (item.subtitle !== '') {
      const subtitle = document.createElement('span');
      subtitle.className = 'nowo-search__option-subtitle';
      appendHighlighted(subtitle, item.subtitle, query);
      text.append(subtitle);
    }
    link.append(text);

    if (item.badge) {
      const badge = document.createElement('span');
      badge.className = `nowo-search__badge nowo-search__badge--${item.badge.tone.replace(/[^a-z0-9_-]/gi, '')}`;
      badge.textContent = item.badge.label;
      link.append(badge);
    }
    const enter = document.createElement('kbd');
    enter.className = 'nowo-search__option-enter';
    enter.setAttribute('aria-hidden', 'true');
    enter.textContent = '↵';
    link.append(enter);

    return link;
  }

  private options(): HTMLElement[] {
    return Array.from(this.results.querySelectorAll<HTMLElement>('[role="option"]'));
  }

  private highlight(index: number, scroll = true): void {
    const options = this.options();
    options.forEach((option, i) => option.setAttribute('aria-selected', i === index ? 'true' : 'false'));
    this.active = index;
    const option = options[index];
    if (option) {
      this.input.setAttribute('aria-activedescendant', option.id);
      if (scroll && typeof option.scrollIntoView === 'function') {
        option.scrollIntoView({ block: 'nearest' });
      }
    }
  }

  private go(option: HTMLElement, newTab: boolean): void {
    const url = option.getAttribute('href') ?? '';
    if (!isSafeUrl(url)) {
      return;
    }
    if (this.config.recent.enabled && !('more' in option.dataset)) {
      rememberRecent(
        this.config.recent.storageKey,
        { title: option.dataset.title ?? '', subtitle: option.dataset.subtitle ?? '', url, kind: option.dataset.kind ?? 'page' },
        this.config.recent.max,
      );
    }
    const detail: NavigateDetail = { url, newTab };
    const proceed = this.root.dispatchEvent(new CustomEvent('nowo-search:navigate', { bubbles: true, cancelable: true, detail }));
    if (!proceed) {
      this.close(false);
      return;
    }
    if (newTab) {
      window.open(url, '_blank', 'noopener');
      return;
    }
    this.close(false);
    window.location.assign(url);
  }

  private listen(target: EventTarget, type: string, listener: EventListener): void {
    target.addEventListener(type, listener);
    this.listeners.push([target, type, listener]);
  }

  private part<T extends HTMLElement = HTMLElement>(name: string): T {
    const el = this.root.querySelector<T>(`[data-part="${name}"]`);
    if (!el) {
      throw new Error(`nowo-search: missing [data-part="${name}"]`);
    }
    return el;
  }
}
