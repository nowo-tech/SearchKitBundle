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
    expect(VERSION).toBe('1.0.2');
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

  it('mountAll finds every root and passes the options', () => {
    document.body.innerHTML = markup();
    const loadPopoverStyles = vi.fn();
    mountAll(document, { loadPopoverStyles });
    root().dispatchEvent(new Event('pointerenter'));
    root().querySelector<HTMLButtonElement>('[data-part="toggle"]')?.click();
    expect(root().classList.contains('is-open')).toBe(true);
    return Promise.resolve().then(() => expect(loadPopoverStyles).toHaveBeenCalledTimes(1));
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
    const Controller = createSearchKitController(Base, { loadPopoverStyles: () => undefined });
    const controller = new Controller(root()) as InstanceType<typeof Base> & { connect(): void; disconnect(): void };
    controller.connect();
    root().querySelector<HTMLButtonElement>('[data-part="toggle"]')?.click();
    expect(root().classList.contains('is-open')).toBe(true);
    controller.disconnect();
    expect(STIMULUS_IDENTIFIER).toBe('nowo-search-kit');
  });

  it('reports every state to onState, the initial one included, until disconnect', () => {
    document.body.innerHTML = markup();
    class Base {
      constructor(readonly element: Element) {}
    }
    const states: string[] = [];
    const Controller = createSearchKitController(Base, { onState: (state, element) => states.push(`${state}:${element === root()}`) });
    const controller = new Controller(root()) as InstanceType<typeof Base> & { connect(): void; disconnect(): void };
    controller.connect();
    root().querySelector<HTMLButtonElement>('[data-part="toggle"]')?.click();
    expect(states).toEqual(['idle:true', 'open:true']);
    controller.disconnect();
    root().dispatchEvent(new CustomEvent('nowo-search:state', { detail: { state: 'loading' } }));
    expect(states).toHaveLength(2);

    const silent = new (createSearchKitController(Base))(root()) as InstanceType<typeof Base> & { connect(): void; disconnect(): void };
    silent.connect();
    silent.disconnect();
  });
});
