<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Scope;

use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Scope\NullScopeResolver;
use Nowo\SearchKitBundle\Scope\SearchContextFactory;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SearchContextTest extends TestCase
{
    public function testTheFactoryCopiesThePageAndTheUserAsPlainValues(): void
    {
        $request = Request::create('/es/projects/acme/page?q=x');
        $request->attributes->set('_route', 'project_page');
        $request->attributes->set('_route_params', ['id' => 'acme', 'n' => 3, 'on' => true, 'none' => null, 'object' => new stdClass(), 'list' => ['a'], 0 => 'positional']);
        $request->setLocale('es');

        $context = SearchContextFactory::create($request, new InMemoryUser('alice', null, ['ROLE_USER', 'ROLE_EDITOR']));

        self::assertSame('alice', $context->userIdentifier);
        self::assertSame('/es/projects/acme/page', $context->path, 'path info, no query string');
        self::assertSame('project_page', $context->routeName);
        self::assertSame(['id' => 'acme', 'n' => 3, 'on' => true, 'none' => null], $context->routeParameters, 'scalars only');
        self::assertSame('es', $context->locale);
        self::assertSame(['ROLE_USER', 'ROLE_EDITOR'], $context->roles);
        self::assertTrue($context->hasRole('ROLE_EDITOR'));
        self::assertFalse($context->hasRole('ROLE_ADMIN'));
        self::assertFalse($context->hasRole('role_editor'), 'exact match');
    }

    public function testARequestWithoutRouteAttributes(): void
    {
        $request = Request::create('/x');
        $request->attributes->set('_route_params', 'not an array');

        $context = SearchContextFactory::create($request, new InMemoryUser('bob', null));

        self::assertNull($context->routeName);
        self::assertSame([], $context->routeParameters);
        self::assertSame([], $context->roles);
    }

    public function testTheDefaultResolverResolvesNothingOnBothPorts(): void
    {
        $resolver = new NullScopeResolver();
        self::assertNull($resolver->resolveFor(new SearchContext('alice', '/projects/acme/page')));
        self::assertNull($resolver->resolve(Request::create('/projects/acme/page'), new InMemoryUser('alice', null)));
    }
}
