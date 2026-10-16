<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Test provider: throws for "boom", echoes the scope otherwise (lowest priority). */
#[AsTaggedItem(priority: -10)]
final class FailingProvider implements SearchProviderInterface
{
    public function search(SearchQuery $query): SearchGroup
    {
        if ($query->text === 'boom') {
            throw new RuntimeException('provider failure');
        }

        return new SearchGroup('echo', 'Echo', [new SearchItem($query->text, '/echo', $query->scope ?? 'unscoped')]);
    }
}
