<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Controller;

use Nowo\SearchKitBundle\Contract\SearchFilterValuesProviderInterface;
use Nowo\SearchKitBundle\Http\EndpointGuard;
use Nowo\SearchKitBundle\Model\SearchFilter;
use Nowo\SearchKitBundle\Model\SearchFilterValue;
use Nowo\SearchKitBundle\Model\SearchScope;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

use function count;
use function in_array;

/**
 * `GET {prefix}/filters?key=…&q=…&scope=…` (1.8.0) → `{values: [{value, label}]}`: autocomplete of a typed filter value
 * from the SearchFilterValuesProviderInterface services, which return only values the viewer may see.
 *
 * Guarded like the search endpoint (signed-in, `role`, same per-user rate limit), never cached; 404 when no filter key
 * is configured. A key that is not configured, a key no provider supports and a prefix with no visible value all
 * answer the same `{values: []}` (no probing). A provider that throws is skipped (logged).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class FilterValuesController
{
    public const string ROUTE = 'nowo_search_kit_filter_values';

    /**
     * @param EndpointGuard $guard User, role and rate limit
     * @param iterable<SearchFilterValuesProviderInterface> $providers Tagged value providers
     * @param list<string> $keys `filters.keys`
     * @param int $limit `filters.values_limit`
     * @param LoggerInterface|null $logger Failing providers are reported here
     */
    public function __construct(
        private EndpointGuard $guard,
        private iterable $providers = [],
        private array $keys = [],
        private int $limit = 10,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Suggests values.
     *
     * @param Request $request The request (`key`, `q`, optional `scope`)
     *
     * @throws NotFoundHttpException When no filter key is configured
     *
     * @return JsonResponse `{values}`; 401 / 403 / 429 like the search endpoint
     */
    #[Route('/filters', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->keys === []) {
            throw new NotFoundHttpException();
        }
        $user = $this->guard->check();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $key    = strtolower($request->query->getString('key'));
        $prefix = trim(mb_substr($request->query->getString('q'), 0, SearchFilter::MAX_VALUE_LENGTH));
        $scope  = $request->query->getString('scope');
        $scope  = preg_match(SearchScope::ID_PATTERN, $scope) === 1 ? $scope : null;

        $values = [];
        if (in_array($key, $this->keys, true)) {
            foreach ($this->providers as $provider) {
                if (count($values) >= $this->limit) {
                    break;
                }
                try {
                    if (!$provider->supportsKey($key)) {
                        continue;
                    }
                    foreach ($provider->values($key, $prefix, $user->getUserIdentifier(), $scope, $this->limit) as $value) {
                        if ($value instanceof SearchFilterValue && $value->value !== '' && !isset($values[$value->value]) && count($values) < $this->limit) {
                            $values[$value->value] = $value->toArray();
                        }
                    }
                } catch (Throwable $exception) {
                    $this->logger?->error('Search filter values provider {provider} failed: {exception}.', [
                        'provider'  => $provider::class,
                        'exception' => $exception::class,
                    ]);
                }
            }
        }

        return EndpointGuard::json(['values' => array_values($values)]);
    }
}
