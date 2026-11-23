/** IIFE build entry (`nowo-search.js`): exposes `window.NowoSearch` and mounts every search box automatically. */
import { autoStart } from './index';
import { createBundleLogger } from './logger';

declare const __SEARCH_KIT_BUILD_TIME__: string;

createBundleLogger('search-kit', {
  buildTime: typeof __SEARCH_KIT_BUILD_TIME__ !== 'undefined' ? __SEARCH_KIT_BUILD_TIME__ : undefined,
}).scriptLoaded();

export * from './index';

autoStart();
