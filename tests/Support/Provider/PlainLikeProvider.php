<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;

/**
 * Answers every query it receives, like a `LIKE` scan: its group proves whether it ran.
 */
final class PlainLikeProvider implements SearchProviderInterface
{
    public function search(SearchQuery $query): SearchGroup
    {
        return new SearchGroup('plain', 'Plain', [new SearchItem($query->text, '/p')]);
    }
}
