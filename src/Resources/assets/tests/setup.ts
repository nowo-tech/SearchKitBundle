import { afterEach } from 'vitest';

afterEach(() => {
  document.body.innerHTML = '';
  document.head.innerHTML = '';
  try {
    window.localStorage.clear();
  } catch {
    // Storage may be mocked to throw by a test.
  }
});
