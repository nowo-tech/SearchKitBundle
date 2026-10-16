<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * Runs every tagged SearchProviderInterface (priority order) and keeps the non-empty groups.
 *
 * A provider that throws is logged and skipped: one broken group never takes the whole search box down. The log
 * entry names the provider and the exception class only (never the query text, which may be personal data).
 * Stateless: safe in FrankenPHP worker mode.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchEngine
{
    /**
     * @param iterable<SearchProviderInterface> $providers Tagged providers, highest priority first
     * @param int $minLength Shorter (trimmed) queries return no groups
     * @param int $perGroup Rows per group
     * @param LoggerInterface|null $logger Failing providers are reported here
     */
    public function __construct(
        private iterable $providers,
        private int $minLength = 2,
        private int $perGroup = 5,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Searches every provider.
     *
     * @param UserInterface $user The signed-in user
     * @param string $text Raw query (trimmed here)
     * @param string|null $scope Scope id, or null
     *
     * @return list<SearchGroup> Non-empty groups, in provider order, each with at most `per_group` rows
     */
    public function search(UserInterface $user, string $text, ?string $scope = null): array
    {
        $text = trim($text);
        if (mb_strlen($text) < $this->minLength) {
            return [];
        }

        $query  = new SearchQuery($text, $user, $this->perGroup, $scope);
        $groups = [];
        foreach ($this->providers as $provider) {
            try {
                $group = $provider->search($query);
            } catch (Throwable $exception) {
                $this->logger?->error('Search provider {provider} failed: {exception}.', [
                    'provider'  => $provider::class,
                    'exception' => $exception::class,
                ]);

                continue;
            }
            if ($group instanceof SearchGroup && $group->items !== []) {
                $groups[] = $group->limit($this->perGroup);
            }
        }

        return $groups;
    }
}
