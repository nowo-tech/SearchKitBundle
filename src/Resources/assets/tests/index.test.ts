import { afterEach, describe, expect, it, vi } from 'vitest';
import { autoStart, CSS_TOKEN_PREFIX, GlobalSearch, mount, mountAll, unmount, VERSION } from '../src/index';
import { createSearchKitController, STIMULUS_IDENTIFIER } from '../src/stimulus';
import { config, markup, root } from './support';

describe('mounting', () => {
  afterEach(() => {
    document.querySelectorAll<HTMLElement>('[data-nowo-search-config]').forEach(unmount);
    vi.restoreAllMocks();
  });

  it('mounts once per root and ignores invalid configurations', () => {
    expect(VERSION).toBe('1.0.0');
    expect(CSS_TOKEN_PREFIX).toBe('--nowo-search-');
    document.body.innerHTML = markup();
    const first = mount(root());
    expect(first).toBeInstanceOf(GlobalSearch);
    expect(mount(root())).toBe(first);
    unmount(root());
    unmount(root());

    const broken = document.createElement('div');
    broken.dataset.nowoSearchConfig = '{not json';
    expect(mount(broken)).toBeNull();
    const partless = document.createElement('div');
    partless.dataset.nowoSearchConfig = JSON.stringify(config());
    expect(mount(partless)).toBeNull();
    expect(mount(document.createElement('div'))).toBeNull();
  });

  it('mountAll finds every root', () => {
    document.body.innerHTML = markup();
    mountAll();
    root().querySelector<HTMLButtonElement>('[data-part="toggle"]')?.click();
    expect(root().classList.contains('is-open')).toBe(true);
  });

  it('autoStart mounts on ready and Turbo visits, and unmounts before Turbo renders', () => {
    document.body.innerHTML = markup();
    const spy = vi.spyOn(document, 'readyState', 'get').mockReturnValue('loading');
    autoStart();
    document.dispatchEvent(new Event('DOMContentLoaded'));
    const mounted = mount(root());
    expect(mounted).toBeInstanceOf(GlobalSearch);
    document.dispatchEvent(new Event('turbo:before-render'));
    expect(mount(root())).not.toBe(mounted);
    spy.mockReturnValue('complete');
    autoStart();
    document.dispatchEvent(new Event('turbo:load'));
  });

  it('wraps a Stimulus controller class', () => {
    document.body.innerHTML = markup();
    class Base {
      constructor(readonly element: Element) {}
    }
    const Controller = createSearchKitController(Base);
    const controller = new Controller(root()) as InstanceType<typeof Base> & { connect(): void; disconnect(): void };
    controller.connect();
    root().querySelector<HTMLButtonElement>('[data-part="toggle"]')?.click();
    expect(root().classList.contains('is-open')).toBe(true);
    controller.disconnect();
    expect(STIMULUS_IDENTIFIER).toBe('nowo-search-kit');
  });
});
