<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Query;

use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The "see all" link of a group (1.9.0): the bundle's own list page with the query text (and, optionally, the scope)
 * as query-string parameters. The link is generated, never echoed: the router escapes the values, and a URL that
 * SearchItem::isSafeUrl() refuses gives null. Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SeeAllLink
{
    /**
     * Builds the link.
     *
     * @param UrlGeneratorInterface $urls The router
     * @param string $route Route of the list page
     * @param array<string, mixed> $parameters Other route parameters
     * @param SearchQuery $query The search
     * @param string $textParameter Parameter that receives the query text
     * @param string|null $scopeParameter Parameter that receives the scope (null: the scope is not passed)
     *
     * @return string|null A path or http(s) URL, or null when the generated URL is not safe
     */
    public static function build(UrlGeneratorInterface $urls, string $route, array $parameters, SearchQuery $query, string $textParameter = 'q', ?string $scopeParameter = null): ?string
    {
        $parameters[$textParameter] = $query->text;
        if ($scopeParameter !== null && ScopeParser::isValid($query->scope)) {
            $parameters[$scopeParameter] = $query->scope;
        }
        $url = $urls->generate($route, $parameters);

        return SearchItem::isSafeUrl($url) ? $url : null;
    }
}
