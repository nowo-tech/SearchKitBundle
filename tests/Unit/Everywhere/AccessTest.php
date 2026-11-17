<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Everywhere;

use Nowo\SearchKitBundle\Model\SearchAccessRequest;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Deny by default: no checker, only abstentions, one veto or a failing checker all deny; URLs are matched as GET.
 */
final class AccessTest extends TestCase
{
    public function testDecisionRules(): void
    {
        $request = new SearchAccessRequest('alice', '/page', 'page');
        self::assertFalse((new SearchAccess())->isAllowed($request), 'no checker: denied');
        self::assertFalse((new SearchAccess([Fixtures::checker(static fn (): ?bool => null)]))->isAllowed($request), 'only abstentions: denied');
        self::assertTrue((new SearchAccess([Fixtures::checker(static fn (): ?bool => null), Fixtures::checker(static fn (): bool => true)]))->isAllowed($request));
        self::assertFalse((new SearchAccess([Fixtures::checker(static fn (): bool => true), Fixtures::checker(static fn (): bool => false)]))->isAllowed($request), 'a veto wins');

        $logger = Fixtures::logger();
        $access = new SearchAccess([Fixtures::checker(static fn (): bool => true), Fixtures::checker(static fn (): never => throw new RuntimeException('db down'))], null, $logger);
        self::assertFalse($access->isAllowed($request), 'a failing checker denies');
        self::assertStringEndsWith('failed: RuntimeException.', $logger->lines[0]);
    }

    public function testUrlsAreMatchedToGetRoutes(): void
    {
        $checker = Fixtures::checker(static fn (SearchAccessRequest $request): bool => true);
        $access  = new SearchAccess([$checker], Fixtures::router('POST'));

        $request = $access->requestForUrl('/projects/acme/tabs/files?x=1#top', 'alice', SearchAccessRequest::KIND_RECENT, 'acme');
        self::assertNotNull($request);
        self::assertSame('tab', $request->route);
        self::assertSame(['id' => 'acme', 'tab' => 'files'], $request->routeParameters);
        self::assertSame('acme', $request->scope);
        self::assertSame(SearchAccessRequest::KIND_RECENT, $request->kind);

        $external = $access->requestForUrl('https://example.com/docs', 'alice', SearchAccessRequest::KIND_ACTION);
        self::assertNotNull($external);
        self::assertNull($external->route);

        self::assertNull($access->requestForUrl('javascript:alert(1)', 'alice', SearchAccessRequest::KIND_ROW));
        self::assertNull($access->requestForUrl('/nowhere', 'alice', SearchAccessRequest::KIND_ROW));
        self::assertNull($access->requestForUrl('/post-only', 'alice', SearchAccessRequest::KIND_ROW), 'matched as a GET even during a POST');
        self::assertSame('POST', Fixtures::router('POST')->getContext()->getMethod());

        self::assertTrue($access->allowsUrl('/page', 'alice', SearchAccessRequest::KIND_ROW));
        self::assertFalse($access->allowsUrl('/nowhere', 'alice', SearchAccessRequest::KIND_ROW));
        self::assertSame(['flag' => true], $access->referenceFor('/defaults', 'pages')?->routeParameters, 'only scalar parameters, no "_" keys');
        self::assertNull($access->referenceFor('https://example.com/x', 'pages'));
        self::assertNull((new SearchAccess([$checker]))->referenceFor('/page', 'pages'), 'no router: nothing can be matched');

        $get = new SearchAccess([$checker], Fixtures::router());
        self::assertSame('page', $get->referenceFor('/page', 'pages')?->route);
    }

    public function testTheRouterContextIsRestored(): void
    {
        $router = Fixtures::router('POST');
        $before = $router->getContext();
        (new SearchAccess([], $router))->referenceFor('/page', 'x');
        self::assertSame($before, $router->getContext());
        self::assertSame('POST', $router->getContext()->getMethod());
    }
}
