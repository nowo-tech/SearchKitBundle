<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Event;

use Nowo\SearchKitBundle\Model\SearchSubject;
use Symfony\Contracts\EventDispatcher\Event;

use function array_values;

/**
 * The shared host access event of search rows (1.9.0, SearchKitEvents::ITEM_VISIBILITY).
 *
 * Dispatched once per search page (batched) by every EntitySearchProvider, **after** the bundle's own access check,
 * with the candidates that check kept. Each subject gets one of three answers:
 *
 * - default (no listener said anything): the bundle's decision stands — the row is shown;
 * - allow: recorded, the row is shown (an allow never overrides a deny);
 * - deny: the row is dropped before it is counted. **Deny wins**, whatever the order of the listeners.
 *
 * A host applies its cross-cutting rules (a disabled project feature, a legal hold, a guest viewer) in one listener
 * for every bundle. The event carries no query text. One instance per dispatch; never reused.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SearchItemVisibilityEvent extends Event
{
    /** @var array<string, SearchSubject> Candidates by key */
    private array $subjects = [];

    /** @var array<string, bool> Decision per key: true allow, false deny */
    private array $decisions = [];

    /**
     * @param string $group Group key of the provider (e.g. `tasks`)
     * @param list<SearchSubject> $subjects The candidates the bundle's check kept
     * @param string $userIdentifier The viewer
     * @param int|string $tenant The tenant the search runs in
     * @param string|null $scope The query scope (client input, already shape-checked), or null
     * @param string|null $locale UI locale, or null
     */
    public function __construct(
        public readonly string $group,
        array $subjects,
        public readonly string $userIdentifier,
        public readonly int|string $tenant,
        public readonly ?string $scope = null,
        public readonly ?string $locale = null,
    ) {
        foreach ($subjects as $subject) {
            $this->subjects[$subject->key()] = $subject;
        }
    }

    /**
     * The candidates, in search order.
     *
     * @return list<SearchSubject>
     */
    public function subjects(): array
    {
        return array_values($this->subjects);
    }

    /**
     * Allows a subject (no effect on a denied one, or on a subject that is not a candidate).
     *
     * @param SearchSubject $subject The subject
     */
    public function allow(SearchSubject $subject): void
    {
        $key = $subject->key();
        if (isset($this->subjects[$key])) {
            // @igor-ignore - per-dispatch event object, created for one search and never reused (not a service)
            $this->decisions[$key] ??= true;
        }
    }

    /**
     * Denies a subject (final: a later allow does not undo it). A subject that is not a candidate is ignored.
     *
     * @param SearchSubject $subject The subject
     */
    public function deny(SearchSubject $subject): void
    {
        $key = $subject->key();
        if (isset($this->subjects[$key])) {
            // @igor-ignore - per-dispatch event object, created for one search and never reused (not a service)
            $this->decisions[$key] = false;
        }
    }

    /**
     * Denies every candidate (e.g. the whole group is off for this viewer).
     */
    public function denyAll(): void
    {
        foreach ($this->subjects as $key => $subject) {
            // @igor-ignore - per-dispatch event object, created for one search and never reused (not a service)
            $this->decisions[$key] = false;
        }
    }

    /**
     * The decision on a subject: true allowed, false denied, null default.
     *
     * @param SearchSubject $subject The subject
     */
    public function decision(SearchSubject $subject): ?bool
    {
        return $this->decisions[$subject->key()] ?? null;
    }

    /**
     * Whether a subject was denied.
     *
     * @param SearchSubject $subject The subject
     */
    public function isDenied(SearchSubject $subject): bool
    {
        return $this->decision($subject) === false;
    }
}
