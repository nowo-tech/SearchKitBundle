<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Recent;

use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use Psr\Log\LoggerInterface;
use Throwable;

use function count;
use function is_array;
use function is_string;

/**
 * Revalidates the recent references of a viewer (1.8.0, `recent.revalidate`): the browser stores only URL + kind and
 * asks for the rows every time it shows them.
 *
 * Per reference, always in this order: same-site path → matched GET route → **access checkers (deny by default)** →
 * the first SearchReferenceResolverInterface that returns a row with the same URL. Anything else is dropped, and a
 * dropped reference looks exactly like one that never existed (the answer only lists the kept rows). Holds no state.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class RecentResolver
{
    /** Allowed kind names (anything else becomes `page`). */
    private const string KIND = '/^[a-z0-9_-]{1,40}$/i';

    /**
     * @param SearchAccess $access Access checkers and the URL matcher
     * @param iterable<SearchReferenceResolverInterface> $resolvers Tagged resolvers (registry and menu pages first)
     * @param LoggerInterface|null $logger Failing resolvers are reported here
     */
    public function __construct(
        private SearchAccess $access,
        private iterable $resolvers = [],
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The rows the viewer may still open, in the order of the references.
     *
     * @param array<mixed> $references Client input: `[{url, kind}]` (other shapes are ignored)
     * @param string $userIdentifier The viewer
     * @param string|null $locale UI locale, or null
     * @param int $max Most references read (`recent.max`)
     *
     * @return list<SearchItem> The kept rows
     */
    public function resolve(array $references, string $userIdentifier, ?string $locale, int $max): array
    {
        $items = [];
        $seen  = [];
        foreach ($references as $reference) {
            if (count($seen) >= $max) {
                break;
            }
            $url  = is_array($reference) && is_string($reference['url'] ?? null) ? $reference['url'] : '';
            $kind = is_array($reference) && is_string($reference['kind'] ?? null) && preg_match(self::KIND, $reference['kind']) === 1 ? $reference['kind'] : 'page';
            if ($url === '' || isset($seen[$url]) || mb_strlen($url) > 2048) {
                continue;
            }
            $seen[$url] = true;
            $item       = $this->resolveOne($url, $kind, $userIdentifier, $locale);
            if ($item instanceof SearchItem) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function resolveOne(string $url, string $kind, string $userIdentifier, ?string $locale): ?SearchItem
    {
        $reference = $this->access->referenceFor($url, $kind);
        if (!$reference instanceof SearchReference) {
            return null;
        }
        $request = new SearchAccessRequest($userIdentifier, $url, $reference->route, $reference->routeParameters, SearchAccessRequest::KIND_RECENT);
        if (!$this->access->isAllowed($request)) {
            return null;
        }
        foreach ($this->resolvers as $resolver) {
            try {
                $item = $resolver->resolve($reference, $userIdentifier, $locale);
            } catch (Throwable $exception) {
                $this->logger?->error('Search reference resolver {resolver} failed: {exception}.', [
                    'resolver'  => $resolver::class,
                    'exception' => $exception::class,
                ]);

                continue;
            }
            if ($item instanceof SearchItem && $item->url === $url) {
                return $item;
            }
        }

        return null;
    }
}
