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
 */
import { mount, unmount } from './index';
import type { SearchOptions } from './types';

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
export function createSearchKitController<T extends StimulusControllerClass>(Base: T, options: SearchOptions = {}): T {
  return class extends Base {
    /** Mounts the search box on the controller element. */
    connect(): void {
      mount(this.element as HTMLElement, options);
    }

    /** Stops the search box of the controller element. */
    disconnect(): void {
      unmount(this.element as HTMLElement);
    }
  };
}
