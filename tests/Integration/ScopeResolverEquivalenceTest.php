<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration;

use Nowo\SearchKitBundle\Tests\Support\TestKernel;
use Nowo\SearchKitBundle\Twig\SearchKitExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function assert;

use const E_USER_DEPRECATED;

/**
 * The scope chip is a permission decision: the 1.7 SearchContext port must scope exactly as the deprecated
 * Request/UserInterface port did. Every host wiring (old port only, new port only, both aliases, one resolver with both
 * ports) is run over the same users × pages matrix in one kept-alive kernel each (no reset between calls), and must
 * give the same scope for every cell: never a wider one, and none for guests or outside a request.
 */
final class ScopeResolverEquivalenceTest extends TestCase
{
    /** @var array<string, list<string>|null> User → roles (null: guest) */
    private const array USERS = [
        'alice' => ['ROLE_USER'],
        'bob'   => ['ROLE_USER'],
        'admin' => ['ROLE_ADMIN'],
        'guest' => null,
    ];

    private const array PATHS = [
        '/projects/acme/page',
        '/projects/beta/page',
        '/projects/gamma/page',
        '/projects/zeta/page',
        '/projects/acme',
        '/projects/ACME/page',
        '/page',
        '/',
    ];

    /** @var list<TestKernel> */
    private array $kernels = [];

    private int $deprecations = 0;

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-search-kit-bundle');
    }

    public function testEveryWiringGivesTheScopesOfTheDeprecatedPort(): void
    {
        $legacy = $this->matrix(TestKernel::SCOPE_LEGACY);
        self::assertSame(24, $this->deprecations, 'the deprecated port is reported once per signed-in call (3 users × 8 pages)');

        foreach ([TestKernel::SCOPE_CONTEXT, TestKernel::SCOPE_BOTH, TestKernel::SCOPE_DUAL] as $port) {
            $this->deprecations = 0;
            self::assertSame($legacy, $this->matrix($port), $port . ' scopes exactly as the deprecated port');
            self::assertSame(0, $this->deprecations, $port . ' never reaches the deprecated port');
        }

        // The policy itself, so that "the same" cannot mean "both empty".
        self::assertSame(['id' => 'acme', 'label' => 'Acme Shop'], $legacy['alice /projects/acme/page']);
        self::assertSame(['id' => 'gamma', 'label' => 'Gamma Shop'], $legacy['alice /projects/gamma/page']);
        self::assertNull($legacy['bob /projects/acme/page'], 'bob cannot open acme: no chip');
        self::assertSame(['id' => 'beta', 'label' => 'Beta Shop'], $legacy['bob /projects/beta/page']);
        self::assertNull($legacy['admin /projects/gamma/page'], 'a role is not an identity: admin is not alice');
        self::assertNull($legacy['alice /projects/zeta/page']);
        self::assertNull($legacy['alice /projects/ACME/page']);
        foreach (self::PATHS as $path) {
            self::assertNull($legacy['guest ' . $path], 'guests never get a scope');
        }
        self::assertCount(5, array_filter($legacy, static fn (?array $scope): bool => $scope !== null));
    }

    public function testWithoutAMainRequestNoResolverIsAsked(): void
    {
        foreach ([TestKernel::SCOPE_LEGACY, TestKernel::SCOPE_CONTEXT, TestKernel::SCOPE_BOTH, TestKernel::SCOPE_DUAL] as $port) {
            $this->deprecations = 0;
            $container          = $this->container($port);
            self::assertNull($this->scope($container, 'alice', null), $port);
            self::assertSame(0, $this->deprecations, $port);
        }
    }

    /**
     * Runs every user × page cell twice, alternating users, in one kernel that is never reset.
     *
     * @return array<string, array{id: string, label: string}|null>
     */
    private function matrix(string $port): array
    {
        $container = $this->container($port);
        $first     = [];
        foreach (self::PATHS as $path) {
            foreach (array_keys(self::USERS) as $user) {
                $first[$user . ' ' . $path] = $this->scope($container, $user, $path);
            }
        }
        $deprecations = $this->deprecations;
        // Second pass in reverse order: no answer depends on the previous caller.
        foreach (array_reverse($first, true) as $cell => $scope) {
            [$user, $path] = explode(' ', $cell, 2);
            self::assertSame($scope, $this->scope($container, $user, $path), $port . ' ' . $cell . ' (second pass)');
        }
        $this->deprecations = $deprecations;

        return $first;
    }

    private function container(string $port): ContainerInterface
    {
        $kernel = new TestKernel([], true, null, $port);
        $kernel->boot();
        $this->kernels[] = $kernel;
        $container       = $kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $container;
    }

    /**
     * @return array{id: string, label: string}|null
     */
    private function scope(ContainerInterface $container, string $user, ?string $path): ?array
    {
        $tokens = $container->get('security.token_storage');
        $stack  = $container->get('request_stack');
        $twig   = $container->get(SearchKitExtension::class);
        assert($tokens instanceof TokenStorageInterface && $stack instanceof RequestStack && $twig instanceof SearchKitExtension);

        $roles = self::USERS[$user];
        $tokens->setToken($roles === null ? null : new UsernamePasswordToken(new InMemoryUser($user, null, $roles), 'main', $roles));
        if ($path !== null) {
            $stack->push(Request::create($path));
        }
        // Test-only, restored in finally: counts the 1.7 deprecation of the old port.
        set_error_handler(function (int $level): bool { // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
            ++$this->deprecations;

            return true;
        }, E_USER_DEPRECATED);
        try {
            return $twig->scope();
        } finally {
            restore_error_handler();
            if ($path !== null) {
                $stack->pop();
            }
            $tokens->setToken(null);
        }
    }
}
