<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

/**
 * A provider that checks permissions against SearchQuery::$userIdentifier only, so it may run in a search made by
 * identifier (SearchEngineInterface::searchFor()), where there is no Symfony user.
 *
 * searchFor() skips every provider without this marker: a 1.x provider may read the deprecated SearchQuery::$user
 * (null there, so a nullable criterion would match the wrong rows) or the Symfony `Security::getUser()` (the user of
 * the HTTP request, if any, not the identifier being searched for). Implement it only when neither is true.
 * The endpoint (`SearchEngine::search()`) runs every provider, marked or not. The security contract of
 * {@see SearchProviderInterface} applies unchanged.
 *
 * It is a marker read with `instanceof` on the tagged service, so a decorator has to implement it as well.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface IdentifierAwareSearchProviderInterface extends SearchProviderInterface
{
}
