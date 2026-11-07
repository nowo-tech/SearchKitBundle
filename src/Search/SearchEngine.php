<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search;

use InvalidArgumentException;
use Nowo\SearchKitBundle\Contract\MinLengthExemptSearchProviderInterface;
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
     * @param list<string> $minLengthExemptPatterns A shorter query matching one runs, on the providers implementing MinLengthExemptSearchProviderInterface only
     *
     * @throws InvalidArgumentException When a pattern is invalid (container compilation reports it first)
     */
    public function __construct(
        private iterable $providers,
        private int $minLength = 2,
        private int $perGroup = 5,
        private ?LoggerInterface $logger = null,
        private array $minLengthExemptPatterns = [],
    ) {
        foreach ($minLengthExemptPatterns as $pattern) {
            $problem = MinLengthExemptPattern::problem($pattern);
            if ($problem !== null) {
                throw new InvalidArgumentException('Invalid min_length exempt pattern: ' . $problem . '.');
            }
        }
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
        $text   = trim($text);
        $exempt = mb_strlen($text) < $this->minLength;
        if ($exempt && !$this->isExempt($text)) {
            return [];
        }

        $query  = new SearchQuery($text, $user, $this->perGroup, $scope, $exempt);
        $groups = [];
        foreach ($this->providers as $provider) {
            // The minimum exists so that providers do not scan for a fragment: only those that asked get one.
            if ($exempt && !$provider instanceof MinLengthExemptSearchProviderInterface) {
                continue;
            }

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

    /**
     * Whether a query below `min_length` matches an exempt pattern. Never the empty query, whatever the pattern.
     */
    private function isExempt(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        foreach ($this->minLengthExemptPatterns as $pattern) {
            $matches = MinLengthExemptPattern::matches($pattern, $text);
            if ($matches === true) {
                return true;
            }
            // Backtrack or JIT limit: the query stays refused, and the host learns its pattern is too costly.
            if ($matches === null) {
                $this->logger?->error('Search min_length exempt pattern {pattern} failed: {error}.', [
                    'pattern' => $pattern,
                    'error'   => preg_last_error_msg(),
                ]);
            }
        }

        return false;
    }
}
