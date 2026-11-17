<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use InvalidArgumentException;

use function in_array;

/**
 * One entry of the page registry (`nowo_search_kit.pages`, or a SearchPageSourceInterface, 1.8.0): a page, tab or
 * settings section that the search finds by its label, its breadcrumb and its keywords.
 *
 * Keywords are a translation key whose message is a comma-separated list (one per locale), matched but never shown.
 * The page is listed only when the SearchAccessCheckerInterface allows its route and parameters for the viewer
 * (deny by default).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchPage
{
    /** The page exists everywhere (default). */
    public const string SCOPE_NONE = 'none';

    /** The page exists only inside a scope (e.g. a project tab): listed only while the search is scoped. */
    public const string SCOPE_REQUIRED = 'required';

    /**
     * @param string $id Stable id (`[A-Za-z0-9._:-]`)
     * @param string $label Translation key, or the plain label when $translationDomain is null
     * @param string $route Route of the page
     * @param array<string, scalar|null> $routeParameters Route parameters
     * @param string|null $translationDomain Domain of $label, $trail and $keywords; null: they are plain text
     * @param list<string> $trail Breadcrumb before the label (translation keys, or plain text)
     * @param string|null $icon UX Icons name, or null for the `pages` kind icon
     * @param string|null $keywords Translation key of the comma-separated keywords (plain list when the domain is null)
     * @param string $scope self::SCOPE_NONE or self::SCOPE_REQUIRED
     * @param string|null $attribute Permission attribute passed to the access checker (SearchAccessRequest::$attribute)
     * @param string|null $subject Subject passed to the access checker (SearchAccessRequest::$subject)
     * @param string|null $scopeParameter With SCOPE_REQUIRED: the route parameter that receives the scope id
     *
     * @throws InvalidArgumentException When the id, the route or the scope is invalid
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $route,
        public array $routeParameters = [],
        public ?string $translationDomain = 'messages',
        public array $trail = [],
        public ?string $icon = null,
        public ?string $keywords = null,
        public string $scope = self::SCOPE_NONE,
        public ?string $attribute = null,
        public ?string $subject = null,
        public ?string $scopeParameter = null,
    ) {
        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $id) !== 1) {
            throw new InvalidArgumentException('A search page id may only contain letters, digits, ".", "_", ":" and "-" (1-128 characters).');
        }
        if (trim($route) === '' || trim($label) === '') {
            throw new InvalidArgumentException('A search page needs a label and a route.');
        }
        if (!in_array($scope, [self::SCOPE_NONE, self::SCOPE_REQUIRED], true)) {
            throw new InvalidArgumentException('A search page scope must be "none" or "required".');
        }
    }

    /**
     * Builds an entry from one normalised `nowo_search_kit.pages` configuration row.
     *
     * @param array{id: string, label: string, translation_domain: string|null, route: string, route_parameters: array<string, scalar|null>, trail: list<string>, icon: string|null, keywords: string|null, scope: string, attribute: string|null, subject: string|null, scope_parameter: string|null} $row
     *
     * @return self The entry
     */
    public static function fromConfig(array $row): self
    {
        return new self(
            $row['id'],
            $row['label'],
            $row['route'],
            $row['route_parameters'],
            $row['translation_domain'],
            $row['trail'],
            $row['icon'],
            $row['keywords'],
            $row['scope'],
            $row['attribute'],
            $row['subject'],
            $row['scope_parameter'],
        );
    }
}
