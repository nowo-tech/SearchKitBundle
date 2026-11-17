<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Synonym;

use Nowo\SearchKitBundle\Contract\SearchSynonymProviderInterface;
use Nowo\SearchKitBundle\Search\Text\TextNormalizer;

use function array_key_exists;
use function dirname;
use function in_array;
use function is_array;
use function is_string;

/**
 * Built-in synonym dictionary (1.8.0, `synonyms.builtin_dictionary`): concept groups across en es it fr pt de nl
 * (`Resources/synonyms/concepts.php`). A word of any locale belongs to its concepts; synonymsOf() returns the words of
 * those concepts in the asked locale.
 *
 * The index is built once in the constructor and never changes (no request state): safe in FrankenPHP worker mode.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ConceptGroupSynonymProvider implements SearchSynonymProviderInterface
{
    /** @var list<array<string, list<string>>> */
    private array $groups;

    /** @var array<string, list<int>> Folded word → indexes in */
    private array $index;

    /**
     * @param list<array<string, list<string>>>|null $groups Concept groups (locale → words); null: the bundle dictionary
     */
    public function __construct(?array $groups = null)
    {
        $groups ??= self::bundled();
        $index = [];
        foreach ($groups as $position => $group) {
            foreach ($group as $words) {
                foreach ($words as $word) {
                    $folded = TextNormalizer::fold($word);
                    if ($folded !== '' && !in_array($position, $index[$folded] ?? [], true)) {
                        $index[$folded][] = $position;
                    }
                }
            }
        }
        $this->groups = $groups;
        $this->index  = $index;
    }

    /**
     * The bundled concept groups.
     *
     * @return list<array<string, list<string>>> The groups of `Resources/synonyms/concepts.php`
     */
    public static function bundled(): array
    {
        /** @var list<array<string, list<string>>> $groups */
        $groups = require dirname(__DIR__, 2) . '/Resources/synonyms/concepts.php';

        return $groups;
    }

    /**
     * Words of the concepts of $word, in $locale (without $word itself).
     */
    public function synonymsOf(string $word, string $locale): array
    {
        $folded = TextNormalizer::fold($word);
        $found  = [];
        $seen   = [$folded => true];
        foreach ($this->index[$folded] ?? [] as $position) {
            $words = $this->groups[$position][$locale] ?? null;
            foreach (is_array($words) ? $words : [] as $synonym) {
                $key = is_string($synonym) ? TextNormalizer::fold($synonym) : '';
                if ($key !== '' && !array_key_exists($key, $seen)) {
                    $seen[$key] = true;
                    $found[]    = $synonym;
                }
            }
        }

        return $found;
    }
}
