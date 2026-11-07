<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

use Nowo\SearchKitBundle\Contract\MinLengthExemptSearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;

/**
 * Opted in to queries below `min_length`; the row subtitle shows whether the query was one.
 */
final class ExemptIssueNumberProvider implements MinLengthExemptSearchProviderInterface
{
    public function search(SearchQuery $query): SearchGroup
    {
        return new SearchGroup('number', 'Number', [new SearchItem($query->text, '/n', $query->belowMinLength ? 'below' : 'normal')]);
    }
}
