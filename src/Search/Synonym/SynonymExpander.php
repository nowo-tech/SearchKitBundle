<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Synonym;

use Nowo\SearchKitBundle\Contract\SearchSynonymProviderInterface;
use Nowo\SearchKitBundle\Model\SearchTerm;
use Nowo\SearchKitBundle\Search\Text\TextNormalizer;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_key_exists;
use function array_slice;
use function count;
use function in_array;
use function is_string;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Expands the query into synonym alternatives before the providers run (1.8.0): SearchQuery::terms().
 *
 * Asks every SearchSynonymProviderInterface for the whole text and for each word, in the UI locale first and then in
 * the other `synonyms.locales`; a word's synonym replaces that word in the text (`cambiar contraseña` →
 * `cambiar password`), so the other words still have to match. Bounded: at most MAX_WORDS words and `synonyms.max_terms`
 * alternatives; a text longer than MAX_TEXT characters is not expanded. A provider that throws is logged and skipped.
 * Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SynonymExpander
{
    /** Most words of the text expanded one by one. */
    public const int MAX_WORDS = 4;

    /** Longest text expanded (characters). */
    public const int MAX_TEXT = 64;

    /**
     * @param iterable<SearchSynonymProviderInterface> $providers Tagged providers
     * @param list<string> $locales Locales asked (the UI locale goes first)
     * @param int $maxTerms Most alternatives per query
     * @param LoggerInterface|null $logger Failing providers are reported here
     */
    public function __construct(
        private iterable $providers = [],
        private array $locales = ['en', 'es', 'it', 'fr', 'pt', 'de', 'nl'],
        private int $maxTerms = 24,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The alternatives of a text.
     *
     * @param string $text The query text (filters removed)
     * @param string|null $locale UI locale, or null
     *
     * @return list<SearchTerm> Alternatives in locale order (UI locale first), without the text itself
     */
    public function expand(string $text, ?string $locale): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            return [];
        }
        $words = array_slice(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::MAX_WORDS);
        $terms = [];
        $seen  = [TextNormalizer::fold($text) => true];
        foreach ($this->orderedLocales($locale) as $target) {
            // The whole text first (multi-word concepts such as "copia de seguridad"), then each word in place.
            foreach ($this->synonyms($text, $target) as $synonym) {
                $this->add($terms, $seen, $synonym, $target);
            }
            if (count($words) > 1) {
                foreach ($words as $position => $word) {
                    foreach ($this->synonyms($word, $target) as $synonym) {
                        $replaced            = $words;
                        $replaced[$position] = $synonym;
                        $this->add($terms, $seen, implode(' ', $replaced), $target);
                    }
                }
            }
            if (count($terms) >= $this->maxTerms) {
                break;
            }
        }

        return array_slice($terms, 0, $this->maxTerms);
    }

    /**
     * @return list<string>
     */
    private function orderedLocales(?string $locale): array
    {
        $ordered = $locale !== null && $locale !== '' ? [$locale] : [];
        foreach ($this->locales as $other) {
            if (!in_array($other, $ordered, true)) {
                $ordered[] = $other;
            }
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    private function synonyms(string $word, string $locale): array
    {
        $found = [];
        foreach ($this->providers as $provider) {
            try {
                foreach ($provider->synonymsOf($word, $locale) as $synonym) {
                    if (is_string($synonym) && trim($synonym) !== '') {
                        $found[] = trim($synonym);
                    }
                }
            } catch (Throwable $exception) {
                $this->logger?->error('Search synonym provider {provider} failed: {exception}.', [
                    'provider'  => $provider::class,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $found;
    }

    /**
     * @param list<SearchTerm> $terms
     * @param array<string, true> $seen
     */
    private function add(array &$terms, array &$seen, string $text, string $locale): void
    {
        $key = TextNormalizer::fold($text);
        if ($key === '' || array_key_exists($key, $seen)) {
            return;
        }
        $seen[$key] = true;
        $terms[]    = new SearchTerm($text, $locale, true);
    }
}
