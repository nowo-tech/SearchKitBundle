<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

/**
 * Synonyms of a query word (1.8.0). The engine expands the words of the query before the providers run; providers read
 * the alternatives through SearchQuery::terms() and match them with SearchQuery::matches() / score().
 *
 * Every service implementing it is tagged `nowo_search_kit.synonym_provider` (autoconfiguration). The bundle ships one
 * (`synonyms.builtin_dictionary`): concept groups across en es it fr pt de nl. ManualKitBundle reads the same port.
 *
 * Contract: `synonymsOf($word, $locale)` returns the words **in $locale** that mean the same as $word — whatever the
 * language $word is written in (cross-locale: `contraseña` → `password` for `en`). The engine asks for the UI locale
 * first, then the other `synonyms.locales`, and tags every alternative with the locale it was asked for. Return plain
 * words (no markup), without $word itself; keep it pure and cheap (an in-memory lookup): it runs on every keystroke.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SearchSynonymProviderInterface
{
    /** Service tag of the implementations (autoconfigured). */
    public const string SYNONYM_PROVIDER_TAG = 'nowo_search_kit.synonym_provider';

    /**
     * Words of one locale that mean the same as a word.
     *
     * @param string $word One word of the query (as typed: fold case and accents yourself when you compare)
     * @param string $locale Locale of the synonyms wanted (`en`, `es` …)
     *
     * @return list<string> The synonyms in that locale (may be empty)
     */
    public function synonymsOf(string $word, string $locale): array;
}
