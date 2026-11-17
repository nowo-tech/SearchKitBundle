<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Http;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The access gate shared by the 1.8.0 JSON endpoints (recents revalidation, filter values): signed-in user granted
 * `role` (JSON 401 / 403), then the per-user rate limiter of the search endpoint (429). Stateless.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class EndpointGuard
{
    /** Headers of every answer: never cached, never indexed. */
    public const array HEADERS = ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex'];

    /**
     * @param Security $security Current user and role check
     * @param string $role Attribute required to search
     * @param RateLimiterFactoryInterface|null $limiter Per-user limiter (null: rate_limit.enabled false)
     */
    public function __construct(
        private Security $security,
        private string $role = 'IS_AUTHENTICATED',
        private ?RateLimiterFactoryInterface $limiter = null,
    ) {
    }

    /**
     * The signed-in user, or the error answer.
     *
     * @return UserInterface|JsonResponse The user, or 401 / 403 / 429 JSON
     */
    public function check(): UserInterface|JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof UserInterface) {
            return self::json(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->security->isGranted($this->role)) {
            return self::json(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }
        if (!$this->accepts($user)) {
            return self::json(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $user;
    }

    /**
     * Consumes one token of the user's rate limit.
     *
     * @param UserInterface $user The user
     *
     * @return bool False when the user exceeded the limit
     */
    public function accepts(UserInterface $user): bool
    {
        return !$this->limiter instanceof RateLimiterFactoryInterface
            || $this->limiter->create(hash('sha256', $user->getUserIdentifier()))->consume()->isAccepted();
    }

    /**
     * A JSON answer with the endpoint headers.
     *
     * @param array<string, mixed> $data Body
     * @param int $status HTTP status
     *
     * @return JsonResponse The answer
     */
    public static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, self::HEADERS);
    }
}
