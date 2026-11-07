import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { compileExemptPatterns, isMinLengthExempt } from '../src/min-length';

interface Case {
  pattern: string;
  match: string[];
  noMatch: string[];
}

const fixture = JSON.parse(readFileSync(resolve(__dirname, '../../../../tests/Fixtures/min-length-exempt-patterns.json'), 'utf8')) as { valid: Case[] };

describe('min_length exempt patterns', () => {
  // Same file as MinLengthExemptPatternTest.php: the two dialects must give the same answer for every pair.
  it.each(fixture.valid)('$pattern means the same as in PCRE', ({ pattern, match, noMatch }) => {
    const [regex] = compileExemptPatterns([pattern]);
    expect(regex).toBeDefined();
    for (const text of match) {
      expect(regex?.test(text), JSON.stringify(text)).toBe(true);
    }
    for (const text of noMatch) {
      expect(regex?.test(text), JSON.stringify(text)).toBe(false);
    }
  });

  it('compiles nothing without patterns', () => {
    expect(compileExemptPatterns()).toEqual([]);
    expect(compileExemptPatterns([])).toEqual([]);
  });

  it('never exempts through a pattern that does not compile', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const patterns = compileExemptPatterns(['(?i)a', 'a++', '^#\\d+$']);
    expect(patterns).toHaveLength(1);
    expect(error).toHaveBeenCalledTimes(2);
    expect(isMinLengthExempt('a', patterns)).toBe(false);
    expect(isMinLengthExempt('#7', patterns)).toBe(true);
  });

  it('tolerates a configuration that is not a list of strings', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    expect(compileExemptPatterns({ issue: '^#\\d+$' })).toEqual([]);
    expect(compileExemptPatterns('^#\\d+$')).toEqual([]);
    expect(compileExemptPatterns([7, null, '^#\\d+$'])).toHaveLength(1);
    expect(error).toHaveBeenCalledTimes(4);
  });

  it('never exempts the empty query, even for a pattern that matches it', () => {
    expect(isMinLengthExempt('', compileExemptPatterns(['^\\d*$']))).toBe(false);
    expect(isMinLengthExempt('7', compileExemptPatterns(['^\\d*$']))).toBe(true);
  });
});
