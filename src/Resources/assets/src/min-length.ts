/**
 * Compiles `minLengthExemptPatterns`. Container compilation already refused anything outside the subset PCRE and
 * ECMAScript read alike (`MinLengthExemptPattern`), so a failure here means the configuration did not come from the
 * bundle: that pattern exempts nothing, and the console says so.
 *
 * @param patterns Pattern sources from the configuration; absent for a host that does not use the option
 * @returns The usable patterns, with the `u` flag the server's `(*UTF)` mirrors
 */
export function compileExemptPatterns(patterns: string[] = []): RegExp[] {
  const compiled: RegExp[] = [];
  for (const pattern of patterns) {
    try {
      compiled.push(new RegExp(pattern, 'u'));
    } catch {
      console.error(`nowo-search: ignoring invalid min_length exempt pattern ${pattern}`);
    }
  }
  return compiled;
}

/**
 * Whether a trimmed query below `minLength` still searches. Never the empty query, whatever the pattern.
 *
 * @param query Trimmed query
 * @param patterns Result of compileExemptPatterns()
 * @returns True when a pattern matches a non-empty query
 */
export function isMinLengthExempt(query: string, patterns: RegExp[]): boolean {
  return query !== '' && patterns.some((pattern) => pattern.test(query));
}
