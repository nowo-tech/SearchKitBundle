/**
 * Stimulus wrapper (no runtime dependency on Stimulus: pass your own `Controller` class).
 *
 * ```ts
 * import { Controller } from '@hotwired/stimulus';
 * import { createSearchKitController } from '<vendor>/nowo-tech/search-kit-bundle/src/Resources/assets/src/stimulus';
 * application.register('nowo-search-kit', createSearchKitController(Controller));
 * ```
 *
 * Add `data-controller="nowo-search-kit"` on the root (`nowo_search_root_attr` include variable) and turn
 * `assets.script` off so the IIFE does not mount the same root.
 *
 * `onState` (1.2): called with every `nowo-search:state` (idle / open / loading, the initial one included) — e.g. to
 * morph the toggle icon — so a host needs no controller of its own:
 *
 * ```ts
 * createSearchKitController(Controller, { loadPopoverStyles: () => import('./popover.css'), onState: (state, root) => … })
 * ```
 */
import { mount, unmount } from './index';
import type { SearchOptions, SearchState } from './types';

/** Options of the Stimulus controller: the search options plus a state hook. */
export type SearchKitControllerOptions = SearchOptions & {
  /** Called on every state change of the box (and once with the initial state on mount). */
  onState?: (state: SearchState, root: HTMLElement) => void;
};

/** Minimal shape of a Stimulus controller class (mixin constructor). */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type StimulusControllerClass = new (...args: any[]) => { readonly element: Element };

/** Identifier the documentation registers the controller under. */
export const STIMULUS_IDENTIFIER = 'nowo-search-kit';

/**
 * Builds a Stimulus controller that mounts the search box on `connect()` and stops it on `disconnect()`.
 *
 * @param Base Stimulus `Controller` class
 * @param options JavaScript-only options (e.g. `loadPopoverStyles: () => import('./search-popover.css')`)
 * @returns The controller class to register
 */
export function createSearchKitController<T extends StimulusControllerClass>(Base: T, options: SearchKitControllerOptions = {}): T {
  const { onState, ...searchOptions } = options;

  return class extends Base {
    /** Forwards `nowo-search:state` to `onState`. */
    private readonly nowoSearchState = (event: Event): void => {
      onState?.((event as CustomEvent<{ state: SearchState }>).detail.state, this.element as HTMLElement);
    };

    /** Mounts the search box on the controller element. */
    connect(): void {
      this.element.addEventListener('nowo-search:state', this.nowoSearchState);
      mount(this.element as HTMLElement, searchOptions);
    }

    /** Stops the search box of the controller element. */
    disconnect(): void {
      this.element.removeEventListener('nowo-search:state', this.nowoSearchState);
      unmount(this.element as HTMLElement);
    }
  };
}
