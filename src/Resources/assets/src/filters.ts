/** Typed filters in the field (1.8.0): `key:value`, `key:"quoted value"`, `-key:value` for the configured keys. */

/** One filter token found in the text. */
export interface FilterToken {
  key: string;
  value: string;
  negated: boolean;
  /** The token as typed (removed from the text when its chip is clicked). */
  raw: string;
}

const TOKEN = /(^|\s)(-?)([A-Za-z][A-Za-z0-9_-]{0,31}):(?:"([^"]*)"?|(\S*))/g;

/**
 * The complete filter tokens of a text, for the configured keys only (a token without a value is not one yet).
 *
 * @param text The field value
 * @param keys Configured filter keys
 * @returns The tokens in typed order
 */
export function parseFilterTokens(text: string, keys: string[]): FilterToken[] {
  const tokens: FilterToken[] = [];
  for (const match of text.matchAll(TOKEN)) {
    const key = (match[3] ?? '').toLowerCase();
    const value = (match[4] ?? match[5] ?? '').trim();
    if (keys.includes(key) && value !== '') {
      tokens.push({ key, value, negated: match[2] === '-', raw: match[0].trim() });
    }
  }
  return tokens;
}

/**
 * The text without one token (whitespace collapsed).
 *
 * @param text The field value
 * @param raw The token as typed
 * @returns The new value
 */
export function removeFilterToken(text: string, raw: string): string {
  const index = text.indexOf(raw);
  if (index === -1) {
    return text;
  }
  return `${text.slice(0, index)} ${text.slice(index + raw.length)}`.replace(/\s+/g, ' ').trim();
}

/** The filter being typed at the end of the field (`assignee:al`), for the autocomplete. */
export interface PendingFilter {
  key: string;
  prefix: string;
  negated: boolean;
  /** Where the token starts in the text. */
  start: number;
}

/**
 * The unfinished `key:prefix` token at the end of the text, when its key is configured.
 *
 * @param text The field value
 * @param keys Configured filter keys
 * @returns The pending filter, or null
 */
export function pendingFilter(text: string, keys: string[]): PendingFilter | null {
  const match = /(^|\s)(-?)([A-Za-z][A-Za-z0-9_-]{0,31}):("?)([^\s"]*)$/.exec(text);
  if (match === null) {
    return null;
  }
  const key = (match[3] ?? '').toLowerCase();
  if (!keys.includes(key)) {
    return null;
  }
  return { key, prefix: match[5] ?? '', negated: match[2] === '-', start: match.index + (match[1] ?? '').length };
}

/**
 * The text with the pending token completed (quoted when the value has spaces) and a trailing space.
 *
 * @param text The field value
 * @param pending The pending filter
 * @param value The chosen value
 * @returns The new value
 */
export function completeFilter(text: string, pending: PendingFilter, value: string): string {
  const clean = value.replace(/"/g, '');
  const quoted = /\s/.test(clean) ? `"${clean}"` : clean;
  return `${text.slice(0, pending.start)}${pending.negated ? '-' : ''}${pending.key}:${quoted} `;
}
