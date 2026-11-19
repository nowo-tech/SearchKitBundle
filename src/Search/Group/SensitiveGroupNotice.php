<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Group;

use Psr\Log\LoggerInterface;

/**
 * Says once per container (one worker process) why a sensitive entity group is off (1.9.0): its rows are sensitive and
 * SearchKit recents are kept in the browser without revalidation. Shared by every EntitySearchProvider of the
 * container. It remembers group keys only (configuration, never user data), so the memo is safe in FrankenPHP worker
 * mode and deliberately survives requests; it implements no reset.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SensitiveGroupNotice
{
    /** @var array<string, true> Group keys already reported */
    private array $reported = [];

    /**
     * @param LoggerInterface|null $logger Receives the notice (null: nothing is logged)
     */
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Logs the notice of a group, the first time only.
     *
     * @param string $group Group key
     */
    public function report(string $group): void
    {
        if (isset($this->reported[$group])) {
            return;
        }
        // @igor-ignore - remembers configured group keys only, to log once per container (no user data, no reset by design)
        $this->reported[$group] = true;
        $this->logger?->notice('Search group {group} is off: its rows are sensitive and SearchKit recents are kept without revalidation (set nowo_search_kit.recent.revalidate: true, or recent.enabled: false).', ['group' => $group]);
    }
}
