/**
 * Appends `text` to `parent`, wrapping every case-insensitive match of `query` in `<mark>`. Built with DOM nodes
 * only: the text is never parsed as HTML.
 *
 * @param parent Element to append to
 * @param text Plain text
 * @param query Text to mark (trimmed; empty marks nothing)
 */
export function appendHighlighted(parent: HTMLElement, text: string, query: string): void {
  const needle = query.trim().toLowerCase();
  if (needle === '') {
    parent.append(text);
    return;
  }
  const haystack = text.toLowerCase();
  let from = 0;
  for (let at = haystack.indexOf(needle); at !== -1; at = haystack.indexOf(needle, from)) {
    parent.append(text.slice(from, at));
    const mark = document.createElement('mark');
    mark.textContent = text.slice(at, at + needle.length);
    parent.append(mark);
    from = at + needle.length;
  }
  parent.append(text.slice(from));
}

/**
 * Whether the viewer is on an Apple platform (⌘ instead of Ctrl in the hints).
 *
 * @returns True on macOS / iOS
 */
export function isMac(): boolean {
  return /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
}
